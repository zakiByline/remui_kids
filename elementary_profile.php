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
 * Elementary Profile Page
 * 
 * @package    theme_remui_kids
 * @copyright  2024
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');
require_once($CFG->dirroot . '/badges/lib.php');
require_once($CFG->dirroot . '/lib/completionlib.php');
require_once($CFG->dirroot . '/cohort/lib.php');
require_once(__DIR__ . '/lib/cohort_sidebar_helper.php');

// Define badge constants if not already defined
if (!defined('BADGE_STATUS_ACTIVE')) {
    define('BADGE_STATUS_ACTIVE', 1);
}
if (!defined('BADGE_STATUS_INACTIVE')) {
    define('BADGE_STATUS_INACTIVE', 0);
}
if (!defined('BADGE_TYPE_COURSE')) {
    define('BADGE_TYPE_COURSE', 1);
}
if (!defined('BADGE_TYPE_SITE')) {
    define('BADGE_TYPE_SITE', 2);
}

// Require login
require_login();

// Get user context
$userid = $USER->id;
$usercontext = context_user::instance($userid);

// Set page context
$PAGE->set_context($usercontext);
$PAGE->set_url(new moodle_url('/theme/remui_kids/elementary_profile.php'));
$PAGE->set_pagelayout('standard');
$PAGE->set_title('My Profile');
$PAGE->set_heading('My Profile');

// Check if user is elementary student (Grades 1-3) - Role-based access control
$is_elementary_student = false;
$cohorts = [];
try {
    $sql = "SELECT c.name, c.idnumber FROM {cohort_members} cm JOIN {cohort} c ON c.id = cm.cohortid WHERE cm.userid = ?";
    $cohorts = $DB->get_records_sql($sql, [$USER->id]);
    foreach ($cohorts as $cohort) {
        $cohort_name_lower = strtolower($cohort->name);
        $cohort_id_lower = strtolower($cohort->idnumber);
        if (strpos($cohort_name_lower, 'grade 1') !== false || strpos($cohort_name_lower, 'grade 2') !== false ||
            strpos($cohort_name_lower, 'grade 3') !== false || strpos($cohort_id_lower, 'grade1') !== false ||
            strpos($cohort_id_lower, 'grade2') !== false || strpos($cohort_id_lower, 'grade3') !== false ||
            strpos($cohort_name_lower, 'elementary') !== false || strpos($cohort_id_lower, 'elementary') !== false) {
            $is_elementary_student = true;
            break;
        }
    }
} catch (Exception $e) {
    $is_elementary_student = true; // Fallback
}

// Get user profile data
$user_data = [];

