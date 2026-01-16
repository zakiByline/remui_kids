<?php
/**
 * Achievements Page - Student Achievements and Progress
 * Displays student achievements, certificates, and learning milestones
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
require_once(__DIR__ . '/lib/highschool_sidebar.php');
require_once(__DIR__ . '/lib/cohort_sidebar_helper.php');

// Require login
require_login();

// Set up the page properly within Moodle
global $USER, $DB, $PAGE, $OUTPUT, $CFG;

// Set page context and properties
$PAGE->set_context(context_system::instance());
$PAGE->set_url('/theme/remui_kids/achievements.php');
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
$dashboardtype = 'default';

if (!empty($usercohorts)) {
    $cohort = reset($usercohorts);
    $usercohortname = $cohort->name;
    $usercohortid = $cohort->id;
    
    // Determine dashboard type based on cohort
    if (preg_match('/grade\s*(?:1[0-2]|[8-9])/i', $usercohortname)) {
        $dashboardtype = 'highschool';
    } elseif (preg_match('/grade\s*[4-7]/i', $usercohortname)) {
        $dashboardtype = 'middle';
    } elseif (preg_match('/grade\s*[1-3]/i', $usercohortname)) {
        $dashboardtype = 'elementary';
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
            
            $badges_list[] = [
                'title' => format_string($badge->name),
                'description' => !empty($badge->description) ? strip_tags(format_text($badge->description, FORMAT_HTML)) : 'Badge earned',
                'date_earned' => $badge->dateissued,
                'date_formatted' => userdate($badge->dateissued, '%B %d, %Y'),
                'icon' => 'fa-trophy',
                'color' => 'gold',
                'course_name' => $course_name,
                'badge_id' => $badge->id,
                'badge_image_url' => $badgeimageurl->out(),
                'uniquehash' => $badge->uniquehash
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

// Prepare template context for the Achievements page
$templatecontext = [
    'custom_achievements' => true,
    'student_name' => $USER->firstname ?: $USER->username,
    'usercohortname' => $usercohortname,
    'dashboardtype' => $dashboardtype,
    'is_elementary_grade' => ($dashboardtype === 'elementary'),
    'is_middle_grade' => ($dashboardtype === 'middle'),
    'is_highschool_grade' => ($dashboardtype === 'highschool'),
    'achievements' => $achievements,
    'certificates' => $certificates_list,
    'badges' => $badges_list,
    'has_certificates' => !empty($certificates_list),
    'has_badges' => !empty($badges_list),
    'has_achievements' => !empty($achievements),
    'total_achievements' => $total_achievements,
    'certificates_count' => $certificates_count,
    'badges_count' => $badges_count,
    'course_completions' => $certificates_count, // For template compatibility
    'quiz_excellence' => 0, // For template compatibility
    'assignment_excellence' => 0, // For template compatibility
    
    // Page identification flags for sidebar
    'is_achievements_page' => true,
    'is_dashboard_page' => false,
    
    // Navigation URLs
    'wwwroot' => $CFG->wwwroot,
    'currentpage' => [
        'achievements' => true
    ]
];

if ($dashboardtype === 'highschool') {
    $sidebarcontext = remui_kids_build_highschool_sidebar_context('achievement', $USER);
    $templatecontext = array_merge($templatecontext, $sidebarcontext);
} else {
    $templatecontext['mycoursesurl'] = new moodle_url('/theme/remui_kids/moodle_mycourses.php');
    $templatecontext['dashboardurl'] = new moodle_url('/my/');
    $templatecontext['assignmentsurl'] = new moodle_url('/mod/assign/index.php');
    $templatecontext['lessonsurl'] = new moodle_url('/theme/remui_kids/lessons.php');
    $templatecontext['activitiesurl'] = new moodle_url('/mod/quiz/index.php');
    $templatecontext['achievementsurl'] = new moodle_url('/theme/remui_kids/achievements.php');
    $templatecontext['competenciesurl'] = new moodle_url('/theme/remui_kids/competencies.php');
    $templatecontext['gradesurl'] = new moodle_url('/theme/remui_kids/grades.php');
    $templatecontext['badgesurl'] = new moodle_url('/theme/remui_kids/badges.php');
    $templatecontext['scheduleurl'] = new moodle_url('/theme/remui_kids/schedule.php');
    $templatecontext['calendarurl'] = new moodle_url('/calendar/view.php');
    $templatecontext['settingsurl'] = new moodle_url('/user/preferences.php');
    $templatecontext['treeviewurl'] = new moodle_url('/theme/remui_kids/treeview.php');
    $templatecontext['scratchemulatorurl'] = (new moodle_url('/theme/remui_kids/scratch_simple.php'))->out();
    $templatecontext['scratcheditorurl'] = (new moodle_url('/theme/remui_kids/scratch_simple.php'))->out();
    $templatecontext['codeeditorurl'] = (new moodle_url('/theme/remui_kids/code_editor_simple.php'))->out();
    $templatecontext['ebooksurl'] = (new moodle_url('/theme/remui_kids/ebooks.php'))->out();
    $templatecontext['askteacherurl'] = (new moodle_url('/theme/remui_kids/pages/student_doubts.php'))->out();
    $templatecontext['messagesurl'] = new moodle_url('/message/index.php');
    $templatecontext['profileurl'] = new moodle_url('/user/profile.php', ['id' => $USER->id]);
    $templatecontext['logouturl'] = new moodle_url('/login/logout.php', ['sesskey' => sesskey()]);
    $templatecontext['has_scratch_editor_access'] = theme_remui_kids_user_has_scratch_editor_access($USER->id);
    $templatecontext['has_code_editor_access'] = theme_remui_kids_user_has_code_editor_access($USER->id);
    $templatecontext['emulatorsurl'] = (new moodle_url('/theme/remui_kids/emulators.php'))->out();
}

// Render the template using Moodle's standard header/footer system
echo $OUTPUT->header();

// Use high school specific template for high school students
if ($dashboardtype === 'highschool') {
    echo $OUTPUT->render_from_template('theme_remui_kids/highschool_achievements_page', $templatecontext);
} else {
    echo $OUTPUT->render_from_template('theme_remui_kids/achievements_page', $templatecontext);
}

echo $OUTPUT->footer();