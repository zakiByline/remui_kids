<?php
/**
 * Parent Dashboard - Main Page
 * Complete parent dashboard with navigation, sidebar, and all features
 */

// Suppress error display to prevent output before headers/session start
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once(__DIR__ . '/../../../config.php');
require_login();

global $USER, $DB, $CFG, $OUTPUT, $PAGE, $SESSION;

// ========================================
// THEME VALIDATION - Fix invalid theme references
// Prevents errors when theme is set to non-existent values like "new"
// This runs early to prevent theme loading errors during page rendering
// ========================================
if (isset($USER) && !empty($USER->theme)) {
    $theme_dir = $CFG->dirroot . '/theme/' . $USER->theme;
    if (!file_exists($theme_dir . '/version.php')) {
        // Theme doesn't exist or is invalid, reset to empty to use default
        $USER->theme = '';
        // Update user preference to prevent future errors
        if (property_exists($USER, 'id') && $USER->id > 0) {
            try {
                set_user_preference('theme', '', $USER->id);
            } catch (Exception $e) {
                // Silently fail if preference can't be set
            }
        }
    }
}
// Also check $CFG->theme for system-wide theme
if (!empty($CFG->theme)) {
    $theme_dir = $CFG->dirroot . '/theme/' . $CFG->theme;
    if (!file_exists($theme_dir . '/version.php')) {
        // Invalid system theme, fallback to remui_kids if available, otherwise boost
        if (file_exists($CFG->dirroot . '/theme/remui_kids/version.php')) {
            $CFG->theme = 'remui_kids';
        } elseif (file_exists($CFG->dirroot . '/theme/boost/version.php')) {
            $CFG->theme = 'boost';
        }
    }
}

$themeremui_parent_embed = defined('THEME_REMUI_KIDS_PARENT_EMBED') && THEME_REMUI_KIDS_PARENT_EMBED === true;
$parentdashboard_standalone = !$themeremui_parent_embed;

// ========================================
// PARENT ACCESS CONTROL - CRITICAL!
// Only users with parent role can access this dashboard
// ========================================
$parent_role = $DB->get_record('role', ['shortname' => 'parent']);
$system_context = context_system::instance();

