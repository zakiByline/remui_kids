<?php
/**
 * Elementary Achievements Page - Student Achievements and Progress
 * Displays student achievements, certificates, and learning milestones for Grades 1-3
 * 
 * @package    theme_remui_kids
 * @copyright  2024 WisdmLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lang_init.php'); // Apply user's selected language
require_once($CFG->dirroot . '/lib/completionlib.php');
require_once($CFG->dirroot . '/cohort/lib.php');
require_once($CFG->dirroot . '/lib/badgeslib.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/lib/cohort_sidebar_helper.php');

// Require login
require_login();

// Set up the page properly within Moodle
global $USER, $DB, $PAGE, $OUTPUT, $CFG;

// Set page context and properties
$PAGE->set_context(context_system::instance());
$PAGE->set_url('/theme/remui_kids/elementary_achievements.php');
$PAGE->set_pagelayout('base');
$PAGE->set_title('My Achievements', false);

// Get user's cohort information
try {
    $usercohorts = $DB->get_records_sql(
        "SELECT c.name, c.id 
         FROM {cohort} c 
         JOIN {cohort_members} cm ON c.id = cm.cohortid 
         WHERE cm.userid = ?",
        [$USER->id]
    );
} catch (Exception $e) {
    // If there's an error, set empty array and continue
    $usercohorts = [];
}

$usercohortname = '';
$usercohortid = 0;
$dashboardtype = 'elementary';

if (!empty($usercohorts)) {
    $cohort = reset($usercohorts);
    $usercohortname = $cohort->name;
    $usercohortid = $cohort->id;
    
    // Verify this is an elementary student (Grades 1-3)
    if (!preg_match('/grade\s*[1-3]/i', $usercohortname)) {
        // Redirect to general achievements if not elementary student
        redirect(new moodle_url('/theme/remui_kids/achievements.php'));
        exit;
    }
}

// Get achievements data - Separate Certificates and Badges
$certificates_list = [];
$badges_list = [];

// Get Certificates (customcert)
$dbman = $DB->get_manager();
if ($dbman->table_exists('customcert_issues') && $dbman->table_exists('customcert')) {
    try {
        require_once($CFG->libdir . '/completionlib.php');
        
        $certificates = $DB->get_records_sql(
            "SELECT ci.id as issueid, ci.userid, ci.customcertid, ci.code, ci.timecreated,
                    c.id as customcert_instance_id, c.name as certificatename, c.course as courseid,
                    co.fullname as coursename, co.id as course_id,
                    cm.id as cmid, cm.section as certificatesection
             FROM {customcert_issues} ci
             JOIN {customcert} c ON ci.customcertid = c.id
             JOIN {course} co ON c.course = co.id
             LEFT JOIN {course_modules} cm ON cm.instance = c.id 
                 AND cm.module = (SELECT id FROM {modules} WHERE name = 'customcert')
             WHERE ci.userid = ?
             ORDER BY ci.timecreated DESC",
            [$USER->id]
        );
        
        foreach ($certificates as $cert) {
            // Check if student is enrolled in the course
            $coursecontext = context_course::instance($cert->courseid);
            if (!is_enrolled($coursecontext, $USER->id, '', true)) {
                continue;
            }
            
            // If certificate is in customcert_issues table, it's already earned by the student
            // No need for additional completion checks - just include it
            
            // Generate download URL and form action for certificate
            $downloadurl = '';
            $downloadaction = '';
            if (!empty($cert->cmid)) {
                $downloadurl = new moodle_url('/mod/customcert/view.php');
                $downloadaction = $downloadurl->out();
            }
            
            $certificates_list[] = [
                'title' => format_string($cert->certificatename),
                'description' => 'Certificate earned from: ' . format_string($cert->coursename),
                'date_earned' => $cert->timecreated,
                'date_formatted' => userdate($cert->timecreated, '%B %d, %Y'),
                'icon' => 'fa-certificate',
                'color' => 'blue',
                'course_name' => format_string($cert->coursename),
                'certificate_id' => $cert->code,
                'cmid' => $cert->cmid,
                'issueid' => $cert->issueid,
                'download_action' => $downloadaction
            ];
        }
    } catch (Exception $e) {
        error_log("Error fetching certificates: " . $e->getMessage());
    }
}

// Get Certificates from certificate_assignment_track table
if ($dbman->table_exists('certificate_assignment_track')) {
    try {
        require_once($CFG->libdir . '/completionlib.php');
        
        $assignment_certificates = $DB->get_records_sql(
            "SELECT cat.id as trackid, cat.certificateid, cat.certificate_issueid, cat.userid, 
                    cat.courseid, cat.certificate_assigned_date as timecreated, cat.status,
                    cat.assignmentid, cat.assignment_completed, cat.submission_status,
                    c.id as customcert_instance_id, c.name as certificatename, c.course as courseid_check,
                    co.fullname as coursename, co.id as course_id,
                    cm.id as cmid, cm.section as certificatesection,
                    ci.code, ci.timecreated as issue_timecreated
             FROM {certificate_assignment_track} cat
             JOIN {customcert} c ON cat.certificateid = c.id
             JOIN {course} co ON cat.courseid = co.id
             LEFT JOIN {customcert_issues} ci ON cat.certificate_issueid = ci.id
             LEFT JOIN {course_modules} cm ON cm.instance = c.id 
                 AND cm.module = (SELECT id FROM {modules} WHERE name = 'customcert')
             WHERE cat.userid = ? 
             AND cat.status = 'assigned'
             ORDER BY cat.certificate_assigned_date DESC",
            [$USER->id]
        );
        
        foreach ($assignment_certificates as $cert) {
            // CRITICAL: Check if the assignment is completed before showing the certificate
            if (!empty($cert->assignmentid)) {
                $assignment_completed = false;
                
                // Method 1: Check the assignment_completed flag in the track table (most reliable)
                if (!empty($cert->assignment_completed)) {
                    $assignment_completed = true;
                } else {
                    // Method 2: Check submission_status field (SUBMITTED or GRADED means completed)
                    if (!empty($cert->submission_status) && 
                        in_array(strtoupper($cert->submission_status), ['SUBMITTED', 'GRADED'])) {
                        $assignment_completed = true;
                    } else {
                        // Method 3: Verify by checking actual assignment submission
                        $submission = $DB->get_record('assign_submission', [
                            'assignment' => $cert->assignmentid,
                            'userid' => $USER->id,
                            'status' => 'submitted',
                            'latest' => 1
                        ], 'id', IGNORE_MISSING);
                        
                        if ($submission) {
                            $assignment_completed = true;
                        } else {
                            // Method 4: Check course_modules_completion for assignment completion
                            $assign_module_id = $DB->get_field('modules', 'id', ['name' => 'assign'], IGNORE_MISSING);
                            if ($assign_module_id) {
                                $assign_cm = $DB->get_record('course_modules', [
                                    'instance' => $cert->assignmentid,
                                    'module' => $assign_module_id,
                                    'course' => $cert->courseid
                                ], 'id', IGNORE_MISSING);
                                
                                if ($assign_cm) {
                                    $completiondata = $DB->get_record('course_modules_completion', [
                                        'coursemoduleid' => $assign_cm->id,
                                        'userid' => $USER->id
                                    ], 'completionstate', IGNORE_MISSING);
                                    
                                    // Check if assignment is completed (COMPLETION_COMPLETE = 1 or COMPLETION_COMPLETE_PASS = 2)
                                    if ($completiondata && 
                                        ($completiondata->completionstate == COMPLETION_COMPLETE || 
                                         $completiondata->completionstate == COMPLETION_COMPLETE_PASS)) {
                                        $assignment_completed = true;
                                    }
                                }
                            }
                        }
                    }
                }
                
                // Only show certificate if assignment is completed
                if (!$assignment_completed) {
                    continue; // Skip this certificate - assignment not completed
                }
            }
            // Check if student is enrolled in the course
            $coursecontext = context_course::instance($cert->courseid);
            if (!is_enrolled($coursecontext, $USER->id, '', true)) {
                continue;
            }
            
            // If assignment is completed and certificate is assigned, it's earned - include it
            // No need for additional subsection/activity completion checks
            
            // Use certificate_issueid if available, otherwise use certificate_assigned_date for time
            $cert_time = !empty($cert->issue_timecreated) ? $cert->issue_timecreated : $cert->timecreated;
            
            // Generate download URL and form action for certificate
            $downloadurl = '';
            $downloadaction = '';
            if (!empty($cert->cmid)) {
                $downloadurl = new moodle_url('/mod/customcert/view.php');
                $downloadaction = $downloadurl->out();
            }
            
            // Use certificate code from issues table if available
            $cert_code = !empty($cert->code) ? $cert->code : '';
            
            $certificates_list[] = [
                'title' => format_string($cert->certificatename),
                'description' => 'Certificate earned from: ' . format_string($cert->coursename),
                'date_earned' => $cert_time,
                'date_formatted' => userdate($cert_time, '%B %d, %Y'),
                'icon' => 'fa-certificate',
                'color' => 'blue',
                'course_name' => format_string($cert->coursename),
                'certificate_id' => $cert_code,
                'cmid' => $cert->cmid,
                'issueid' => $cert->certificate_issueid,
                'download_action' => $downloadaction
            ];
        }
    } catch (Exception $e) {
        error_log("Error fetching assignment track certificates: " . $e->getMessage());
    }
}

// Get Badges
if (!empty($CFG->enablebadges)) {
    try {
        $user_badges = badges_get_user_badges($USER->id, 0, 0, 0, '', false);
        
        foreach ($user_badges as $badge) {
            $badgeobj = new badge($badge->id);
            
            // Get badge context for image URL
            if (empty($badge->courseid)) {
                $badgecontext = context_system::instance();
            } else {
                $badgecontext = context_course::instance($badge->courseid);
            }
            
            // Get badge image URL
            $badgeimageurl = moodle_url::make_pluginfile_url($badgecontext->id, 'badges', 'badgeimage', $badge->id, '/', 'f3', false);
            
            $course_name = '';
            if (!empty($badge->courseid)) {
                $course = $DB->get_record('course', ['id' => $badge->courseid], 'fullname', IGNORE_MISSING);
                if ($course) {
                    $course_name = format_string($course->fullname);
                }
            }
            
            // Determine badge category/type
            $badge_category = 'General';
            $badge_name_lower = strtolower($badge->name);
            $badge_desc_lower = !empty($badge->description) ? strtolower(strip_tags(format_text($badge->description, FORMAT_HTML))) : '';
            
            if (strpos($badge_name_lower, 'quiz') !== false || strpos($badge_desc_lower, 'quiz') !== false) {
                $badge_category = 'Quiz Master';
            } elseif (strpos($badge_name_lower, 'assignment') !== false || strpos($badge_desc_lower, 'assignment') !== false) {
                $badge_category = 'Assignment Expert';
            } elseif (strpos($badge_name_lower, 'lesson') !== false || strpos($badge_desc_lower, 'lesson') !== false) {
                $badge_category = 'Lesson Learner';
            } elseif (strpos($badge_name_lower, 'forum') !== false || strpos($badge_desc_lower, 'forum') !== false) {
                $badge_category = 'Discussion Leader';
            } elseif (strpos($badge_name_lower, 'completion') !== false || strpos($badge_desc_lower, 'completion') !== false) {
                $badge_category = 'Course Completer';
            } elseif (strpos($badge_name_lower, 'participation') !== false || strpos($badge_desc_lower, 'participation') !== false) {
                $badge_category = 'Active Participant';
            }
            
            // Check if badge is recent (earned in last 7 days)
            $is_recent = ($badge->dateissued >= (time() - (7 * 24 * 60 * 60)));
            
            $badges_list[] = [
                'title' => format_string($badge->name),
                'description' => !empty($badge->description) ? strip_tags(format_text($badge->description, FORMAT_HTML)) : 'Badge earned',
                'date_earned' => $badge->dateissued,
                'date_formatted' => userdate($badge->dateissued, '%B %d, %Y'),
                'date_earned_short' => userdate($badge->dateissued, '%d %b %Y'),
                'icon' => 'fa-trophy',
                'color' => 'gold',
                'course_name' => $course_name,
                'badge_id' => $badge->id,
                'badge_image_url' => $badgeimageurl->out(),
                'uniquehash' => $badge->uniquehash,
                'category' => $badge_category,
                'image' => $badgeimageurl->out(),
                'name' => format_string($badge->name),
                'badge_url' => (new moodle_url('/badges/badge.php', ['hash' => $badge->uniquehash]))->out(),
                'is_recent' => $is_recent
            ];
        }
    } catch (Exception $e) {
        error_log("Error fetching badges: " . $e->getMessage());
    }
}

// Combine certificates and badges into a single achievements array for display
$achievements = [];

// Add certificates to achievements
foreach ($certificates_list as $cert) {
    $achievements[] = [
        'type' => 'certificate',
        'title' => $cert['title'],
        'description' => $cert['description'],
        'date_earned' => $cert['date_earned'],
        'date_formatted' => $cert['date_formatted'],
        'icon' => $cert['icon'],
        'color' => $cert['color'],
        'course_name' => $cert['course_name'],
        'certificate_id' => $cert['certificate_id'],
        'cmid' => $cert['cmid'],
        'issueid' => $cert['issueid'],
        'download_action' => $cert['download_action'] ?? ''
    ];
}

// Add badges to achievements
foreach ($badges_list as $badge) {
    $achievements[] = [
        'type' => 'badge',
        'title' => $badge['title'],
        'description' => $badge['description'],
        'date_earned' => $badge['date_earned'],
        'date_formatted' => $badge['date_formatted'],
        'icon' => $badge['icon'],
        'color' => $badge['color'],
        'course_name' => $badge['course_name'],
        'badge_id' => $badge['badge_id'],
        'badge_image_url' => $badge['badge_image_url'],
        'uniquehash' => $badge['uniquehash']
    ];
}

// Sort all achievements by date (most recent first)
usort($achievements, function($a, $b) {
    return $b['date_earned'] - $a['date_earned'];
});

// Calculate statistics
$certificates_count = count($certificates_list);
$badges_count = count($badges_list);
$total_achievements = count($achievements);
$total_badges = $DB->count_records('badge', ['status' => 1]); // Active badges
$recent_badges = count(array_filter($badges_list, function($badge) { return $badge['is_recent'] ?? false; }));
$completion_rate = $total_badges > 0 ? round(($badges_count / $total_badges) * 100) : 0;

// Get user profile picture for welcome header
$usercontext = context_user::instance($USER->id);
$userpicture = $OUTPUT->user_picture($USER, ['size' => 100, 'link' => false]);
$student_picture_url_only = '';
if (preg_match('/src="([^"]+)"/', $userpicture, $matches)) {
    $student_picture_url_only = $matches[1];
}

// Prepare template context for the Elementary Achievements page
$templatecontext = [
    'custom_elementary_achievements' => true,
    'student_name' => $USER->firstname ?: $USER->username,
    'student_picture_url_only' => $student_picture_url_only,
    'usercohortname' => $usercohortname,
    'dashboardtype' => $dashboardtype,
    'is_elementary_grade' => true,
    
    // Achievements data
    'achievements' => $achievements,
    'certificates' => $certificates_list,
    'badges' => $badges_list,
    'has_certificates' => !empty($certificates_list),
    'has_badges' => !empty($badges_list),
    'has_achievements' => !empty($achievements),
    'total_achievements' => $total_achievements,
    'certificates_count' => $certificates_count,
    'badges_count' => $badges_count,
    
    // Stats for template
    'total_badges' => $total_badges,
    'earned_badges' => $badges_count,
    'recent_badges' => $recent_badges,
    'completion_rate' => $completion_rate,
    
    // Page identification flags for sidebar
    'is_achievements_page' => true,
    'is_dashboard_page' => false,
    'is_lessons_page' => false,
    'is_mycourses_page' => false,
    'is_activities_page' => false,
    
    // Navigation URLs
    'wwwroot' => $CFG->wwwroot,
    'dashboardurl' => (new moodle_url('/my/'))->out(),
    'mycoursesurl' => (new moodle_url('/theme/remui_kids/elementary_my_course.php'))->out(),
    'elementary_mycoursesurl' => (new moodle_url('/theme/remui_kids/elementary_my_course.php'))->out(),
    'lessonsurl' => (new moodle_url('/theme/remui_kids/elementary_lessons.php'))->out(),
    'activitiesurl' => (new moodle_url('/theme/remui_kids/elementary_activities.php'))->out(),
    'achievementsurl' => (new moodle_url('/theme/remui_kids/elementary_achievements.php'))->out(),
    'competenciesurl' => (new moodle_url('/theme/remui_kids/elementary_competencies.php'))->out(),
    'scheduleurl' => (new moodle_url('/theme/remui_kids/elementary_calendar.php'))->out(),
    'myreportsurl' => (new moodle_url('/theme/remui_kids/elementary_myreports.php'))->out(),
    'treeviewurl' => (new moodle_url('/theme/remui_kids/elementary_treeview.php'))->out(),
    'allcoursesurl' => (new moodle_url('/course/index.php'))->out(),
    'profileurl' => (new moodle_url('/theme/remui_kids/elementary_profile.php'))->out(),
    'settingsurl' => (new moodle_url('/user/preferences.php'))->out(),
    'logouturl' => (new moodle_url('/login/logout.php', ['sesskey' => sesskey()]))->out(),
    'scratcheditorurl' => (new moodle_url('/theme/remui_kids/scratch_simple.php'))->out(),
    'codeeditorurl' => (new moodle_url('/theme/remui_kids/code_editor_simple.php'))->out(),
    'emulatorsurl' => (new moodle_url('/theme/remui_kids/emulators.php'))->out(),
    'has_scratch_editor_access' => theme_remui_kids_user_has_scratch_editor_access($USER->id),
    'has_code_editor_access' => theme_remui_kids_user_has_code_editor_access($USER->id),
];

// Render the template using Moodle's standard header/footer system
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('theme_remui_kids/elementary_achievements_simple', $templatecontext);
echo $OUTPUT->footer();