try {
    // Basic user information
    $user_data['id'] = $USER->id;
    $user_data['username'] = $USER->username;
    $user_data['firstname'] = $USER->firstname;
    $user_data['lastname'] = $USER->lastname;
    $user_data['fullname'] = fullname($USER);
    $user_data['email'] = $USER->email;
    
    // Generate initials for placeholder
    $first_initial = !empty($USER->firstname) ? strtoupper(substr($USER->firstname, 0, 1)) : '';
    $last_initial = !empty($USER->lastname) ? strtoupper(substr($USER->lastname, 0, 1)) : '';
    $user_data['initials'] = $first_initial . $last_initial;
    if (empty($user_data['initials'])) {
        $user_data['initials'] = strtoupper(substr($USER->username, 0, 1));
    }
    $user_data['city'] = $USER->city;
    $user_data['country'] = $USER->country;
    $user_data['timezone'] = $USER->timezone;
    $user_data['lang'] = $USER->lang;
    $user_data['firstaccess'] = $USER->firstaccess;
    $user_data['lastaccess'] = $USER->lastaccess;
    $user_data['lastlogin'] = $USER->lastlogin;
    $user_data['currentlogin'] = $USER->currentlogin;
    
    // Get user picture
    $user_data['profile_picture'] = '';
    $user_data['has_profile_picture'] = false;
    
    if ($USER && $USER->picture > 0) {
        // Verify the file actually exists in file storage
        $user_context = context_user::instance($USER->id);
        $fs = get_file_storage();
        
        // Check if file exists in 'icon' area (where Moodle stores profile pics)
        $files = $fs->get_area_files($user_context->id, 'user', 'icon', 0, 'itemid', false);
        
        if (!empty($files)) {
            try {
                // Generate user picture URL using Moodle's standard method
                $user_picture = new user_picture($USER);
                $user_picture->size = 1; // Full size
                $profile_url = $user_picture->get_url($PAGE)->out(false);
                
                // If URL is generated and not empty, use it
                if (!empty($profile_url)) {
                    $user_data['profile_picture'] = $profile_url;
                    $user_data['has_profile_picture'] = true;
                }
            } catch (Exception $e) {
                error_log("Profile picture error for user {$USER->id}: " . $e->getMessage());
            }
        }
    }
    
    // Get user description/bio
    $user_data['description'] = '';
    try {
        $user_description = $DB->get_field('user', 'description', ['id' => $USER->id], IGNORE_MISSING);
        if ($user_description) {
            $user_data['description'] = format_text($user_description, FORMAT_HTML);
        }
    } catch (Exception $e) {
        error_log("User description error: " . $e->getMessage());
    }
    
    // Get user's grade level from cohorts
    $user_data['grade_level'] = 'Elementary';
    $user_data['cohorts'] = [];
    try {
        foreach ($cohorts as $cohort) {
            $user_data['cohorts'][] = [
                'name' => $cohort->name,
                'idnumber' => $cohort->idnumber
            ];
            // Determine grade level
            $cohort_name_lower = strtolower($cohort->name);
            if (strpos($cohort_name_lower, 'grade 1') !== false || strpos($cohort_name_lower, 'grade1') !== false) {
                $user_data['grade_level'] = 'Grade 1';
            } elseif (strpos($cohort_name_lower, 'grade 2') !== false || strpos($cohort_name_lower, 'grade2') !== false) {
                $user_data['grade_level'] = 'Grade 2';
            } elseif (strpos($cohort_name_lower, 'grade 3') !== false || strpos($cohort_name_lower, 'grade3') !== false) {
                $user_data['grade_level'] = 'Grade 3';
            }
        }
    } catch (Exception $e) {
        error_log("Cohort data error: " . $e->getMessage());
    }
    
    // Get user's course enrollment statistics
    $user_data['total_courses'] = 0;
    $user_data['completed_courses'] = 0;
    $user_data['in_progress_courses'] = 0;
    
    try {
        // Get enrolled courses
        $enrolled_courses = enrol_get_users_courses($USER->id, true);
        $user_data['total_courses'] = count($enrolled_courses);
        
        // Calculate completion statistics
        foreach ($enrolled_courses as $course) {
            try {
                $completion_info = new completion_info($course);
                if ($completion_info->is_enabled()) {
                    $completion = $completion_info->get_completion($USER->id, COMPLETION_AGGREGATION_ALL);
                    if ($completion && $completion->is_complete()) {
                        $user_data['completed_courses']++;
                    } else {
                        $user_data['in_progress_courses']++;
                    }
                } else {
                    $user_data['in_progress_courses']++;
                }
            } catch (Exception $e) {
                $user_data['in_progress_courses']++;
            }
        }
    } catch (Exception $e) {
        error_log("Course statistics error: " . $e->getMessage());
    }
    
    // Get user's badge statistics
    $user_data['total_badges'] = 0;
    $user_data['earned_badges'] = 0;
    
    try {
        // Total badges available
        $total_badges = $DB->count_records('badge', ['status' => BADGE_STATUS_ACTIVE]);
        $user_data['total_badges'] = $total_badges;
        
        // Earned badges
        $earned_badges = $DB->count_records('badge_issued', ['userid' => $USER->id]);
        $user_data['earned_badges'] = $earned_badges;
    } catch (Exception $e) {
        error_log("Badge statistics error: " . $e->getMessage());
    }
    
    // Get user's activity statistics
    $user_data['total_activities'] = 0;
    $user_data['completed_activities'] = 0;
    
    try {
        // Get all activities across enrolled courses
        $course_ids = array_keys($enrolled_courses);
        if (!empty($course_ids)) {
            list($course_sql, $course_params) = $DB->get_in_or_equal($course_ids);
            $sql = "SELECT COUNT(*) FROM {course_modules} cm 
                    JOIN {modules} m ON m.id = cm.module 
                    WHERE cm.course {$course_sql} AND m.visible = 1";
            $user_data['total_activities'] = $DB->count_records_sql($sql, $course_params);
            
            // Get completed activities
            $sql = "SELECT COUNT(*) FROM {course_modules_completion} cmc 
                    JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid 
                    JOIN {modules} m ON m.id = cm.module 
                    WHERE cm.course {$course_sql} AND cmc.userid = ? AND cmc.completionstate > 0";
            $params = array_merge($course_params, [$USER->id]);
            $user_data['completed_activities'] = $DB->count_records_sql($sql, $params);
        }
    } catch (Exception $e) {
        error_log("Activity statistics error: " . $e->getMessage());
    }
    
    // Format dates
    $user_data['firstaccess_formatted'] = $user_data['firstaccess'] ? userdate($user_data['firstaccess'], '%d %b %Y') : 'Never';
    $user_data['lastaccess_formatted'] = $user_data['lastaccess'] ? userdate($user_data['lastaccess'], '%d %b %Y') : 'Never';
    $user_data['lastlogin_formatted'] = $user_data['lastlogin'] ? userdate($user_data['lastlogin'], '%d %b %Y, %I:%M %p') : 'Never';
    $user_data['currentlogin_formatted'] = $user_data['currentlogin'] ? userdate($user_data['currentlogin'], '%d %b %Y, %I:%M %p') : 'Never';
    
    // Calculate progress percentages
    $user_data['course_progress'] = $user_data['total_courses'] > 0 ? 
        round(($user_data['completed_courses'] / $user_data['total_courses']) * 100) : 0;
    $user_data['activity_progress'] = $user_data['total_activities'] > 0 ? 
        round(($user_data['completed_activities'] / $user_data['total_activities']) * 100) : 0;
    $user_data['badge_progress'] = $user_data['total_badges'] > 0 ? 
        round(($user_data['earned_badges'] / $user_data['total_badges']) * 100) : 0;
        
} catch (Exception $e) {
    error_log("Profile data error: " . $e->getMessage());
    // Set default values
    $user_data = [
        'id' => $USER->id,
        'username' => $USER->username,
        'firstname' => $USER->firstname,
        'lastname' => $USER->lastname,
        'fullname' => fullname($USER),
        'email' => $USER->email,
        'grade_level' => 'Elementary',
        'total_courses' => 0,
        'completed_courses' => 0,
        'in_progress_courses' => 0,
        'total_activities' => 0,
        'completed_activities' => 0,
        'total_badges' => 0,
        'earned_badges' => 0,
        'course_progress' => 0,
        'activity_progress' => 0,
        'badge_progress' => 0
    ];
}