$is_parent = false;
if ($parent_role) {
    // Check if user has parent role in system context
    $is_parent = user_has_role_assignment($USER->id, $parent_role->id, $system_context->id);
    
    // Also check if user has parent role in any user context (assigned to specific children)
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

// Deny access if not a parent
if (!$is_parent) {
    $redirect_url = new moodle_url('/');
    
    // If user is a student, redirect to student dashboard
    $student_role = $DB->get_record('role', ['shortname' => 'student']);
    if ($student_role && user_has_role_assignment($USER->id, $student_role->id, $system_context->id)) {
        $redirect_url = new moodle_url('/my/');
    }
    
    // Show error message and redirect
    redirect(
        $redirect_url,
        get_string('nopermissions', 'error', 'Access parent dashboard'),
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}

// Include child session manager
require_once(__DIR__ . '/../lib/child_session.php');

// Debug mode - set to true to see debugging information
$debug_mode = optional_param('debug', 0, PARAM_INT);

// Week offset for calendar navigation (0 = current week, -1 = previous week, +1 = next week)
$week_offset = optional_param('week_offset', 0, PARAM_INT);

// Get/Set selected child (persists across pages)
$selected_child_id = get_selected_child();

// Debug: Show what's selected
if ($debug_mode) {
    echo "<div style='background: #fef3c7; border: 2px solid #f59e0b; padding: 15px; margin: 20px; border-radius: 8px;'>";
    echo "<h3 style='margin: 0 0 10px 0; color: #92400e;'>DEBUG: Child Selection</h3>";
    echo "<p><strong>Selected Child ID:</strong> " . var_export($selected_child_id, true) . " (Type: " . gettype($selected_child_id) . ")</p>";
    echo "<p><strong>URL Parameter:</strong> " . var_export(optional_param('child', null, PARAM_RAW), true) . "</p>";
    echo "<p><strong>Session Value:</strong> " . var_export($SESSION->parent_selected_child ?? 'NOT SET', true) . "</p>";
    echo "<p><strong>Is Specific Child Selected?</strong> " . (($selected_child_id && $selected_child_id !== 'all' && $selected_child_id != 0) ? 'YES' : 'NO') . "</p>";
    if ($selected_child_id && $selected_child_id !== 'all' && $selected_child_id != 0) {
        $sel_child = null;
        foreach ($children_records as $ch) {
            if ($ch->id == $selected_child_id) {
                $sel_child = $ch;
                break;
            }
        }
        if ($sel_child) {
            echo "<p><strong>Selected Child Name:</strong> " . fullname($sel_child) . "</p>";
            echo "<p><strong>Selected Child Courses:</strong> " . count(enrol_get_users_courses($sel_child->id, true)) . "</p>";
        } else {
            echo "<p style='color: #dc2626;'><strong>ERROR:</strong> Selected child ID not found in children records!</p>";
        }
    }
    echo "</div>";
}

// Set up page context - use system context like parent_schedule.php
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/parent/parent_dashboard.php');
$PAGE->set_title('Parent Dashboard');
$PAGE->set_heading('Parent Dashboard');
$PAGE->set_pagelayout('base');

// Ensure blocks are loaded and initialized before calling standard_head_html()
// This is necessary because we're rendering outside the normal page flow
if (method_exists($PAGE->blocks, 'load_blocks')) {
    $PAGE->blocks->load_blocks();
    // Ensure all regions have at least an empty array in birecordsbyregion
    // This prevents "Trying to access array offset on null" errors
    $reflection = new ReflectionClass($PAGE->blocks);
    $property = $reflection->getProperty('birecordsbyregion');
    $property->setAccessible(true);
    $birecords = $property->getValue($PAGE->blocks);
    if ($birecords === null) {
        $birecords = [];
    }
    // Ensure all regions exist in the array
    foreach ($PAGE->blocks->get_regions() as $region) {
        if (!isset($birecords[$region])) {
            $birecords[$region] = [];
        }
    }
    $property->setValue($PAGE->blocks, $birecords);
}

// Get user information
$userid = $USER->id;

// ========================================
// FETCH REAL MOODLE DATA
// ========================================

// Get children linked to this parent
$children = [];

// Try multiple methods to find children
$children_records = [];

try {
    // Method 1: IOMAD company_users approach (if table exists)
    if ($DB->get_manager()->table_exists('company_users')) {
        // Get parent's company
        $parent_company = $DB->get_record('company_users', ['userid' => $userid]);
        
        if ($parent_company) {
            // Get all students in same company with parent role assignment
            // Include all fields required for user_picture
            $picture_fields = \core_user\fields::get_picture_fields();
            $picture_fields_sql = 'u.' . implode(', u.', $picture_fields);
            $sql = "SELECT DISTINCT $picture_fields_sql, u.timecreated,
                           u.phone1, u.phone2, u.address, u.city, u.country,
                           c.id as cohortid, c.name as cohortname,
                           cu.companyid
                    FROM {user} u
                    INNER JOIN {company_users} cu ON cu.userid = u.id
                    LEFT JOIN {cohort_members} cm ON cm.userid = u.id
                    LEFT JOIN {cohort} c ON c.id = cm.cohortid
                    WHERE cu.companyid = :companyid
                    AND u.id IN (
                        SELECT ctx.instanceid 
                        FROM {role_assignments} ra
                        JOIN {context} ctx ON ctx.id = ra.contextid
                        JOIN {role} r ON r.id = ra.roleid
                        WHERE ra.userid = :parentid
                        AND ctx.contextlevel = :ctxlevel
                        AND r.shortname = 'parent'
                    )
                    AND u.deleted = 0
                    ORDER BY u.firstname, u.lastname";
            
            $children_records = $DB->get_records_sql($sql, [
                'companyid' => $parent_company->companyid,
                'parentid' => $userid,
                'ctxlevel' => CONTEXT_USER
            ]);
        }
    }
    
    // Method 2: Standard Moodle role assignment (if Method 1 found nothing)
    if (empty($children_records)) {
        // Include all fields required for user_picture
        $picture_fields = \core_user\fields::get_picture_fields();
        $picture_fields_sql = 'u.' . implode(', u.', $picture_fields);
        $sql = "SELECT DISTINCT $picture_fields_sql, u.timecreated,
                       u.phone1, u.phone2, u.address, u.city, u.country,
                       c.id as cohortid, c.name as cohortname
                FROM {user} u
                LEFT JOIN {cohort_members} cm ON cm.userid = u.id
                LEFT JOIN {cohort} c ON c.id = cm.cohortid
                WHERE u.id IN (
                    SELECT ctx.instanceid 
                    FROM {role_assignments} ra
                    JOIN {context} ctx ON ctx.id = ra.contextid
                    JOIN {role} r ON r.id = ra.roleid
                    WHERE ra.userid = :parentid
                    AND ctx.contextlevel = :ctxlevel
                    AND r.shortname = 'parent'
                )
                AND u.deleted = 0
                ORDER BY u.firstname, u.lastname";
        
        $children_records = $DB->get_records_sql($sql, [
            'parentid' => $userid,
            'ctxlevel' => CONTEXT_USER
        ]);
    }
    
    // If no children found via role assignments, try to get mentees
    if (empty($children_records)) {
        // Get mentees (children) for this parent user
        $sql_mentee = "SELECT u.* 
                       FROM {user} u
                       WHERE u.id IN (
                           SELECT userid FROM {role_assignments} 
                           WHERE contextid IN (
                               SELECT id FROM {context} 
                               WHERE contextlevel = " . CONTEXT_USER . "
                               AND instanceid = :userid
                           )
                       )";
        $children_records = $DB->get_records_sql($sql_mentee, ['userid' => $userid]);
    }
    
    // Professional light blue color palette
    $avatar_colors = ['#60a5fa', '#3b82f6', '#2563eb', '#1d4ed8', '#93c5fd', '#7dd3fc'];
    $color_index = 0;
    
    foreach ($children_records as $child) {
        // Extract grade/class from cohort name if available
        $class = 'N/A';
        $section = 'A';
        if (!empty($child->cohortname)) {
            if (preg_match('/grade[\s]*(\d+)/i', $child->cohortname, $matches)) {
                $class = $matches[1];
            }
            if (preg_match('/section[\s]*([A-Z])/i', $child->cohortname, $matches)) {
                $section = $matches[1];
            }
        }
        
        // Get profile picture URL from Moodle
        $profile_picture_url = '';
        $has_profile_picture = false;
        if (isset($child->picture) && $child->picture > 0) {
            try {
                // Get full user record with all required fields for user_picture
                $child_user = $DB->get_record('user', ['id' => $child->id], '*', MUST_EXIST);
                if ($child_user && $child_user->picture > 0) {
                    $user_context = context_user::instance($child->id);
                    $fs = get_file_storage();
                    $files = $fs->get_area_files($user_context->id, 'user', 'icon', 0, 'itemid', false);
                    
                    if (!empty($files)) {
                        $user_picture = new user_picture($child_user);
                        $user_picture->size = 1; // Full size
                        $profile_picture_url = $user_picture->get_url($PAGE)->out(false);
                        if (!empty($profile_picture_url)) {
                            $has_profile_picture = true;
                        }
                    }
                }
            } catch (Exception $e) {
                // Profile picture not available - log error for debugging
                debugging('Error getting child profile picture for user ' . $child->id . ': ' . $e->getMessage());
            }
        }
        
        $children[] = [
            'id' => $child->id,  //   CRITICAL: Must have ID for selection!
            'name' => fullname($child),
            'gender' => 'N/A',
            'class' => $class,
            'roll' => str_pad($child->id, 3, '0', STR_PAD_LEFT),
            'section' => $section,
            'admission_id' => 'STU-' . $child->id,
            'admission_date' => date('d M, Y', $child->timecreated),
            'avatar_color' => $avatar_colors[$color_index++ % count($avatar_colors)],
            'course_count' => count(enrol_get_users_courses($child->id, true)),
            'profile_picture_url' => $profile_picture_url,
            'has_profile_picture' => $has_profile_picture
        ];
    }
} catch (Exception $e) {
    // If query fails, set empty array
    debugging('Error fetching children: ' . $e->getMessage());
    if ($debug_mode) {
        echo "<div style='background: #fee2e2; border: 2px solid #ef4444; padding: 20px; margin: 20px; border-radius: 8px;'>";
        echo "<h3 style='color: #991b1b;'>Debug Error:</h3>";
        echo "<p>" . $e->getMessage() . "</p>";
        echo "</div>";
    }
}

// Debug output
if ($debug_mode) {
    echo "<div style='background: #f0f9ff; border: 2px solid #60a5fa; padding: 20px; margin: 20px; border-radius: 8px; position: relative; z-index: 9999;'>";
    echo "<h2 style='color: #1e40af;'>" . " Debug Information</h2>";
    echo "<p><strong>Parent User ID:</strong> " . $userid . "</p>";
    echo "<p><strong>Parent Name:</strong> " . fullname($USER) . "</p>";
    echo "<p><strong>Parent Email:</strong> " . $USER->email . "</p>";
    
    // Check if Iomad
    $has_company = $DB->get_manager()->table_exists('company_users');
    echo "<p><strong>Iomad Detected:</strong> " . ($has_company ? "YES" : "NO") . "</p>";
    
    if ($has_company) {
        $parent_company = $DB->get_record('company_users', ['userid' => $userid]);
        echo "<p><strong>Parent in Company:</strong> " . ($parent_company ? "YES (ID: " . $parent_company->companyid . ")" : "NO") . "</p>";
    }
    
    echo "<p><strong>Children Found:</strong> " . count($children_records) . "</p>";
    
    if (!empty($children_records)) {
        echo "<table style='width: 100%; border-collapse: collapse; margin: 10px 0;'>";
        echo "<tr style='background: #f3f4f6;'><th style='padding: 10px; border: 1px solid #ddd;'>ID</th><th style='padding: 10px; border: 1px solid #ddd;'>Name</th><th style='padding: 10px; border: 1px solid #ddd;'>Email</th></tr>";
        foreach ($children_records as $c) {
            echo "<tr><td style='padding: 10px; border: 1px solid #ddd;'>" . $c->id . "</td>";
            echo "<td style='padding: 10px; border: 1px solid #ddd;'>" . fullname($c) . "</td>";
            echo "<td style='padding: 10px; border: 1px solid #ddd;'>" . $c->email . "</td></tr>";
        }
        echo "</table>";
    }
    
    echo "<p style='margin-top: 20px;'><a href='debug_parent_children.php' style='background: #60a5fa; color: white; padding: 10px 20px; text-decoration: none; border-radius: 6px; display: inline-block;'>Run Full Diagnostic Tool '</a></p>";
    echo "</div>";
}

// Calculate academic statistics based on selected child
$total_results = 0;
$total_courses = 0;
$total_activities = 0;

// Filter children based on selection for statistics
$stats_children = [];
if ($selected_child_id && $selected_child_id !== 'all' && $selected_child_id != 0) {
    // Get stats for selected child only
    if (isset($children_records[$selected_child_id])) {
        $stats_children[$selected_child_id] = $children_records[$selected_child_id];
    }
} else {
    // Get stats for all children
    $stats_children = $children_records;
}

// Get comprehensive activity data for selected children
$all_activity_data = [];
$recent_submissions = [];
$graded_items = [];
$lesson_attempts = [];
$forum_posts = [];

if (!empty($stats_children)) {
    $child_user_ids = array_keys($stats_children);
    list($insql_all, $params_all) = $DB->get_in_or_equal($child_user_ids, SQL_PARAMS_NAMED);
    
    // Get ALL course modules for these children's courses
    foreach ($stats_children as $child) {
        $child_courses = enrol_get_users_courses($child->id, true);
        foreach ($child_courses as $course) {
            // Get all activities/modules in this course
            try {
                $sql_all_modules = "SELECT cm.id, cm.course, cm.instance, m.name as modname,
                                          cm.added, cm.completion, cm.visible
                                   FROM {course_modules} cm
                                   JOIN {modules} m ON m.id = cm.module
                                   WHERE cm.course = :courseid
                                   AND cm.deletioninprogress = 0
                                   ORDER BY cm.added DESC";
                $modules = $DB->get_records_sql($sql_all_modules, ['courseid' => $course->id]);
                
                foreach ($modules as $mod) {
                    // Get module-specific details
                    $module_detail = null;
                    try {
                        if ($mod->modname == 'assign') {
                            $module_detail = $DB->get_record('assign', ['id' => $mod->instance]);
                        } elseif ($mod->modname == 'quiz') {
                            $module_detail = $DB->get_record('quiz', ['id' => $mod->instance]);
                        } elseif ($mod->modname == 'lesson') {
                            $module_detail = $DB->get_record('lesson', ['id' => $mod->instance]);
                        } elseif ($mod->modname == 'forum') {
                            $module_detail = $DB->get_record('forum', ['id' => $mod->instance]);
                        } elseif ($mod->modname == 'resource') {
                            $module_detail = $DB->get_record('resource', ['id' => $mod->instance]);
                        } elseif ($mod->modname == 'page') {
                            $module_detail = $DB->get_record('page', ['id' => $mod->instance]);
                        } elseif ($mod->modname == 'book') {
                            $module_detail = $DB->get_record('book', ['id' => $mod->instance]);
                        }
                    } catch (Exception $e) {}
                    
                    if ($module_detail) {
                        $all_activity_data[] = [
                            'child_id' => $child->id,
                            'child_name' => fullname($child),
                            'course_id' => $course->id,
                            'course_name' => $course->fullname,
                            'module_id' => $mod->id,
                            'module_type' => $mod->modname,
                            'module_name' => $module_detail->name ?? 'Unknown',
                            'added_date' => $mod->added,
                            'visible' => $mod->visible,
                            'completion_enabled' => $mod->completion
                        ];
                    }
                }
            } catch (Exception $e) {}
        }
    }
    
    // Get recent assignment submissions with grades
    try {
        $sql_submissions = "SELECT asub.id, asub.userid, asub.timecreated, asub.timemodified, asub.status,
                                   a.name AS assignname, a.grade AS maxgrade, c.fullname AS coursename,
                                   u.firstname, u.lastname,
                                   ag.grade AS rawgrade
                            FROM {assign_submission} asub
                            JOIN {assign} a ON a.id = asub.assignment
                            JOIN {course} c ON c.id = a.course
                            JOIN {user} u ON u.id = asub.userid
                            LEFT JOIN {assign_grades} ag ON ag.assignment = a.id AND ag.userid = asub.userid
                            WHERE asub.userid $insql_all
                              AND asub.status = 'submitted'
                            ORDER BY asub.timemodified DESC
                            LIMIT 20";
        $submissions_raw = $DB->get_records_sql($sql_submissions, $params_all);
        
        // Calculate percentage grades
        $recent_submissions = [];
        foreach ($submissions_raw as $sub) {
            // Calculate grade percentage if graded
            if ($sub->rawgrade !== null && $sub->maxgrade > 0) {
                $sub->grade = ($sub->rawgrade / $sub->maxgrade) * 100;
            } else {
                $sub->grade = null;
            }
            $recent_submissions[] = $sub;
        }
    } catch (Exception $e) {
        $recent_submissions = [];
    }

    // If there are no recent submissions, fall back to upcoming assignments
    if (empty($recent_submissions)) {
        try {
            $now = time();
            // Look for upcoming assignments for the parent's children
            $sql_upcoming = "SELECT a.id AS assignid, ue.userid, a.duedate, a.name AS assignname,
                                    c.fullname AS coursename,
                                    u.firstname, u.lastname
                             FROM {assign} a
                             JOIN {course} c ON c.id = a.course
                             JOIN {enrol} e ON e.courseid = c.id
                             JOIN {user_enrolments} ue ON ue.enrolid = e.id
                             JOIN {user} u ON u.id = ue.userid
                             WHERE ue.userid $insql_all
                               AND a.duedate IS NOT NULL
                               AND a.duedate >= :now
                             ORDER BY a.duedate ASC
                             LIMIT 20";
            $upcoming_params = $params_all;
            $upcoming_params['now'] = $now;
            $upcoming_raw = $DB->get_records_sql($sql_upcoming, $upcoming_params);

            foreach ($upcoming_raw as $up) {
                // Align fields with recent submissions structure
                $up->timecreated  = $up->duedate;
                $up->timemodified = $up->duedate;
                $up->status       = 'upcoming';
                $up->maxgrade     = null;
                $up->rawgrade     = null;
                $up->grade        = null;
                $recent_submissions[] = $up;
            }
        } catch (Exception $e) {
            // If upcoming query fails, leave recent_submissions as empty array
        }
    }
    
    // Get graded items
    try {
        $sql_grades = "SELECT gg.id, gg.userid, gg.finalgrade, gg.timemodified,
                             gi.itemname, gi.itemtype, gi.itemmodule, gi.grademax,
                             c.fullname as coursename, u.firstname, u.lastname
                      FROM {grade_grades} gg
                      JOIN {grade_items} gi ON gi.id = gg.itemid
                      JOIN {course} c ON c.id = gi.courseid
                      JOIN {user} u ON u.id = gg.userid
                      WHERE gg.userid $insql_all
                      AND gg.finalgrade IS NOT NULL
                      ORDER BY gg.timemodified DESC
                      LIMIT 20";
        $graded_items = $DB->get_records_sql($sql_grades, $params_all);
    } catch (Exception $e) {}
    
    // Get lesson attempts
    try {
        $sql_lessons = "SELECT la.id, la.userid, la.lessonid, la.timeseen, la.grade,
                              l.name as lessonname, c.fullname as coursename,
                              u.firstname, u.lastname
                       FROM {lesson_attempts} la
                       JOIN {lesson} l ON l.id = la.lessonid
                       JOIN {course} c ON c.id = l.course
                       JOIN {user} u ON u.id = la.userid
                       WHERE la.userid $insql_all
                       ORDER BY la.timeseen DESC
                       LIMIT 20";
        $lesson_attempts = $DB->get_records_sql($sql_lessons, $params_all);
    } catch (Exception $e) {}
    
    // Get forum posts
    try {
        $sql_forum = "SELECT fp.id, fp.userid, fp.created, fp.modified, fp.subject, fp.message,
                            fd.name as discussionname, f.name as forumname, c.fullname as coursename,
                            u.firstname, u.lastname
                     FROM {forum_posts} fp
                     JOIN {forum_discussions} fd ON fd.id = fp.discussion
                     JOIN {forum} f ON f.id = fd.forum
                     JOIN {course} c ON c.id = f.course
                     JOIN {user} u ON u.id = fp.userid
                     WHERE fp.userid $insql_all
                     ORDER BY fp.created DESC
                     LIMIT 20";
        $forum_posts = $DB->get_records_sql($sql_forum, $params_all);
    } catch (Exception $e) {}
}

// Debug: Show filtering
if ($debug_mode) {
    echo "<div style='background: #dbeafe; border: 2px solid #3b82f6; padding: 15px; margin: 20px; border-radius: 8px;'>";
    echo "<h3 style='margin: 0 0 10px 0; color: #3b82f6;'>DEBUG: Data Filtering</h3>";
    echo "<p><strong>Total Children Records:</strong> " . count($children_records) . "</p>";
    echo "<p><strong>Children Record IDs:</strong> " . implode(', ', array_keys($children_records)) . "</p>";
    echo "<p><strong>Filtered Stats Children:</strong> " . count($stats_children) . "</p>";
    echo "<p><strong>Filtered IDs:</strong> " . implode(', ', array_keys($stats_children)) . "</p>";
    echo "<hr style='margin: 10px 0;'>";
    echo "<p><strong>Total Quiz Attempts:</strong> " . $total_results . "</p>";
    echo "<p><strong>Total Enrolled Courses:</strong> " . $total_courses . "</p>";
    echo "<p><strong>Upcoming Activities Count:</strong> " . $total_activities . "</p>";
    echo "<p><strong>Average Attendance:</strong> " . number_format($avg_attendance, 1) . "%</p>";
    echo "<hr style='margin: 10px 0;'>";
    echo "<p><strong>ALL Activity Data:</strong> " . count($all_activity_data) . " items</p>";
    echo "<p><strong>Recent Submissions:</strong> " . count($recent_submissions) . " items</p>";
    echo "<p><strong>Graded Items:</strong> " . count($graded_items) . " items</p>";
    echo "<p><strong>Lesson Attempts:</strong> " . count($lesson_attempts) . " items</p>";
    echo "<p><strong>Forum Posts:</strong> " . count($forum_posts) . " items</p>";
    echo "</div>";
}

// Get quiz attempts and course data for selected children
if (!empty($stats_children)) {
    $child_user_ids = array_keys($stats_children);
    list($insql, $params) = $DB->get_in_or_equal($child_user_ids, SQL_PARAMS_NAMED);
    
    // Get total quiz attempts (exam results)
    $total_results = $DB->count_records_select('quiz_attempts', "userid $insql AND state = 'finished'", $params);
    
    // Count total enrolled courses for selected children
    foreach ($stats_children as $child) {
        $courses = enrol_get_users_courses($child->id, true);
        $total_courses += count($courses);
    }
    
    // Count upcoming assignments for these children
    list($insql_act, $params_act) = $DB->get_in_or_equal($child_user_ids, SQL_PARAMS_NAMED);
    $params_act['now'] = time();
    
    $sql_activities = "SELECT COUNT(DISTINCT a.id) as total
                      FROM {assign} a
                      JOIN {course} c ON c.id = a.course
                      JOIN {user_enrolments} ue ON ue.userid $insql_act
                      JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = c.id
                      WHERE a.duedate > :now";
    
    $activity_count = $DB->get_record_sql($sql_activities, $params_act);
    $total_activities = $activity_count ? $activity_count->total : 0;
}

// Calculate average attendance percentage for selected children
$avg_attendance = 0;
if (!empty($stats_children)) {
    $child_user_ids = array_keys($stats_children);
    
    // Try to get real attendance data
    if ($DB->get_manager()->table_exists('attendance_log')) {
        list($insql_att, $params_att) = $DB->get_in_or_equal($child_user_ids, SQL_PARAMS_NAMED);
        
        $sql_attendance = "SELECT 
                          COUNT(CASE WHEN atts.acronym = 'P' THEN 1 END) as present_count,
                          COUNT(*) as total_count
                          FROM {attendance_log} al
                          JOIN {attendance_statuses} atts ON atts.id = al.statusid
                          WHERE al.studentid $insql_att";
        
        try {
            $att_stats = $DB->get_record_sql($sql_attendance, $params_att);
            if ($att_stats && $att_stats->total_count > 0) {
                $avg_attendance = ($att_stats->present_count / $att_stats->total_count) * 100;
            }
        } catch (Exception $e) {
            // Fallback: Calculate from course access logs
            list($insql_logs, $params_logs) = $DB->get_in_or_equal($child_user_ids, SQL_PARAMS_NAMED);
            $params_logs['lastmonth'] = time() - (30 * 24 * 60 * 60);
            
            $sql_logs = "SELECT COUNT(DISTINCT DATE(FROM_UNIXTIME(timecreated)), userid) as access_days
                        FROM {logstore_standard_log}
                        WHERE userid $insql_logs
                        AND action = 'viewed'
                        AND target = 'course'
                        AND timecreated > :lastmonth";
            
            try {
                $log_stats = $DB->get_record_sql($sql_logs, $params_logs);
                // Estimate: access_days / 30 days * 100
                $avg_attendance = $log_stats && $log_stats->access_days > 0 ? ($log_stats->access_days / 30) * 100 : 0;
            } catch (Exception $e) {
                $avg_attendance = 0;
            }
        }
    }
    
    // Ensure reasonable range
    if ($avg_attendance > 100) $avg_attendance = 100;
    if ($avg_attendance == 0 && !empty($stats_children)) {
        $avg_attendance = 0; // Show 0 if no attendance data
    }
}

// ========================================
// NEW: Enhanced Dashboard Sections Data Fetching
// ========================================

// 1. Recent Resources (from logstore_standard_log - recently accessed/viewed resources)
$recent_resources = [];
if ($selected_child_id && $selected_child_id !== 'all' && $selected_child_id != 0) {
    try {
        $fs = get_file_storage();
        $course_ids = [];
        $child_courses = enrol_get_users_courses($selected_child_id, true);
        foreach ($child_courses as $course) {
            $course_ids[] = $course->id;
        }
        
        if (!empty($course_ids)) {
            list($course_in_sql, $course_params) = $DB->get_in_or_equal($course_ids, SQL_PARAMS_NAMED, 'course');
            
            // Get recently accessed resources from logstore_standard_log
            // Look for viewed resource, folder, page, file activities
            $log_params = array_merge($course_params, [
                'userid' => $selected_child_id,
                'recent_time' => time() - (30 * 24 * 60 * 60), // Last 30 days
                'resource_action' => 'viewed',
                'resource_target' => 'resource',
                'folder_target' => 'folder',
                'page_target' => 'page',
                'file_target' => 'file',
                'modlevel' => CONTEXT_MODULE
            ]);
            
            $sql_recent_resources = "SELECT DISTINCT lsl.contextinstanceid as cmid, lsl.timecreated as access_time,
                                            cm.id as cm_id, m.name as modname, 
                                            c.id as courseid, c.fullname as coursename,
                                            CASE 
                                                WHEN m.name = 'resource' THEN r.name
                                                WHEN m.name = 'folder' THEN f.name
                                                WHEN m.name = 'page' THEN p.name
                                                ELSE ''
                                            END as resourcename
                                     FROM {logstore_standard_log} lsl
                                     JOIN {course_modules} cm ON cm.id = lsl.contextinstanceid
                                     JOIN {modules} m ON m.id = cm.module
                                     JOIN {course} c ON c.id = cm.course
                                     LEFT JOIN {resource} r ON r.id = cm.instance AND m.name = 'resource'
                                     LEFT JOIN {folder} f ON f.id = cm.instance AND m.name = 'folder'
                                     LEFT JOIN {page} p ON p.id = cm.instance AND m.name = 'page'
                                     WHERE lsl.userid = :userid
                                     AND lsl.courseid $course_in_sql
                                     AND lsl.timecreated > :recent_time
                                     AND lsl.action = :resource_action
                                     AND lsl.target IN (:resource_target, :folder_target, :page_target, :file_target)
                                     AND lsl.contextlevel = :modlevel
                                     AND cm.visible = 1
                                     AND cm.deletioninprogress = 0
                                     AND m.name IN ('resource', 'folder', 'page', 'file')
                                     ORDER BY lsl.timecreated DESC
                                     LIMIT 50";
            
            $accessed_resources = $DB->get_records_sql($sql_recent_resources, $log_params);
            
            // Process each accessed resource to get file details
            $processed_resource_ids = [];
            foreach ($accessed_resources as $log_entry) {
                // Skip duplicates (same resource accessed multiple times)
                $cmid = $log_entry->cmid ?? $log_entry->cm_id;
                if (!$cmid || in_array($cmid, $processed_resource_ids)) {
                    continue;
                }
                $processed_resource_ids[] = $cmid;
                
                try {
                    
                    $modinfo = get_fast_modinfo($log_entry->courseid);
                    $cm = $modinfo->get_cm($cmid);
                    if (!$cm || !$cm->uservisible) continue;
                    
                    $file_url = '';
                    $filename = '';
                    $filesize = 0;
                    $mimetype = 'application/octet-stream';
                    $file_timecreated = $log_entry->access_time;
                    
                    // Get file details based on module type
                    if ($cm->modname === 'resource') {
                        // Try to get the actual file first
                        try {
                            $context = context_module::instance($cmid);
                            $files = $fs->get_area_files($context->id, 'mod_resource', 'content', 0, 'id DESC', false);
                            if (!empty($files)) {
                                $file = reset($files);
                                $filename = $file->get_filename();
                                $filesize = $file->get_filesize();
                                $mimetype = $file->get_mimetype();
                                $file_timecreated = $file->get_timecreated();
                                
                                // Use pluginfile URL for actual file downloads
                                $file_url = moodle_url::make_pluginfile_url(
                                    $file->get_contextid(),
                                    $file->get_component(),
                                    $file->get_filearea(),
                                    $file->get_itemid(),
                                    $file->get_filepath(),
                                    $file->get_filename(),
                                    true
                                )->out(false);
                            } else {
                                $filename = $log_entry->resourcename ?: $cm->name;
                                // Use our custom parent theme page for resource pages
                                if (!empty($cmid) && !empty($log_entry->courseid) && !empty($selected_child_id) && $selected_child_id !== 'all' && $selected_child_id != 0) {
                                    $file_url = (new moodle_url('/theme/remui_kids/parent/parent_activity_preview.php', [
                                        'cmid' => $cmid,
                                        'child' => $selected_child_id,
                                        'courseid' => $log_entry->courseid
                                    ]))->out();
                                }
                            }
                        } catch (Exception $e) {
                            $filename = $log_entry->resourcename ?: $cm->name;
                            // Use our custom parent theme page for resource pages
                            if (!empty($cmid) && !empty($log_entry->courseid) && !empty($selected_child_id) && $selected_child_id !== 'all' && $selected_child_id != 0) {
                                $file_url = (new moodle_url('/theme/remui_kids/parent/parent_activity_preview.php', [
                                    'cmid' => $cmid,
                                    'child' => $selected_child_id,
                                    'courseid' => $log_entry->courseid
                                ]))->out();
                            }
                        }
                    } elseif ($cm->modname === 'folder') {
                        if (!empty($cmid) && !empty($log_entry->courseid) && !empty($selected_child_id) && $selected_child_id !== 'all' && $selected_child_id != 0) {
                            $file_url = (new moodle_url('/theme/remui_kids/parent/parent_activity_preview.php', [
                                'cmid' => $cmid,
                                'child' => $selected_child_id,
                                'courseid' => $log_entry->courseid
                            ]))->out();
                        }
                        $filename = $log_entry->resourcename ?: $cm->name;
                        // For folders, use the folder name as the filename
                    } elseif ($cm->modname === 'page') {
                        if (!empty($cmid) && !empty($log_entry->courseid) && !empty($selected_child_id) && $selected_child_id !== 'all' && $selected_child_id != 0) {
                            $file_url = (new moodle_url('/theme/remui_kids/parent/parent_activity_preview.php', [
                                'cmid' => $cmid,
                                'child' => $selected_child_id,
                                'courseid' => $log_entry->courseid
                            ]))->out();
                        }
                        $filename = $log_entry->resourcename ?: $cm->name;
                    } elseif ($cm->modname === 'file') {
                        try {
                            $context = context_module::instance($cmid);
                            $files = $fs->get_area_files($context->id, 'mod_file', 'content', 0, 'id DESC', false);
                            if (!empty($files)) {
                                $file = reset($files);
                                $filename = $file->get_filename();
                                $filesize = $file->get_filesize();
                                $mimetype = $file->get_mimetype();
                                $file_timecreated = $file->get_timecreated();
                                
                                $file_url = moodle_url::make_pluginfile_url(
                                    $file->get_contextid(),
                                    $file->get_component(),
                                    $file->get_filearea(),
                                    $file->get_itemid(),
                                    $file->get_filepath(),
                                    $file->get_filename(),
                                    true
                                )->out(false);
                            } else {
                                $filename = $log_entry->resourcename ?: $cm->name;
                                if (!empty($cmid) && !empty($log_entry->courseid) && !empty($selected_child_id) && $selected_child_id !== 'all' && $selected_child_id != 0) {
                                    $file_url = (new moodle_url('/theme/remui_kids/parent/parent_activity_preview.php', [
                                        'cmid' => $cmid,
                                        'child' => $selected_child_id,
                                        'courseid' => $log_entry->courseid
                                    ]))->out();
                                }
                            }
                        } catch (Exception $e) {
                            $filename = $log_entry->resourcename ?: $cm->name;
                            if (!empty($cmid) && !empty($log_entry->courseid) && !empty($selected_child_id) && $selected_child_id !== 'all' && $selected_child_id != 0) {
                                $file_url = (new moodle_url('/theme/remui_kids/parent/parent_activity_preview.php', [
                                    'cmid' => $cmid,
                                    'child' => $selected_child_id,
                                    'courseid' => $log_entry->courseid
                                ]))->out();
                            }
                        }
                    } else {
                        continue;
                    }
                    
                    $recent_resources[] = [
                        'filename' => $filename,
                        'filesize' => $filesize,
                        'timecreated' => $log_entry->access_time, // Use access time, not file creation time
                        'coursename' => $log_entry->coursename,
                        'resourcename' => $log_entry->resourcename ?: $filename,
                        'mimetype' => $mimetype,
                        'cmid' => $cmid,
                        'courseid' => $log_entry->courseid,
                        'file_url' => $file_url,
                        'child_id' => $selected_child_id
                    ];
                } catch (Exception $e) {
                    // Skip resources that cause errors
                    continue;
                }
            }
        }
        
        // Sort by access time (most recent first) and limit to 5
        usort($recent_resources, function($a, $b) {
            return $b['timecreated'] - $a['timecreated'];
        });
        $recent_resources = array_slice($recent_resources, 0, 5);
    } catch (Exception $e) {
        debugging('Error fetching recent resources from logstore: ' . $e->getMessage());
        // Fallback to empty array
        $recent_resources = [];
    }
}

// 2. Child Calendar Events (for Schedule section)
$child_calendar_events = [];
if ($selected_child_id && $selected_child_id !== 'all' && $selected_child_id != 0) {
    try {
        // Calculate week range based on offset
        $base_week_start = strtotime('today');
        $week_start = strtotime(($week_offset * 7) . ' days', $base_week_start);
        $week_end = strtotime('+6 days', $week_start);
        // Extend range a bit to catch events at boundaries
        $query_start = $week_start - (24 * 60 * 60);
        $query_end = $week_end + (24 * 60 * 60);
        
        // Get enrolled courses for the selected child
        $child_courses = enrol_get_users_courses($selected_child_id, true);
        $course_ids = array_keys($child_courses);
        
        if (!empty($course_ids)) {
            // Get calendar events from courses
            list($course_in_sql, $course_params) = $DB->get_in_or_equal($course_ids, SQL_PARAMS_NAMED);
            
            $sql_events = "SELECT e.*, c.fullname as coursename
                          FROM {event} e
                          LEFT JOIN {course} c ON c.id = e.courseid
                          WHERE e.courseid $course_in_sql
                          AND e.timestart BETWEEN :start AND :end
                          AND e.visible = 1
                          ORDER BY e.timestart ASC";
            
            $calendar_events = $DB->get_records_sql($sql_events, array_merge($course_params, [
                'start' => $query_start,
                'end' => $query_end
            ]));
            
            // Get assignment due dates
            list($assign_in_sql, $assign_params) = $DB->get_in_or_equal($course_ids, SQL_PARAMS_NAMED);
            $assign_params['start'] = $query_start;
            $assign_params['end'] = $query_end;
            
            $sql_assignments = "SELECT a.id, a.name, a.duedate, a.course, c.fullname as coursename
                                FROM {assign} a
                                JOIN {course} c ON c.id = a.course
                                JOIN {course_modules} cm ON cm.instance = a.id
                                JOIN {modules} m ON m.id = cm.module AND m.name = 'assign'
                                WHERE a.course $assign_in_sql
                                AND a.duedate > 0
                                AND a.duedate BETWEEN :start AND :end
                                AND cm.visible = 1
                                AND cm.deletioninprogress = 0
                                ORDER BY a.duedate ASC";
            
            $assignments = $DB->get_records_sql($sql_assignments, $assign_params);
            
            // Get quiz close dates
            list($quiz_in_sql, $quiz_params) = $DB->get_in_or_equal($course_ids, SQL_PARAMS_NAMED);
            $quiz_params['start'] = $query_start;
            $quiz_params['end'] = $query_end;
            
            $sql_quizzes = "SELECT q.id, q.name, q.timeclose, q.course, c.fullname as coursename
                            FROM {quiz} q
                            JOIN {course} c ON c.id = q.course
                            JOIN {course_modules} cm ON cm.instance = q.id
                            JOIN {modules} m ON m.id = cm.module AND m.name = 'quiz'
                            WHERE q.course $quiz_in_sql
                            AND q.timeclose > 0
                            AND q.timeclose BETWEEN :start AND :end
                            AND cm.visible = 1
                            AND cm.deletioninprogress = 0
                            ORDER BY q.timeclose ASC";
            
            $quizzes = $DB->get_records_sql($sql_quizzes, $quiz_params);
            
            // Get lesson deadlines
            list($lesson_in_sql, $lesson_params) = $DB->get_in_or_equal($course_ids, SQL_PARAMS_NAMED);
            $lesson_params['start'] = $query_start;
            $lesson_params['end'] = $query_end;
            
            $sql_lessons = "SELECT l.id, l.name, l.deadline, l.course, c.fullname as coursename
                            FROM {lesson} l
                            JOIN {course} c ON c.id = l.course
                            JOIN {course_modules} cm ON cm.instance = l.id
                            JOIN {modules} m ON m.id = cm.module AND m.name = 'lesson'
                            WHERE l.course $lesson_in_sql
                            AND l.deadline > 0
                            AND l.deadline BETWEEN :start AND :end
                            AND cm.visible = 1
                            AND cm.deletioninprogress = 0
                            ORDER BY l.deadline ASC";
            
            $lessons = $DB->get_records_sql($sql_lessons, $lesson_params);
            
            // Combine all events into a unified array
            foreach ($calendar_events as $event) {
                $child_calendar_events[] = (object)[
                    'id' => 'event_' . $event->id,
                    'name' => $event->name,
                    'timestart' => (int)$event->timestart,
                    'eventtype' => 'course',
                    'source' => 'event',
                    'eventkind' => 'event',
                    'coursename' => $event->coursename ?? '',
                    'icon' => 'fa-calendar'
                ];
            }
            
            foreach ($assignments as $assign) {
                $child_calendar_events[] = (object)[
                    'id' => 'assign_' . $assign->id,
                    'name' => $assign->name,
                    'timestart' => (int)$assign->duedate,
                    'eventtype' => 'child',
                    'source' => 'assignment',
                    'eventkind' => 'assignment',
                    'coursename' => $assign->coursename ?? '',
                    'icon' => 'fa-tasks'
                ];
            }
            
            foreach ($quizzes as $quiz) {
                $child_calendar_events[] = (object)[
                    'id' => 'quiz_' . $quiz->id,
                    'name' => $quiz->name,
                    'timestart' => (int)$quiz->timeclose,
                    'eventtype' => 'child',
                    'source' => 'quiz',
                    'eventkind' => 'quiz',
                    'coursename' => $quiz->coursename ?? '',
                    'icon' => 'fa-clipboard-check'
                ];
            }
            
            foreach ($lessons as $lesson) {
                $child_calendar_events[] = (object)[
                    'id' => 'lesson_' . $lesson->id,
                    'name' => $lesson->name,
                    'timestart' => (int)$lesson->deadline,
                    'eventtype' => 'child',
                    'source' => 'lesson',
                    'eventkind' => 'lesson',
                    'coursename' => $lesson->coursename ?? '',
                    'icon' => 'fa-book'
                ];
            }
            
            // Sort all events by timestart
            usort($child_calendar_events, function($a, $b) {
                return ($a->timestart ?? 0) <=> ($b->timestart ?? 0);
            });
        }
    } catch (Exception $e) {
        debugging('Error fetching calendar events: ' . $e->getMessage());
    }
}

// 2. Recent Quiz Completions
$recent_quiz_completions = [];
if (!empty($stats_children)) {
    try {
        $child_ids = array_keys($stats_children);
        list($insql_quiz, $params_quiz) = $DB->get_in_or_equal($child_ids, SQL_PARAMS_NAMED);
        
        $sql_quizzes = "SELECT qa.id, qa.userid, qa.quiz, qa.sumgrades, qa.timemodified, qa.timefinish,
                               q.name as quizname, q.grade as maxgrade, c.fullname as coursename,
                               u.firstname, u.lastname, u.picture, u.imagealt, u.email
                        FROM {quiz_attempts} qa
                        JOIN {quiz} q ON q.id = qa.quiz
                        JOIN {course} c ON c.id = q.course
                        JOIN {user} u ON u.id = qa.userid
                        WHERE qa.userid $insql_quiz
                        AND qa.state = 'finished'
                        ORDER BY qa.timefinish DESC
                        LIMIT 5";
        
        $quiz_attempts = $DB->get_records_sql($sql_quizzes, $params_quiz);
        
        foreach ($quiz_attempts as $attempt) {
            $percentage = ($attempt->maxgrade > 0) ? ($attempt->sumgrades / $attempt->maxgrade) * 100 : 0;
            
            // Get full user record for profile picture
            $child_user = $DB->get_record('user', ['id' => $attempt->userid], '*');
            $profile_picture_url = '';
            $has_profile_picture = false;
            
            if ($child_user && $child_user->picture > 0) {
                try {
                    $user_picture = new user_picture($child_user);
                    $user_picture->size = 1;
                    $profile_picture_url = $user_picture->get_url($PAGE)->out(false);
                    $has_profile_picture = true;
                } catch (Exception $e) {}
            }
            
            $recent_quiz_completions[] = [
                'child_name' => fullname($attempt),
                'quiz_name' => $attempt->quizname,
                'course_name' => $attempt->coursename,
                'percentage' => round($percentage, 1),
                'timefinish' => $attempt->timefinish,
                'profile_picture_url' => $profile_picture_url,
                'has_profile_picture' => $has_profile_picture,
                'child_id' => $attempt->userid
            ];
        }
    } catch (Exception $e) {
        debugging('Error fetching quiz completions: ' . $e->getMessage());
    }
}

// 3. Recent Community Posts - Using same logic as teacher dashboard
$recent_community_posts = [];

// Try using the library function first (same as teacher dashboard)
require_once($CFG->dirroot . '/theme/remui_kids/lib.php');
if (function_exists('theme_remui_kids_get_recent_community_posts')) {
    try {
        $community_posts_data = theme_remui_kids_get_recent_community_posts(30);
        if (!empty($community_posts_data) && isset($community_posts_data['posts'])) {
            // Convert to format used in parent dashboard
            foreach ($community_posts_data['posts'] as $post) {
                $recent_community_posts[] = [
                    'id' => $post['id'],
                    'discussion_id' => $post['id'],
                    'subject' => $post['subject'] ?? 'Community Post',
                    'message' => strip_tags($post['message'] ?? ''),
                    'message_short' => $post['message_summary'] ?? '',
                    'forum_name' => $post['space_name'] ?? $post['community_name'] ?? 'Community Hub',
                    'forum_type' => isset($post['space_name']) ? 'space' : 'community',
                    'course_name' => $post['community_name'] ?? 'General',
                    'course_id' => $post['communityid'] ?? 0,
                    'created' => $post['timecreated'] ?? time(),
                    'modified' => $post['timecreated'] ?? time(),
                    'author_name' => $post['author_name'] ?? 'Unknown',
                    'author_id' => $post['authorid'] ?? 0,
                    'author_location' => '',
                    'profile_picture_url' => $post['author_avatar'] ?? '',
                    'has_profile_picture' => !empty($post['author_avatar']),
                    'discussion_url' => $post['link'] ?? '',
                    'reply_count' => 0,
                    'like_count' => 0,
                    'view_count' => 0,
                    'space_color' => null
                ];
            }
        }
    } catch (Exception $e) {
        debugging('Error using library function for community posts: ' . $e->getMessage());
    }
}

// Fallback: Manual query if library function didn't work
if (empty($recent_community_posts)) {
try {
    // ✅ Method 1: Fetch from COMMUNITY HUB custom tables (if exists)
    if ($DB->get_manager()->table_exists('communityhub_posts')) {
        // Enhanced query with more data
        $sql_posts = "SELECT p.id, p.title as subject, p.content as message, p.timecreated as created, 
                             p.timemodified as modified, p.userid,
                             p.communityid, p.spaceid,
                             u.firstname, u.lastname, u.picture, u.imagealt, u.email, u.city, u.country,
                             c.name as communityname, c.description as communitydesc,
                             s.name as spacename, s.color as spacecolor,
                             (SELECT COUNT(1) FROM {communityhub_replies} r 
                              WHERE r.postid = p.id AND r.deleted = 0) AS replycount,
                             (SELECT COUNT(1) FROM {communityhub_likes} l 
                              WHERE l.postid = p.id AND (l.replyid IS NULL OR l.replyid = 0)) AS likecount,
                             (SELECT COUNT(1) FROM {communityhub_views} v 
                              WHERE v.postid = p.id) AS viewcount
                      FROM {communityhub_posts} p
                      JOIN {user} u ON u.id = p.userid
                      LEFT JOIN {communityhub_communities} c ON c.id = p.communityid
                      LEFT JOIN {communityhub_spaces} s ON s.id = p.spaceid
                      WHERE p.deleted = 0
                      AND u.deleted = 0
                      ORDER BY p.timecreated DESC
                      LIMIT 30";
        
        $hub_posts_data = $DB->get_records_sql($sql_posts);
        
        foreach ($hub_posts_data as $post) {
                // Get profile picture
                $profile_picture_url = '';
                $has_profile_picture = false;
                
                if (isset($post->picture) && $post->picture > 0) {
                    try {
                        // Get complete user record with all required fields for user_picture
                        $user_obj = $DB->get_record('user', ['id' => $post->userid], 
                            implode(',', \core_user\fields::get_picture_fields()), MUST_EXIST);
                        if ($user_obj) {
                        $user_picture = new user_picture($user_obj);
                        $user_picture->size = 1;
                        $profile_picture_url = $user_picture->get_url($PAGE)->out(false);
                        $has_profile_picture = true;
                        }
                    } catch (Exception $e) {}
                }
                
                // Get post URL (links to community.php)
                $post_url = new moodle_url('/theme/remui_kids/community.php', [
                    'id' => $post->communityid,
                    'postid' => $post->id
                ]);
                
                // Clean and truncate message
                $message_clean = strip_tags($post->message);
                $message_short = strlen($message_clean) > 150 ? substr($message_clean, 0, 150) . '...' : $message_clean;
                
                // Use space name or community name as "forum_name"
                $forum_name = !empty($post->spacename) ? $post->spacename : $post->communityname;
                
                $recent_community_posts[] = [
                    'id' => $post->id,
                    'discussion_id' => $post->id,
                    'subject' => !empty($post->subject) ? $post->subject : 'Community Post',
                    'message' => $message_clean,
                    'message_short' => $message_short,
                    'forum_name' => $forum_name ?? 'Community Hub',
                    'forum_type' => !empty($post->spacename) ? 'space' : 'community',
                    'course_name' => $post->communityname ?? 'General',
                    'course_id' => $post->communityid,
                    'created' => $post->created,
                    'modified' => $post->modified,
                    'author_name' => fullname($post),
                    'author_id' => $post->userid,
                    'author_location' => trim(($post->city ?? '') . ', ' . ($post->country ?? ''), ', '),
                    'profile_picture_url' => $profile_picture_url,
                    'has_profile_picture' => $has_profile_picture,
                    'discussion_url' => $post_url->out(false),
                    'reply_count' => $post->replycount ?? 0,
                    'like_count' => $post->likecount ?? 0,
                    'view_count' => $post->viewcount ?? 0,
                    'space_color' => $post->spacecolor ?? null
                ];
            }
        } else {
            // Fallback to standard Moodle forums if communityhub tables don't exist
            if ($debug_mode) {
                echo "<div style='background: #fef3c7; border: 2px solid #f59e0b; padding: 15px; margin: 20px; border-radius: 8px;'>";
                echo "<p style='margin: 0;'><strong>⚠ Community Hub tables not found.</strong> Falling back to standard forums...</p>";
                echo "</div>";
            }
            
            // ✅ Method 2: Fetch from Standard Moodle Forums (Enhanced)
            // Get ALL courses where ANY child is enrolled
            $all_child_courses = [];
            foreach ($child_ids as $child_id) {
                $child_courses = enrol_get_users_courses($child_id, true, ['id', 'fullname']);
                foreach ($child_courses as $course) {
                    $all_child_courses[$course->id] = $course->fullname;
                }
            }
            
            if (!empty($all_child_courses)) {
                list($course_insql, $course_params) = $DB->get_in_or_equal(array_keys($all_child_courses), SQL_PARAMS_NAMED);
                
                // Enhanced query with more forum data
                $sql_posts = "SELECT fp.id, fp.subject, fp.message, fp.created, fp.modified, fp.userid,
                                     fd.id as discussionid, fd.name as discussionname, fd.timestart, fd.timeend,
                                     f.id as forumid, f.name as forumname, f.type as forumtype,
                                     c.fullname as coursename, c.id as courseid,
                                     u.firstname, u.lastname, u.picture, u.imagealt, u.email, u.city, u.country,
                                     (SELECT COUNT(1) FROM {forum_posts} fp2 
                                      WHERE fp2.discussion = fd.id AND fp2.deleted = 0) - 1 AS replycount,
                                     (SELECT COUNT(1) FROM {forum_read} fr 
                                      WHERE fr.discussionid = fd.id) AS readcount
                              FROM {forum_posts} fp
                              JOIN {forum_discussions} fd ON fd.id = fp.discussion
                              JOIN {forum} f ON f.id = fd.forum
                              JOIN {course} c ON c.id = f.course
                              JOIN {user} u ON u.id = fp.userid
                              WHERE c.id $course_insql
                              AND fp.deleted = 0
                              AND u.deleted = 0
                              AND fp.parent = 0
                              ORDER BY fp.created DESC
                              LIMIT 30";
                
                $forum_posts_data = $DB->get_records_sql($sql_posts, $course_params);
                
                foreach ($forum_posts_data as $post) {
                    $profile_picture_url = '';
                    $has_profile_picture = false;
                    
                    if (isset($post->picture) && $post->picture > 0) {
                        try {
                            // Get complete user record with all required fields for user_picture
                            $user_obj = $DB->get_record('user', ['id' => $post->userid], 
                                implode(',', \core_user\fields::get_picture_fields()), MUST_EXIST);
                            if ($user_obj) {
                            $user_picture = new user_picture($user_obj);
                            $user_picture->size = 1;
                            $profile_picture_url = $user_picture->get_url($PAGE)->out(false);
                            $has_profile_picture = true;
                            }
                        } catch (Exception $e) {}
                    }
                    
                    $discussion_url = new moodle_url('/mod/forum/discuss.php', ['d' => $post->discussionid]);
                    $message_clean = strip_tags($post->message);
                    $message_short = strlen($message_clean) > 150 ? substr($message_clean, 0, 150) . '...' : $message_clean;
                    
                    $recent_community_posts[] = [
                        'id' => $post->id,
                        'discussion_id' => $post->discussionid,
                        'subject' => !empty($post->subject) ? $post->subject : 'No Subject',
                        'message' => $message_clean,
                        'message_short' => $message_short,
                        'forum_name' => $post->forumname,
                        'forum_type' => $post->forumtype ?? 'forum',
                        'course_name' => $post->coursename,
                        'course_id' => $post->courseid ?? 0,
                        'created' => $post->created,
                        'modified' => $post->modified,
                        'author_name' => fullname($post),
                        'author_id' => $post->userid,
                        'author_location' => trim(($post->city ?? '') . ', ' . ($post->country ?? ''), ', '),
                        'profile_picture_url' => $profile_picture_url,
                        'has_profile_picture' => $has_profile_picture,
                        'discussion_url' => $discussion_url->out(false),
                        'reply_count' => $post->replycount ?? 0,
                        'read_count' => $post->readcount ?? 0,
                        'discussion_timestart' => $post->timestart ?? null,
                        'discussion_timeend' => $post->timeend ?? null
                    ];
                }
            }
        }
    } catch (Exception $e) {
        debugging('Error fetching community posts fallback: ' . $e->getMessage());
    }
} // End of if empty(recent_community_posts)

// 4. Best Performing Students - Fetch real data from all courses
$best_performing_students = [];
$parent_children_ids = [];

// Get ALL parent's children IDs (not just selected one)
// This allows us to mark ALL parent's children as "Your Child" in Top Performers
if (!empty($children_records)) {
    $parent_children_ids = array_keys($children_records);
}

// ✅ FIXED: Get all courses in the system (since parents aren't enrolled in courses)
try {
    // Get all visible courses
    $all_courses = $DB->get_records_select('course', 'visible = 1 AND id != 1', null, '', 'id');
    $all_courseids = array_keys($all_courses);
    
    if (!empty($all_courseids)) {
        // Get all enrolled students from these courses
        list($course_insql, $course_params) = $DB->get_in_or_equal($all_courseids, SQL_PARAMS_NAMED, 'course');
        
        // Get student role
        $student_role = $DB->get_record('role', ['shortname' => 'student']);
        
        if ($student_role) {
            // Fetch all students with activity - include all fields required for user_picture
            $picture_fields = \core_user\fields::get_picture_fields();
            $picture_fields_sql = 'u.' . implode(', u.', $picture_fields);
            $sql = "SELECT DISTINCT $picture_fields_sql
                    FROM {user} u
                    INNER JOIN {user_enrolments} ue ON ue.userid = u.id
                    INNER JOIN {enrol} e ON e.id = ue.enrolid
                    INNER JOIN {role_assignments} ra ON ra.userid = u.id
                    INNER JOIN {context} ctx ON ra.contextid = ctx.id AND ctx.contextlevel = 50
                    WHERE e.courseid $course_insql
                    AND e.courseid = ctx.instanceid
                    AND ra.roleid = :roleid
                    AND u.deleted = 0
                    AND u.suspended = 0
                    AND ue.status = 0
                    ORDER BY u.firstname, u.lastname";
            
            $students = $DB->get_records_sql($sql, array_merge($course_params, ['roleid' => $student_role->id]));
            
            if ($debug_mode) {
                echo "<div style='background: #dbeafe; border: 2px solid #3b82f6; padding: 15px; margin: 20px; border-radius: 8px;'>";
                echo "<h3 style='color: #1e40af; margin: 0 0 10px 0;'>🔍 DEBUG: Best Performing Students - Data Fetching</h3>";
                echo "<p><strong>Total Courses:</strong> " . count($all_courseids) . "</p>";
                echo "<p><strong>Total Students Found:</strong> " . count($students) . "</p>";
                echo "</div>";
            }
            
            // Calculate performance for each student
            foreach ($students as $student) {
                $total_score = 0;
                $grade_avg = 0;
                $grade_count = 0;
                $comp_rate = 0;
                $assign_rate = 0;
                $quiz_avg = 0;
                
                // 1. GRADE AVERAGE (40% weight)
                foreach ($all_courseids as $cid) {
                    $grade_item = $DB->get_record('grade_items', [
                        'courseid' => $cid,
                        'itemtype' => 'course'
                    ]);
                    
                    if ($grade_item) {
                        $grade = $DB->get_record('grade_grades', [
                            'itemid' => $grade_item->id,
                            'userid' => $student->id
                        ]);
                        
                        if ($grade && $grade->finalgrade !== null && $grade_item->grademax > 0) {
                            $grade_percent = ($grade->finalgrade / $grade_item->grademax) * 100;
                            $grade_avg += $grade_percent;
                            $grade_count++;
                        }
                    }
                }
                
                if ($grade_count > 0) {
                    $grade_avg = $grade_avg / $grade_count;
                    $total_score += $grade_avg * 0.4;
                }
                
                // 2. COMPETENCY PROFICIENCY (30% weight)
                $student_params = array_merge(['userid' => $student->id], $course_params);
                
                $total_comps = $DB->count_records_sql(
                    "SELECT COUNT(DISTINCT cc.competencyid)
                     FROM {competency_coursecomp} cc
                     WHERE cc.courseid $course_insql",
                    $course_params
                );
                
                $proficient_comps = $DB->count_records_sql(
                    "SELECT COUNT(DISTINCT ucc.competencyid)
                     FROM {competency_usercompcourse} ucc
                     WHERE ucc.userid = :userid
                     AND ucc.courseid $course_insql
                     AND ucc.proficiency = 1",
                    $student_params
                );
                
                if ($total_comps > 0) {
                    $comp_rate = ($proficient_comps / $total_comps) * 100;
                    $total_score += $comp_rate * 0.3;
                }
                
                // 3. ASSIGNMENT COMPLETION (15% weight)
                $total_assigns = $DB->count_records_sql(
                    "SELECT COUNT(a.id)
                     FROM {assign} a
                     JOIN {course_modules} cm ON cm.instance = a.id
                     JOIN {modules} m ON m.id = cm.module AND m.name = 'assign'
                     WHERE a.course $course_insql
                     AND cm.deletioninprogress = 0",
                    $course_params
                );
                
                $completed_assigns = $DB->count_records_sql(
                    "SELECT COUNT(DISTINCT a.id)
                     FROM {assign} a
                     JOIN {course_modules} cm ON cm.instance = a.id
                     JOIN {modules} m ON m.id = cm.module AND m.name = 'assign'
                     JOIN {assign_submission} asub ON asub.assignment = a.id
                     WHERE a.course $course_insql
                     AND cm.deletioninprogress = 0
                     AND asub.userid = :userid
                     AND asub.status = 'submitted'",
                    $student_params
                );
                
                if ($total_assigns > 0) {
                    $assign_rate = ($completed_assigns / $total_assigns) * 100;
                    $total_score += $assign_rate * 0.15;
                }
                
                // 4. QUIZ PERFORMANCE (15% weight)
                $quiz_count = 0;
                $quiz_attempts = $DB->get_records_sql(
                    "SELECT qa.sumgrades, q.sumgrades as maxgrade
                     FROM {quiz_attempts} qa
                     JOIN {quiz} q ON qa.quiz = q.id
                     JOIN {course_modules} cm ON cm.instance = q.id
                     JOIN {modules} m ON m.id = cm.module AND m.name = 'quiz'
                     WHERE q.course $course_insql
                     AND cm.deletioninprogress = 0
                     AND qa.userid = :userid
                     AND qa.state = 'finished'",
                    $student_params
                );
                
                foreach ($quiz_attempts as $attempt) {
                    if ($attempt->maxgrade > 0) {
                        $quiz_avg += ($attempt->sumgrades / $attempt->maxgrade) * 100;
                        $quiz_count++;
                    }
                }
                
                if ($quiz_count > 0) {
                    $quiz_avg = $quiz_avg / $quiz_count;
                    $total_score += $quiz_avg * 0.15;
                }
                
                // Only include students with some activity
                if ($grade_count > 0 || $proficient_comps > 0 || $completed_assigns > 0 || $quiz_count > 0) {
                    // Get profile picture - ensure student has all required fields
                    if (!property_exists($student, 'firstnamephonetic') || !property_exists($student, 'lastnamephonetic')) {
                        $student = $DB->get_record('user', ['id' => $student->id], 
                            implode(',', \core_user\fields::get_picture_fields()), MUST_EXIST);
                    }
                    $user_picture = new user_picture($student);
                    $user_picture->size = 1; // Size f1
                    $avatar_url = $user_picture->get_url($PAGE)->out(false);
                    
                    $best_performing_students[] = [
                        'id' => $student->id,
                        'name' => fullname($student),
                        'email' => $student->email,
                        'grade_percentage' => round($grade_avg, 1),
                        'competency_percentage' => round($comp_rate, 1),
                        'assignment_percentage' => round($assign_rate, 1),
                        'quiz_percentage' => round($quiz_avg, 1),
                        'overall_score' => round($total_score),
                        'profile_picture_url' => $avatar_url,
                        'has_profile_picture' => true,
                        'is_parent_child' => in_array($student->id, $parent_children_ids), // Check if student is ANY of parent's children
                        'actual_rank' => 0 // Will be set after sorting
                    ];
                }
            }
            
            // Sort by overall score descending
            usort($best_performing_students, function($a, $b) {
                if ($a['overall_score'] == $b['overall_score']) {
                    return strcmp($a['name'], $b['name']);
                }
                return $b['overall_score'] - $a['overall_score'];
            });
            
            // Set ranks
            foreach ($best_performing_students as $idx => &$student) {
                $student['actual_rank'] = $idx + 1;
            }
            
            // Limit to top 100
            $best_performing_students = array_slice($best_performing_students, 0, 100);
            
            if ($debug_mode) {
                echo "<div style='background: #d1fae5; border: 2px solid #10b981; padding: 15px; margin: 20px; border-radius: 8px;'>";
                echo "<p style='margin: 0; color: #059669;'><strong>✓ SUCCESS!</strong> Found " . count($best_performing_students) . " students with performance data</p>";
                if (!empty($best_performing_students)) {
                    echo "<p style='margin: 10px 0 0 0;'><strong>Top Student:</strong> {$best_performing_students[0]['name']} - Score: {$best_performing_students[0]['overall_score']}</p>";
                }
                echo "</div>";
            }
        }
    }
} catch (Exception $e) {
    if ($debug_mode) {
        echo "<div style='background: #fee2e2; border: 2px solid #ef4444; padding: 15px; margin: 20px; border-radius: 8px;'>";
        echo "<p style='margin: 0; color: #991b1b;'><strong>❌ ERROR:</strong> " . $e->getMessage() . "</p>";
        echo "</div>";
    }
    debugging('Error fetching best performing students: ' . $e->getMessage());
}

// Get notifications count (from messages or announcements)
$total_notifications = 0;
try {
    // Count unread messages for parent
    $total_notifications = $DB->count_records('messages', [
        'useridto' => $userid,
        'timeread' => 0
    ]);
    
    // Add forum posts in announcement forums
    $sql_announcements = "SELECT COUNT(DISTINCT fp.id)
                         FROM {forum_posts} fp
                         JOIN {forum_discussions} fd ON fd.id = fp.discussion
                         JOIN {forum} f ON f.id = fd.forum
                         WHERE f.type = 'news'
                         AND fp.created > :lastweek";
    $announcements = $DB->count_records_sql($sql_announcements, [
        'lastweek' => time() - (7 * 24 * 60 * 60)
    ]);
    $total_notifications += $announcements;
} catch (Exception $e) {
    $total_notifications = 0;
}

// Get real notifications from Moodle
$notifications = [];
// Professional light blue notification colors
$notification_colors = ['#60a5fa', '#3b82f6', '#2563eb', '#1d4ed8', '#93c5fd'];

try {
    // Get recent messages
    $sql_messages = "SELECT m.id, m.useridfrom, m.subject, m.fullmessage, m.timecreated,
                           u.firstname, u.lastname, u.email
                    FROM {messages} m
                    JOIN {user} u ON u.id = m.useridfrom
                    WHERE m.useridto = :userid
                    AND m.timeread = 0
                    ORDER BY m.timecreated DESC
                    LIMIT 5";
    $messages = $DB->get_records_sql($sql_messages, ['userid' => $userid]);
    
    $color_idx = 0;
    foreach ($messages as $msg) {
        $notifications[] = [
            'type' => 'message',
            'title' => format_string($msg->subject),
            'message' => shorten_text(strip_tags($msg->fullmessage), 100),
            'time' => userdate($msg->timecreated, '%d %b, %H:%M'),
            'from' => fullname($msg),
            'color' => $notification_colors[$color_idx % count($notification_colors)]
        ];
        $color_idx++;
    }
    
    // Get recent forum posts in news forums
    $sql_forum = "SELECT fp.id, fp.subject, fp.message, fp.created,
                        u.firstname, u.lastname, f.name as forumname
                 FROM {forum_posts} fp
                 JOIN {forum_discussions} fd ON fd.id = fp.discussion
                 JOIN {forum} f ON f.id = fd.forum
                 JOIN {user} u ON u.id = fp.userid
                 WHERE f.type = 'news'
                 AND fp.created > :lastweek
                 ORDER BY fp.created DESC
                 LIMIT 5";
    $forum_posts = $DB->get_records_sql($sql_forum, [
        'lastweek' => time() - (7 * 24 * 60 * 60)
    ]);
    
    foreach ($forum_posts as $post) {
        $notifications[] = [
            'type' => 'announcement',
            'title' => format_string($post->subject),
            'message' => shorten_text(strip_tags($post->message), 100),
            'time' => userdate($post->created, '%d %b, %H:%M'),
            'from' => fullname($post) . ' (' . format_string($post->forumname) . ')',
            'color' => $notification_colors[$color_idx % count($notification_colors)]
        ];
        $color_idx++;
    }
} catch (Exception $e) {
    debugging('Error fetching notifications: ' . $e->getMessage());
}

// If still no notifications, add a welcome message
if (empty($notifications)) {
    $notifications[] = [
        'type' => 'system',
        'title' => get_string('welcome', 'theme_remui_kids'),
        'message' => 'No new notifications',
        'time' => userdate(time(), '%d %b, %H:%M'),
        'from' => 'System',
        'color' => '#60a5fa'
    ];
}

// Get parent events
try {
    $parent_calendar_events = $DB->get_records('event', [
        'eventtype' => 'parent',
        'parentid' => $userid
    ]);
} catch (Exception $e) {
    $parent_calendar_events = [];
}

if (!empty($parent_calendar_events)) {
    foreach ($parent_calendar_events as $event) {
        $notifications[] = [
            'type' => 'event',
            'title' => format_string($event->name),
            'message' => shorten_text(strip_tags($event->description), 100),
            'time' => userdate($event->timestart, '%d %b, %H:%M'),
            'from' => 'Calendar',
            'color' => '#3b82f6'
        ];
    }
}

// Get upcoming events
try {
    $parent_upcoming_events = $DB->count_records('event', [
        'eventtype' => 'parent',
        'parentid' => $userid
    ]);
} catch (Exception $e) {
    $parent_upcoming_events = [];
}

// ========================================
// NEW LOGIC: Collect upcoming events/deadlines from children's courses
// ========================================
$upcoming_events = [];

// Build upcoming_events from assignments, quizzes, lessons in child-courses
if (!empty($child_user_ids)) {
    // Course IDs for children
    list($child_insql, $child_params) = $DB->get_in_or_equal($child_user_ids, SQL_PARAMS_NAMED, 'child');
    
    // Get course IDs where children are enrolled
    $sql_child_courses = "SELECT DISTINCT e.courseid
                         FROM {enrol} e
                         JOIN {user_enrolments} ue ON ue.enrolid = e.id
                         WHERE ue.userid $child_insql
                         AND e.status = 0
                         AND ue.status = 0";
    $child_course_records = $DB->get_records_sql($sql_child_courses, $child_params);
    $child_course_ids = array_keys($child_course_records);
    
    if (!empty($child_course_ids)) {
        list($course_insql, $course_params) = $DB->get_in_or_equal($child_course_ids, SQL_PARAMS_NAMED, 'course');
        
        // Assignment due dates in the future
        try {
            $sql_assignments = "SELECT a.id, a.name, a.duedate, c.fullname as coursename
                               FROM {assign} a
                               JOIN {course} c ON c.id = a.course
                               WHERE a.course $course_insql
                               AND a.duedate > :now
                               AND a.duedate < :twoweeks
                               ORDER BY a.duedate ASC
                               LIMIT 20";
            $params_assign = array_merge($course_params, [
                'now' => time(),
                'twoweeks' => time() + (14 * 24 * 60 * 60)
            ]);
            $assignments = $DB->get_records_sql($sql_assignments, $params_assign);
            
            foreach ($assignments as $assign) {
                $upcoming_events[] = [
                    'type' => 'assignment',
                    'title' => format_string($assign->name),
                    'course' => format_string($assign->coursename),
                    'date' => userdate($assign->duedate, '%d %b %Y'),
                    'timestamp' => $assign->duedate,
                    'color' => '#f59e0b'
                ];
            }
        } catch (Exception $e) {
            // Ignore assignment fetch errors to avoid breaking dashboard.
        }

        // Quiz close dates.
        try {
            $sql_quizzes = "SELECT q.id, q.name, q.timeclose, c.fullname as coursename
                           FROM {quiz} q
                           JOIN {course} c ON c.id = q.course
                           WHERE q.course $course_insql
                           AND q.timeclose > :now
                           AND q.timeclose < :twoweeks
                           ORDER BY q.timeclose ASC
                           LIMIT 20";
            $params_quiz = array_merge($course_params, [
                'now' => time(),
                'twoweeks' => time() + (14 * 24 * 60 * 60)
            ]);
            $quizzes = $DB->get_records_sql($sql_quizzes, $params_quiz);
            
            foreach ($quizzes as $quiz) {
                $upcoming_events[] = [
                    'type' => 'quiz',
                    'title' => format_string($quiz->name),
                    'course' => format_string($quiz->coursename),
                    'date' => userdate($quiz->timeclose, '%d %b %Y'),
                    'timestamp' => $quiz->timeclose,
                    'color' => '#8b5cf6'
                ];
            }
        } catch (Exception $e) {
            // Ignore quiz fetch errors.
        }

        // Lesson deadlines.
        try {
            $sql_lessons = "SELECT l.id, l.name, l.deadline, c.fullname as coursename
                           FROM {lesson} l
                           JOIN {course} c ON c.id = l.course
                           WHERE l.course $course_insql
                           AND l.deadline > :now
                           AND l.deadline < :twoweeks
                           ORDER BY l.deadline ASC
                           LIMIT 20";
            $params_lesson = array_merge($course_params, [
                'now' => time(),
                'twoweeks' => time() + (14 * 24 * 60 * 60)
            ]);
            $lessons = $DB->get_records_sql($sql_lessons, $params_lesson);
            
            foreach ($lessons as $lesson) {
                $upcoming_events[] = [
                    'type' => 'lesson',
                    'title' => format_string($lesson->name),
                    'course' => format_string($lesson->coursename),
                    'date' => userdate($lesson->deadline, '%d %b %Y'),
                    'timestamp' => $lesson->deadline,
                    'color' => '#10b981'
                ];
            }
        } catch (Exception $e) {
            // Ignore lesson fetch errors.
        }
    }
}

// Sort upcoming events by timestamp
usort($upcoming_events, function($a, $b) {
    return $a['timestamp'] - $b['timestamp'];
});

// Limit to 10 upcoming events
$upcoming_events = array_slice($upcoming_events, 0, 10);

// ========================================
// END of upcoming events logic
// ========================================

// Continue with page HTML output below...

// Add any required CSS/JS BEFORE standard_head_html() is called
// This prevents "Cannot require a CSS file after <head> has been printed" errors
// Call theme_remui_kids_page_init manually to add CSS before head is printed
if (function_exists('theme_remui_kids_page_init')) {
    theme_remui_kids_page_init($PAGE);
}

// Set global flag to prevent theme_remui_kids_page_init from running again
// This is critical because standard_head_html() may trigger hooks that call it
global $THEME_REMUI_KIDS_PAGE_INIT_DONE;
$THEME_REMUI_KIDS_PAGE_INIT_DONE = true;

// Use Moodle's standard header (includes navbar automatically) - same as other parent pages
echo $OUTPUT->header();
include_once(__DIR__ . '/../components/parent_sidebar.php');
?>

<link rel="stylesheet" href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/style/parent_dashboard.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

<style>
    body {
        background: #ffffff;
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        margin: 0 !important;
        padding: 0 !important;
        overflow-x: hidden;
        width: 100%;
        max-width: 100%;
    }
    
    html {
        overflow-x: hidden;
        width: 100%;
        max-width: 100%;
    }
    
    /* Force full width and remove all margins - same as parent_schedule.php */
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
    
    .container,
    .container-fluid,
    #region-main,
    #region-main-box {
        margin: 0 !important;
        padding: 0 !important;
        max-width: 100% !important;
    }
    
    /* Sidebar visibility - Respect responsive behavior */
    #parent-sidebar,
    .parent-sidebar {
        display: block !important;
        visibility: visible !important;
        opacity: 1 !important;
        position: fixed !important;
        left: 0 !important;
        top: 0 !important;
        width: 280px !important;
        height: 100vh !important;
        z-index: 1000 !important;
        background: white !important;
    }
    
    /* Desktop: Show sidebar */
    @media screen and (min-width: 1025px) {
        #parent-sidebar,
        .parent-sidebar {
            transform: translateX(0) !important;
        }
    }
    
    /* Mobile/Tablet: Hide sidebar by default, show when .sidebar-open class is added */
    @media screen and (max-width: 1024px) {
        #parent-sidebar,
        .parent-sidebar {
            transform: translateX(-100%) !important;
        }
        
        #parent-sidebar.sidebar-open,
        .parent-sidebar.sidebar-open {
            transform: translateX(0) !important;
        }
    }
    
    
    /* Dashboard Header - Clean and Spaced */
    .dashboard-header {
        background: white;
        border-radius: 16px;
        padding: 32px 36px;
        margin-bottom: 28px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        border: 1px solid #e5e7eb;
    }
    
    .dashboard-header h1 {
        margin: 0 0 8px 0;
        color: #111827;
        font-size: 28px;
        font-weight: 700;
        line-height: 1.2;
    }
    
    .dashboard-header p {
        margin: 0;
        color: #6b7280;
        font-size: 15px;
        line-height: 1.5;
    }
    
    /* Child Selector - Well Styled */
    .child-selector {
        margin-bottom: 0;
        padding: 20px 24px;
        background: white;
        border-radius: 12px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        border: 1px solid #e5e7eb;
    }
    
    .child-selector label {
        display: block;
        margin-bottom: 10px;
        color: #374151;
        font-weight: 600;
        font-size: 14px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .child-selector select {
        width: 100%;
        padding: 12px 16px;
        border: 2px solid #d1d5db;
        border-radius: 8px;
        font-size: 15px;
        background: white;
        cursor: pointer;
        transition: all 0.2s;
        color: #111827;
    }
    
    .child-selector select:hover {
        border-color: #9ca3af;
    }
    
    .child-selector select:focus {
        outline: none;
        border-color: #667eea;
        box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
    }
    
    /* Stats Grid - Responsive and Clean */
    .parent-stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        gap: 20px;
        margin-bottom: 28px;
    }
    
    .stat-card {
        background: white;
        border-radius: 12px;
        padding: 24px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        border: 1px solid #e5e7eb;
        transition: all 0.3s ease;
        position: relative;
        overflow: hidden;
    }
    
    .stat-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 4px;
        height: 100%;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        opacity: 0;
        transition: opacity 0.3s;
    }
    
    .stat-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    }
    
    .stat-card:hover::before {
        opacity: 1;
    }
    
    .stat-card h3 {
        margin: 0 0 12px 0;
        color: #6b7280;
        font-size: 13px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .stat-card .stat-value {
        font-size: 32px;
        font-weight: 700;
        color: #111827;
        margin: 0;
        line-height: 1.2;
    }
    
    /* Modern Card - Consistent Styling */
    .modern-card {
        background: white;
        border-radius: 16px;
        padding: 28px 32px;
        margin-bottom: 0;
        box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        border: 1px solid #e5e7eb;
        transition: all 0.3s ease;
    }
    
    .modern-card:hover {
        box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        transform: translateY(-2px);
    }
    
    .modern-card h2 {
        margin: 0 0 24px 0;
        color: #111827;
        font-size: 20px;
        font-weight: 700;
        display: flex;
        align-items: center;
        gap: 10px;
        padding-bottom: 16px;
        border-bottom: 2px solid #f3f4f6;
    }
    
    .modern-card h2 i {
        color: #667eea;
        font-size: 22px;
    }
    
    /* Top Performers Row Layout - Like Image */
    .top-performers-list {
        background: white;
        border-radius: 12px;
        overflow: hidden;
        border: 1px solid #e5e7eb;
    }
    
    .top-performer-row {
        background: white;
        border-bottom: 1px solid #e5e7eb;
        padding: 20px 24px;
        display: flex;
        align-items: center;
        gap: 16px;
        transition: background 0.2s ease;
    }
    
    .top-performer-row:last-child {
        border-bottom: none;
    }
    
    .top-performer-row:hover {
        background: #f9fafb;
    }
    
    .top-performer-row .profile-picture {
        width: 56px;
        height: 56px;
        border-radius: 50%;
        overflow: hidden;
        flex-shrink: 0;
        border: 2px solid #e5e7eb;
    }
    
    .top-performer-row .profile-picture img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    
    .top-performer-row .student-info {
        flex: 1;
        min-width: 0;
    }
    
    .top-performer-row .student-name {
        font-size: 16px;
        font-weight: 700;
        color: #111827;
        margin: 0 0 4px 0;
        line-height: 1.3;
    }
    
    .top-performer-row .student-description {
        font-size: 13px;
        color: #6b7280;
        margin: 0;
        line-height: 1.4;
    }
    
    .top-performer-row .student-score {
        text-align: right;
        flex-shrink: 0;
        min-width: 80px;
        font-size: 20px;
        font-weight: 700;
        color: #111827;
        line-height: 1.2;
    }
    
    /* Empty State */
    .empty-state {
        text-align: center;
        padding: 60px 20px;
        color: #9ca3af;
    }
    
    .empty-state i {
        font-size: 64px;
        margin-bottom: 16px;
        opacity: 0.5;
        color: #d1d5db;
    }
    
    .empty-state p {
        margin: 0;
        font-size: 16px;
        color: #6b7280;
    }
    
    /* Mobile responsive - hide sidebar and adjust content */
    @media (max-width: 768px) {
        
        .dashboard-header {
            padding: 24px 20px;
            margin-bottom: 20px;
        }
        
        .dashboard-header h1 {
            font-size: 24px;
        }
        
        .parent-stats-grid {
            grid-template-columns: 1fr;
            gap: 16px;
            margin-bottom: 20px;
        }
        
        .modern-card {
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .child-selector {
            padding: 16px;
            margin-bottom: 20px;
        }
        
        .parent-sidebar-toggle {
            display: flex !important;
        }
    }
    </style>

<!-- Ensure sidebar is visible and content is properly spaced -->
<div class="parent-main-content">
    
    <!-- Dashboard Header -->

<!-- Enhanced Modern UI Styles -->
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


/* Remove Bootstrap container margins */
.container,
.container-fluid {
    margin-left: 0 !important;
    margin-right: 0 !important;
    padding-left: 0 !important;
    padding-right: 0 !important;
    max-width: 100% !important;
    width: 100% !important;
}

/* Enhanced parent content area - Proper Layout */
.parent-main-content {
    margin-left: 280px;
    padding: 24px 28px;
    background: #f8fafc;
    min-height: 100vh;
    width: calc(100% - 280px);
    max-width: 100%;
    box-sizing: border-box;
    transition: margin-left 0.3s ease, width 0.3s ease;
}

/* Mobile/Tablet: Full width content */
@media screen and (max-width: 1024px) {
    .parent-main-content {
        margin-left: 0 !important;
        margin-right: 0 !important;
        width: 100% !important;
        max-width: 100% !important;
        padding-left: 16px !important;
        padding-right: 16px !important;
        box-sizing: border-box !important;
    }
}

/* Comprehensive Responsive Design for All Screen Sizes */
@media screen and (max-width: 1024px) {
    .parent-main-content {
        margin-left: 0 !important;
        margin-right: 0 !important;
        width: 100% !important;
        max-width: 100% !important;
        padding: 16px !important;
        box-sizing: border-box !important;
    }
    
    .dashboard-header {
        padding: 24px 28px !important;
    }
    
    .dashboard-header h1 {
        font-size: 24px !important;
    }
    
    .parent-section {
        margin-bottom: 16px;
    }
    
    .section-title {
        font-size: 16px !important;
    }
    
    .modern-card {
        padding: 20px !important;
    }
}

@media (max-width: 768px) {
    .parent-main-content {
        margin-left: 0;
        width: 100%;
        padding: 16px;
    }
    
    .dashboard-header {
        padding: 20px !important;
        border-radius: 12px !important;
    }
    
    .dashboard-header h1 {
        font-size: 22px !important;
    }
    
    .dashboard-header p {
        font-size: 14px !important;
    }
    
    .child-selector {
        padding: 16px !important;
    }
    
    .parent-section {
        margin-bottom: 16px;
    }
    
    .section-title {
        font-size: 16px !important;
        margin-bottom: 12px !important;
    }
    
    .modern-card {
        padding: 16px !important;
        border-radius: 10px !important;
    }
    
    /* Make grids responsive */
    .calendar-highlights,
    .academic-events-grid,
    [style*="grid-template-columns"] {
        grid-template-columns: 1fr !important;
        gap: 12px !important;
    }
    
    .parent-calendar__grid {
        grid-template-columns: repeat(7, minmax(0, 1fr)) !important;
        gap: 4px !important;
    }
    
    .parent-calendar__weekday {
        font-size: 10px !important;
        padding: 4px 2px !important;
    }
    
    /* Stack flex containers */
    .parent-calendar__header,
    .parent-calendar__meta,
    .dashboard-header > div {
        flex-direction: column !important;
        align-items: flex-start !important;
        gap: 12px !important;
    }
    
    /* Make tables scrollable with proper overflow handling */
    table {
        display: block !important;
        overflow-x: auto !important;
        -webkit-overflow-scrolling: touch !important;
        width: 100% !important;
        max-width: 100% !important;
        box-sizing: border-box !important;
    }
    
    table thead,
    table tbody,
    table tr {
        display: table !important;
        width: 100% !important;
        table-layout: fixed !important;
    }
    
    /* Fix text overflow in all containers */
    .parent-main-content,
    .parent-content-wrapper,
    .parent-section,
    .modern-card,
    .dashboard-header,
    .child-selector,
    .stat-card,
    .event-chip__title {
        word-wrap: break-word !important;
        word-break: break-word !important;
        overflow-wrap: break-word !important;
        max-width: 100% !important;
        box-sizing: border-box !important;
    }
    
    /* Remove text ellipsis on mobile for better readability */
    .event-chip__title,
    .stat-card h3,
    .modern-card h3 {
        white-space: normal !important;
        text-overflow: clip !important;
        overflow: visible !important;
    }
    
    /* Adjust font sizes */
    h1, h2, h3 {
        font-size: 1.2em !important;
    }
    
    /* Stack buttons */
    .parent-calendar__actions,
    .course-card-actions,
    .parent-header-actions,
    [style*="display: flex"][style*="gap"] {
        flex-direction: column !important;
        width: 100% !important;
    }
    
    .calendar-nav-btn,
    .course-view-btn,
    .course-child-view-btn,
    .back-link,
    .back-link-btn {
        width: 100% !important;
        text-align: center !important;
        justify-content: center !important;
    }
    
    /* Make all grids single column on mobile */
    [style*="grid-template-columns: repeat"],
    [style*="display: grid"] {
        grid-template-columns: 1fr !important;
    }
    
    /* Adjust spacing */
    .parent-section {
        margin-bottom: 16px !important;
    }
    
    /* Make cards stack */
    .modern-card,
    .parent-section > div {
        margin-bottom: 12px;
    }
}

/* Tablet responsive adjustments (768px - 1024px) */
@media screen and (min-width: 769px) and (max-width: 1024px) {
    .parent-main-content {
        padding: 18px 20px !important;
    }
    
    .dashboard-header {
        padding: 24px !important;
    }
    
    .dashboard-header h1 {
        font-size: 24px !important;
    }
    
    .modern-card {
        padding: 20px !important;
    }
    
    /* Quiz Results - Tablet */
    .quiz-results-header h2 {
        font-size: 19px !important;
    }
    
    .quiz-result-item {
        padding: 16px 18px !important;
    }
    
    .quiz-result-item h4 {
        font-size: 14px !important;
    }
    
    /* Community Posts - Tablet */
    .community-header h2 {
        font-size: 19px !important;
    }
    
    .community-card {
        padding: 24px !important;
        min-height: 550px !important;
        max-height: 600px !important;
    }
    
    /* Recent Activities - Tablet */
    .activities-header h2 {
        font-size: 19px !important;
    }
    
    .activity-item {
        padding: 16px 18px !important;
    }
    
    .activity-item h4 {
        font-size: 14px !important;
    }
    
    /* All Course Activities - Tablet */
    .activities-content-header h2 {
        font-size: 15px !important;
    }
    
    .activities-content-table th,
    .activities-content-table td {
        padding: 10px 12px !important;
        font-size: 12px !important;
    }
    
    .activities-content-table th {
        font-size: 10px !important;
    }
    
    .activities-content-table td strong {
        font-size: 12px !important;
    }
    
    /* Grid layouts for tablet - 2 columns where appropriate */
    .parent-stats-grid {
        grid-template-columns: repeat(2, 1fr) !important;
        gap: 18px !important;
    }
    
    .community-performance-grid {
        grid-template-columns: 1fr !important;
    }
    
    .activities-submissions-grid {
        grid-template-columns: 1fr !important;
    }
}

@media (max-width: 480px) {
    .parent-main-content {
        padding: 12px !important;
    }
    
    .dashboard-header {
        padding: 16px !important;
    }
    
    .dashboard-header h1 {
        font-size: 20px !important;
    }
    
    .dashboard-header p {
        font-size: 13px !important;
    }
    
    .child-selector {
        padding: 12px !important;
    }
    
    .modern-card {
        padding: 12px !important;
    }
    
    .section-title {
        font-size: 14px !important;
    }
    
    .parent-calendar__grid {
        gap: 2px !important;
    }
    
    .parent-calendar__weekday {
        font-size: 9px !important;
    }
    
    /* Make all text smaller on very small screens */
    body {
        font-size: 14px !important;
    }
    
    .calendar-highlight strong,
    .stat-value {
        font-size: 20px !important;
    }
    
    /* Hide decorative elements on mobile */
    [style*="position: absolute"][style*="top: -"],
    [style*="position: absolute"][style*="right: -"] {
        display: none !important;
    }
    
    /* Fix Recent Quiz Results overflow on mobile */
    .quiz-results-header {
        flex-wrap: wrap !important;
        gap: 12px !important;
    }
    
    .quiz-results-header h2 {
        font-size: 18px !important;
        flex: 1 1 100% !important;
        min-width: 0 !important;
    }
    
    .quiz-results-header a {
        font-size: 12px !important;
        padding: 5px 10px !important;
    }
    
    /* Fix quiz item text overflow */
    .quiz-result-item {
        flex-wrap: wrap !important;
        align-items: flex-start !important;
    }
    
    .quiz-result-item > div[style*="flex: 1"] {
        flex: 1 1 100% !important;
        min-width: 0 !important;
        max-width: 100% !important;
    }
    
    .quiz-result-meta {
        flex: 1 1 100% !important;
        text-align: left !important;
        align-items: flex-start !important;
        margin-top: 8px !important;
    }
    
    /* Fix Open Community/Recent Community Posts overflow on mobile */
    .community-card {
        padding: 20px 16px !important;
        min-height: auto !important;
        max-height: none !important;
        overflow-x: hidden !important;
    }
    
    .community-header {
        flex-wrap: wrap !important;
        gap: 12px !important;
    }
    
    .community-header h2 {
        font-size: 18px !important;
        flex: 1 1 100% !important;
        min-width: 0 !important;
    }
    
    .community-header a {
        font-size: 12px !important;
        padding: 6px 12px !important;
    }
    
    /* Fix community post items text overflow */
    .community-post-item {
        padding: 14px 0 !important;
    }
    
    .community-post-item > div[style*="display: flex"][style*="gap: 14px"] {
        flex-wrap: wrap !important;
        gap: 12px !important;
    }
    
    .community-post-item > div[style*="display: flex"] > div[style*="flex: 1"] {
        flex: 1 1 100% !important;
        min-width: 0 !important;
        max-width: 100% !important;
    }
    
    .community-post-item h3 {
        white-space: normal !important;
        text-overflow: clip !important;
        overflow: visible !important;
        word-wrap: break-word !important;
        word-break: break-word !important;
    }
    
    .community-post-item p {
        word-wrap: break-word !important;
        word-break: break-word !important;
        overflow-wrap: break-word !important;
    }
    
    /* Fix Recent Activities overflow on mobile */
    .activities-header {
        flex-wrap: wrap !important;
        gap: 12px !important;
    }
    
    .activities-header > div:first-child {
        flex: 1 1 100% !important;
        min-width: 0 !important;
    }
    
    .activities-header > div:last-child {
        flex-shrink: 0 !important;
    }
    
    .activities-header h2 {
        font-size: 18px !important;
        flex-wrap: wrap !important;
    }
    
    .activities-header p {
        font-size: 12px !important;
    }
    
    /* Fix activity items text overflow */
    .activity-item {
        flex-wrap: wrap !important;
        align-items: flex-start !important;
        padding: 14px 16px !important;
    }
    
    .activity-item > div[style*="flex: 1"] {
        flex: 1 1 100% !important;
        min-width: 0 !important;
        max-width: 100% !important;
    }
    
    .activity-item h4 {
        white-space: normal !important;
        word-wrap: break-word !important;
        word-break: break-word !important;
        overflow-wrap: break-word !important;
        font-size: 13px !important;
    }
    
    .activity-meta {
        flex: 1 1 100% !important;
        text-align: left !important;
        margin-top: 12px !important;
        min-width: auto !important;
    }
    
    .activity-item > div[style*="display: flex"][style*="gap: 12px"] {
        flex-wrap: wrap !important;
        gap: 8px !important;
    }
    
    /* Fix All Course Activities & Content overflow on mobile */
    .activities-content-header {
        padding: 12px 14px !important;
    }
    
    .activities-content-header h2 {
        font-size: 14px !important;
    }
    
    .activities-content-table-container {
        overflow-x: auto !important;
        -webkit-overflow-scrolling: touch !important;
    }
    
    .activities-content-table {
        min-width: 600px !important;
        table-layout: auto !important;
    }
    
    .activities-content-table th,
    .activities-content-table td {
        padding: 8px 10px !important;
        font-size: 11px !important;
        word-wrap: break-word !important;
        word-break: break-word !important;
        overflow-wrap: break-word !important;
        white-space: normal !important;
    }
    
    .activities-content-table th {
        font-size: 9px !important;
        padding: 8px 10px !important;
    }
    
    .activities-content-table td strong {
        font-size: 11px !important;
        white-space: normal !important;
        word-wrap: break-word !important;
        word-break: break-word !important;
    }
    
    .activities-content-table td span {
        word-wrap: break-word !important;
        word-break: break-word !important;
        overflow-wrap: break-word !important;
    }
}
    padding: 24px 32px 24px 0;
    min-height: 100vh;
    background: #ffffff;
    width: calc(100% - 280px);
    max-width: 100%;
    box-sizing: border-box;
    position: relative;
    font-family: 'Inter', 'Segoe UI', -apple-system, BlinkMacSystemFont, sans-serif;
    transition: margin-left 0.3s ease;
}

/* Ensure proper spacing for all content */
.parent-main-content > * {
    max-width: 100%;
    box-sizing: border-box;
}

/* Note: Tablet and mobile styles are handled in media queries above (tablet: 769px-1024px, mobile: max-width 768px and 480px) */

/* Main Content Container - Clean and Responsive */
.parent-main-content {
    margin-left: 130px !important;
    padding: 20px 24px !important;
    width: calc(100% - 130px) !important;
    max-width: calc(100% - 130px) !important;
    overflow-x: hidden !important;
    overflow-y: auto !important;
    box-sizing: border-box !important;
    margin-top: 0 !important;
    position: relative !important;
    z-index: 1 !important;
    word-wrap: break-word !important;
    word-break: break-word !important;
}

/* Ensure all containers prevent overflow */
.parent-content-wrapper,
.parent-section,
.modern-card,
.dashboard-header,
.child-selector {
    box-sizing: border-box !important;
    overflow-x: hidden !important;
    word-wrap: break-word !important;
    word-break: break-word !important;
    max-width: 100% !important;
}

/* Sidebar responsive behavior - Respect mobile/tablet hiding */
.parent-sidebar {
    z-index: 1000 !important;
    position: fixed !important;
    display: block !important;
    visibility: visible !important;
    opacity: 1 !important;
    left: 0 !important;
    top: 0 !important;
}

/* Desktop: Always show sidebar */
@media screen and (min-width: 1025px) {
    .parent-sidebar {
        transform: translateX(0) !important;
    }
}

/* Mobile/Tablet: Hide sidebar by default */
@media screen and (max-width: 1024px) {
    .parent-sidebar,
    #parent-sidebar {
        transform: translateX(-100%) !important;
        -webkit-transform: translateX(-100%) !important;
        -moz-transform: translateX(-100%) !important;
        -ms-transform: translateX(-100%) !important;
        left: -280px !important;
    }
    
    .parent-sidebar.sidebar-open,
    #parent-sidebar.sidebar-open {
        transform: translateX(0) !important;
        -webkit-transform: translateX(0) !important;
        -moz-transform: translateX(0) !important;
        -ms-transform: translateX(0) !important;
        left: 0 !important;
    }
    
    .parent-main-content {
        margin-left: 0 !important;
        margin-right: 0 !important;
        width: 100% !important;
        max-width: 100% !important;
        padding-left: 16px !important;
        padding-right: 16px !important;
        box-sizing: border-box !important;
    }
    
    .parent-sidebar-toggle {
        display: flex !important;
        visibility: visible !important;
        opacity: 1 !important;
    }
}