// Determine dashboard type
$dashboardtype = 'elementary';
$usercohortname = '';
$usercohortid = null;

$usercohorts = cohort_get_user_cohorts($USER->id);
if (!empty($usercohorts)) {
    $firstcohort = reset($usercohorts);
    $usercohortid = $firstcohort->id;
    $usercohortname = $firstcohort->name;
    
    // Determine dashboard type based on cohort name
    if (preg_match('/grade\s*[1-3]/i', $usercohortname)) {
        $dashboardtype = 'elementary';
    } elseif (preg_match('/grade\s*[4-6]/i', $usercohortname)) {
        $dashboardtype = 'middle';
    } elseif (preg_match('/grade\s*[7-9]/i', $usercohortname)) {
        $dashboardtype = 'high';
    }
}

// Prepare template context
$templatecontext = [
    'user_data' => $user_data,
    'is_profile_page' => true,
    'is_elementary_student' => $is_elementary_student,
    'dashboardtype' => $dashboardtype,
    'dashboard_type' => $dashboardtype,
    'usercohortname' => $usercohortname,
    'user_cohort_name' => $usercohortname,
    'user_cohort_id' => $usercohortid,
    'student_name' => $USER->firstname,
    'student_fullname' => fullname($USER),
    'sitename' => $SITE->fullname,
    'userfullname' => fullname($USER),
    'userfirstname' => $USER->firstname,
    'userlastname' => $USER->lastname,
    'useremail' => $USER->email,
    'userpicture' => $user_data['profile_picture'],
    'has_profile_picture' => $user_data['has_profile_picture'],
    'currentyear' => date('Y'),
    'currentdate' => userdate(time(), '%d %b %Y'),
    'currenttime' => userdate(time(), '%H:%M'),
    
    // Navigation URLs based on dashboard type
    'wwwroot' => $CFG->wwwroot,
    'dashboardurl' => (new moodle_url('/my/'))->out(),
    'mycoursesurl' => $dashboardtype === 'elementary' ? 
        (new moodle_url('/theme/remui_kids/elementary_my_course.php'))->out() : 
        (new moodle_url('/my/courses.php'))->out(),
    'elementary_mycoursesurl' => (new moodle_url('/theme/remui_kids/elementary_my_course.php'))->out(),
    'lessonsurl' => $dashboardtype === 'elementary' ? 
        (new moodle_url('/theme/remui_kids/elementary_lessons.php'))->out() : 
        (new moodle_url('/mod/lesson/index.php'))->out(),
    'activitiesurl' => $dashboardtype === 'elementary' ? 
        (new moodle_url('/theme/remui_kids/elementary_activities.php'))->out() : 
        (new moodle_url('/mod/quiz/index.php'))->out(),
    'achievementsurl' => $dashboardtype === 'elementary' ? 
        (new moodle_url('/theme/remui_kids/elementary_achievements.php'))->out() : 
        (new moodle_url('/badges/mybadges.php'))->out(),
    'competenciesurl' => (new moodle_url('/theme/remui_kids/elementary_competencies.php'))->out(),
    'scheduleurl' => $dashboardtype === 'elementary' ? 
        (new moodle_url('/theme/remui_kids/elementary_calendar.php'))->out() : 
        (new moodle_url('/calendar/view.php'))->out(),
    'myreportsurl' => (new moodle_url('/theme/remui_kids/elementary_myreports.php'))->out(),
    'calendarurl' => (new moodle_url('/calendar/view.php'))->out(),
    'treeviewurl' => (new moodle_url('/theme/remui_kids/treeview.php'))->out(),
    'settingsurl' => (new moodle_url('/user/preferences.php'))->out(),
    'logouturl' => (new moodle_url('/login/logout.php', ['sesskey' => sesskey()]))->out(),
    'settingsurl' => (new moodle_url('/user/preferences.php'))->out(),
    'profileurl' => (new moodle_url('/theme/remui_kids/elementary_profile.php'))->out(),
    'logouturl' => (new moodle_url('/login/logout.php', ['sesskey' => sesskey()]))->out(),
    'scratchemulatorurl' => (new moodle_url('/theme/remui_kids/scratch_simple.php'))->out(),
    'scratcheditorurl' => (new moodle_url('/theme/remui_kids/scratch_simple.php'))->out(),
    'codeeditorurl' => (new moodle_url('/theme/remui_kids/code_editor_simple.php'))->out(),
    'messagesurl' => (new moodle_url('/message/index.php'))->out(),
    
    // Sidebar access permissions (based on user's cohort)
    'has_scratch_editor_access' => theme_remui_kids_user_has_scratch_editor_access($USER->id),
    'has_code_editor_access' => theme_remui_kids_user_has_code_editor_access($USER->id),
    'emulatorsurl' => (new moodle_url('/theme/remui_kids/emulators.php'))->out(),
    
    // Page identification flags for sidebar
    'show_elementary_sidebar' => true,
];

// Render the page using Moodle's standard header/footer system
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('theme_remui_kids/elementary_profile_page_clean', $templatecontext);
echo $OUTPUT->footer();