/* Subtle background pattern - removed to keep layout clean */

@keyframes gradientShift {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.8; }
}

/* Enhanced UI Animations */
@keyframes float {
    0%, 100% { transform: translateY(0px); }
    50% { transform: translateY(-10px); }
}

@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.8; }
}

.modern-card {
    animation: fadeInUp 0.5s ease-out;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.modern-card:hover {
    transform: translateY(-8px);
    box-shadow: 0 20px 50px rgba(0,0,0,0.2) !important;
}

.resource-item, .submission-item, .quiz-item {
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    animation: fadeInUp 0.4s ease-out;
}

.resource-item:hover, .submission-item:hover, .quiz-item:hover {
    transform: translateX(8px);
    background: rgba(255,255,255,0.98) !important;
    box-shadow: 0 8px 24px rgba(0,0,0,0.15) !important;
}

.schedule-day {
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.schedule-day:hover {
    transform: translateY(-5px) scale(1.02);
}

.action-button {
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
}

.action-button:hover {
    transform: scale(1.15);
}

.grade-badge {
    animation: pulse 2s ease-in-out infinite;
}

/* Glassmorphism effect */
.glass-card {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(15px);
    -webkit-backdrop-filter: blur(15px);
    border: 1px solid rgba(255, 255, 255, 0.4);
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.12);
}

/* Gradient text */
.gradient-text {
    background: linear-gradient(45deg, #667eea 0%, #764ba2 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

/* Hover glow effect */
.glow-on-hover {
    transition: all 0.3s ease;
}

.glow-on-hover:hover {
    box-shadow: 0 0 25px rgba(102, 126, 234, 0.7);
}

/* Modern section styling - Compact */
.parent-section {
    margin-bottom: 20px;
    position: relative;
    z-index: 1;
}

.section-title {
    font-size: 18px;
    font-weight: 700;
    color: #0f172a;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 8px;
    letter-spacing: -0.3px;
}

.section-title i {
    font-size: 16px;
    background: linear-gradient(135deg, #3b82f6, #8b5cf6);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

/* Enhanced card styling - Compact */
.modern-card {
    background: #ffffff;
    border-radius: 12px;
    padding: 16px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04), 0 1px 2px rgba(0, 0, 0, 0.06);
    border: 1px solid rgba(226, 232, 240, 0.8);
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    overflow: hidden;
}

.modern-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(90deg, #3b82f6, #8b5cf6, #ec4899);
    opacity: 0;
    transition: opacity 0.3s ease;
}

.modern-card:hover {
    transform: translateY(-6px);
    box-shadow: 0 12px 40px rgba(59, 130, 246, 0.15), 0 4px 12px rgba(0, 0, 0, 0.1);
    border-color: rgba(59, 130, 246, 0.3);
}

.modern-card:hover::before {
    opacity: 1;
}

/* Enhanced breadcrumb - Compact */
.parent-breadcrumb {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 24px;
    padding: 12px 20px;
    background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
    border-radius: 12px;
    border: 1px solid rgba(226, 232, 240, 0.8);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
    backdrop-filter: blur(10px);
}

.breadcrumb-link {
    color: #64748b;
    text-decoration: none;
    font-size: 14px;
    font-weight: 500;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 6px 12px;
    border-radius: 8px;
}

.breadcrumb-link:hover {
    color: #3b82f6;
    background: rgba(59, 130, 246, 0.08);
    transform: translateX(2px);
}

.breadcrumb-current {
    color: #0f172a;
    font-size: 15px;
    font-weight: 600;
}

.parent-content-wrapper {
    position: relative;
    z-index: 1;
    width: 100% !important;
    max-width: 100% !important;
}

/* Enhanced filter badge styling */
.filter-badge {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    margin-left: 16px;
    padding: 8px 18px;
    background: linear-gradient(135deg, #eff6ff, #dbeafe);
    color: #1e40af;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 700;
    border: 2px solid #bfdbfe;
    box-shadow: 0 2px 8px rgba(59, 130, 246, 0.15);
}

/* Responsive design - consolidated with main styles above */

.parent-calendar-section {
    position: relative;
    border-radius: 12px;
    padding: 18px;
    background: #ffffff;
    border: 1px solid #e9ecef;
    box-shadow: 0 2px 8px rgba(0,0,0,0.04);
    overflow: hidden;
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.parent-calendar__header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    position: relative;
    z-index: 1;
    padding-bottom: 12px;
    border-bottom: 1px solid rgba(148, 163, 184, 0.15);
}

.parent-calendar__header > div:first-child {
    flex: 1;
}

.parent-calendar__subtitle {
    margin: 6px 0 0;
    color: #64748b;
    font-size: 13px;
    font-weight: 500;
}

.parent-calendar__actions {
    display: inline-flex;
    gap: 12px;
    flex-wrap: wrap;
}

.calendar-nav-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: #ffffff;
    border: 1px solid #dee2e6;
    border-radius: 6px;
    padding: 7px 12px;
    color: #495057;
    font-weight: 500;
    font-size: 13px;
    text-decoration: none;
    transition: all 0.2s ease;
}

.calendar-nav-btn:hover {
    transform: translateY(-1px);
    border-color: #adb5bd;
    background: #f8f9fa;
    color: #212529;
}

.calendar-nav-btn i {
    font-size: 11px;
}

.parent-calendar__meta {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
    position: relative;
    z-index: 1;
}

.parent-calendar__month {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 7px 14px;
    background: #f8f9fa;
    color: #495057;
    font-weight: 600;
    font-size: 14px;
    border-radius: 6px;
    border: 1px solid #e9ecef;
}

.parent-calendar__month i {
    font-size: 14px;
}

.parent-calendar__legend {
    display: inline-flex;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
    color: #475569;
    font-size: 12px;
    font-weight: 600;
}

.legend-dot {
    display: inline-block;
    width: 10px;
    height: 10px;
    border-radius: 50%;
    margin-right: 6px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.15);
}

.legend-dot--personal {
    background: #6c757d;
}

.legend-dot--site {
    background: #495057;
}

.legend-dot--assignment {
    background: #fb923c;
}

.legend-dot--quiz {
    background: #a78bfa;
}

.legend-dot--lesson {
    background: #2dd4bf;
}

.calendar-highlights {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 12px;
}

.calendar-highlight {
    background: linear-gradient(135deg, #eff6ff, #ffffff);
    border: 1px solid rgba(148, 163, 184, 0.3);
    border-radius: 12px;
    padding: 16px;
    box-shadow: 0 4px 14px rgba(15, 23, 42, 0.05);
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.calendar-highlight span {
    font-size: 12px;
    color: #64748b;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

.calendar-highlight strong {
    font-size: 28px;
    font-weight: 800;
    color: #0f172a;
    line-height: 1.1;
}

.calendar-highlight small {
    font-size: 11px;
    color: #94a3b8;
    font-weight: 600;
}

.calendar-filter-bar {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    background: #f8fafc;
    border: 1px solid rgba(148, 163, 184, 0.2);
    border-radius: 12px;
    padding: 10px;
}

.calendar-filter-btn {
    border: 1px solid transparent;
    background: transparent;
    color: #475569;
    font-size: 12px;
    font-weight: 700;
    padding: 8px 14px;
    border-radius: 999px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    cursor: pointer;
    transition: all 0.2s ease;
}

.calendar-filter-btn.active {
    background: linear-gradient(135deg, #3b82f6, #8b5cf6);
    color: #ffffff;
    border-color: transparent;
    box-shadow: 0 6px 16px rgba(59, 130, 246, 0.35);
}

.calendar-filter-btn:hover:not(.active) {
    border-color: rgba(59, 130, 246, 0.3);
    color: #1d4ed8;
}

.calendar-academic-panel {
    margin-top: 24px;
    padding: 18px;
    border: 1px solid rgba(148, 163, 184, 0.3);
    border-radius: 16px;
    background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
    box-shadow: 0 4px 18px rgba(15, 23, 42, 0.06);
    display: flex;
    flex-direction: column;
    gap: 18px;
}

.calendar-academic-header {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
}

.calendar-academic-header h3 {
    margin: 0;
    font-size: 18px;
    font-weight: 800;
    color: #0f172a;
    display: flex;
    align-items: center;
    gap: 10px;
}

.calendar-academic-meta {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    font-size: 12px;
    color: #64748b;
    font-weight: 600;
}

.calendar-academic-list {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
    gap: 12px;
}

.academic-event-card {
    border: 1px solid rgba(148, 163, 184, 0.25);
    border-radius: 14px;
    padding: 14px;
    background: #ffffff;
    box-shadow: 0 3px 12px rgba(15, 23, 42, 0.05);
    display: flex;
    flex-direction: column;
    gap: 10px;
    position: relative;
    overflow: hidden;
}

.academic-event-card::after {
    content: '';
    position: absolute;
    inset: 0;
    opacity: 0;
    background: linear-gradient(135deg, rgba(59,130,246,0.05), rgba(139,92,246,0.05));
    transition: opacity 0.2s ease;
}

.academic-event-card:hover::after {
    opacity: 1;
}

.academic-event-card:hover {
    transform: translateY(-2px);
}

.academic-event-type {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 11px;
    text-transform: uppercase;
    font-weight: 800;
    letter-spacing: 0.05em;
}

.academic-event-title {
    font-size: 15px;
    font-weight: 700;
    color: #0f172a;
    display: flex;
    justify-content: space-between;
    gap: 10px;
}

.academic-event-meta {
    display: flex;
    flex-direction: column;
    gap: 6px;
    font-size: 12px;
    color: #475569;
    font-weight: 600;
}

.academic-event-meta span {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.academic-event-meta i {
    color: #94a3b8;
}

.academic-event-card .badge--assignment,
.academic-event-card .badge--quiz,
.academic-event-card .badge--lesson {
    min-width: auto;
    padding: 6px 12px;
    font-size: 10px;
    box-shadow: none;
    border-radius: 10px;
}

.parent-calendar__grid {
    display: grid;
    grid-template-columns: repeat(7, minmax(0, 1fr));
    gap: 6px;
    position: relative;
    z-index: 1;
}

.parent-calendar__weekday {
    text-align: center;
    font-weight: 600;
    color: #6c757d;
    letter-spacing: 0.05em;
    font-size: 11px;
    text-transform: uppercase;
    padding: 8px 4px;
    background: #f8f9fa;
    border-radius: 6px;
}

.parent-calendar__cell {
    position: relative;
    border-radius: 8px;
    border: 1px solid #e9ecef;
    padding: 8px 6px;
    min-height: 80px;
    background: #ffffff;
    transition: all 0.2s ease;
    display: flex;
    flex-direction: column;
    cursor: pointer;
    overflow: hidden;
}

.parent-calendar__cell:hover {
    transform: translateY(-2px);
    border-color: #dee2e6;
    box-shadow: 0 2px 6px rgba(0,0,0,0.06);
    background: #f8f9fa;
}

.parent-calendar__cell--empty {
    background: rgba(248, 250, 252, 0.5);
    border-style: dashed;
    border-color: rgba(148, 163, 184, 0.2);
    box-shadow: none;
    cursor: default;
}

.parent-calendar__cell--empty:hover {
    transform: none;
    box-shadow: none;
}

.parent-calendar__cell--today {
    border: 2px solid #212529;
    background: #f8f9fa;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.parent-calendar__cell--today::after {
    content: "Today";
    position: absolute;
    top: 4px;
    right: 4px;
    padding: 2px 6px;
    border-radius: 4px;
    background: #212529;
    color: #ffffff;
    font-size: 8px;
    font-weight: 600;
    text-transform: uppercase;
}

.parent-calendar__day-number {
    font-size: 14px;
    font-weight: 700;
    color: #0f172a;
    margin-bottom: 4px;
    position: relative;
    z-index: 2;
    display: flex;
    align-items: center;
    gap: 4px;
}

.parent-calendar__events {
    list-style: none;
    margin: 4px 0 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.event-chip {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 6px;
    padding: 4px 6px;
    border-radius: 4px;
    font-size: 10px;
    font-weight: 600;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.08);
    transition: all 0.2s ease;
    border: 1px solid rgba(255, 255, 255, 0.5);
    position: relative;
    overflow: hidden;
    cursor: pointer;
}

.event-chip:hover {
    transform: translateX(2px);
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.12);
}

.event-chip__title {
    color: #0f172a;
    overflow: visible;
    text-overflow: clip;
    white-space: normal;
    flex: 1;
    font-weight: 600;
    position: relative;
    z-index: 1;
    word-wrap: break-word;
    word-break: break-word;
    overflow-wrap: break-word;
    max-width: 100%;
}

.event-chip__title i {
    margin-right: 4px;
    color: rgba(15, 23, 42, 0.65);
}

.event-chip__child {
    font-weight: 700;
    color: #0f172a;
}

.event-chip__course {
    font-weight: 600;
    color: #64748b;
    margin-left: 4px;
}

.event-chip__separator {
    color: rgba(15, 23, 42, 0.35);
    margin: 0 4px;
    font-weight: 700;
}

.event-chip__time {
    color: rgba(0, 0, 0, 0.6);
    font-variant-numeric: tabular-nums;
    font-weight: 700;
    font-size: 9px;
    position: relative;
    z-index: 1;
    background: rgba(255, 255, 255, 0.5);
    padding: 2px 4px;
    border-radius: 3px;
    flex-shrink: 0;
}

.event-chip__time i {
    margin-right: 4px;
}

.event-chip--personal {
    background: #f8f9fa;
    color: #495057;
    border-color: #e9ecef;
}

.event-chip--assigned {
    background: #f8f9fa;
    color: #495057;
    border-color: #e9ecef;
}

.event-chip--site {
    background: #212529;
    color: #f8f9fa;
    border-color: #212529;
}

.event-chip--assignment {
    background: rgba(251, 146, 60, 0.15);
    color: #9a3412;
    border-color: rgba(251, 146, 60, 0.4);
}

.event-chip--quiz {
    background: rgba(167, 139, 250, 0.15);
    color: #5b21b6;
    border-color: rgba(167, 139, 250, 0.4);
}

.event-chip--lesson {
    background: rgba(45, 212, 191, 0.15);
    color: #115e59;
    border-color: rgba(45, 212, 191, 0.4);
}

.event-chip--overdue {
    background: linear-gradient(135deg, #fee2e2, #fecaca) !important;
    color: #991b1b !important;
    border-left: 3px solid #ef4444 !important;
    box-shadow: 0 2px 8px rgba(239, 68, 68, 0.25) !important;
    animation: pulse-overdue 2s ease-in-out infinite;
}

.event-chip--due-today {
    background: linear-gradient(135deg, #fef3c7, #fde68a) !important;
    color: #92400e !important;
    border-left: 3px solid #f59e0b !important;
    box-shadow: 0 2px 8px rgba(245, 158, 11, 0.25) !important;
}

.event-chip--due-soon {
    background: linear-gradient(135deg, #dbeafe, #bfdbfe) !important;
    color: #1e40af !important;
    border-left: 3px solid #3b82f6 !important;
    box-shadow: 0 2px 8px rgba(59, 130, 246, 0.25) !important;
}

@keyframes pulse-overdue {
    0%, 100% {
        box-shadow: 0 2px 8px rgba(239, 68, 68, 0.25);
    }
    50% {
        box-shadow: 0 4px 16px rgba(239, 68, 68, 0.4);
    }
}

.event-chip--overdue .event-chip__title,
.event-chip--due-today .event-chip__title,
.event-chip--due-soon .event-chip__title {
    font-weight: 700;
}

.parent-calendar__more {
    font-size: 12px;
    color: #475569;
    padding: 6px 0 0;
}

.parent-calendar__footer {
    position: relative;
    z-index: 1;
}

.parent-calendar__upcoming ul {
    list-style: none;
    margin: 16px 0 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.parent-calendar__upcoming li {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    background: white;
    padding: 12px 16px;
    border-radius: 8px;
    border: 1px solid rgba(226, 232, 240, 0.8);
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    transition: all 0.2s ease;
    position: relative;
}

.parent-calendar__upcoming li::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 3px;
    background: rgba(59, 130, 246, 0.5);
    border-radius: 0 2px 2px 0;
    opacity: 0;
    transition: opacity 0.2s ease;
}

.parent-calendar__upcoming li:hover {
    transform: translateX(2px);
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
    border-color: rgba(59, 130, 246, 0.3);
}

.parent-calendar__upcoming li:hover::before {
    opacity: 1;
}

.parent-calendar__upcoming h3 {
    margin: 0 0 12px 0;
    font-size: 16px;
    font-weight: 700;
    color: #0f172a;
    display: flex;
    align-items: center;
    gap: 8px;
    padding-bottom: 12px;
    border-bottom: 1px solid rgba(148, 163, 184, 0.15);
}

.upcoming-title {
    font-weight: 700;
    color: #0f172a;
    margin-bottom: 6px;
}

.upcoming-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    color: #475569;
    font-size: 13px;
    align-items: center;
}

.upcoming-meta span {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.upcoming-meta i {
    color: #94a3b8;
    font-size: 11px;
}

.upcoming-child {
    color: #0f172a;
    font-weight: 700;
}

.upcoming-course {
    color: #475569;
    font-weight: 600;
}

.upcoming-assignedby {
    color: #6b21a8;
    font-weight: 600;
}

.badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 80px;
    padding: 8px 16px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15), inset 0 1px 2px rgba(255, 255, 255, 0.4);
    border: 1px solid rgba(255, 255, 255, 0.3);
    transition: all 0.3s ease;
    flex-shrink: 0;
}

.badge:hover {
    transform: scale(1.05);
    box-shadow: 0 6px 16px rgba(0, 0, 0, 0.2), inset 0 1px 2px rgba(255, 255, 255, 0.5);
}

.badge--personal {
    background: rgba(99, 102, 241, 0.14);
    color: #4338ca;
}

.badge--assigned {
    background: rgba(249, 115, 22, 0.16);
    color: #c2410c;
}

.badge--site {
    background: rgba(14, 165, 233, 0.16);
    color: #0c4a6e;
}

.badge--assignment {
    background: rgba(251, 146, 60, 0.18);
    color: #9a3412;
}

.badge--quiz {
    background: rgba(167, 139, 250, 0.18);
    color: #5b21b6;
}

.badge--lesson {
    background: rgba(45, 212, 191, 0.18);
    color: #115e59;
}

.parent-calendar__empty {
    display: flex;
    align-items: center;
    gap: 20px;
    padding: 32px 28px;
    background: linear-gradient(135deg, rgba(241, 245, 249, 0.8), rgba(226, 232, 240, 0.6));
    border-radius: 22px;
    color: #64748b;
    font-size: 15px;
    font-weight: 600;
    border: 2px dashed rgba(148, 163, 184, 0.5);
    box-shadow: inset 0 2px 8px rgba(0, 0, 0, 0.05);
    position: relative;
    overflow: hidden;
}

.parent-calendar__empty::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -50%;
    width: 200px;
    height: 200px;
    background: radial-gradient(circle, rgba(59, 130, 246, 0.1) 0%, transparent 70%);
    border-radius: 50%;
    filter: blur(20px);
}

.parent-calendar__empty i {
    font-size: 36px;
    color: #3b82f6;
    position: relative;
    z-index: 1;
    opacity: 0.7;
}

.parent-calendar__empty p {
    margin: 0;
    position: relative;
    z-index: 1;
    line-height: 1.6;
}

@media (max-width: 1200px) {
    .parent-calendar__grid {
        gap: 12px;
    }

    .parent-calendar__cell {
        min-height: 120px;
    }
}

@media (max-width: 992px) {
    .parent-calendar-section {
        padding: 28px 24px;
    }

    .parent-calendar__actions {
        width: 100%;
        justify-content: flex-start;
    }

    .parent-calendar__grid {
        gap: 10px;
    }
}

@media (max-width: 768px) {
    .parent-calendar-section {
        margin-left: 0;
        border-radius: 22px;
    }

    .parent-calendar__header {
        flex-direction: column;
        align-items: flex-start;
    }

    .parent-calendar__grid {
        gap: 8px;
    }

    .parent-calendar__cell {
        padding: 14px;
        min-height: 110px;
    }

    .event-chip {
        flex-direction: column;
        align-items: flex-start;
        gap: 4px;
    }
}

@media (max-width: 576px) {
    .parent-calendar__grid {
        grid-template-columns: repeat(2, 1fr);
    }

    .parent-calendar__weekday {
        display: none;
    }
}
</style>

<!-- Main Content Area -->
<div class="parent-main-content">
    <div class="parent-content-wrapper">
        
        <!-- Enhanced Breadcrumb Navigation -->
        <nav class="parent-breadcrumb">
            <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                <a href="<?php echo $CFG->wwwroot; ?>/my/" class="breadcrumb-link">
                    <i class="fas fa-home" style="font-size: 13px;"></i> Home
                </a>
                <i class="fas fa-chevron-right breadcrumb-separator" style="color: #cbd5e1; font-size: 11px; margin: 0 4px;"></i>
                <span class="breadcrumb-current">Parents Dashboard</span>
                <?php if ($selected_child_id && $selected_child_id !== 'all' && $selected_child_id != 0): 
                    $filtered_child_name = '';
                    foreach ($children as $child) {
                        if ($child['id'] == $selected_child_id) {
                            $filtered_child_name = $child['name'];
                            break;
                        }
                    }
                ?>
                <span style="display: inline-flex; align-items: center; gap: 8px; margin-left: 16px; padding: 5px 14px; background: #f8f9fa; color: #495057; border-radius: 6px; font-size: 12px; font-weight: 600; border: 1px solid #dee2e6;">
                    <i class="fas fa-filter" style="font-size: 10px; color: #6c757d;"></i>
                    <span><?php echo htmlspecialchars($filtered_child_name); ?></span>
                </span>
                <?php endif; ?>
            </div>
        </nav>

        <!-- Enhanced Child Selection Cards -->
        <?php if (!empty($children)): ?>
        <div class="parent-section">
            <h2 class="section-title">
                <i class="fas fa-users"></i>
                Select Your Child
            </h2>
            
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 18px;">
                <?php foreach ($children as $child): 
                    $is_selected = ($selected_child_id == $child['id']);
                ?>
                <div onclick="selectChild(<?php echo intval($child['id']); ?>)" 
                     data-child-id="<?php echo intval($child['id']); ?>"
                     class="child-card modern-card <?php echo $is_selected ? 'selected' : ''; ?>"
                     style="cursor: pointer; text-align: center; <?php echo $is_selected ? 'border: 2px solid #3b82f6; background: linear-gradient(135deg, #ffffff 0%, #f0f9ff 100%);' : ''; ?>">
                    
                    <?php if ($is_selected): ?>
                    <div style="position: absolute; top: 10px; right: 10px; background: linear-gradient(135deg, #3b82f6, #8b5cf6); color: white; padding: 5px 12px; border-radius: 8px; font-size: 10px; font-weight: 700; z-index: 10; text-transform: uppercase; letter-spacing: 0.5px; box-shadow: 0 2px 8px rgba(59, 130, 246, 0.4);">
                        <i class="fas fa-check-circle" style="margin-right: 4px; font-size: 9px;"></i> Active
                    </div>
                    <?php endif; ?>
                    
                    <!-- Compact Circle Avatar -->
                    <div style="width: 80px; height: 80px; background: linear-gradient(135deg, <?php echo $child['avatar_color']; ?>, <?php echo str_replace(['#'], ['#'], $child['avatar_color']); ?>dd); border-radius: 50%; margin: 0 auto 16px; display: flex; align-items: center; justify-content: center; font-size: 32px; font-weight: 700; color: white; box-shadow: 0 4px 16px rgba(0,0,0,0.12), inset 0 2px 4px rgba(255,255,255,0.2); position: relative; z-index: 2; transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1); border: 3px solid rgba(255,255,255,0.3); overflow: hidden;">
                        <?php if ($child['has_profile_picture']): ?>
                            <img src="<?php echo htmlspecialchars($child['profile_picture_url']); ?>" alt="<?php echo htmlspecialchars($child['name']); ?>" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
                        <?php else: ?>
                        <?php echo strtoupper(substr($child['name'], 0, 1)); ?>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Child Name -->
                    <h3 style="margin: 0 0 8px 0; font-size: 18px; font-weight: 700; color: #0f172a; position: relative; z-index: 2; line-height: 1.3; letter-spacing: -0.3px;">
                        <?php echo htmlspecialchars($child['name']); ?>
                    </h3>
                    
                    <!-- Class Info -->
                    <div style="margin: 0 0 12px 0; padding: 6px 12px; background: linear-gradient(135deg, #f8fafc, #f1f5f9); border-radius: 8px; border: 1px solid #e2e8f0; position: relative; z-index: 2; display: inline-flex; align-items: center; gap: 6px;">
                        <i class="fas fa-graduation-cap" style="font-size: 11px; color: #3b82f6;"></i>
                        <span style="font-size: 12px; color: #475569; font-weight: 600;">
                            Class <?php echo htmlspecialchars($child['class']); ?> - <?php echo htmlspecialchars($child['section']); ?>
                        </span>
                    </div>
                    
                    <!-- Course Count Badge -->
                    <div style="background: linear-gradient(135deg, #eff6ff, #dbeafe); color: #1e40af; padding: 6px 14px; border-radius: 8px; font-size: 11px; font-weight: 700; display: inline-block; margin-bottom: 14px; border: 2px solid #bfdbfe; position: relative; z-index: 2; box-shadow: 0 2px 6px rgba(59, 130, 246, 0.15);">
                        <i class="fas fa-book" style="margin-right: 6px; font-size: 11px;"></i> 
                        <span><?php echo $child['course_count']; ?></span> 
                        <span style="font-weight: 500;">Course<?php echo $child['course_count'] != 1 ? 's' : ''; ?></span>
                    </div>
                    
                    <!-- View Profile Button -->
                    <div style="margin-top: 12px; position: relative; z-index: 2;">
                        <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/parent/parent_profile.php?child=<?php echo intval($child['id']); ?>" 
                           onclick="event.stopPropagation();"
                           style="display: block; background: linear-gradient(135deg, #3b82f6, #8b5cf6); color: white; padding: 10px 18px; border-radius: 10px; text-decoration: none; font-size: 12px; font-weight: 700; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); box-shadow: 0 2px 8px rgba(59, 130, 246, 0.3);"
                           onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 16px rgba(59, 130, 246, 0.4)';"
                           onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 8px rgba(59, 130, 246, 0.3)';">
                            <i class="fas fa-user-circle" style="margin-right: 6px;"></i> View Profile
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <?php 
            // Enhanced selection status banner
            if ($selected_child_id && $selected_child_id !== 'all' && $selected_child_id != 0):
                $selected_child_name = '';
                foreach ($children as $child) {
                    if ($child['id'] == $selected_child_id) {
                        $selected_child_name = $child['name'];
                        break;
                    }
                }
            ?>
            <div class="modern-card" style="margin-top: 24px; background: linear-gradient(135deg, #ffffff 0%, #f0f9ff 100%); border: 2px solid #3b82f6; position: relative; overflow: hidden; padding: 16px;">
                <div style="position: absolute; top: 0; right: 0; width: 120px; height: 120px; background: radial-gradient(circle, rgba(59, 130, 246, 0.1) 0%, transparent 70%); border-radius: 50%; transform: translate(30%, -30%);"></div>
                <div style="display: flex; align-items: center; gap: 14px; position: relative; z-index: 1; flex-wrap: wrap;">
                    <div style="width: 48px; height: 48px; background: linear-gradient(135deg, #3b82f6, #8b5cf6); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-size: 20px; box-shadow: 0 4px 16px rgba(59, 130, 246, 0.3); flex-shrink: 0;">
                        <i class="fas fa-user-check"></i>
                    </div>
                    <div style="flex: 1; min-width: 200px;">
                        <div style="font-size: 10px; color: #3b82f6; margin-bottom: 4px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.6px; display: flex; align-items: center; gap: 6px;">
                            <i class="fas fa-eye" style="font-size: 9px;"></i> Currently Viewing
                        </div>
                        <div style="font-size: 20px; font-weight: 700; color: #0f172a; margin-bottom: 4px; line-height: 1.2; letter-spacing: -0.3px;"><?php echo htmlspecialchars($selected_child_name); ?></div>
                        <div style="font-size: 12px; color: #64748b; margin-top: 2px; font-weight: 500; line-height: 1.4;">All dashboard sections are filtered to show this child's data only</div>
                    </div>
                    <button onclick="clearSelection()" 
                            style="background: linear-gradient(135deg, #ffffff, #f8fafc); border: 2px solid #cbd5e1; color: #475569; padding: 8px 16px; border-radius: 10px; font-weight: 600; cursor: pointer; font-size: 12px; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); display: flex; align-items: center; gap: 6px; box-shadow: 0 2px 6px rgba(0,0,0,0.05);"
                            onmouseover="this.style.background='linear-gradient(135deg, #f8fafc, #f1f5f9)'; this.style.borderColor='#94a3b8'; this.style.color='#0f172a'; this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 10px rgba(0,0,0,0.1)';"
                            onmouseout="this.style.background='linear-gradient(135deg, #ffffff, #f8fafc)'; this.style.borderColor='#cbd5e1'; this.style.color='#475569'; this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 6px rgba(0,0,0,0.05)';">
                        <i class="fas fa-times" style="font-size: 10px;"></i> View All
                    </button>
                </div>
            </div>
            <?php else: ?>
            <div class="modern-card" style="margin-top: 24px; background: linear-gradient(135deg, #e0f2fe 0%, #dbeafe 100%); border: 2px solid #93c5fd; position: relative; overflow: hidden; padding: 16px;">
                <div style="position: absolute; top: -30px; right: -30px; width: 120px; height: 120px; background: radial-gradient(circle, rgba(59, 130, 246, 0.15) 0%, transparent 70%); border-radius: 50%; filter: blur(20px);"></div>
                <div style="position: absolute; bottom: -20px; left: -20px; width: 100px; height: 100px; background: radial-gradient(circle, rgba(139, 92, 246, 0.1) 0%, transparent 70%); border-radius: 50%; filter: blur(20px);"></div>
                <div style="display: flex; align-items: center; gap: 16px; position: relative; z-index: 1; flex-wrap: wrap;">
                    <div style="width: 56px; height: 56px; background: linear-gradient(135deg, #60a5fa, #3b82f6); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-size: 24px; box-shadow: 0 4px 16px rgba(96,165,250,0.4); flex-shrink: 0;">
                        <i class="fas fa-hand-pointer"></i>
                    </div>
                    <div style="flex: 1; min-width: 200px;">
                        <div style="font-size: 11px; color: #1e40af; margin-bottom: 4px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.6px;">No Child Selected</div>
                        <div style="font-size: 22px; font-weight: 800; color: #1e3a8a; margin-bottom: 4px; letter-spacing: -0.3px;">Select a Child Above</div>
                        <div style="font-size: 12px; color: #1e40af; margin-top: 2px; font-weight: 600; line-height: 1.4;">Click on a child card above to view their academic progress, courses, and learning activities</div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- NEW: Your Schedule & Recent Resources Section -->
        <?php if ($selected_child_id && $selected_child_id !== 'all' && $selected_child_id != 0): ?>
        <div class="parent-section" style="margin-top: 24px;">
            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 24px;">
                
                <!-- Your Schedule -->
                <div class="modern-card" style="padding: 28px; background: linear-gradient(135deg, #e0f2fe 0%, #ddd6fe 100%); color: #1e293b; position: relative; overflow: hidden; border: 1px solid #e0e7ff; box-shadow: 0 4px 20px rgba(59, 130, 246, 0.2);">
                    <!-- Decorative elements -->
                    <div style="position: absolute; top: -50px; right: -50px; width: 150px; height: 150px; background: rgba(147,197,253,0.2); border-radius: 50%;"></div>
                    <div style="position: absolute; bottom: -30px; left: -30px; width: 100px; height: 100px; background: rgba(196,181,253,0.2); border-radius: 50%;"></div>
                    
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; position: relative; z-index: 1;">
                        <h2 style="font-size: 24px; font-weight: 800; margin: 0; display: flex; align-items: center; gap: 12px; color: #3730a3;">
                            <i class="fas fa-calendar-check" style="font-size: 28px; color: #6366f1;"></i>
                            Your Schedule
                        </h2>
                        <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/parent/parent_schedule.php" style="color: #4f46e5; text-decoration: none; font-size: 14px; font-weight: 700; display: flex; align-items: center; gap: 6px; background: rgba(99,102,241,0.1); padding: 8px 16px; border-radius: 20px; transition: all 0.3s; border: 1px solid rgba(99,102,241,0.2);" onmouseover="this.style.background='rgba(99,102,241,0.2)'; this.style.transform='translateX(2px)';" onmouseout="this.style.background='rgba(99,102,241,0.1)'; this.style.transform='translateX(0)';">
                            View Full Calendar <i class="fas fa-arrow-right" style="font-size: 12px;"></i>
                        </a>
                    </div>
                    
                    <!-- Calendar Navigation -->
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 12px;">
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <?php
                            // Calculate week navigation URLs
                            $prev_week_url = new moodle_url($PAGE->url, ['week_offset' => ($week_offset - 1)]);
                            $next_week_url = new moodle_url($PAGE->url, ['week_offset' => ($week_offset + 1)]);
                            $current_week_url = new moodle_url($PAGE->url, ['week_offset' => 0]);
                            ?>
                            <a href="<?php echo $prev_week_url->out(); ?>" style="background: #f1f5f9; border: none; border-radius: 8px; padding: 8px 12px; cursor: pointer; color: #475569; text-decoration: none; display: inline-flex; align-items: center;">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                            <span style="font-size: 14px; font-weight: 600; color: #0f172a;">
                                <?php 
                                // Calculate week range based on offset
                                // Week offset: 0 = this week, -1 = last week, +1 = next week
                                $base_week_start = strtotime('today');
                                $week_start = strtotime(($week_offset * 7) . ' days', $base_week_start);
                                $week_end = strtotime('+6 days', $week_start);
                                echo date('M j', $week_start) . ' - ' . date('j, Y', $week_end); 
                                ?>
                            </span>
                            <a href="<?php echo $next_week_url->out(); ?>" style="background: #f1f5f9; border: none; border-radius: 8px; padding: 8px 12px; cursor: pointer; color: #475569; text-decoration: none; display: inline-flex; align-items: center;">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                            <?php if ($week_offset != 0): ?>
                            <a href="<?php echo $current_week_url->out(); ?>" style="background: linear-gradient(135deg, #3b82f6, #8b5cf6); color: white; border: none; border-radius: 8px; padding: 6px 12px; cursor: pointer; font-size: 12px; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; transition: all 0.2s; box-shadow: 0 2px 8px rgba(59, 130, 246, 0.3);" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 12px rgba(59, 130, 246, 0.4)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 8px rgba(59, 130, 246, 0.3)';">
                                <i class="fas fa-calendar-day" style="font-size: 10px;"></i> This Week
                            </a>
                            <?php endif; ?>
                        </div>
                        
                    </div>
                    
                    <!-- Weekly Calendar Grid (Week View with Navigation) -->
                    <div style="display: grid; grid-template-columns: repeat(7, 1fr); gap: 10px;">
                        <?php
                        // ✅ Show WEEK based on offset (0 = this week, -1 = last week, +1 = next week)
                        $week_days = ['SUN', 'MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT'];
                        $actual_today = strtotime('today'); // Actual today for comparison
                        $actual_today_ymd = date('Y-m-d', $actual_today);
                        
                        // Calculate week start based on offset
                        $base_week_start = strtotime('today');
                        $week_display_start = strtotime(($week_offset * 7) . ' days', $base_week_start);
                        
                        // Loop through 7 days starting from the calculated week start
                        for ($i = 0; $i < 7; $i++) {
                            $day_timestamp = strtotime("+$i days", $week_display_start);
                            $day_num = (int)date('j', $day_timestamp);
                            $day_month_num = (int)date('n', $day_timestamp);
                            $day_year = (int)date('Y', $day_timestamp);
                            $day_of_week = (int)date('w', $day_timestamp); // 0=Sun, 6=Sat
                            $day_ymd = date('Y-m-d', $day_timestamp);
                            
                            // Check if this day is actually TODAY
                            $is_today = ($day_ymd === $actual_today_ymd);
                            
                            // ✅ Get events for this SPECIFIC day by matching full date
                            $day_events = [];
                            if (!empty($child_calendar_events)) {
                                foreach ($child_calendar_events as $event) {
                                    $event_date = date('Y-m-d', $event->timestart);
                                    $current_date = date('Y-m-d', $day_timestamp);
                                    if ($event_date === $current_date) {
                                        $day_events[] = $event;
                                    }
                                }
                            }
                            $has_events = !empty($day_events);
                        ?>
                        <div style="background: <?php echo $is_today ? '#dbeafe' : '#ffffff'; ?>; border: 2px solid <?php echo $is_today ? '#3b82f6' : '#e2e8f0'; ?>; border-radius: 10px; padding: 12px; min-height: 120px; display: flex; flex-direction: column; box-shadow: <?php echo $is_today ? '0 4px 12px rgba(59, 130, 246, 0.2)' : '0 1px 3px rgba(0, 0, 0, 0.04)'; ?>; transition: all 0.2s;" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='<?php echo $is_today ? '0 6px 16px rgba(59, 130, 246, 0.3)' : '0 2px 8px rgba(0, 0, 0, 0.08)'; ?>';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='<?php echo $is_today ? '0 4px 12px rgba(59, 130, 246, 0.2)' : '0 1px 3px rgba(0, 0, 0, 0.04)'; ?>';">
                            <div style="text-align: center; margin-bottom: 10px;">
                                <div style="font-size: 10px; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;"><?php echo $week_days[$day_of_week]; ?></div>
                                <div style="font-size: 22px; font-weight: 700; color: <?php echo $is_today ? '#3b82f6' : '#0f172a'; ?>; margin: 4px 0;"><?php echo $day_num; ?></div>
                                <div style="font-size: 9px; color: #94a3b8; font-weight: 600; text-transform: uppercase;"><?php echo strtoupper(date('F', $day_timestamp)); ?></div>
                            </div>
                            
                            <?php if ($has_events): ?>
                                <?php 
                                // Sort events by time for this day
                                usort($day_events, function($a, $b) {
                                    return $a->timestart <=> $b->timestart;
                                });
                                $event_count = 0;
                                foreach ($day_events as $event): 
                                    if ($event_count >= 2) break; // Show max 2 events per day
                                    $event_count++;
                                    
                                    // Color based on event type - using real colors
                                    $event_color = '#3b82f6'; // Default blue
                                    $event_bg = 'rgba(59, 130, 246, 0.1)';
                                    $event_icon = 'fa-calendar';
                                    $event_url = '';
                                    if (!empty($event->source)) {
                                        switch($event->source) {
                                            case 'assign':
                                                $event_color = '#f97316'; // Orange for assignments
                                                $event_bg = 'rgba(249, 115, 22, 0.1)';
                                                $event_icon = 'fa-tasks';
                                                // Generate assignment URL
                                                if (preg_match('/assign_(\d+)/', $event->id, $matches)) {
                                                    $assign_id = $matches[1];
                                                    $cm = $DB->get_record_sql("SELECT cm.id, cm.course FROM {course_modules} cm JOIN {modules} m ON m.id = cm.module WHERE m.name = 'assign' AND cm.instance = :instanceid", ['instanceid' => $assign_id]);
                                                    if ($cm && !empty($selected_child_id) && $selected_child_id !== 'all' && $selected_child_id != 0) {
                                                        $event_url = (new moodle_url('/theme/remui_kids/parent/parent_activity_preview.php', [
                                                            'cmid' => $cm->id,
                                                            'child' => $selected_child_id,
                                                            'courseid' => $cm->course
                                                        ]))->out();
                                                    }
                                                }
                                                break;
                                            case 'quiz':
                                                $event_color = '#8b5cf6'; // Purple for quizzes
                                                $event_bg = 'rgba(139, 92, 246, 0.1)';
                                                $event_icon = 'fa-clipboard-check';
                                                // Generate quiz URL
                                                if (preg_match('/quiz_(\d+)/', $event->id, $matches)) {
                                                    $quiz_id = $matches[1];
                                                    $cm = $DB->get_record_sql("SELECT cm.id, cm.course FROM {course_modules} cm JOIN {modules} m ON m.id = cm.module WHERE m.name = 'quiz' AND cm.instance = :instanceid", ['instanceid' => $quiz_id]);
                                                    if ($cm && !empty($selected_child_id) && $selected_child_id !== 'all' && $selected_child_id != 0) {
                                                        $event_url = (new moodle_url('/theme/remui_kids/parent/parent_activity_preview.php', [
                                                            'cmid' => $cm->id,
                                                            'child' => $selected_child_id,
                                                            'courseid' => $cm->course
                                                        ]))->out();
                                                    }
                                                }
                                                break;
                                            case 'lesson':
                                                $event_color = '#10b981'; // Green for lessons
                                                $event_bg = 'rgba(16, 185, 129, 0.1)';
                                                $event_icon = 'fa-book';
                                                // Generate lesson URL
                                                if (preg_match('/lesson_(\d+)/', $event->id, $matches)) {
                                                    $lesson_id = $matches[1];
                                                    $cm = $DB->get_record_sql("SELECT cm.id, cm.course FROM {course_modules} cm JOIN {modules} m ON m.id = cm.module WHERE m.name = 'lesson' AND cm.instance = :instanceid", ['instanceid' => $lesson_id]);
                                                    if ($cm && !empty($selected_child_id) && $selected_child_id !== 'all' && $selected_child_id != 0) {
                                                        $event_url = (new moodle_url('/theme/remui_kids/parent/parent_activity_preview.php', [
                                                            'cmid' => $cm->id,
                                                            'child' => $selected_child_id,
                                                            'courseid' => $cm->course
                                                        ]))->out();
                                                    }
                                                }
                                                break;
                                            case 'event':
                                                $event_color = '#3b82f6';
                                                $event_bg = 'rgba(59, 130, 246, 0.1)';
                                                $event_icon = 'fa-calendar';
                                                break;
                                        }
                                    }
                                ?>
                                <div onclick="showEventDetails('<?php echo htmlspecialchars($event->name, ENT_QUOTES); ?>', '<?php echo date('F j, Y g:i A', $event->timestart); ?>', '<?php echo htmlspecialchars($event->coursename ?? 'N/A', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($event->source ?? 'event', ENT_QUOTES); ?>', '<?php echo !empty($event_url) ? htmlspecialchars($event_url, ENT_QUOTES) : ''; ?>')" style="background: <?php echo $event_bg; ?>; border-left: 3px solid <?php echo $event_color; ?>; padding: 6px 8px; border-radius: 6px; margin-bottom: 6px; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04); cursor: pointer; transition: all 0.2s;" onmouseover="this.style.transform='translateX(2px)'; this.style.boxShadow='0 2px 6px rgba(0, 0, 0, 0.08)';" onmouseout="this.style.transform='translateX(0)'; this.style.boxShadow='0 1px 3px rgba(0, 0, 0, 0.04)';" title="Click to view details">
                                    <div style="display: flex; align-items: center; gap: 6px; margin-bottom: 2px;">
                                        <i class="fas <?php echo $event_icon; ?>" style="font-size: 10px; color: <?php echo $event_color; ?>;"></i>
                                        <span style="font-size: 9px; color: #64748b; font-weight: 600;"><?php echo date('g:i A', $event->timestart); ?></span>
                                    </div>
                                    <div style="font-size: 10px; color: #0f172a; font-weight: 600; line-height: 1.3; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php echo htmlspecialchars($event->name); ?>"><?php echo htmlspecialchars(substr($event->name, 0, 25)); ?></div>
                                    <?php if (!empty($event->coursename)): ?>
                                    <div style="font-size: 9px; color: #64748b; margin-top: 2px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php echo htmlspecialchars($event->coursename); ?>"><?php echo htmlspecialchars(substr($event->coursename, 0, 18)); ?></div>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                                <?php if (count($day_events) > 2): ?>
                                <div style="text-align: center; margin-top: 4px;">
                                    <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/parent/parent_schedule.php?date=<?php echo date('Y-m-d', $day_timestamp); ?>" style="font-size: 9px; color: #3b82f6; font-weight: 600; text-decoration: none;" onmouseover="this.style.color='#2563eb';" onmouseout="this.style.color='#3b82f6';">+<?php echo (count($day_events) - 2); ?> more</a>
                                </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <div style="flex: 1; display: flex; align-items: center; justify-content: center;">
                                    <span style="font-size: 10px; color: #94a3b8; font-weight: 500;">No events</span>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php } ?>
                    </div>
                </div>
                
                <!-- Recent Resources -->
                <div class="modern-card" style="padding: 28px; background: linear-gradient(135deg, #fce7f3 0%, #fef3c7 100%); color: #1e293b; position: relative; overflow: hidden; border: 1px solid #fde68a; box-shadow: 0 4px 20px rgba(251, 207, 232, 0.3);">
                    <!-- Decorative elements -->
                    <div style="position: absolute; top: -40px; right: -40px; width: 120px; height: 120px; background: rgba(251,207,232,0.3); border-radius: 50%;"></div>
                    <div style="position: absolute; bottom: -20px; left: -20px; width: 80px; height: 80px; background: rgba(254,240,138,0.3); border-radius: 50%;"></div>
                    
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; position: relative; z-index: 1;">
                        <h2 style="font-size: 22px; font-weight: 800; margin: 0; display: flex; align-items: center; gap: 10px; color: #be185d;">
                            <i class="fas fa-folder-open" style="font-size: 24px; color: #ec4899;"></i>
                            Recent Resources
                        </h2>
                        <?php if (!empty($recent_resources)): ?>
                        <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/parent/parent_reports.php" style="color: #be185d; text-decoration: none; font-size: 13px; font-weight: 700; display: flex; align-items: center; gap: 6px; background: rgba(236,72,153,0.1); padding: 6px 14px; border-radius: 18px; transition: all 0.3s; border: 1px solid rgba(236,72,153,0.2);" onmouseover="this.style.background='rgba(236,72,153,0.2)'; this.style.transform='translateX(2px)';" onmouseout="this.style.background='rgba(236,72,153,0.1)'; this.style.transform='translateX(0)';">
                            View All <i class="fas fa-arrow-right" style="font-size: 11px;"></i>
                        </a>
                        <?php endif; ?>
                    </div>
                    
                    <?php if (!empty($recent_resources)): ?>
                        <?php foreach ($recent_resources as $resource): ?>
                        <?php
                        $icon_class = 'fa-file';
                        $icon_color = '#64748b';
                        if (strpos($resource['mimetype'], 'pdf') !== false) {
                            $icon_class = 'fa-file-pdf';
                            $icon_color = '#ef4444';
                        } elseif (strpos($resource['mimetype'], 'powerpoint') !== false || strpos($resource['mimetype'], 'presentation') !== false) {
                            $icon_class = 'fa-file-powerpoint';
                            $icon_color = '#f97316';
                        } elseif (strpos($resource['mimetype'], 'word') !== false || strpos($resource['mimetype'], 'document') !== false) {
                            $icon_class = 'fa-file-word';
                            $icon_color = '#3b82f6';
                        }
                        
                        $filesize_mb = round($resource['filesize'] / 1048576, 1);
                        if ($filesize_mb < 0.1) {
                            $filesize_kb = round($resource['filesize'] / 1024, 1);
                            $filesize_display = $filesize_kb . ' KB';
                        } else {
                            $filesize_display = $filesize_mb . ' MB';
                        }
                        ?>
                        <div style="background: #ffffff; border: 1px solid #fce7f3; border-radius: 10px; padding: 14px; margin-bottom: 12px; display: flex; align-items: center; gap: 12px; transition: all 0.2s; box-shadow: 0 2px 6px rgba(251, 207, 232, 0.2); cursor: pointer;" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 12px rgba(251, 207, 232, 0.3)'; this.style.borderColor='#f9a8d4';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 6px rgba(251, 207, 232, 0.2)'; this.style.borderColor='#fce7f3';" onclick="<?php echo !empty($resource['file_url']) ? "window.open('" . htmlspecialchars($resource['file_url']) . "', '_blank')" : (!empty($resource['cmid']) ? "window.open('" . $CFG->wwwroot . "/mod/resource/view.php?id=" . $resource['cmid'] . "', '_blank')" : ''); ?>">
                            <div style="width: 40px; height: 40px; background: linear-gradient(135deg, <?php echo $icon_color; ?>20, <?php echo $icon_color; ?>10); border: 2px solid <?php echo $icon_color; ?>40; border-radius: 8px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                <i class="fas <?php echo $icon_class; ?>" style="font-size: 18px; color: <?php echo $icon_color; ?>;"></i>
                            </div>
                            <div style="flex: 1; min-width: 0;">
                                <div style="font-size: 12px; font-weight: 600; color: #0f172a; margin-bottom: 3px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php echo htmlspecialchars($resource['filename']); ?>"><?php echo htmlspecialchars($resource['filename']); ?></div>
                                <div style="font-size: 10px; color: #64748b; display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                    <span style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 120px;" title="<?php echo htmlspecialchars($resource['coursename']); ?>"><?php echo htmlspecialchars(substr($resource['coursename'], 0, 18)); ?><?php echo strlen($resource['coursename']) > 18 ? '...' : ''; ?></span>
                                    <span style="color: #f9a8d4;">•</span>
                                    <span><?php echo $filesize_display; ?></span>
                                    <span style="color: #f9a8d4;">•</span>
                                    <span><?php echo date('M j, Y', $resource['timecreated']); ?></span>
                                </div>
                            </div>
                            <div style="display: flex; gap: 6px;">
                                <?php if (!empty($resource['file_url'])): ?>
                                <a href="<?php echo htmlspecialchars($resource['file_url']); ?>" target="_blank" onclick="event.stopPropagation();" style="background: linear-gradient(135deg, #dbeafe, #bfdbfe); border: 1px solid #93c5fd; border-radius: 6px; padding: 6px 10px; cursor: pointer; color: #3b82f6; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; transition: all 0.2s; box-shadow: 0 1px 3px rgba(59, 130, 246, 0.2);" title="View" onmouseover="this.style.background='linear-gradient(135deg, #bfdbfe, #93c5fd)'; this.style.transform='scale(1.1)'; this.style.boxShadow='0 2px 6px rgba(59, 130, 246, 0.3)';" onmouseout="this.style.background='linear-gradient(135deg, #dbeafe, #bfdbfe)'; this.style.transform='scale(1)'; this.style.boxShadow='0 1px 3px rgba(59, 130, 246, 0.2)';">
                                    <i class="fas fa-eye" style="font-size: 10px;"></i>
                                </a>
                                <a href="<?php echo htmlspecialchars($resource['file_url']); ?>?download=1" onclick="event.stopPropagation();" style="background: linear-gradient(135deg, #d1fae5, #a7f3d0); border: 1px solid #6ee7b7; border-radius: 6px; padding: 6px 10px; cursor: pointer; color: #10b981; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; transition: all 0.2s; box-shadow: 0 1px 3px rgba(16, 185, 129, 0.2);" title="Download" onmouseover="this.style.background='linear-gradient(135deg, #a7f3d0, #6ee7b7)'; this.style.transform='scale(1.1)'; this.style.boxShadow='0 2px 6px rgba(16, 185, 129, 0.3)';" onmouseout="this.style.background='linear-gradient(135deg, #d1fae5, #a7f3d0)'; this.style.transform='scale(1)'; this.style.boxShadow='0 1px 3px rgba(16, 185, 129, 0.2)';">
                                    <i class="fas fa-download" style="font-size: 10px;"></i>
                                </a>
                                <?php elseif (!empty($resource['cmid'])): ?>
                                <a href="<?php echo $CFG->wwwroot; ?>/mod/resource/view.php?id=<?php echo $resource['cmid']; ?>" target="_blank" onclick="event.stopPropagation();" style="background: linear-gradient(135deg, #dbeafe, #bfdbfe); border: 1px solid #93c5fd; border-radius: 6px; padding: 6px 10px; cursor: pointer; color: #3b82f6; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; transition: all 0.2s; box-shadow: 0 1px 3px rgba(59, 130, 246, 0.2);" title="View" onmouseover="this.style.background='linear-gradient(135deg, #bfdbfe, #93c5fd)'; this.style.transform='scale(1.1)'; this.style.boxShadow='0 2px 6px rgba(59, 130, 246, 0.3)';" onmouseout="this.style.background='linear-gradient(135deg, #dbeafe, #bfdbfe)'; this.style.transform='scale(1)'; this.style.boxShadow='0 1px 3px rgba(59, 130, 246, 0.2)';">
                                    <i class="fas fa-external-link-alt" style="font-size: 10px;"></i>
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="text-align: center; padding: 40px 20px; color: #94a3b8;">
                            <i class="fas fa-folder-open" style="font-size: 48px; margin-bottom: 12px; opacity: 0.5; color: #f9a8d4;"></i>
                            <p style="margin: 0; font-size: 13px; font-weight: 500; color: #be185d;">No resources available</p>
                            <p style="margin: 8px 0 0 0; font-size: 11px; color: #ec4899;">Resources from your child's courses will appear here</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- NEW: Recent Submissions & Quiz Completions -->
        <div class="parent-section" style="margin-top: 24px;">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
                
                <!-- Recent Assignment Submissions -->
                <div class="modern-card" style="padding: 28px; background: linear-gradient(135deg, #dbeafe 0%, #e0f2fe 100%); color: #1e293b; position: relative; overflow: hidden; border: 1px solid #bfdbfe; box-shadow: 0 4px 20px rgba(191, 219, 254, 0.4);">
                    <!-- Decorative elements -->
                    <div style="position: absolute; top: -30px; right: -30px; width: 100px; height: 100px; background: rgba(147,197,253,0.3); border-radius: 50%; animation: float 3s ease-in-out infinite;"></div>
                    <div style="position: absolute; bottom: -20px; left: -20px; width: 70px; height: 70px; background: rgba(186,230,253,0.3); border-radius: 50%; animation: float 4s ease-in-out infinite;"></div>
                    
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; position: relative; z-index: 1;">
                        <h2 style="font-size: 20px; font-weight: 800; margin: 0; display: flex; align-items: center; gap: 10px; color: #0369a1;">
                            <i class="fas fa-file-upload" style="font-size: 22px; color: #0ea5e9;"></i>
                            Recent Assignments
                        </h2>
                        <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/parent/parent_reports.php" style="color: #0369a1; text-decoration: none; font-size: 13px; font-weight: 700; display: flex; align-items: center; gap: 6px; background: rgba(14,165,233,0.1); padding: 6px 14px; border-radius: 18px; transition: all 0.3s; border: 1px solid rgba(14,165,233,0.2);">
                            View All <i class="fas fa-chevron-right" style="font-size: 11px;"></i>
                        </a>
                    </div>
                    
                    <?php if (!empty($recent_submissions)): ?>
                        <?php foreach (array_slice($recent_submissions, 0, 5) as $submission): ?>
                        <?php
                        // Get child info from stats_children
                        $child_info = null;
                        foreach ($stats_children as $child) {
                            if ($child->id == $submission->userid) {
                                $child_info = $child;
                                break;
                            }
                        }
                        
                        // Get profile picture
                        $profile_picture_url = '';
                        $has_profile_picture = false;
                        if ($child_info && isset($child_info->picture) && $child_info->picture > 0) {
                            try {
                                // Ensure child_info has all required fields for user_picture
                                if (!property_exists($child_info, 'firstnamephonetic') || !property_exists($child_info, 'lastnamephonetic')) {
                                    $child_info = $DB->get_record('user', ['id' => $child_info->id], 
                                        implode(',', \core_user\fields::get_picture_fields()), MUST_EXIST);
                                }
                                if ($child_info) {
                                $user_picture = new user_picture($child_info);
                                $user_picture->size = 1;
                                $profile_picture_url = $user_picture->get_url($PAGE)->out(false);
                                $has_profile_picture = true;
                                }
                            } catch (Exception $e) {}
                        }
                        
                        // Determine status and grade display
                        $status_color = '#10b981';
                        $status_bg = '#d1fae5';
                        $status_text = 'SUBMITTED';
                        
                        // Upcoming assignment (no submission yet)
                        if (isset($submission->status) && $submission->status === 'upcoming') {
                            $status_text  = 'DUE SOON';
                            $status_color = '#3b82f6';
                            $status_bg    = '#dbeafe';
                        } else {
                            // Check if graded
                            if (isset($submission->grade) && $submission->grade !== null && $submission->grade > 0) {
                                $status_text = round($submission->grade, 1) . '%';
                                if ($submission->grade >= 80) {
                                    $status_color = '#10b981'; // Green
                                    $status_bg = '#d1fae5';
                                } elseif ($submission->grade >= 60) {
                                    $status_color = '#3b82f6'; // Blue
                                    $status_bg = '#dbeafe';
                                } else {
                                    $status_color = '#f59e0b'; // Orange
                                    $status_bg = '#fef3c7';
                                }
                            }
                        }
                        
                        $child_name = $child_info ? fullname($child_info) : fullname($submission);
                        ?>
                        <div style="display: flex; align-items: center; gap: 12px; padding: 14px 0; border-bottom: 1px solid #f1f5f9;">
                            <?php if ($has_profile_picture): ?>
                                <img src="<?php echo $profile_picture_url; ?>" alt="<?php echo htmlspecialchars($child_name); ?>" style="width: 40px; height: 40px; border-radius: 50%; object-fit: cover;">
                            <?php else: ?>
                                <?php
                                $initials = '';
                                if ($child_info) {
                                    $name_parts = explode(' ', fullname($child_info));
                                    $initials = strtoupper(substr($name_parts[0], 0, 1));
                                    if (count($name_parts) > 1) {
                                        $initials .= strtoupper(substr($name_parts[count($name_parts) - 1], 0, 1));
                                    }
                                }
                                $avatar_colors = ['#60a5fa', '#3b82f6', '#2563eb', '#8b5cf6', '#ec4899'];
                                $avatar_color = $avatar_colors[($child_info ? $child_info->id : 0) % count($avatar_colors)];
                                ?>
                                <div style="width: 40px; height: 40px; background: <?php echo $avatar_color; ?>; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-weight: 700; font-size: 14px;">
                                    <?php echo $initials; ?>
                                </div>
                            <?php endif; ?>
                            
                            <div style="flex: 1; min-width: 0;">
                                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px;">
                                    <span style="font-size: 13px; font-weight: 600; color: #0f172a;"><?php echo htmlspecialchars($child_name); ?></span>
                                    <span style="background: <?php echo $status_bg; ?>; color: <?php echo $status_color; ?>; padding: 2px 8px; border-radius: 12px; font-size: 10px; font-weight: 700;"><?php echo $status_text; ?></span>
                                </div>
                                <div style="font-size: 12px; font-weight: 600; color: #475569; margin-bottom: 2px;"><?php echo htmlspecialchars($submission->assignname); ?></div>
                                <div style="font-size: 10px; color: #94a3b8;"><?php echo htmlspecialchars($submission->coursename); ?></div>
                            </div>
                            
                            <div style="text-align: right; display: flex; flex-direction: column; align-items: flex-end; gap: 4px;">
                                <div style="font-size: 10px; color: #64748b; display: flex; align-items: center; gap: 4px;">
                                    <i class="fas fa-clock" style="font-size: 9px;"></i>
                                    <?php
                                    // For upcoming assignments, show due date instead of "time ago"
                                    if (isset($submission->status) && $submission->status === 'upcoming') {
                                        echo 'Due ' . date('M j, Y', $submission->timemodified);
                                    } else {
                                        $diff = time() - $submission->timemodified;
                                        if ($diff < 3600) {
                                            echo floor($diff / 60) . ' min ago';
                                        } elseif ($diff < 86400) {
                                            echo floor($diff / 3600) . ' hours ago';
                                        } else {
                                            echo date('M j, Y', $submission->timemodified);
                                        }
                                    }
                                    ?>
                                </div>
                                <i class="fas fa-arrow-right" style="color: #cbd5e1; font-size: 12px;"></i>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="text-align: center; padding: 60px 20px; color: #94a3b8;">
                            <i class="fas fa-clipboard-list" style="font-size: 48px; margin-bottom: 12px; opacity: 0.5;"></i>
                            <p style="margin: 0; font-size: 13px; font-weight: 500;">No recent submissions</p>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Recent Quiz Completions -->
                <div class="modern-card" style="padding: 28px; background: linear-gradient(135deg, #fef3c7 0%, #fed7aa 100%); color: #1e293b; position: relative; overflow: hidden; border: 1px solid #fde68a; box-shadow: 0 4px 20px rgba(254, 243, 199, 0.4);">
                    <!-- Decorative elements -->
                    <div style="position: absolute; top: -25px; right: -25px; width: 90px; height: 90px; background: rgba(251,191,36,0.2); border-radius: 50%; animation: float 3.5s ease-in-out infinite;"></div>
                    <div style="position: absolute; bottom: -15px; left: -15px; width: 60px; height: 60px; background: rgba(253,186,116,0.2); border-radius: 50%; animation: float 4.5s ease-in-out infinite;"></div>
                    
                    <div class="quiz-results-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; position: relative; z-index: 1; flex-wrap: wrap; gap: 12px;">
                        <h2 style="font-size: 20px; font-weight: 800; margin: 0; display: flex; align-items: center; gap: 10px; color: #b45309; word-wrap: break-word; word-break: break-word; flex: 1; min-width: 0;">
                            <i class="fas fa-brain" style="font-size: 22px; color: #f59e0b; flex-shrink: 0;"></i>
                            <span style="word-wrap: break-word; word-break: break-word;">Recent Quiz Results</span>
                        </h2>
                        <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/parent/parent_reports.php" style="color: #b45309; text-decoration: none; font-size: 13px; font-weight: 700; display: flex; align-items: center; gap: 6px; background: rgba(245,158,11,0.1); padding: 6px 14px; border-radius: 18px; transition: all 0.3s; border: 1px solid rgba(245,158,11,0.2); flex-shrink: 0; white-space: nowrap;">
                            View All <i class="fas fa-chevron-right" style="font-size: 11px;"></i>
                        </a>
                    </div>
                    
                    <?php if (!empty($recent_quiz_completions)): ?>
                        <?php foreach ($recent_quiz_completions as $quiz): ?>
                        <?php
                        $initials = '';
                        $name_parts = explode(' ', $quiz['child_name']);
                        $initials = strtoupper(substr($name_parts[0], 0, 1));
                        if (count($name_parts) > 1) {
                            $initials .= strtoupper(substr($name_parts[count($name_parts) - 1], 0, 1));
                        }
                        $avatar_colors = ['#60a5fa', '#3b82f6', '#2563eb', '#8b5cf6', '#ec4899'];
                        $avatar_color = $avatar_colors[$quiz['child_id'] % count($avatar_colors)];
                        ?>
                        <div class="quiz-result-item" style="display: flex; align-items: flex-start; gap: 12px; padding: 14px 0; border-bottom: 1px solid #f1f5f9; flex-wrap: wrap;">
                            <?php if ($quiz['has_profile_picture']): ?>
                                <img src="<?php echo $quiz['profile_picture_url']; ?>" alt="<?php echo htmlspecialchars($quiz['child_name']); ?>" style="width: 40px; height: 40px; border-radius: 50%; object-fit: cover; flex-shrink: 0;">
                            <?php else: ?>
                                <div style="width: 40px; height: 40px; background: <?php echo $avatar_color; ?>; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: white; flex-shrink: 0;">
                                    <i class="fas fa-graduation-cap" style="font-size: 18px;"></i>
                                </div>
                            <?php endif; ?>
                            
                            <div style="flex: 1; min-width: 0; max-width: 100%; word-wrap: break-word; word-break: break-word; overflow-wrap: break-word;">
                                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px; flex-wrap: wrap;">
                                    <span style="font-size: 13px; font-weight: 600; color: #0f172a; word-wrap: break-word; word-break: break-word;"><?php echo htmlspecialchars($quiz['child_name']); ?></span>
                                    <span style="background: #dbeafe; color: #3b82f6; padding: 2px 8px; border-radius: 12px; font-size: 10px; font-weight: 700; flex-shrink: 0; white-space: nowrap;"><?php echo $quiz['percentage']; ?>%</span>
                                </div>
                                <div style="font-size: 12px; font-weight: 600; color: #475569; margin-bottom: 2px; word-wrap: break-word; word-break: break-word; overflow-wrap: break-word; white-space: normal;"><?php echo htmlspecialchars($quiz['quiz_name']); ?></div>
                                <div style="font-size: 10px; color: #94a3b8; word-wrap: break-word; word-break: break-word; overflow-wrap: break-word;"><?php echo htmlspecialchars($quiz['course_name']); ?></div>
                            </div>
                            
                            <div class="quiz-result-meta" style="text-align: right; display: flex; flex-direction: column; align-items: flex-end; gap: 4px; flex-shrink: 0;">
                                <div style="font-size: 10px; color: #64748b; display: flex; align-items: center; gap: 4px; white-space: nowrap;">
                                    <i class="fas fa-clock" style="font-size: 9px;"></i>
                                    <?php echo date('M j, Y', $quiz['timefinish']); ?>
                                </div>
                                <i class="fas fa-arrow-right" style="color: #cbd5e1; font-size: 12px;"></i>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="text-align: center; padding: 60px 20px; color: #94a3b8;">
                            <i class="fas fa-question-circle" style="font-size: 48px; margin-bottom: 12px; opacity: 0.5;"></i>
                            <p style="margin: 0; font-size: 13px; font-weight: 500;">No recent quiz attempts</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Recent Community Posts & Best Performing Students - Side by Side -->
        <style>
        .community-performance-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            margin-top: 24px;
        }
        @media (max-width: 1400px) {
            .community-performance-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }
        }
        </style>
        <div class="community-performance-grid">
        
        <!-- NEW: Recent Community Posts -->
        <div class="parent-section">
            <div class="modern-card community-card" style="padding: 28px; background: linear-gradient(135deg, #faf9ff 0%, #fefbff 100%); border: 1px solid #f3f4f6; box-shadow: 0 2px 10px rgba(139, 92, 246, 0.08); position: relative; overflow: hidden; display: flex; flex-direction: column; min-height: 600px; max-height: 650px; box-sizing: border-box;">
                <!-- Decorative elements -->
                <div style="position: absolute; top: -30px; right: -30px; width: 100px; height: 100px; background: rgba(237,233,254,0.3); border-radius: 50%;"></div>
                <div style="position: absolute; bottom: -20px; left: -20px; width: 70px; height: 70px; background: rgba(243,232,255,0.2); border-radius: 50%;"></div>
                
                <div class="community-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; position: relative; z-index: 1; flex-shrink: 0; flex-wrap: wrap; gap: 12px;">
                    <h2 style="font-size: 20px; font-weight: 800; margin: 0; display: flex; align-items: center; gap: 12px; color: #7c3aed; word-wrap: break-word; word-break: break-word; flex: 1; min-width: 0;">
                        <div style="width: 48px; height: 48px; background: linear-gradient(135deg, #e9d5ff, #ddd6fe); border-radius: 12px; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 8px rgba(196, 181, 253, 0.2); flex-shrink: 0;">
                            <i class="fas fa-comments" style="color: #8b5cf6; font-size: 22px;"></i>
                        </div>
                        <span style="word-wrap: break-word; word-break: break-word;">Recent Community Posts</span>
                    </h2>
                    <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/community.php" style="color: #8b5cf6; text-decoration: none; font-size: 13px; font-weight: 700; display: flex; align-items: center; gap: 6px; background: rgba(237,233,254,0.5); padding: 8px 16px; border-radius: 18px; transition: all 0.3s; border: 1px solid rgba(196,181,253,0.3); flex-shrink: 0; white-space: nowrap;">
                        Open Community <i class="fas fa-external-link-alt" style="font-size: 11px;"></i>
                    </a>
                </div>
                
                <!-- Scrollable content area -->
                <div style="flex: 1; overflow-y: auto; position: relative; z-index: 1; margin: 0 -8px 0 0; padding-right: 16px;">
                    <style>
                    .community-posts-scroll::-webkit-scrollbar {
                        width: 8px;
                    }
                    .community-posts-scroll::-webkit-scrollbar-track {
                        background: linear-gradient(135deg, #faf9ff, #f3e8ff);
                        border-radius: 10px;
                    }
                    .community-posts-scroll::-webkit-scrollbar-thumb {
                        background: linear-gradient(135deg, #ddd6fe, #c4b5fd);
                        border-radius: 10px;
                    }
                    .community-posts-scroll::-webkit-scrollbar-thumb:hover {
                        background: linear-gradient(135deg, #c4b5fd, #a78bfa);
                    }
                    .community-post-item {
                        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
                    }
                    .community-post-item:hover {
                        transform: translateX(5px);
                        background: rgba(237,233,254,0.3) !important;
                    }
                    </style>
                    
                <?php if (!empty($recent_community_posts)): ?>
                    <div class="community-posts-scroll" style="display: flex; flex-direction: column;">
                    <?php 
                    foreach ($recent_community_posts as $post): 
                    ?>
                    <a href="<?php echo $post['discussion_url']; ?>" class="community-post-item" style="text-decoration: none; color: inherit; display: block; border-bottom: 1px solid #f1f5f9; padding: 16px 0; margin-bottom: 4px;">
                        <div style="display: flex; gap: 14px; align-items: flex-start;">
                            <!-- Author Avatar -->
                            <div style="flex-shrink: 0;">
                                <?php if ($post['has_profile_picture']): ?>
                                    <img src="<?php echo $post['profile_picture_url']; ?>" 
                                         alt="<?php echo htmlspecialchars($post['author_name']); ?>"
                                         style="width: 42px; height: 42px; border-radius: 50%; object-fit: cover; border: 2px solid #e2e8f0;">
                                <?php else: ?>
                                    <div style="width: 42px; height: 42px; border-radius: 50%; background: linear-gradient(135deg, #8b5cf6, #7c3aed); display: flex; align-items: center; justify-content: center; color: white; font-weight: 700; font-size: 16px;">
                                        <?php echo strtoupper(substr($post['author_name'], 0, 1)); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <div style="flex: 1; min-width: 0; max-width: 100%; word-wrap: break-word; word-break: break-word; overflow-wrap: break-word;">
                                <!-- Forum Badge -->
                                <div style="background: #f3e8ff; color: #7c3aed; padding: 3px 10px; border-radius: 12px; font-size: 9px; font-weight: 700; text-transform: uppercase; display: inline-block; margin-bottom: 6px; letter-spacing: 0.5px; word-wrap: break-word; word-break: break-word;">
                                    <?php echo htmlspecialchars($post['forum_name']); ?>
                                </div>
                                
                                <!-- Post Title -->
                                <h3 style="font-size: 14px; font-weight: 700; color: #0f172a; margin: 0 0 4px 0; word-wrap: break-word; word-break: break-word; overflow-wrap: break-word; white-space: normal; line-height: 1.4;">
                                    <?php echo htmlspecialchars($post['subject']); ?>
                                </h3>
                                
                                <!-- Post Message Preview -->
                                <p style="font-size: 12px; color: #64748b; margin: 0 0 8px 0; line-height: 1.5; word-wrap: break-word; word-break: break-word; overflow-wrap: break-word; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;">
                                    <?php echo htmlspecialchars($post['message_short']); ?>
                                </p>
                                
                                <!-- Meta Info at Bottom -->
                                <div style="display: flex; align-items: center; gap: 12px; font-size: 11px; color: #94a3b8; margin-top: 4px;">
                                    <span style="display: flex; align-items: center; gap: 4px;">
                                        <i class="fas fa-book" style="font-size: 10px;"></i>
                                        <?php echo htmlspecialchars($post['course_name']); ?>
                                    </span>
                                    <span style="display: flex; align-items: center; gap: 4px;">
                                        <i class="fas fa-comments" style="font-size: 10px;"></i>
                                        <?php echo $post['reply_count']; ?> replies
                                    </span>
                                    <span style="display: flex; align-items: center; gap: 4px;">
                                        <i class="fas fa-clock" style="font-size: 10px;"></i>
                                        <?php
                                        $diff = time() - $post['created'];
                                        if ($diff < 3600) {
                                            echo round($diff / 60) . ' min ago';
                                        } elseif ($diff < 86400) {
                                            echo round($diff / 3600) . ' hrs ago';
                                        } elseif ($diff < 604800) {
                                            echo round($diff / 86400) . ' days ago';
                                        } else {
                                            echo date('M j, Y', $post['created']);
                                        }
                                        ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </a>
                    <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div style="text-align: center; padding: 50px 20px; color: #94a3b8;">
                        <div style="width: 100px; height: 100px; background: linear-gradient(135deg, #f3f4f6, #e9d5ff); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; box-shadow: 0 4px 16px rgba(196, 181, 253, 0.15);">
                            <i class="fas fa-comments" style="font-size: 48px; color: #a78bfa;"></i>
                        </div>
                        <p style="margin: 0 0 8px 0; font-size: 16px; font-weight: 700; color: #8b5cf6;">No recent community posts</p>
                        <p style="margin: 0; font-size: 13px; color: #a78bfa; opacity: 0.7;">Community discussions will appear here when available</p>
                        <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/community.php" style="display: inline-block; margin-top: 16px; padding: 10px 20px; background: linear-gradient(135deg, #f3f4f6, #e9d5ff); color: #8b5cf6; text-decoration: none; border-radius: 20px; font-size: 13px; font-weight: 700; transition: all 0.3s; box-shadow: 0 2px 8px rgba(196, 181, 253, 0.2); border: 1px solid rgba(196,181,253,0.3);">
                            <i class="fas fa-plus-circle" style="margin-right: 6px;"></i>Explore Community
                        </a>
                    </div>
                <?php endif; ?>
                </div>
                
                <!-- Post count footer -->
                <div style="margin-top: 16px; padding: 12px 16px; background: linear-gradient(135deg, #faf9ff, #f3e8ff); border-radius: 10px; text-align: center; position: relative; z-index: 1; border: 1px solid #e9d5ff; flex-shrink: 0;">
                    <div style="display: flex; align-items: center; justify-content: center; gap: 10px;">
                        <i class="fas fa-comments" style="color: #8b5cf6; font-size: 14px;"></i>
                        <span style="font-size: 13px; color: #7c3aed; font-weight: 700;">
                            <?php echo !empty($recent_community_posts) ? count($recent_community_posts) . ' community posts' : 'No posts yet'; ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Top Performing Students - Horizontal Row Layout -->
        <div class="parent-section">
            <div class="modern-card">
                <h2 style="font-size: 20px; font-weight: 700; margin: 0 0 20px 0; display: flex; align-items: center; gap: 10px; color: #111827; padding-bottom: 16px; border-bottom: 2px solid #f3f4f6;">
                                <i class="fas fa-trophy" style="color: #f59e0b; font-size: 22px;"></i>
                    <span>Top Performers</span>
                        </h2>
                
                <div class="top-performers-list">
                
                <?php if (!empty($best_performing_students)): ?>
                <?php 
                        // Show top 3 students in horizontal row layout like the image
                        $top_students = array_slice($best_performing_students, 0, 3);
                        foreach ($top_students as $idx => $performer):
                            $rank = isset($performer['actual_rank']) ? $performer['actual_rank'] : ($idx + 1);
                            $is_parent_child = isset($performer['is_parent_child']) && $performer['is_parent_child'];
                            
                            // Determine best achievement description
                            $best_achievement = '';
                            $best_value = 0;
                            if ($performer['grade_percentage'] > $best_value) {
                                $best_value = $performer['grade_percentage'];
                                $best_achievement = 'Best in marks';
                            }
                            if ($performer['competency_percentage'] > $best_value) {
                                $best_value = $performer['competency_percentage'];
                                $best_achievement = 'Best in competencies';
                            }
                            if ($performer['quiz_percentage'] > $best_value) {
                                $best_value = $performer['quiz_percentage'];
                                $best_achievement = 'Best exam result';
                            }
                            if ($performer['assignment_percentage'] > $best_value) {
                                $best_value = $performer['assignment_percentage'];
                                $best_achievement = 'Best in assignments';
                            }
                            if (empty($best_achievement)) {
                                $best_achievement = 'Top performer';
                            }
                            
                            // Format score
                            $score_display = $performer['overall_score'] . '%';
                        ?>
                        <div class="top-performer-row" style="background: white; border-bottom: 1px solid #e5e7eb; padding: 18px 20px; display: flex; align-items: center; gap: 16px; transition: background 0.2s; <?php echo $is_parent_child ? 'background: #eff6ff;' : ''; ?>">
                            <!-- Circular Profile Picture -->
                            <div style="width: 50px; height: 50px; border-radius: 50%; overflow: hidden; flex-shrink: 0; border: 2px solid #e5e7eb;">
                                    <?php if ($performer['has_profile_picture']): ?>
                                    <img src="<?php echo $performer['profile_picture_url']; ?>" 
                                         alt="<?php echo htmlspecialchars($performer['name']); ?>" 
                                         style="width: 100%; height: 100%; object-fit: cover;">
                                    <?php else: ?>
                                    <div style="width: 100%; height: 100%; background: linear-gradient(135deg, #667eea, #764ba2); display: flex; align-items: center; justify-content: center; color: white; font-weight: 700; font-size: 18px;">
                                        <?php echo strtoupper(substr($performer['name'], 0, 1)); ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            
                            <!-- Student Name and Description -->
                            <div style="flex: 1; min-width: 0;">
                                <h3 style="font-size: 15px; font-weight: 700; color: #111827; margin: 0 0 3px 0; line-height: 1.3;">
                                        <?php echo htmlspecialchars($performer['name']); ?>
                                    <?php if ($is_parent_child): ?>
                                        <span style="background: #3b82f6; color: white; padding: 2px 8px; border-radius: 4px; font-size: 10px; font-weight: 600; margin-left: 8px;">Your Child</span>
                                    <?php endif; ?>
                                    </h3>
                                <p style="font-size: 12px; color: #6b7280; margin: 0; line-height: 1.4;">
                                    <?php echo htmlspecialchars($best_achievement); ?>
                                </p>
                            </div>
                            
                            <!-- Score aligned to the right -->
                            <div style="text-align: right; flex-shrink: 0; min-width: 70px;">
                                <div style="font-size: 18px; font-weight: 700; color: #111827; line-height: 1.2;">
                                    <?php echo $score_display; ?>
                                </div>
                            </div>
                        </div>
                        <?php 
                        endforeach; 
                        ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-chart-line"></i>
                        <p>No performance data available yet</p>
                        </div>
                            <?php endif; ?>
                    </div>
            </div>
        </div>
        
        </div> <!-- End of community-performance-grid -->
        <?php endif; ?>

        <!-- Academic Progress Section -->
        <?php 
        // Debug: Show what's happening
        if ($debug_mode) {
            echo "<div style='background: #ec4899; color: white; padding: 15px; border-radius: 8px; margin: 20px 0;'>";
            echo "<h3 style='margin: 0 0 10px 0;'>" . " DEBUG: Section Visibility Check</h3>";
            echo "<p><strong>selected_child_id:</strong> " . var_export($selected_child_id, true) . " (type: " . gettype($selected_child_id) . ")</p>";
            echo "<p><strong>Is NOT 'all'?</strong> " . ($selected_child_id !== 'all' ? 'YES' : 'NO') . "</p>";
            echo "<p><strong>Is NOT 0?</strong> " . ($selected_child_id != 0 ? 'YES' : 'NO') . "</p>";
            echo "<p><strong>Is truthy?</strong> " . ($selected_child_id ? 'YES' : 'NO') . "</p>";
            echo "<p><strong>SHOULD SHOW SECTIONS?</strong> " . (($selected_child_id && $selected_child_id !== 'all' && $selected_child_id != 0) ? ' YES' : ' NO') . "</p>";
            echo "<p><strong>Stats Children Count:</strong> " . count($stats_children) . "</p>";
            echo "<p><strong>Total Results:</strong> {$total_results}</p>";
            echo "<p><strong>Total Courses:</strong> {$total_courses}</p>";
            echo "</div>";
        }
        
        if ($selected_child_id && $selected_child_id !== 'all' && $selected_child_id != 0): ?>
        <div class="parent-section academic-progress-section">
            <h2 class="section-title">
                <i class="fas fa-chart-line"></i>
                Academic Progress
            </h2>
            
            <!-- Compact Progress Cards -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 30px;">
                <div class="modern-card" style="background: linear-gradient(135deg, #ffffff 0%, #fef3c7 100%); border: 1px solid #fde68a; padding: 16px;">
                    <div style="position: relative; z-index: 1;">
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
                            <div style="width: 44px; height: 44px; background: linear-gradient(135deg, #fbbf24, #f59e0b); border-radius: 12px; display: flex; align-items: center; justify-content: center; box-shadow: 0 3px 10px rgba(251, 191, 36, 0.3);">
                                <i class="fas fa-clipboard-check" style="font-size: 18px; color: white;"></i>
                            </div>
                        </div>
                        <div style="font-size: 36px; font-weight: 800; margin-bottom: 8px; color: #0f172a; line-height: 1; letter-spacing: -1px;">
                            <?php echo $total_results; ?>
                        </div>
                        <div style="font-size: 11px; color: #92400e; font-weight: 700; text-transform: uppercase; letter-spacing: 0.6px;">
                            Completed Assessments
                        </div>
                    </div>
                </div>
                
                <div class="modern-card" style="background: linear-gradient(135deg, #ffffff 0%, #dbeafe 100%); border: 1px solid #bfdbfe; padding: 16px;">
                    <div style="position: relative; z-index: 1;">
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
                            <div style="width: 44px; height: 44px; background: linear-gradient(135deg, #3b82f6, #2563eb); border-radius: 12px; display: flex; align-items: center; justify-content: center; box-shadow: 0 3px 10px rgba(59, 130, 246, 0.3);">
                                <i class="fas fa-book-open" style="font-size: 18px; color: white;"></i>
                            </div>
                        </div>
                        <div style="font-size: 36px; font-weight: 800; margin-bottom: 8px; color: #0f172a; line-height: 1; letter-spacing: -1px;">
                            <?php echo $total_courses; ?>
                        </div>
                        <div style="font-size: 11px; color: #1e40af; font-weight: 700; text-transform: uppercase; letter-spacing: 0.6px;">
                            Active Courses
                        </div>
                    </div>
                </div>
                
                <div class="modern-card" style="background: linear-gradient(135deg, #ffffff 0%, #fce7f3 100%); border: 1px solid #fbcfe8; padding: 16px;">
                    <div style="position: relative; z-index: 1;">
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
                            <div style="width: 44px; height: 44px; background: linear-gradient(135deg, #ec4899, #db2777); border-radius: 12px; display: flex; align-items: center; justify-content: center; box-shadow: 0 3px 10px rgba(236, 72, 153, 0.3);">
                                <i class="fas fa-tasks" style="font-size: 18px; color: white;"></i>
                            </div>
                        </div>
                        <div style="font-size: 36px; font-weight: 800; margin-bottom: 8px; color: #0f172a; line-height: 1; letter-spacing: -1px;">
                            <?php echo $total_activities; ?>
                        </div>
                        <div style="font-size: 11px; color: #be185d; font-weight: 700; text-transform: uppercase; letter-spacing: 0.6px;">
                            Upcoming Activities
                        </div>
                    </div>
                </div>
                
                <div class="modern-card" style="background: linear-gradient(135deg, #ffffff 0%, #d1fae5 100%); border: 1px solid #a7f3d0; padding: 16px;">
                    <div style="position: relative; z-index: 1;">
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
                            <div style="width: 44px; height: 44px; background: linear-gradient(135deg, #10b981, #059669); border-radius: 12px; display: flex; align-items: center; justify-content: center; box-shadow: 0 3px 10px rgba(16, 185, 129, 0.3);">
                                <i class="fas fa-user-check" style="font-size: 18px; color: white;"></i>
                            </div>
                        </div>
                        <div style="font-size: 36px; font-weight: 800; margin-bottom: 8px; color: #0f172a; line-height: 1; letter-spacing: -1px;">
                            <?php echo number_format($avg_attendance, 1); ?>%
                        </div>
                        <div style="font-size: 11px; color: #065f46; font-weight: 700; text-transform: uppercase; letter-spacing: 0.6px;">
                            Avg Attendance
                        </div>
                    </div>
                </div>
            </div>
            
            <style>
            /* Enhanced child card hover effects */
            .child-card {
                position: relative;
            }
            
            .child-card:hover .child-card > div:first-child {
                transform: scale(1.05);
            }
            
            .child-card:hover .child-card > div[style*="120px"] {
                transform: scale(1.08) rotate(5deg);
                box-shadow: 0 12px 32px rgba(59, 130, 246, 0.25) !important;
            }
            
            .child-card:hover .child-card > div[style*="120px"] > div {
                opacity: 1 !important;
            }
            
            /* Academic progress card hover */
            .academic-progress-section .modern-card:hover {
                transform: translateY(-8px) scale(1.02);
            }
            
            @keyframes pulse {
                0%, 100% { opacity: 1; }
                50% { opacity: 0.8; }
            }
            
            /* Smooth transitions for all interactive elements */
            .child-card,
            .modern-card,
            .breadcrumb-link,
            button {
                transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            }
            </style>
        </div>
        <?php else: ?>
        <!-- Enhanced Empty State -->
        <div class="modern-card" style="text-align: center; padding: 120px 40px; background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 50%, #dbeafe 100%); border: 2px dashed rgba(59,130,246,0.4); position: relative; overflow: hidden;">
            <div style="position: absolute; top: -60px; right: -60px; width: 240px; height: 240px; background: radial-gradient(circle, rgba(59,130,246,0.15) 0%, transparent 70%); border-radius: 50%; filter: blur(40px); animation: float 6s ease-in-out infinite;"></div>
            <div style="position: absolute; bottom: -60px; left: -60px; width: 240px; height: 240px; background: radial-gradient(circle, rgba(139,92,246,0.12) 0%, transparent 70%); border-radius: 50%; filter: blur(40px); animation: float 8s ease-in-out infinite reverse;"></div>
            <div style="width: 160px; height: 160px; background: linear-gradient(135deg, #dbeafe, #bfdbfe); border-radius: 50%; margin: 0 auto 40px; display: flex; align-items: center; justify-content: center; box-shadow: 0 12px 40px rgba(59,130,246,0.25), inset 0 2px 8px rgba(255,255,255,0.3); position: relative; z-index: 1; animation: pulseIcon 3s ease-in-out infinite;">
                <i class="fas fa-hand-pointer" style="font-size: 64px; background: linear-gradient(135deg, #3b82f6, #8b5cf6); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; animation: bounce 2s ease-in-out infinite;"></i>
            </div>
            <h2 style="margin: 0 0 20px 0; font-size: 36px; font-weight: 800; color: #0f172a; position: relative; z-index: 1; letter-spacing: -1px;">
                Select a Child to View Data
            </h2>
            <p style="margin: 0 0 40px 0; font-size: 18px; color: #475569; max-width: 600px; margin-left: auto; margin-right: auto; line-height: 1.8; font-weight: 500; position: relative; z-index: 1;">
                Click on one of the child cards above to view their academic progress, courses, assignments, and all learning activities.
            </p>
            <div style="display: inline-flex; align-items: center; gap: 12px; padding: 20px 40px; background: linear-gradient(135deg, #ffffff, #f8fafc); border-radius: 16px; border: 2px solid rgba(59,130,246,0.3); box-shadow: 0 6px 20px rgba(59,130,246,0.2); position: relative; z-index: 1; transition: all 0.3s ease;"
                 onmouseover="this.style.transform='translateY(-4px)'; this.style.boxShadow='0 8px 24px rgba(59,130,246,0.3)';"
                 onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 6px 20px rgba(59,130,246,0.2)';">
                <i class="fas fa-arrow-up" style="color: #3b82f6; font-size: 24px; animation: bounce 2s ease-in-out infinite;"></i>
                <span style="color: #1e3a8a; font-weight: 700; font-size: 16px; letter-spacing: 0.3px;">Click a child card above</span>
            </div>
            
            <style>
            @keyframes float {
                0%, 100% { transform: translate(0, 0) scale(1); }
                50% { transform: translate(20px, -20px) scale(1.1); }
            }
            
            @keyframes pulseIcon {
                0%, 100% { transform: scale(1); box-shadow: 0 12px 40px rgba(59,130,246,0.25), inset 0 2px 8px rgba(255,255,255,0.3); }
                50% { transform: scale(1.05); box-shadow: 0 16px 48px rgba(59,130,246,0.35), inset 0 2px 8px rgba(255,255,255,0.4); }
            }
            
            @keyframes bounce {
                0%, 100% { transform: translateY(0); }
                50% { transform: translateY(-8px); }
            }
            </style>
        </div>
        <?php endif; ?>

        <!-- Enrolled Courses Section -->
        <?php if ($selected_child_id && $selected_child_id !== 'all' && $selected_child_id != 0 && !empty($children_records)): ?>
        <div class="parent-section enrolled-courses-section" style="margin-bottom: 32px;">
            <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px; margin-bottom: 24px; padding-bottom: 16px; border-bottom: 2px solid #e2e8f0;">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <div style="width: 48px; height: 48px; background: linear-gradient(135deg, #475569 0%, #334155 100%); border-radius: 12px; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 12px rgba(71,85,105,0.2);">
                        <i class="fas fa-book-open" style="color: white; font-size: 20px;"></i>
                    </div>
                    <div>
                        <h2 class="section-title" style="margin: 0; font-size: 24px; font-weight: 800; color: #1e293b; letter-spacing: -0.5px; line-height: 1.2;">
                Enrolled Courses
                        </h2>
                        <p style="margin: 4px 0 0 0; font-size: 13px; color: #64748b; font-weight: 500;">
                            View all courses your child is enrolled in
                        </p>
                    </div>
                </div>
                <?php if ($selected_child_id && $selected_child_id !== 'all' && $selected_child_id != 0): ?>
                <div style="background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%); color: #475569; padding: 8px 16px; border-radius: 10px; font-size: 12px; font-weight: 700; box-shadow: 0 2px 8px rgba(0,0,0,0.08); border: 1px solid #cbd5e1; display: flex; align-items: center; gap: 6px;">
                    <i class="fas fa-filter" style="font-size: 11px; color: #64748b;"></i> Filtered View
                </div>
                <?php endif; ?>
            </div>
            
            <?php if ($debug_mode): ?>
            <div style="background: #dbeafe; padding: 15px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #3b82f6;">
                <strong>DEBUG: Course Filtering</strong><br>
                Selected Child ID: <?php echo var_export($selected_child_id, true); ?><br>
                Is Filtering: <?php echo ($selected_child_id && $selected_child_id !== 'all' && $selected_child_id != 0) ? 'YES - ONLY SELECTED CHILD' : 'NO - ALL CHILDREN'; ?>
            </div>
            <?php endif; ?>
            
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 24px;">
                <?php
                // Get courses based on selected child
                $all_courses = [];
                $target_child_records = [];
                
                // Filter children records based on selection
                $is_filtering = ($selected_child_id && $selected_child_id !== 'all' && $selected_child_id != 0);
                
                if ($debug_mode) {
                    echo "<div style='background: #8b5cf6; color: white; padding: 12px; border-radius: 6px; margin-bottom: 15px;'>";
                    echo "<strong>" . " DETAILED COMPARISON:</strong><br>";
                    echo "selected_child_id = " . var_export($selected_child_id, true) . " (type: " . gettype($selected_child_id) . ")<br>";
                    echo "Is filtering? " . ($is_filtering ? 'YES' : 'NO') . "<br>";
                    echo "Available children IDs: " . implode(', ', array_map(function($c) { return $c->id; }, $children_records)) . "<br>";
                    echo "</div>";
                }
                
                if ($is_filtering) {
                    // Show only selected child's courses
                    foreach ($children_records as $child) {
                        if ($debug_mode) {
                            echo "<div style='background: #fef3c7; padding: 6px; border-radius: 4px; margin-bottom: 5px; font-size: 11px;'>";
                            echo "Comparing: child->id ({$child->id}) == selected_child_id ({$selected_child_id}) ? " . ($child->id == $selected_child_id ? 'MATCH' : 'no match');
                            echo "</div>";
                        }
                        
                        if ($child->id == $selected_child_id) {
                            $target_child_records[] = $child;
                            if ($debug_mode) {
                                echo "<div style='background: #10b981; color: white; padding: 10px; border-radius: 6px; margin-bottom: 15px;'>";
                                echo "<strong>FILTERING ENABLED:</strong> Showing courses for " . fullname($child) . " (ID: {$child->id})";
                                echo "</div>";
                            }
                            break;
                        }
                    }
                    
                    if (empty($target_child_records) && $debug_mode) {
                        echo "<div style='background: #ef4444; color: white; padding: 10px; border-radius: 6px; margin-bottom: 15px;'>";
                        echo "<strong> ERROR:</strong> Selected child ID '{$selected_child_id}' not found in children_records!";
                        echo "</div>";
                    }
                } else {
                    // Show all children's courses
                    $target_child_records = $children_records;
                    if ($debug_mode) {
                        echo "<div style='background: #f59e0b; color: white; padding: 10px; border-radius: 6px; margin-bottom: 15px;'>";
                        echo "<strong> NO FILTER:</strong> Showing courses for ALL " . count($children_records) . " children";
                        echo "</div>";
                    }
                }
                
                foreach ($target_child_records as $child) {
                    $child_name = fullname($child);
                    $courses = enrol_get_users_courses($child->id, true);
                    
                    if ($debug_mode) {
                        echo "<div style='background: #fef3c7; padding: 8px; border-radius: 4px; margin-bottom: 10px; font-size: 12px;'>";
                        echo "Child: {$child_name} (ID: {$child->id}) has " . count($courses) . " courses";
                        echo "</div>";
                    }
                    
                    foreach ($courses as $course) {
                        // Get course completion
                        $completion = new completion_info($course);
                        $is_complete = false;
                        if ($completion->is_enabled()) {
                            $is_complete = $completion->is_course_complete($child->id);
                        }
                        
                        // Get course progress percentage
                        $progress = 0;
                        try {
                            $sql_progress = "SELECT COUNT(CASE WHEN cmc.completionstate > 0 THEN 1 END) * 100.0 / COUNT(*) as percentage
                                            FROM {course_modules} cm
                                            LEFT JOIN {course_modules_completion} cmc ON cmc.coursemoduleid = cm.id AND cmc.userid = :userid
                                            WHERE cm.course = :courseid
                                            AND cm.completion > 0
                                            AND cm.deletioninprogress = 0";
                            $prog_data = $DB->get_record_sql($sql_progress, ['userid' => $child->id, 'courseid' => $course->id]);
                            $progress = ($prog_data && $prog_data->percentage !== null) ? round((float)$prog_data->percentage) : 0;
                        } catch (Exception $e) {
                            $progress = 0;
                        }
                        
                        // Get activity counts for this course
                        $activity_counts = [];
                        try {
                            $sql_activities = "SELECT m.name as modname, COUNT(*) as count
                                             FROM {course_modules} cm
                                             JOIN {modules} m ON m.id = cm.module
                                             WHERE cm.course = :courseid
                                             AND cm.deletioninprogress = 0
                                             GROUP BY m.name";
                            $activities = $DB->get_records_sql($sql_activities, ['courseid' => $course->id]);
                            foreach ($activities as $act) {
                                $activity_counts[$act->modname] = $act->count;
                            }
                        } catch (Exception $e) {
                            $activity_counts = [];
                        }
                        
                        // Get course image - Using same logic as teacher courses
                        require_once($CFG->libdir . '/filelib.php');
                        $course_image = '';
                        $coursecontext = context_course::instance($course->id);
                        $fs = get_file_storage();
                        $files = $fs->get_area_files($coursecontext->id, 'course', 'overviewfiles', 0);
                        foreach ($files as $file) {
                            if ($file->is_valid_image()) {
                                $course_image = moodle_url::make_pluginfile_url(
                                    $file->get_contextid(),
                                    $file->get_component(),
                                    $file->get_filearea(),
                                    null,
                                    $file->get_filepath(),
                                    $file->get_filename()
                                )->out();
                                break;
                            }
                        }
                        
                        // Fallback: Use book type detection and default covers (same as teacher)
                        $fallback_image = '';
                        if (empty($course_image)) {
                            try {
                                // Include helper functions
                                if (!function_exists('theme_remui_kids_slugify')) {
                                    function theme_remui_kids_slugify(string $text): string {
                                        $text = strtolower($text);
                                        $text = preg_replace('/[^a-z0-9]+/', '-', $text);
                                        return trim($text, '-');
                                    }
                                }
                                
                                if (!function_exists('theme_remui_kids_get_booktype_cover_overrides')) {
                                    function theme_remui_kids_get_booktype_cover_overrides(): array {
                                        static $overrides = null;
                                        if ($overrides !== null) {
                                            return $overrides;
                                        }
                                        global $CFG;
                                        $jsonpath = $CFG->dirroot . '/theme/remui_kids/CradsImg/booktype_covers.json';
                                        if (file_exists($jsonpath)) {
                                            $decoded = json_decode(file_get_contents($jsonpath), true);
                                            if (is_array($decoded)) {
                                                $overrides = $decoded;
                                                return $overrides;
                                            }
                                        }
                                        $overrides = [];
                                        return $overrides;
                                    }
                                }
                                
                                if (!function_exists('theme_remui_kids_course_keyword_match')) {
                                    function theme_remui_kids_course_keyword_match(string $haystack, array $keywords): bool {
                                        foreach ($keywords as $keyword) {
                                            $keyword = strtolower(trim($keyword));
                                            if ($keyword === '') {
                                                continue;
                                            }
                                            if (strpos($haystack, $keyword) !== false) {
                                                return true;
                                            }
                                            if (strlen($keyword) <= 3 && preg_match('/\b' . preg_quote($keyword, '/') . '\b/', $haystack)) {
                                                return true;
                                            }
                                        }
                                        return false;
                                    }
                                }
                                
                                if (!function_exists('theme_remui_kids_extract_label_from_fullname')) {
                                    function theme_remui_kids_extract_label_from_fullname(string $fullname): string {
                                        $fullname = trim($fullname);
                                        if ($fullname === '') {
                                            return '';
                                        }
                                        $parts = preg_split('/\s*(?:-|–|—|:|\||•)\s*/u', $fullname);
                                        if (!empty($parts) && trim($parts[0]) !== '') {
                                            return trim($parts[0]);
                                        }
                                        return $fullname;
                                    }
                                }
                                
                                if (!function_exists('theme_remui_kids_detect_course_book_type')) {
                                    function theme_remui_kids_detect_course_book_type($course): string {
                                        // Handle both stdClass objects and arrays
                                        if (is_object($course)) {
                                            $course = (array)$course;
                                        }
                                        $fullname = $course['fullname'] ?? '';
                                        $shortname = $course['shortname'] ?? '';
                                        $haystack = strtolower($fullname . ' ' . $shortname);
                                        
                                        $bookTypeKeywords = [
                                            'Student Course' => ['student course', 'student-course', 'studentcourse', 'sc', 'student courses'],
                                            'Practice Book' => ['practice book', 'practice-book', 'practicebook', 'pb'],
                                            'Student Book' => ['student book', 'student-book', 'studentbook', 'sb', 'learner book', 'learner\'s book'],
                                            'Teacher Resource' => ['teacher resource', 'resource pack', 'resource book', 'tr'],
                                            'Teacher Book' => ['teacher book', 'teachers book', 'tb'],
                                            'Teacher Guide' => ['teacher guide', 'teachers guide', 'guide book', 'guidebook', 'tg'],
                                            'Worksheet Pack' => ['worksheet pack', 'worksheet', 'worksheets', 'activity pack', 'wp'],
                                            'Workbook' => ['workbook', 'work book', 'wb'],
                                            'Assessment Book' => ['assessment book', 'assessment pack', 'assessment', 'ab']
                                        ];
                                        
                                        foreach ($bookTypeKeywords as $label => $keywords) {
                                            if (theme_remui_kids_course_keyword_match($haystack, $keywords)) {
                                                return $label;
                                            }
                                        }
                                        
                                        $derivedLabel = theme_remui_kids_extract_label_from_fullname($fullname);
                                        if ($derivedLabel !== '') {
                                            $derivedLower = strtolower($derivedLabel);
                                            foreach ($bookTypeKeywords as $label => $keywords) {
                                                foreach ($keywords as $keyword) {
                                                    if (strtolower($keyword) === $derivedLower || strpos($derivedLower, strtolower($keyword)) !== false) {
                                                        return $label;
                                                    }
                                                }
                                            }
                                            if (stripos($derivedLabel, 'student') !== false && stripos($derivedLabel, 'course') !== false) {
                                                return 'Student Course';
                                            }
                                        }
                                        
                                        return '';
                                    }
                                }
                                
                                if (!function_exists('theme_remui_kids_select_course_cover')) {
                                    function theme_remui_kids_select_course_cover($course, array $defaults, array $cycle, int &$index, ?string &$type = null) {
                                        static $dynamiccovermap = [];
                                        global $CFG;
                                        $overrides = theme_remui_kids_get_booktype_cover_overrides();
                                        
                                        $labelKeyMap = [
                                            'Student Book' => 'student_book',
                                            'Student Course' => 'student_course',
                                            'Teacher Resource' => 'teacher_resource',
                                            'Worksheet Pack' => 'worksheet_pack',
                                            'Teacher Guide' => 'teacher_guide',
                                            'Practice Book' => 'practice_book',
                                            'Teacher Book' => 'teacher_book',
                                            'Workbook' => 'workbook',
                                            'Assessment Book' => 'assessment_book'
                                        ];
                                        
                                        if (empty($type)) {
                                            // Convert course object to array for function compatibility
                                            $course_for_type = is_object($course) ? (array)$course : $course;
                                            $type = theme_remui_kids_detect_course_book_type($course_for_type);
                                        }
                                        
                                        $hasType = !empty($type);
                                        $slug = '';
                                        if ($hasType && function_exists('theme_remui_kids_slugify')) {
                                            $slug = theme_remui_kids_slugify($type);
                                        }
                                        
                                        if ($hasType) {
                                            if (isset($labelKeyMap[$type]) && isset($defaults[$labelKeyMap[$type]])) {
                                                return $defaults[$labelKeyMap[$type]];
                                            }
                                            
                                            $customcoverdir = $CFG->dirroot . '/theme/remui_kids/CradsImg';
                                            $customcoverurl = $CFG->wwwroot . '/theme/remui_kids/CradsImg';
                                            
                                            if (!empty($slug)) {
                                                if (isset($overrides[$slug])) {
                                                    $overridefile = $overrides[$slug];
                                                    if ($overridefile && file_exists($customcoverdir . '/' . $overridefile)) {
                                                        $cover = $customcoverurl . '/' . $overridefile;
                                                        $dynamiccovermap[$slug] = $cover;
                                                        return $cover;
                                                    }
                                                }
                                                
                                                $generatedCandidates = [
                                                    'Gemini_Generated_Image_' . $slug . '.png',
                                                    'booktype-' . $slug . '.png',
                                                    $slug . '.png'
                                                ];
                                                foreach ($generatedCandidates as $candidate) {
                                                    if (file_exists($customcoverdir . '/' . $candidate)) {
                                                        $cover = $customcoverurl . '/' . $candidate;
                                                        $dynamiccovermap[$slug] = $cover;
                                                        return $cover;
                                                    }
                                                }
                                                
                                                if (isset($dynamiccovermap[$slug])) {
                                                    return $dynamiccovermap[$slug];
                                                }
                                            }
                                            
                                            return '';
                                        }
                                        
                                        if (empty($cycle)) {
                                            $type = 'Student Book';
                                            return isset($defaults['student_book']) ? $defaults['student_book'] : '';
                                        }
                                        
                                        $cycleIndex = $index % count($cycle);
                                        $cover = $cycle[$cycleIndex];
                                        $index++;
                                        
                                        if (!empty($slug)) {
                                            $dynamiccovermap[$slug] = $cover;
                                        }
                                        
                                        return $cover;
                                    }
                                }
                                
                                // Default course covers
                                $coursecoverdefaults = [
                                    'student_book' => $CFG->wwwroot . '/theme/remui_kids/CradsImg/Gemini_Generated_Image_96dybo96dybo96dy.png',
                                    'student_course' => $CFG->wwwroot . '/theme/remui_kids/CradsImg/Gemini_Generated_Image_hcwxdbhcwxdbhcwx.png',
                                    'teacher_resource' => $CFG->wwwroot . '/theme/remui_kids/CradsImg/Gemini_Generated_Image_7xb0pl7xb0pl7xb0.png',
                                    'worksheet_pack' => $CFG->wwwroot . '/theme/remui_kids/CradsImg/Gemini_Generated_Image_ciywx0ciywx0ciyw.png',
                                    'teacher_guide' => $CFG->wwwroot . '/theme/remui_kids/CradsImg/Gemini_Generated_Image_k3ktqnk3ktqnk3kt.png',
                                    'practice_book' => $CFG->wwwroot . '/theme/remui_kids/CradsImg/Gemini_Generated_Image_hz61skhz61skhz61.png',
                                    'teacher_book' => $CFG->wwwroot . '/theme/remui_kids/CradsImg/Gemini_Generated_Image_kmjtndkmjtndkmjt.png'
                                ];
                                $coursecovercycle = array_values($coursecoverdefaults);
                                
                                // Convert course object to array for function compatibility
                                $course_array = is_object($course) ? (array)$course : $course;
                                $booktypelabel = theme_remui_kids_detect_course_book_type($course_array);
                                $fallbackLabel = $booktypelabel;
                                
                                // Use a static counter for fallback index
                                if (!isset($GLOBALS['parent_dashboard_fallback_index'])) {
                                    $GLOBALS['parent_dashboard_fallback_index'] = 0;
                                }
                                $fallbackindex = &$GLOBALS['parent_dashboard_fallback_index'];
                                
                                $fallback_image = theme_remui_kids_select_course_cover($course_array, $coursecoverdefaults, $coursecovercycle, $fallbackindex, $fallbackLabel);
                                
                                if (!empty($fallback_image)) {
                                    $course_image = $fallback_image;
                                }
                            } catch (Exception $e) {
                                // If fallback fails, leave empty
                                $course_image = '';
                            }
                        }
                        
                        // Get course summary/description
                        $course_summary = '';
                        if (!empty($course->summary)) {
                            $course_summary = strip_tags($course->summary);
                            $course_summary = shorten_text($course_summary, 120);
                        }
                        
                        // Get course start date
                        $start_date = '';
                        if (!empty($course->startdate) && $course->startdate > 0) {
                            $start_date = userdate($course->startdate, '%d %b %Y');
                        }
                        
                        // Get course end date
                        $end_date = '';
                        if (!empty($course->enddate) && $course->enddate > 0) {
                            $end_date = userdate($course->enddate, '%d %b %Y');
                        }
                        
                        // Get course category
                        $category_name = '';
                        if (!empty($course->category)) {
                            try {
                                $category = $DB->get_record('course_categories', ['id' => $course->category], 'name');
                                if ($category) {
                                    $category_name = $category->name;
                                }
                            } catch (Exception $e) {
                                // Ignore category fetch errors
                            }
                        }
                        
                        $all_courses[] = [
                            'id' => $course->id,
                            'name' => $course->fullname,
                            'shortname' => $course->shortname,
                            'student' => $child_name,
                            'student_id' => $child->id,
                            'progress' => $progress,
                            'is_complete' => $is_complete,
                            'activities' => $activity_counts,
                            'image' => $course_image,
                            'summary' => $course_summary,
                            'start_date' => $start_date,
                            'end_date' => $end_date,
                            'category' => $category_name
                        ];
                    }
                }
                
                // Debug: Show course count
                if ($debug_mode) {
                    echo "<div style='background: #3b82f6; color: white; padding: 12px; border-radius: 6px; margin-bottom: 15px; font-weight: bold;'>";
                    echo "" . " TOTAL COURSES TO DISPLAY: " . count($all_courses);
                    echo "</div>";
                }
                
                // Show message if no courses
                if (empty($all_courses)) {
                    echo "<div style='grid-column: 1/-1; text-align: center; padding: 60px 20px; background: #f9fafb; border-radius: 12px;'>";
                    echo "<i class='fas fa-book' style='font-size: 64px; color: #d1d5db; margin-bottom: 20px;'></i>";
                    echo "<h3 style='color: #6b7280; margin: 0 0 10px 0;'>No Courses Found</h3>";
                    if ($selected_child_id && $selected_child_id !== 'all' && $selected_child_id != 0) {
                        echo "<p style='color: #9ca3af; margin: 0;'>The selected child is not enrolled in any courses yet.</p>";
                    } else {
                        echo "<p style='color: #9ca3af; margin: 0;'>No children are enrolled in any courses yet.</p>";
                    }
                    echo "</div>";
                }
                
                // Display course cards
                foreach ($all_courses as $course):
                    // Light neutral progress color scheme
                    $progress_color = $course['progress'] >= 75 ? '#64748b' : ($course['progress'] >= 50 ? '#94a3b8' : ($course['progress'] >= 25 ? '#cbd5e1' : '#e2e8f0'));
                    $progress_gradient = $course['progress'] >= 75 ? 'linear-gradient(135deg, #64748b 0%, #475569 100%)' : ($course['progress'] >= 50 ? 'linear-gradient(135deg, #94a3b8 0%, #64748b 100%)' : ($course['progress'] >= 25 ? 'linear-gradient(135deg, #cbd5e1 0%, #94a3b8 100%)' : 'linear-gradient(135deg, #e2e8f0 0%, #cbd5e1 100%)'));
                    $course_image = !empty($course['image']) ? $course['image'] : '';
                    $total_activities = array_sum($course['activities']);
                ?>
                <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/parent/parent_course_view.php?courseid=<?php echo $course['id']; ?>&child=<?php echo $course['student_id']; ?>" 
                   class="course-card-modern" 
                   style="background: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.08), 0 1px 3px rgba(0,0,0,0.05); border: 1px solid #e5e7eb; transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1); position: relative; display: flex; flex-direction: column; text-decoration: none; color: inherit; height: 100%;">
                    
                    <!-- Course Image Header with Enhanced Design -->
                    <div class="course-image-wrapper-modern" style="position: relative; height: 180px; overflow: hidden; background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);">
                        <?php if (!empty($course_image)): ?>
                        <img src="<?php echo htmlspecialchars($course_image, ENT_QUOTES); ?>" 
                             alt="<?php echo htmlspecialchars($course['name']); ?>"
                             class="course-image-modern"
                             style="width: 100%; height: 100%; object-fit: cover; transition: transform 0.6s cubic-bezier(0.4, 0, 0.2, 1); display: block;"
                             loading="lazy"
                             onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                        <?php else: ?>
                        <!-- Enhanced Fallback with Light Gradient -->
                        <div class="course-image-fallback-modern" 
                             style="width: 100%; height: 100%; background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 50%, #e2e8f0 100%); display: flex; align-items: center; justify-content: center; position: relative;">
                            <div style="background: rgba(255,255,255,0.6); backdrop-filter: blur(10px); border-radius: 50%; width: 80px; height: 80px; display: flex; align-items: center; justify-content: center; border: 3px solid rgba(148,163,184,0.3);">
                                <i class="fas fa-book-open" style="font-size: 36px; color: #94a3b8;"></i>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Enhanced Overlay Gradient - Light -->
                        <div style="position: absolute; inset: 0; background: linear-gradient(to bottom, rgba(0,0,0,0) 0%, rgba(0,0,0,0.1) 60%, rgba(0,0,0,0.4) 100%); pointer-events: none; z-index: 1;"></div>
                        
                        <!-- Progress Badge - Enhanced Design -->
                        <div style="position: absolute; top: 12px; right: 12px; background: rgba(255,255,255,0.95); backdrop-filter: blur(10px); padding: 6px 12px; border-radius: 20px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); border: 1px solid rgba(226,232,240,0.8); z-index: 3;">
                            <span style="font-size: 12px; font-weight: 800; color: #475569; display: flex; align-items: center; gap: 6px; letter-spacing: 0.3px;">
                                <i class="fas fa-chart-line" style="font-size: 11px; color: #64748b;"></i> <?php echo $course['progress']; ?>%
                            </span>
                        </div>
                        
                        <!-- Course Title Overlay - Enhanced -->
                        <div style="position: absolute; bottom: 0; left: 0; right: 0; padding: 20px 16px 16px; z-index: 2;">
                            <h3 style="margin: 0 0 8px 0; font-size: 16px; font-weight: 700; color: #1e293b; line-height: 1.3; text-shadow: 0 1px 3px rgba(255,255,255,0.8); letter-spacing: -0.2px;">
                                <?php echo htmlspecialchars($course['name']); ?>
                            </h3>
                            <div style="display: flex; align-items: center; gap: 8px; margin-top: 8px;">
                                <div style="background: rgba(255,255,255,0.7); backdrop-filter: blur(10px); padding: 4px 10px; border-radius: 12px; border: 1px solid rgba(226,232,240,0.5);">
                                    <p style="margin: 0; font-size: 11px; color: #475569; font-weight: 600; display: flex; align-items: center; gap: 5px;">
                                        <i class="fas fa-user-circle" style="font-size: 10px; color: #64748b;"></i> <?php echo htmlspecialchars($course['student']); ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Card Body - Enhanced Design -->
                    <div style="padding: 20px; flex: 1; display: flex; flex-direction: column; gap: 16px; background: #ffffff;">
                        
                        <!-- Course Code Badge - Modern Design -->
                        <div style="display: flex; justify-content: space-between; align-items: center; padding: 10px 14px; background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%); border-radius: 10px; border: 1px solid #e2e8f0;">
                            <span style="font-size: 10px; color: #64748b; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px; display: flex; align-items: center; gap: 6px;">
                                <i class="fas fa-hashtag" style="font-size: 10px;"></i> Course Code
                            </span>
                            <span style="font-size: 12px; font-weight: 800; color: #1e293b; background: #ffffff; padding: 4px 12px; border-radius: 8px; border: 1px solid #cbd5e1; box-shadow: 0 1px 3px rgba(0,0,0,0.05); letter-spacing: 0.3px;">
                                <?php echo htmlspecialchars($course['shortname']); ?>
                            </span>
                        </div>
                        
                        <!-- Course Details Section -->
                        <div style="display: flex; flex-direction: column; gap: 12px;">
                            <!-- Course Summary/Description -->
                            <?php if (!empty($course['summary'])): ?>
                            <div style="padding: 12px; background: #f8fafc; border-radius: 10px; border: 1px solid #e2e8f0;">
                                <div style="font-size: 10px; color: #64748b; margin-bottom: 6px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.6px; display: flex; align-items: center; gap: 5px;">
                                    <i class="fas fa-info-circle" style="font-size: 10px;"></i> Description
                                </div>
                                <p style="margin: 0; font-size: 12px; color: #475569; line-height: 1.5; font-weight: 500;">
                                    <?php echo htmlspecialchars($course['summary']); ?>
                                </p>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Course Category -->
                            <?php if (!empty($course['category'])): ?>
                            <div style="display: flex; align-items: center; gap: 10px; padding: 8px 12px; background: #f8fafc; border-radius: 8px; border: 1px solid #e2e8f0;">
                                <i class="fas fa-folder" style="font-size: 12px; color: #94a3b8;"></i>
                                <span style="font-size: 11px; color: #64748b; font-weight: 600;">Category:</span>
                                <span style="font-size: 11px; color: #475569; font-weight: 700;"><?php echo htmlspecialchars($course['category']); ?></span>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Course Dates -->
                            <?php if (!empty($course['start_date']) || !empty($course['end_date'])): ?>
                            <div style="display: flex; flex-direction: column; gap: 6px; padding: 10px 12px; background: #f8fafc; border-radius: 8px; border: 1px solid #e2e8f0;">
                                <?php if (!empty($course['start_date'])): ?>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <i class="fas fa-calendar-alt" style="font-size: 11px; color: #94a3b8;"></i>
                                    <span style="font-size: 11px; color: #64748b; font-weight: 600;">Start:</span>
                                    <span style="font-size: 11px; color: #475569; font-weight: 700;"><?php echo htmlspecialchars($course['start_date']); ?></span>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($course['end_date'])): ?>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <i class="fas fa-calendar-check" style="font-size: 11px; color: #94a3b8;"></i>
                                    <span style="font-size: 11px; color: #64748b; font-weight: 600;">End:</span>
                                    <span style="font-size: 11px; color: #475569; font-weight: 700;"><?php echo htmlspecialchars($course['end_date']); ?></span>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Progress Section - Enhanced -->
                        <div style="margin-top: auto; padding: 14px; background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%); border-radius: 12px; border: 1px solid #e2e8f0;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                                <span style="font-size: 11px; color: #64748b; font-weight: 700; text-transform: uppercase; letter-spacing: 0.6px; display: flex; align-items: center; gap: 6px;">
                                    <i class="fas fa-tasks" style="font-size: 11px; color: <?php echo $progress_color; ?>;"></i> Completion
                                </span>
                                <span style="font-size: 18px; font-weight: 800; color: <?php echo $progress_color; ?>; letter-spacing: -0.5px;">
                                    <?php echo $course['progress']; ?>%
                                </span>
                            </div>
                            <div style="background: #e2e8f0; border-radius: 10px; height: 10px; overflow: hidden; position: relative; box-shadow: inset 0 2px 4px rgba(0,0,0,0.06);">
                                <div style="background: <?php echo $progress_gradient; ?>; height: 100%; width: <?php echo $course['progress']; ?>%; transition: width 0.8s cubic-bezier(0.4, 0, 0.2, 1); border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.15);"></div>
                            </div>
                        </div>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
            
            <style>
            /* Modern Course Card Styles */
            .enrolled-courses-section .course-card-modern {
                cursor: pointer;
            }
            .enrolled-courses-section .course-card-modern:hover {
                transform: translateY(-4px);
                box-shadow: 0 8px 24px rgba(0,0,0,0.12), 0 2px 8px rgba(0,0,0,0.08) !important;
                border-color: #cbd5e1 !important;
            }
            .enrolled-courses-section .course-card-modern:hover .course-image-modern {
                transform: scale(1.1);
            }
            .enrolled-courses-section .course-card-modern:hover .course-image-fallback-modern {
                background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 50%, #e2e8f0 100%);
            }
            
            /* Responsive adjustments */
            @media (max-width: 768px) {
                .enrolled-courses-section .course-card-modern {
                    margin-bottom: 0;
                }
            }
            </style>
        </div>
        <?php endif; ?>

        <!-- Notifications Section -->
        <?php if ($selected_child_id && $selected_child_id !== 'all' && $selected_child_id != 0): ?>
        <div class="parent-section notifications-section">
            <h2 class="section-title" style="display: flex; align-items: center; gap: 12px; font-size: 22px; font-weight: 800; color: #4b5563; margin-bottom: 24px;">
                <i class="fas fa-bell" style="color: #3b82f6;"></i>
                Notifications
            </h2>
            
            <div class="notifications-list" style="display: grid; gap: 16px;">
                <?php if (!empty($notifications)): ?>
                <?php foreach ($notifications as $notification): ?>
                <div class="notification-item" style="display: flex; gap: 16px; align-items: flex-start; background: #ffffff; border: 1px solid #e9ecef; border-radius: 12px; padding: 18px; box-shadow: 0 2px 6px rgba(0,0,0,0.04);">
                    <div class="notification-date" style="background: #f8f9fa; border: 1px solid #e9ecef; border-radius: 8px; padding: 8px 12px; font-size: 12px; font-weight: 600; color: #495057; min-width: 110px; text-align: center;">
                            <?php echo htmlspecialchars($notification['time'] ?? $notification['date'] ?? date('d M, Y')); ?>
                    </div>
                    <div style="flex: 1;">
                            <?php if (!empty($notification['title'])): ?>
                            <h4 style="margin: 0 0 8px 0; font-size: 15px; font-weight: 700; color: #212529;">
                                <?php echo htmlspecialchars($notification['title']); ?>
                            </h4>
                            <?php endif; ?>
                        <p class="notification-message" style="margin: 0 0 8px 0; font-size: 14px; color: #212529; line-height: 1.5;">
                                <?php echo htmlspecialchars($notification['message'] ?? ''); ?>
                        </p>
                        <div class="notification-meta" style="display: flex; justify-content: space-between; align-items: center; font-size: 12px; color: #6c757d;">
                                <span class="notification-sender" style="font-weight: 600;">
                                    <?php echo htmlspecialchars($notification['from'] ?? $notification['sender'] ?? 'System'); ?>
                                </span>
                                <span class="notification-time">
                                    <?php echo htmlspecialchars($notification['time'] ?? ''); ?>
                                </span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php else: ?>
                    <div style="text-align: center; padding: 40px 20px; color: #6c757d;">
                        <i class="fas fa-bell-slash" style="font-size: 48px; color: #dee2e6; margin-bottom: 16px;"></i>
                        <p style="margin: 0; font-size: 16px;">No new notifications</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- UNIFIED RECENT ACTIVITIES SECTION - Highly Visible -->
        <?php 
        // Combine all recent activities into one array
        $recent_activities_combined = [];
        
        if ($selected_child_id && $selected_child_id !== 'all' && $selected_child_id != 0) {
            // Add submissions
            if (!empty($recent_submissions)) {
                foreach ($recent_submissions as $sub) {
                    $recent_activities_combined[] = [
                        'type' => 'submission',
                        'icon' => 'fa-file-upload',
                        'color' => '#3b82f6',
                        'bg_color' => '#dbeafe',
                        'title' => htmlspecialchars($sub->assignname ?? 'Assignment Submission'),
                        'course' => htmlspecialchars($sub->coursename ?? ''),
                        'student' => fullname($sub),
                        'time' => $sub->timemodified ?? time(),
                        'status' => ucfirst($sub->status ?? 'submitted'),
                        'action' => 'Submitted'
                    ];
                }
            }
            
            // Add graded items
            if (!empty($graded_items)) {
                foreach (array_slice($graded_items, 0, 10) as $grade) {
                    $percentage = $grade->grademax > 0 ? round(($grade->finalgrade / $grade->grademax) * 100, 1) : 0;
                    $recent_activities_combined[] = [
                        'type' => 'grade',
                        'icon' => 'fa-star',
                        'color' => '#10b981',
                        'bg_color' => '#d1fae5',
                        'title' => htmlspecialchars($grade->itemname ?? 'Grade'),
                        'course' => htmlspecialchars($grade->coursename ?? ''),
                        'student' => fullname($grade),
                        'time' => $grade->timemodified ?? time(),
                        'status' => $percentage . '%',
                        'action' => 'Graded'
                    ];
                }
            }
            
            // Add lesson attempts
            if (!empty($lesson_attempts)) {
                foreach (array_slice($lesson_attempts, 0, 10) as $lesson) {
                    $recent_activities_combined[] = [
                        'type' => 'lesson',
                        'icon' => 'fa-book-reader',
                        'color' => '#8b5cf6',
                        'bg_color' => '#e9d5ff',
                        'title' => htmlspecialchars($lesson->lessonname ?? 'Lesson'),
                        'course' => htmlspecialchars($lesson->coursename ?? ''),
                        'student' => fullname($lesson),
                        'time' => $lesson->timeseen ?? time(),
                        'status' => $lesson->grade !== null ? number_format($lesson->grade, 1) . '%' : 'In Progress',
                        'action' => 'Completed'
                    ];
                }
            }
            
            // Add forum posts
            if (!empty($forum_posts)) {
                foreach (array_slice($forum_posts, 0, 10) as $post) {
                    $recent_activities_combined[] = [
                        'type' => 'forum',
                        'icon' => 'fa-comments',
                        'color' => '#f59e0b',
                        'bg_color' => '#fef3c7',
                        'title' => htmlspecialchars(($post->subject ?? '') ?: ('Re: ' . ($post->discussionname ?? ''))),
                        'course' => htmlspecialchars($post->coursename ?? ''),
                        'student' => fullname($post),
                        'time' => $post->created ?? time(),
                        'status' => htmlspecialchars($post->forumname ?? 'Forum'),
                        'action' => 'Posted'
                    ];
                }
            }
            
            // Sort by time (most recent first)
            usort($recent_activities_combined, function($a, $b) {
                return ($b['time'] ?? 0) - ($a['time'] ?? 0);
            });
            
            // Limit to 20 most recent
            $recent_activities_combined = array_slice($recent_activities_combined, 0, 20);
        }
        ?>
        
        <?php if (!empty($recent_activities_combined)): ?>
        <!-- Recent Activities & Submissions - Side by Side -->
        <style>
        .activities-submissions-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            margin-top: 20px;
        }
        @media (max-width: 1200px) {
            .activities-submissions-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }
        }
        </style>
        <div class="activities-submissions-grid">
            
        <!-- Recent Activities - Prominent Section -->
        <div class="parent-section" style="position: relative;">
            <!-- Eye-catching Header -->
            <div style="background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); padding: 18px 24px; border-radius: 8px 8px 0 0; box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3); position: relative; overflow: hidden;">
                <div style="position: absolute; top: -50%; right: -10%; width: 200px; height: 200px; background: rgba(255, 255, 255, 0.1); border-radius: 50%;"></div>
                <div style="position: absolute; bottom: -30%; left: -5%; width: 150px; height: 150px; background: rgba(255, 255, 255, 0.08); border-radius: 50%;"></div>
                <div class="activities-header" style="display: flex; align-items: center; justify-content: space-between; position: relative; z-index: 1; flex-wrap: wrap; gap: 12px;">
                    <div style="display: flex; align-items: center; gap: 14px; flex: 1; min-width: 0;">
                        <div style="width: 48px; height: 48px; background: rgba(255, 255, 255, 0.2); backdrop-filter: blur(10px); border-radius: 10px; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15); flex-shrink: 0;">
                            <i class="fas fa-history" style="font-size: 22px; color: white;"></i>
                        </div>
                        <div style="flex: 1; min-width: 0; word-wrap: break-word; word-break: break-word;">
                            <h2 style="margin: 0; font-size: 20px; font-weight: 700; color: white; letter-spacing: -0.3px; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; word-wrap: break-word; word-break: break-word;">
                                <span style="word-wrap: break-word; word-break: break-word;">Recent Activities</span>
                                <span style="background: rgba(255, 255, 255, 0.25); backdrop-filter: blur(10px); padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 700; border: 1px solid rgba(255, 255, 255, 0.3); flex-shrink: 0; white-space: nowrap;">
                                    <?php echo count($recent_activities_combined); ?>
                                </span>
                            </h2>
                            <p style="margin: 4px 0 0 0; font-size: 13px; color: rgba(255, 255, 255, 0.9); font-weight: 500; word-wrap: break-word; word-break: break-word;">
                                Latest activity from all courses
                            </p>
                        </div>
                    </div>
                    <div style="background: rgba(255, 255, 255, 0.2); backdrop-filter: blur(10px); padding: 8px 16px; border-radius: 6px; border: 1px solid rgba(255, 255, 255, 0.3); flex-shrink: 0; white-space: nowrap;">
                        <div style="font-size: 11px; color: rgba(255, 255, 255, 0.9); font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">Live Feed</div>
                    </div>
                </div>
            </div>
            
            <!-- Activities List -->
            <div style="background: #ffffff; border-radius: 0 0 8px 8px; border: 1px solid #e2e8f0; border-top: none; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06); max-height: 600px; overflow-y: auto;">
                <div style="display: grid; gap: 0;">
                    <?php foreach ($recent_activities_combined as $index => $activity): 
                        $time_ago = time() - $activity['time'];
                        $time_text = '';
                        if ($time_ago < 3600) {
                            $minutes = floor($time_ago / 60);
                            $time_text = $minutes <= 1 ? 'Just now' : $minutes . ' min ago';
                        } elseif ($time_ago < 86400) {
                            $hours = floor($time_ago / 3600);
                            $time_text = $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
                        } elseif ($time_ago < 604800) {
                            $days = floor($time_ago / 86400);
                            $time_text = $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
                        } else {
                            $time_text = userdate($activity['time'], '%d %b %Y');
                        }
                        
                        $is_even = $index % 2 == 0;
                        $row_bg = $is_even ? '#ffffff' : '#f8fafc';
                    ?>
                    <div class="activity-item" style="background: <?php echo $row_bg; ?>; padding: 16px 20px; border-bottom: 1px solid #f1f5f9; transition: all 0.2s ease; display: flex; align-items: flex-start; gap: 16px; position: relative; flex-wrap: wrap;" 
                         onmouseover="this.style.background='#f1f5f9'; this.style.transform='translateX(4px)';" 
                         onmouseout="this.style.background='<?php echo $row_bg; ?>'; this.style.transform='translateX(0)';">
                        <!-- Left: Icon -->
                        <div style="width: 44px; height: 44px; background: <?php echo $activity['bg_color']; ?>; border-radius: 10px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);">
                            <i class="fas <?php echo $activity['icon']; ?>" style="font-size: 18px; color: <?php echo $activity['color']; ?>;"></i>
                        </div>
                        
                        <!-- Center: Content -->
                        <div style="flex: 1; min-width: 0; max-width: 100%; word-wrap: break-word; word-break: break-word; overflow-wrap: break-word;">
                            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 6px; flex-wrap: wrap;">
                                <h4 style="margin: 0; font-size: 14px; font-weight: 700; color: #0f172a; line-height: 1.4; word-wrap: break-word; word-break: break-word; overflow-wrap: break-word; white-space: normal;">
                                    <?php echo $activity['title']; ?>
                                </h4>
                                <span style="background: <?php echo $activity['bg_color']; ?>; color: <?php echo $activity['color']; ?>; padding: 3px 10px; border-radius: 12px; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.3px; border: 1px solid <?php echo $activity['color']; ?>30; flex-shrink: 0; white-space: nowrap;">
                                    <?php echo $activity['action']; ?>
                                </span>
                            </div>
                            <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap; font-size: 12px; color: #64748b; word-wrap: break-word; word-break: break-word;">
                                <span style="display: flex; align-items: center; gap: 5px; font-weight: 500; word-wrap: break-word; word-break: break-word; overflow-wrap: break-word;">
                                    <i class="fas fa-book" style="font-size: 10px; color: #94a3b8; flex-shrink: 0;"></i>
                                    <span style="word-wrap: break-word; word-break: break-word;"><?php echo $activity['course']; ?></span>
                                </span>
                                <span style="display: flex; align-items: center; gap: 5px; font-weight: 500; word-wrap: break-word; word-break: break-word; overflow-wrap: break-word;">
                                    <i class="fas fa-user" style="font-size: 10px; color: #94a3b8; flex-shrink: 0;"></i>
                                    <span style="word-wrap: break-word; word-break: break-word;"><?php echo $activity['student']; ?></span>
                                </span>
                            </div>
                        </div>
                        
                        <!-- Right: Time & Status -->
                        <div class="activity-meta" style="text-align: right; min-width: 140px; flex-shrink: 0;">
                            <div style="font-size: 12px; font-weight: 700; color: <?php echo $activity['color']; ?>; margin-bottom: 4px; white-space: nowrap;">
                                <?php echo $time_text; ?>
                            </div>
                            <div style="font-size: 11px; color: #64748b; font-weight: 500; white-space: nowrap;">
                                <?php echo userdate($activity['time'], '%H:%M'); ?>
                            </div>
                            <?php if (!empty($activity['status'])): ?>
                            <div style="margin-top: 6px; padding: 4px 10px; background: #f8fafc; border-radius: 6px; font-size: 11px; font-weight: 600; color: #475569; display: inline-block; border: 1px solid #e2e8f0; white-space: nowrap;">
                                <?php echo $activity['status']; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- Footer -->
            <div style="background: #f8fafc; padding: 12px 20px; border-radius: 0 0 8px 8px; border-top: 1px solid #e2e8f0; text-align: center;">
                <p style="margin: 0; font-size: 12px; color: #64748b; font-weight: 500;">
                    <i class="fas fa-sync-alt" style="margin-right: 6px; color: #94a3b8;"></i>
                    Showing latest <?php echo count($recent_activities_combined); ?> activities • Updates automatically
                </p>
            </div>
            
            <style>
            /* Custom Scrollbar */
            div[style*="max-height: 600px"]::-webkit-scrollbar {
                width: 6px;
            }
            
            div[style*="max-height: 600px"]::-webkit-scrollbar-track {
                background: #f8fafc;
            }
            
            div[style*="max-height: 600px"]::-webkit-scrollbar-thumb {
                background: #cbd5e1;
                border-radius: 3px;
            }
            
            div[style*="max-height: 600px"]::-webkit-scrollbar-thumb:hover {
                background: #94a3b8;
            }
            </style>
        </div>
        
        <!-- Recent Submissions Section -->
        <?php if ($selected_child_id && $selected_child_id !== 'all' && $selected_child_id != 0 && !empty($recent_submissions)): ?>
        <div class="parent-section">
            <h2 class="section-title">
                <i class="fas fa-file-upload"></i>
                Recent Submissions (Last 20)
            </h2>
            
            <div style="display: grid; gap: 15px;">
                <?php foreach ($recent_submissions as $submission): ?>
                <div style="background: #ffffff; padding: 20px; border-radius: 12px; border: 1px solid #e9ecef; box-shadow: 0 1px 4px rgba(0,0,0,0.05);">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px; gap: 12px;">
                        <div style="flex: 1;">
                            <h4 style="margin: 0 0 4px 0; font-size: 15px; font-weight: 600; color: #212529; display: flex; align-items: center; gap: 6px;">
                                <i class="fas fa-file-alt" style="color: #6c757d;"></i>
                                <?php echo htmlspecialchars($submission->assignname ?? ''); ?>
                            </h4>
                            <p style="margin: 0; font-size: 13px; color: #6c757d;">
                                <?php echo htmlspecialchars($submission->coursename ?? ''); ?>
                            </p>
                        </div>
                        <div style="text-align: right;">
                            <span style="background: #f8f9fa; color: #495057; padding: 4px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; border: 1px solid #e5e7eb;">
                                <?php echo ucfirst($submission->status); ?>
                            </span>
                        </div>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 10px; padding-top: 10px; border-top: 1px solid #e5e7eb;">
                        <span style="font-size: 13px; color: #6c757d; display: flex; align-items: center; gap: 6px;">
                            <i class="fas fa-user"></i> <?php echo fullname($submission); ?>
                        </span>
                        <span style="font-size: 13px; color: #6c757d; display: flex; align-items: center; gap: 6px;">
                            <i class="fas fa-clock"></i> <?php echo userdate($submission->timemodified, '%d %b %Y, %H:%M'); ?>
                        </span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php else: ?>
        <!-- Empty placeholder if no submissions -->
        <div></div>
        <?php endif; ?>
        
        </div> <!-- End of side-by-side grid -->
        <?php endif; ?> <!-- End of recent_activities_combined check -->
        
        <!-- Recent Grades Section -->
        <?php if ($selected_child_id && $selected_child_id !== 'all' && $selected_child_id != 0 && !empty($graded_items)): ?>
        <div class="parent-section recent-grades-section" style="margin-top: 18px;">
            <h2 class="section-title" style="display: flex; align-items: center; gap: 8px; font-size: 16px; font-weight: 700; color: #475569; margin-bottom: 12px;">
                <i class="fas fa-star" style="color: #3b82f6; font-size: 16px;"></i>
                Recent Grades 
                <span style="background: #dbeafe; color: #1e3a8a; padding: 3px 8px; border-radius: 5px; font-size: 10px; font-weight: 700; border: 1px solid #93c5fd;">
                    Last 20
                </span>
            </h2>
            
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 12px;">
                <?php foreach ($graded_items as $grade): 
                    $percentage = $grade->grademax > 0 ? ($grade->finalgrade / $grade->grademax) * 100 : 0;
                    $grade_color = $percentage >= 75 ? '#3b82f6' : ($percentage >= 50 ? '#60a5fa' : '#93c5fd');
                    $badge_bg = $percentage >= 75 ? '#dbeafe' : ($percentage >= 50 ? '#e0f2fe' : '#f0f9ff');
                    $badge_text = $percentage >= 75 ? '#1e40af' : ($percentage >= 50 ? '#0369a1' : '#0c4a6e');
                ?>
                <div style="background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%); padding: 14px; border-radius: 9px; box-shadow: 0 1px 4px rgba(15,23,42,0.05); border: 1px solid #e2e8f0; transition: all 0.3s ease; position: relative; overflow: hidden;" 
                     onmouseover="this.style.transform='translateY(-1px)'; this.style.boxShadow='0 4px 10px rgba(15,23,42,0.08)'; this.style.borderColor='#cbd5f5';" 
                     onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 1px 4px rgba(15,23,42,0.05)'; this.style.borderColor='#e2e8f0';">
                    <div style="position: absolute; top: 0; right: 0; width: 46px; height: 46px; background: linear-gradient(135deg, <?php echo $grade_color; ?>22, transparent); border-radius: 0 9px 0 46px; pointer-events: none;"></div>
                    
                    <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 8px; position: relative; z-index: 1;">
                        <h4 style="margin: 0; font-size: 13px; font-weight: 700; color: #1f2937; line-height: 1.3; flex: 1; padding-right: 8px;">
                            <?php echo htmlspecialchars($grade->itemname ?? $grade->itemmodule ?? ''); ?>
                        </h4>
                        <div style="background: linear-gradient(135deg, <?php echo $grade_color; ?>, <?php echo $grade_color; ?>cc); color: white; padding: 3px 8px; border-radius: 5px; font-size: 11px; font-weight: 700; box-shadow: 0 1px 3px rgba(59,130,246,0.25); white-space: nowrap;">
                            <?php echo round($percentage); ?>%
                        </div>
                    </div>
                    
                    <p style="margin: 0 0 10px 0; font-size: 11px; color: #6b7280; font-weight: 600; position: relative; z-index: 1;">
                        <i class="fas fa-book" style="color: #3b82f6; margin-right: 5px; font-size: 11px;"></i><?php echo htmlspecialchars($grade->coursename ?? ''); ?>
                    </p>
                    
                    <div style="display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 8px; position: relative; z-index: 1;">
                        <span style="font-size: 24px; font-weight: 800; color: <?php echo $grade_color; ?>; line-height: 1;">
                            <?php echo number_format($grade->finalgrade, 1); ?>
                        </span>
                        <span style="font-size: 12px; color: #6b7280; font-weight: 600;">
                            / <?php echo number_format($grade->grademax, 1); ?>
                        </span>
                    </div>
                    
                    <div style="background: #f3f4f6; border-radius: 7px; height: 6px; overflow: hidden; margin-bottom: 8px; box-shadow: inset 0 1px 2px rgba(0,0,0,0.04); position: relative; z-index: 1;">
                        <div style="background: linear-gradient(90deg, <?php echo $grade_color; ?>, <?php echo $grade_color; ?>cc); height: 100%; width: <?php echo $percentage; ?>%; transition: width 0.3s ease; box-shadow: 0 0 4px rgba(59,130,246,0.25); border-radius: 7px;"></div>
                    </div>
                    
                    <div style="display: flex; justify-content: space-between; align-items: center; font-size: 10px; color: #6b7280; padding-top: 8px; border-top: 1px solid #eef2f7; position: relative; z-index: 1;">
                        <span style="font-weight: 600; display: flex; align-items: center; gap: 4px;">
                            <i class="fas fa-user-circle" style="color: #3b82f6; font-size: 10px;"></i><?php echo htmlspecialchars(fullname($grade) ?? ''); ?>
                        </span>
                        <span style="background: <?php echo $badge_bg; ?>; color: <?php echo $badge_text; ?>; padding: 2px 7px; border-radius: 4px; font-weight: 700; border: 1px solid <?php echo $grade_color; ?>30; font-size: 9px;">
                            <?php echo $percentage >= 75 ? 'Excellent' : ($percentage >= 50 ? 'Good' : 'Needs Work'); ?>
                        </span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Lesson Activity Section -->
        <?php if ($selected_child_id && $selected_child_id !== 'all' && $selected_child_id != 0 && !empty($lesson_attempts)): ?>
        <div class="parent-section" style="margin-top: 30px;">
            <h2 class="section-title">
                <i class="fas fa-book-reader"></i>
                Recent Lesson Activity (Last 20)
            </h2>
            
            <div style="display: grid; gap: 12px;">
                <?php foreach ($lesson_attempts as $attempt): ?>
                <div style="background: #ffffff; padding: 18px; border-radius: 10px; border: 1px solid #e9ecef; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 1px 4px rgba(0,0,0,0.04); gap: 12px;">
                    <div style="flex: 1;">
                        <h4 style="margin: 0 0 4px 0; font-size: 15px; font-weight: 600; color: #212529; display: flex; align-items: center; gap: 6px;">
                            <i class="fas fa-book" style="color: #6c757d;"></i>
                            <?php echo htmlspecialchars($attempt->lessonname ?? ''); ?>
                        </h4>
                        <p style="margin: 0; font-size: 13px; color: #6c757d;">
                            <?php echo htmlspecialchars($attempt->coursename ?? ''); ?> — <?php echo fullname($attempt); ?>
                        </p>
                    </div>
                    <div style="text-align: right;">
                        <?php if ($attempt->grade !== null): ?>
                        <div style="font-size: 18px; font-weight: 600; color: #212529; margin-bottom: 4px;">
                            <?php echo number_format($attempt->grade, 1); ?>%
                        </div>
                        <?php endif; ?>
                        <div style="font-size: 12px; color: #6c757d; display: flex; align-items: center; justify-content: flex-end; gap: 6px;">
                            <i class="fas fa-clock"></i> <?php echo userdate($attempt->timeseen, '%d %b, %H:%M'); ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Forum Activity Section -->
        <?php if ($selected_child_id && $selected_child_id !== 'all' && $selected_child_id != 0 && !empty($forum_posts)): ?>
        <div class="parent-section" style="margin-top: 30px;">
            <h2 class="section-title">
                <i class="fas fa-comments"></i>
                Recent Forum Posts (Last 20)
            </h2>
            
            <div style="display: grid; gap: 15px;">
                <?php foreach ($forum_posts as $post): ?>
                <div style="background: #ffffff; padding: 20px; border-radius: 12px; border: 1px solid #e9ecef; box-shadow: 0 1px 4px rgba(0,0,0,0.05);">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px; gap: 12px;">
                        <div style="flex: 1;">
                            <h4 style="margin: 0 0 5px 0; font-size: 15px; font-weight: 600; color: #212529; display: flex; align-items: center; gap: 6px;">
                                <i class="fas fa-comment" style="color: #6c757d;"></i>
                                <?php echo htmlspecialchars(($post->subject ?? '') ?: ('Re: ' . ($post->discussionname ?? ''))); ?>
                            </h4>
                            <p style="margin: 0; font-size: 12px; color: #6c757d;">
                                Forum: <?php echo htmlspecialchars($post->forumname ?? ''); ?> — <?php echo htmlspecialchars($post->coursename ?? ''); ?>
                            </p>
                        </div>
                        <span style="font-size: 12px; color: #6c757d; white-space: nowrap; display: flex; align-items: center; gap: 6px;">
                            <i class="fas fa-clock"></i> <?php echo userdate($post->created, '%d %b %Y'); ?>
                        </span>
                    </div>
                    <div style="background: #f8f9fa; padding: 12px; border-radius: 8px; font-size: 13px; color: #495057; line-height: 1.6;">
                        <?php echo substr(strip_tags($post->message), 0, 200) . (strlen($post->message) > 200 ? '...' : ''); ?>
                    </div>
                    <div style="margin-top: 10px; font-size: 12px; color: #6c757d; display: flex; align-items: center; gap: 6px;">
                        <i class="fas fa-user"></i> <?php echo fullname($post); ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- All Course Activities Section - Enhanced -->
        <?php if ($selected_child_id && $selected_child_id !== 'all' && $selected_child_id != 0 && !empty($all_activity_data)): ?>
        <div class="parent-section" style="margin-top: 20px; position: relative;">
            <!-- Section Header with Badge -->
            <div class="activities-content-header" style="background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%); padding: 14px 16px; border-radius: 8px; margin-bottom: 14px; border: 1px solid #e2e8f0; box-shadow: 0 1px 4px rgba(15,23,42,0.04); position: relative; z-index: 1; box-sizing: border-box;">
                <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
                    <div style="flex: 1; min-width: 0; word-wrap: break-word; word-break: break-word;">
                        <h2 style="font-size: 16px; font-weight: 700; margin: 0; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; color: #1e293b; word-wrap: break-word; word-break: break-word;">
                            <i class="fas fa-th-list" style="color: #3b82f6; font-size: 16px; flex-shrink: 0;"></i>
                            <span style="word-wrap: break-word; word-break: break-word;">All Course Activities & Content</span>
                        </h2>
                    </div>
                    <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap; flex-shrink: 0;">
                        <div style="background: linear-gradient(135deg, #eff6ff, #dbeafe); padding: 8px 14px; border-radius: 6px; border: 1px solid #bfdbfe; white-space: nowrap;">
                            <div style="font-size: 9px; color: #1e40af; font-weight: 700; text-transform: uppercase; letter-spacing: 0.3px; margin-bottom: 1px;">Total</div>
                            <div style="font-size: 18px; font-weight: 800; color: #1e3a8a; line-height: 1;">
                                <?php echo count($all_activity_data); ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Enhanced Table -->
            <div class="activities-content-table-container" style="background: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 4px rgba(15,23,42,0.04); border: 1px solid #e2e8f0; position: relative; z-index: 1; box-sizing: border-box;">
                <div style="overflow-x: auto; max-height: 600px; overflow-y: auto; -webkit-overflow-scrolling: touch;">
                    <table class="activities-content-table" style="width: 100%; border-collapse: separate; border-spacing: 0; table-layout: auto;">
                        <thead style="position: sticky; top: 0; z-index: 10; background: linear-gradient(180deg, #f8fafc 0%, #f1f5f9 100%);">
                            <tr style="border-bottom: 2px solid #e2e8f0;">
                                <th style="padding: 10px 12px; text-align: left; color: #475569; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; border-bottom: none; position: relative;">
                                    <div style="display: flex; align-items: center; gap: 5px;">
                                        <i class="fas fa-tag" style="font-size: 10px; color: #64748b;"></i>
                                        <span>Type</span>
                                    </div>
                                </th>
                                <th style="padding: 10px 12px; text-align: left; color: #475569; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; border-bottom: none; position: relative;">
                                    <div style="display: flex; align-items: center; gap: 5px;">
                                        <i class="fas fa-bookmark" style="font-size: 10px; color: #64748b;"></i>
                                        <span>Activity Name</span>
                                    </div>
                                </th>
                                <th style="padding: 10px 12px; text-align: left; color: #475569; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; border-bottom: none; position: relative;">
                                    <div style="display: flex; align-items: center; gap: 5px;">
                                        <i class="fas fa-book" style="font-size: 10px; color: #64748b;"></i>
                                        <span>Course</span>
                                    </div>
                                </th>
                                <th style="padding: 10px 12px; text-align: left; color: #475569; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; border-bottom: none; position: relative;">
                                    <div style="display: flex; align-items: center; gap: 5px;">
                                        <i class="fas fa-user" style="font-size: 10px; color: #64748b;"></i>
                                        <span>Student</span>
                                    </div>
                                </th>
                                <th style="padding: 10px 12px; text-align: left; color: #475569; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; border-bottom: none; position: relative;">
                                    <div style="display: flex; align-items: center; gap: 5px;">
                                        <i class="fas fa-calendar" style="font-size: 10px; color: #64748b;"></i>
                                        <span>Added</span>
                                    </div>
                                </th>
                                <th style="padding: 10px 12px; text-align: center; color: #475569; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; border-bottom: none; position: relative;">
                                    <div style="display: flex; align-items: center; justify-content: center; gap: 5px;">
                                        <i class="fas fa-eye" style="font-size: 10px; color: #64748b;"></i>
                                        <span>Status</span>
                                    </div>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $row_index = 0;
                            foreach (array_slice($all_activity_data, 0, 100) as $activity): 
                                $row_bg = $row_index % 2 == 0 ? '#ffffff' : '#f8fafc';
                                
                                // Professional color coding by type
                                $type_colors = [
                                    'assign' => ['icon' => 'file-alt', 'color' => '#3b82f6'],
                                    'quiz' => ['icon' => 'clipboard-check', 'color' => '#8b5cf6'],
                                    'lesson' => ['icon' => 'book-reader', 'color' => '#10b981'],
                                    'forum' => ['icon' => 'comments', 'color' => '#f59e0b'],
                                    'resource' => ['icon' => 'file', 'color' => '#6366f1'],
                                    'page' => ['icon' => 'file-lines', 'color' => '#ec4899']
                                ];
                                
                                $type_config = $type_colors[$activity['module_type']] ?? ['icon' => 'puzzle-piece', 'color' => '#64748b'];
                                $row_index++;
                            ?>
                            <tr class="activity-row" style="background: <?php echo $row_bg; ?>; transition: all 0.2s ease; border-bottom: 1px solid #f1f5f9; position: relative;" 
                                onmouseover="this.style.background='#f1f5f9'; this.style.transform='translateX(1px)';" 
                                onmouseout="this.style.background='<?php echo $row_bg; ?>'; this.style.transform='translateX(0)';">
                                <td style="padding: 10px 12px; border-bottom: 1px solid #f1f5f9;">
                                    <span style="background: linear-gradient(135deg, <?php echo $type_config['color']; ?>15, <?php echo $type_config['color']; ?>08); color: <?php echo $type_config['color']; ?>; padding: 3px 8px; border-radius: 4px; font-size: 9px; font-weight: 700; text-transform: uppercase; display: inline-flex; align-items: center; gap: 4px; border: 1px solid <?php echo $type_config['color']; ?>30;">
                                        <i class="fas fa-<?php echo $type_config['icon']; ?>" style="font-size: 9px;"></i>
                                        <?php echo htmlspecialchars($activity['module_type']); ?>
                                    </span>
                                </td>
                                <td style="padding: 10px 12px; border-bottom: 1px solid #f1f5f9; word-wrap: break-word; word-break: break-word; overflow-wrap: break-word;">
                                    <strong style="color: #1e293b; font-size: 12px; font-weight: 600; line-height: 1.4; display: block; word-wrap: break-word; word-break: break-word; overflow-wrap: break-word; white-space: normal;">
                                        <?php echo htmlspecialchars($activity['module_name']); ?>
                                    </strong>
                                </td>
                                <td style="padding: 10px 12px; border-bottom: 1px solid #f1f5f9; word-wrap: break-word; word-break: break-word; overflow-wrap: break-word;">
                                    <div style="display: flex; align-items: center; gap: 5px; color: #475569; font-size: 11px; font-weight: 500; flex-wrap: wrap;">
                                        <i class="fas fa-book" style="color: #64748b; font-size: 10px; flex-shrink: 0;"></i>
                                        <span style="word-wrap: break-word; word-break: break-word; overflow-wrap: break-word;"><?php echo htmlspecialchars($activity['course_name']); ?></span>
                                    </div>
                                </td>
                                <td style="padding: 10px 12px; border-bottom: 1px solid #f1f5f9; word-wrap: break-word; word-break: break-word; overflow-wrap: break-word;">
                                    <div style="display: flex; align-items: center; gap: 5px; color: #64748b; font-size: 11px; font-weight: 500; flex-wrap: wrap;">
                                        <div style="width: 22px; height: 22px; border-radius: 50%; background: linear-gradient(135deg, #f1f5f9, #e2e8f0); display: flex; align-items: center; justify-content: center; color: #475569; font-size: 9px; font-weight: 600; border: 1px solid #e2e8f0; flex-shrink: 0;">
                                            <i class="fas fa-user" style="font-size: 9px;"></i>
                                        </div>
                                        <span style="word-wrap: break-word; word-break: break-word; overflow-wrap: break-word;"><?php echo htmlspecialchars($activity['child_name']); ?></span>
                                    </div>
                                </td>
                                <td style="padding: 10px 12px; border-bottom: 1px solid #f1f5f9;">
                                    <div style="display: flex; align-items: center; gap: 5px; color: #64748b; font-size: 11px; font-weight: 500;">
                                        <div style="width: 22px; height: 22px; border-radius: 5px; background: linear-gradient(135deg, #f1f5f9, #e2e8f0); display: flex; align-items: center; justify-content: center; border: 1px solid #e2e8f0;">
                                            <i class="fas fa-calendar" style="color: #64748b; font-size: 9px;"></i>
                                        </div>
                                        <span><?php echo userdate($activity['added_date'], '%d %b %Y'); ?></span>
                                    </div>
                                </td>
                                <td style="padding: 10px 12px; border-bottom: 1px solid #f1f5f9; text-align: center;">
                                    <?php if ($activity['visible']): ?>
                                    <span style="background: linear-gradient(135deg, #d1fae5, #a7f3d0); color: #065f46; padding: 3px 8px; border-radius: 4px; font-size: 9px; font-weight: 700; display: inline-flex; align-items: center; gap: 4px; border: 1px solid #86efac;">
                                        <i class="fas fa-eye" style="font-size: 8px;"></i> Visible
                                    </span>
                                    <?php else: ?>
                                    <span style="background: linear-gradient(135deg, #fee2e2, #fecaca); color: #991b1b; padding: 3px 8px; border-radius: 4px; font-size: 9px; font-weight: 700; display: inline-flex; align-items: center; gap: 4px; border: 1px solid #fca5a5;">
                                        <i class="fas fa-eye-slash" style="font-size: 8px;"></i> Hidden
                                    </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <?php if (count($all_activity_data) > 100): ?>
            <div style="margin-top: 12px; padding: 10px 14px; background: linear-gradient(135deg, #f8fafc, #f1f5f9); border-radius: 6px; text-align: center; color: #475569; font-weight: 600; border: 1px solid #e2e8f0; position: relative; z-index: 1; font-size: 11px;">
                <i class="fas fa-info-circle" style="margin-right: 5px; color: #94a3b8; font-size: 10px;"></i> 
                Showing first <strong>100</strong> of <strong><?php echo count($all_activity_data); ?></strong> activities
            </div>
            <?php endif; ?>
            
            <style>
            /* Enhanced Table Row Hover Effect */
            .activity-row {
                cursor: pointer;
                position: relative;
            }
            
            /* Smooth scrollbar styling */
            .parent-section > div[style*="overflow"]::-webkit-scrollbar {
                width: 6px;
                height: 6px;
            }
            
            .parent-section > div[style*="overflow"]::-webkit-scrollbar-track {
                background: #f8fafc;
                border-radius: 3px;
            }
            
            .parent-section > div[style*="overflow"]::-webkit-scrollbar-thumb {
                background: #cbd5e1;
                border-radius: 3px;
            }
            
            .parent-section > div[style*="overflow"]::-webkit-scrollbar-thumb:hover {
                background: #94a3b8;
            }
            </style>
        </div>
        <?php endif; ?>

    </div>
</div>

<!-- JavaScript -->
<script>
// PHP selected child ID
const selectedChildId = '<?php echo $selected_child_id; ?>';

// Store children data
const childrenData = <?php echo json_encode($children); ?>;

// Cache child card elements
const childCards = document.querySelectorAll('.child-card');

// Select child and reload
function selectChild(childId) {
    console.log(' Child selected:', childId);
    console.log('Type:', typeof childId);
    
    // Validate child ID
    if (!childId || childId === 'undefined' || childId === undefined) {
        console.error(' ERROR: Invalid child ID!', childId);
        alert('Error: Could not select child. Please try again.');
        return;
    }
    
    // Ensure it's a number
    childId = parseInt(childId);
    console.log(' Validated child ID:', childId);
    
    // Show loading overlay
    const loadingDiv = document.createElement('div');
    loadingDiv.style.cssText = 'position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(255,255,255,0.9); z-index: 9999; display: flex; align-items: center; justify-content: center;';
    loadingDiv.innerHTML = '<div style="background: white; padding: 40px; border-radius: 12px; box-shadow: 0 8px 32px rgba(59,130,246,0.15); border: 1px solid #bfdbfe; text-align: center;"><div style="width: 80px; height: 80px; margin: 0 auto 20px; border: 4px solid #dbeafe; border-top-color: #3b82f6; border-radius: 50%; animation: spin 1s linear infinite;"></div><p style="margin: 0; color: #3b82f6; font-weight: 600; font-size: 18px;">Loading child data...</p></div><style>@keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); }}</style>';
    document.body.appendChild(loadingDiv);
    
    // Redirect
    console.log('Redirecting to: ?child=' + childId);
    setTimeout(() => {
        window.location.href = '?child=' + childId;
    }, 500);
}

// Clear selection and show empty state
function clearSelection() {
    console.log('Clearing selection - showing empty state');
    
    // Show loading overlay
    const loadingDiv = document.createElement('div');
    loadingDiv.style.cssText = 'position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(255,255,255,0.9); z-index: 9999; display: flex; align-items: center; justify-content: center;';
    loadingDiv.innerHTML = '<div style="background: white; padding: 40px; border-radius: 12px; box-shadow: 0 8px 32px rgba(59,130,246,0.15); border: 1px solid #bfdbfe; text-align: center;"><div style="width: 80px; height: 80px; margin: 0 auto 20px; border: 4px solid #dbeafe; border-top-color: #3b82f6; border-radius: 50%; animation: spin 1s linear infinite;"></div><p style="margin: 0; color: #3b82f6; font-weight: 600; font-size: 18px;">Clearing selection...</p></div><style>@keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); }}</style>';
    document.body.appendChild(loadingDiv);
    
    // Redirect to empty state
    setTimeout(() => {
        window.location.href = '?child=0';
    }, 500);
}

// Refresh data
function refreshData() {
    location.reload();
}

// Table checkbox select all
document.addEventListener('DOMContentLoaded', function() {
    // Debug logging
    console.log('Page loaded. Selected child ID:', selectedChildId);
    console.log('Children data:', childrenData);
    console.log('Found ' + childCards.length + ' child cards');
    
    childCards.forEach((card, index) => {
        const childId = card.getAttribute('data-child-id');
        console.log(`Card ${index + 1}: ID = ${childId}, onclick set = ${card.onclick ? 'YES' : 'NO'}`);
        
        // Add click event listener as backup
        card.addEventListener('click', function() {
            const id = this.getAttribute('data-child-id');
            console.log(' Card clicked! Child ID from data attribute:', id);
            if (id && id !== 'undefined') {
                selectChild(parseInt(id));
            }
        });
    });
    
    document.querySelectorAll('thead .table-checkbox').forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            const table = this.closest('table');
            const checkboxes = table.querySelectorAll('tbody .table-checkbox');
            checkboxes.forEach(cb => cb.checked = this.checked);
        });
    });
    
    // Add visual indicator if child is selected
    if (selectedChildId && selectedChildId !== 'all' && selectedChildId != '0') {
        console.log(' Specific child selected, adding filter badge');
        const selectedName = childrenData.find(c => c.id == selectedChildId);
        if (selectedName) {
            // Add badge showing filtered view
            const breadcrumb = document.querySelector('.parent-breadcrumb');
            if (breadcrumb) {
                const badge = document.createElement('span');
                badge.style.cssText = 'background: #3b82f6; color: white; padding: 4px 12px; border-radius: 8px; font-size: 12px; font-weight: 600; margin-left: 10px;';
                badge.innerHTML = '<i class="fas fa-filter"></i> Filtered: ' + selectedName.name;
                breadcrumb.appendChild(badge);
            }
        }
    } else {
        console.log(' No child selected (empty state)');
    }

    const calendarFilterButtons = document.querySelectorAll('.calendar-filter-btn');
    const calendarEventChips = document.querySelectorAll('.event-chip');
    const academicEventCards = document.querySelectorAll('.academic-event-card');
    if (calendarFilterButtons.length && (calendarEventChips.length || academicEventCards.length)) {
        calendarFilterButtons.forEach(button => {
            button.addEventListener('click', () => {
                const filter = button.getAttribute('data-calendar-filter');
                calendarFilterButtons.forEach(btn => btn.classList.toggle('active', btn === button));
                if (calendarEventChips.length) {
                    calendarEventChips.forEach(chip => {
                        const chipType = chip.getAttribute('data-event-type') || 'personal';
                        chip.style.display = (filter === 'all' || chipType === filter) ? '' : 'none';
                    });
                }
                if (academicEventCards.length) {
                    academicEventCards.forEach(card => {
                        const cardType = card.getAttribute('data-event-type') || 'personal';
                        card.style.display = (filter === 'all' || cardType === filter) ? '' : 'none';
                    });
                }
            });
        });
    }
});

// Toggle Reminders Show More
function toggleReminders() {
    const container = document.getElementById('reminders-more-container');
    const btn = document.getElementById('reminders-toggle-btn');
    
    if (container && btn) {
        const isHidden = container.style.display === 'none' || !container.style.display;
        const moreCount = container.querySelectorAll('li').length;
        
        if (isHidden) {
            container.style.display = 'block';
            btn.innerHTML = '<i class="fas fa-chevron-up" id="reminders-toggle-icon"></i><span>Show Less</span>';
            setTimeout(() => {
                btn.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }, 100);
        } else {
            container.style.display = 'none';
            btn.innerHTML = '<i class="fas fa-chevron-down" id="reminders-toggle-icon"></i><span>Show ' + moreCount + ' More Reminders</span>';
        }
    }
}

// Show Event Details Modal
function showEventDetails(eventName, eventTime, courseName, eventType, eventUrl) {
    // Create modal overlay
    const modal = document.createElement('div');
    modal.id = 'event-details-modal';
    modal.style.cssText = 'position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0, 0, 0, 0.5); z-index: 10000; display: flex; align-items: center; justify-content: center; padding: 20px;';
    modal.onclick = function(e) {
        if (e.target === modal) {
            closeEventDetails();
        }
    };
    
    // Event type colors and icons
    const typeInfo = {
        'assign': { color: '#f97316', icon: 'fa-tasks', label: 'Assignment' },
        'quiz': { color: '#8b5cf6', icon: 'fa-clipboard-check', label: 'Quiz' },
        'lesson': { color: '#10b981', icon: 'fa-book', label: 'Lesson' },
        'event': { color: '#3b82f6', icon: 'fa-calendar', label: 'Event' }
    };
    const info = typeInfo[eventType] || typeInfo['event'];
    
    modal.innerHTML = `
        <div style="background: white; border-radius: 16px; padding: 28px; max-width: 500px; width: 100%; box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3); position: relative; animation: modalSlideIn 0.3s ease-out;">
            <button onclick="closeEventDetails()" style="position: absolute; top: 16px; right: 16px; background: #f1f5f9; border: none; border-radius: 50%; width: 32px; height: 32px; cursor: pointer; display: flex; align-items: center; justify-content: center; color: #64748b; transition: all 0.2s;" onmouseover="this.style.background='#e2e8f0'; this.style.color='#475569';" onmouseout="this.style.background='#f1f5f9'; this.style.color='#64748b';">
                <i class="fas fa-times"></i>
            </button>
            <div style="display: flex; align-items: center; gap: 16px; margin-bottom: 20px;">
                <div style="width: 56px; height: 56px; background: linear-gradient(135deg, ${info.color}, ${info.color}dd); border-radius: 12px; display: flex; align-items: center; justify-content: center; color: white; font-size: 24px; box-shadow: 0 4px 12px ${info.color}40;">
                    <i class="fas ${info.icon}"></i>
                </div>
                <div>
                    <div style="font-size: 11px; color: ${info.color}; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">${info.label}</div>
                    <div style="font-size: 20px; font-weight: 800; color: #0f172a; line-height: 1.2;">${eventName}</div>
                </div>
            </div>
            <div style="background: #f8fafc; border-radius: 12px; padding: 16px; margin-bottom: 20px;">
                <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 12px;">
                    <i class="fas fa-calendar-alt" style="color: #64748b; font-size: 16px;"></i>
                    <div>
                        <div style="font-size: 11px; color: #94a3b8; font-weight: 600; text-transform: uppercase; margin-bottom: 2px;">Date & Time</div>
                        <div style="font-size: 14px; color: #1e293b; font-weight: 600;">${eventTime}</div>
                    </div>
                </div>
                <div style="display: flex; align-items: center; gap: 12px;">
                    <i class="fas fa-book" style="color: #64748b; font-size: 16px;"></i>
                    <div>
                        <div style="font-size: 11px; color: #94a3b8; font-weight: 600; text-transform: uppercase; margin-bottom: 2px;">Course</div>
                        <div style="font-size: 14px; color: #1e293b; font-weight: 600;">${courseName}</div>
                    </div>
                </div>
            </div>
            ${eventUrl ? `
            <div style="display: flex; gap: 12px;">
                <a href="${eventUrl}" target="_blank" style="flex: 1; background: linear-gradient(135deg, ${info.color}, ${info.color}dd); color: white; padding: 12px 20px; border-radius: 10px; text-decoration: none; font-weight: 700; text-align: center; transition: all 0.2s; box-shadow: 0 4px 12px ${info.color}40;" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 16px ${info.color}60';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 12px ${info.color}40';">
                    <i class="fas fa-external-link-alt" style="margin-right: 6px;"></i> View Details
                </a>
            </div>
            ` : ''}
            <style>
                @keyframes modalSlideIn {
                    from { opacity: 0; transform: translateY(-20px); }
                    to { opacity: 1; transform: translateY(0); }
                }
            </style>
        </div>
    `;
    
    document.body.appendChild(modal);
    document.body.style.overflow = 'hidden';
}

function closeEventDetails() {
    const modal = document.getElementById('event-details-modal');
    if (modal) {
        modal.style.animation = 'modalSlideOut 0.2s ease-out';
        setTimeout(() => {
            document.body.removeChild(modal);
            document.body.style.overflow = '';
        }, 200);
    }
}

// Toggle Timeline Show More
function toggleTimeline() {
    const container = document.getElementById('timeline-more-container');
    const btn = document.getElementById('timeline-toggle-btn');
    
    if (container && btn) {
        const isHidden = container.style.display === 'none' || !container.style.display;
        const moreCount = container.querySelectorAll('.academic-event-card').length;
        
        if (isHidden) {
            container.style.display = 'grid';
            container.style.gap = '16px';
            btn.innerHTML = '<i class="fas fa-chevron-up" id="timeline-toggle-icon"></i><span>Show Less</span>';
            setTimeout(() => {
                btn.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }, 100);
        } else {
            container.style.display = 'none';
            btn.innerHTML = '<i class="fas fa-chevron-down" id="timeline-toggle-icon"></i><span>Show ' + moreCount + ' More Timeline Items</span>';
        }
    }
}
</script>

</div> <!-- Close parent-main-content -->

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

<?php
// Use Moodle's standard footer (same as other parent pages)
echo $OUTPUT->footer();
?>
















