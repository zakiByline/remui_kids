<?php
/**
 * Custom Elementary Activities page for remui_kids theme
 * Specifically designed for Grade 1-3 students
 *
 * @package    theme_remui_kids
 * @copyright  2024 KodeIt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/lib/completionlib.php');
require_once($CFG->dirroot . '/theme/remui_kids/lib.php');

require_login();

global $USER, $DB, $PAGE, $OUTPUT, $CFG;

// Purge caches to ensure fresh data
// Removed purge_all_caches() for performance;

// Debug: Log user information
error_log("Elementary Activities: Starting for user {$USER->id} ({$USER->firstname} {$USER->lastname})");

// Set up the page
$PAGE->set_context(context_system::instance());
$PAGE->set_url('/theme/remui_kids/elementary_activities.php');
$PAGE->set_pagelayout('elementary_activities');
$PAGE->set_title('My Activities');
$PAGE->set_heading('My Activities');

// Get user's cohort information
$usercohorts = $DB->get_records_sql(
    "SELECT c.name, c.id 
     FROM {cohort} c 
     JOIN {cohort_members} cm ON c.id = cm.cohortid 
     WHERE cm.userid = ?",
    [$USER->id]
);

$usercohortname = '';
$usercohortid = 0;
$dashboardtype = 'elementary'; // Force elementary for this page

if (!empty($usercohorts)) {
    $cohort = reset($usercohorts);
    $usercohortname = $cohort->name;
    $usercohortid = $cohort->id;
    
    // Ensure this is for elementary students
    if (preg_match('/grade\s*[1-3]/i', $usercohortname)) {
        $dashboardtype = 'elementary';
    }
}

// Test query to see if we can fetch any activities at all
error_log("Elementary Activities: Testing basic activity query...");
$test_activities = $DB->get_records_sql(
    "SELECT cm.id, cm.course, m.name as modulename, c.fullname as coursename
     FROM {course_modules} cm
     JOIN {modules} m ON cm.module = m.id
     JOIN {course} c ON cm.course = c.id
     WHERE cm.visible = 1 AND cm.deletioninprogress = 0
     LIMIT 10",
    []
);
error_log("Elementary Activities: Test query found " . count($test_activities) . " activities in the system");

// Get elementary student's activities
$studentactivities = [];
$activitytypes = [
    'quiz' => 'Quiz',
    'assign' => 'Assignment',
    'lesson' => 'Lesson',
    'forum' => 'Discussion',
    'choice' => 'Poll',
    'glossary' => 'Glossary',
    'wiki' => 'Wiki',
    'workshop' => 'Workshop',
    'scorm' => 'SCORM Package',
    'hvp' => 'Interactive Content',
    'lti' => 'External Tool',
    'book' => 'Book',
    'page' => 'Page',
    'url' => 'URL',
    'file' => 'File',
    'folder' => 'Folder',
    'resource' => 'Resource',
    'label' => 'Label',
    'chat' => 'Chat',
    'data' => 'Database',
    'feedback' => 'Feedback',
    'survey' => 'Survey',
    'game' => 'Game',
    'hotpot' => 'Hot Potatoes',
    'journal' => 'Journal',
    'lightboxgallery' => 'Lightbox Gallery',
    'mindmap' => 'Mind Map',
    'oublog' => 'OU Blog',
    'ouwiki' => 'OU Wiki',
    'questionnaire' => 'Questionnaire',
    'recordingsbn' => 'BigBlueButtonBN',
    'turnitintooltwo' => 'Turnitin',
    'videofile' => 'Video File',
    'zoom' => 'Zoom Meeting',
    'checklist' => 'Checklist',
    'certificate' => 'Certificate',
    'customcert' => 'Custom Certificate',
    'dialogue' => 'Dialogue',
    'etherpadlite' => 'Etherpad Lite',
    'bigbluebuttonbn' => 'BigBlueButton',
    'attendance' => 'Attendance',
    'checkmark' => 'Checkmark'
];

try {
    // Get user's enrolled courses
    $courses = enrol_get_all_users_courses($USER->id, true);
    
    // Debug: Log the number of enrolled courses
    error_log("Elementary Activities: User {$USER->id} has " . count($courses) . " enrolled courses");
    
    foreach ($courses as $course) {
        // Debug: Log course info
        error_log("Elementary Activities: Processing course: {$course->fullname} (ID: {$course->id})");
        
        // Get all course modules (activities) - focused on active activities like in the image
        $activities = $DB->get_records_sql(
            "SELECT cm.id as cmid, cm.instance, cm.completion, cm.completionview, cm.visible as cmvisible,
                    cm.section, cm.sequence, cm.availability, cm.indent,
                    m.name as modulename, m.id as moduleid,
                    c.id as courseid, c.fullname as coursename, c.shortname as courseshortname,
                    cs.name as sectionname, cs.summary as sectionsummary,
                    COALESCE(
                        q.name, a.name, l.name, f.name, ch.name, g.name, w.name, ws.name, s.name, h.name, lt.name,
                        b.name, p.name, u.name, fi.name, fo.name, r.name, lab.name, chat.name, d.name, fb.name, 
                        su.name, game.name, hp.name, j.name, lbg.name, mm.name, ob.name, ow.name, qn.name, 
                        rbn.name, tt.name, vf.name, z.name, t.name, ck.name, cr.name, cc.name, cd.name, ce.name
                    ) as activityname,
                    COALESCE(
                        q.intro, a.intro, l.intro, f.intro, ch.intro, g.intro, w.intro, ws.intro, s.intro, h.intro, lt.intro,
                        b.intro, p.intro, u.intro, fi.intro, fo.intro, r.intro, lab.intro, chat.intro, d.intro, fb.intro,
                        su.intro, game.intro, hp.intro, j.intro, lbg.intro, mm.intro, ob.intro, ow.intro, qn.intro,
                        rbn.intro, tt.intro, vf.intro, z.intro, t.intro, ck.intro, cr.intro, cc.intro, cd.intro, ce.intro
                    ) as activityintro
             FROM {course_modules} cm
             JOIN {modules} m ON cm.module = m.id
             JOIN {course} c ON cm.course = c.id
             LEFT JOIN {course_sections} cs ON cm.section = cs.id
             
             -- Core activities (most common)
             LEFT JOIN {quiz} q ON (m.name = 'quiz' AND cm.instance = q.id)
             LEFT JOIN {assign} a ON (m.name = 'assign' AND cm.instance = a.id)
             LEFT JOIN {lesson} l ON (m.name = 'lesson' AND cm.instance = l.id)
             LEFT JOIN {forum} f ON (m.name = 'forum' AND cm.instance = f.id)
             LEFT JOIN {choice} ch ON (m.name = 'choice' AND cm.instance = ch.id)
             LEFT JOIN {glossary} g ON (m.name = 'glossary' AND cm.instance = g.id)
             LEFT JOIN {wiki} w ON (m.name = 'wiki' AND cm.instance = w.id)
             LEFT JOIN {workshop} ws ON (m.name = 'workshop' AND cm.instance = ws.id)
             LEFT JOIN {scorm} s ON (m.name = 'scorm' AND cm.instance = s.id)
             LEFT JOIN {hvp} h ON (m.name = 'hvp' AND cm.instance = h.id)
             LEFT JOIN {lti} lt ON (m.name = 'lti' AND cm.instance = lt.id)
             
             -- Additional activities
             LEFT JOIN {book} b ON (m.name = 'book' AND cm.instance = b.id)
             LEFT JOIN {page} p ON (m.name = 'page' AND cm.instance = p.id)
             LEFT JOIN {url} u ON (m.name = 'url' AND cm.instance = u.id)
             LEFT JOIN {resource} r ON (m.name = 'resource' AND cm.instance = r.id)
             LEFT JOIN {label} lab ON (m.name = 'label' AND cm.instance = lab.id)
             LEFT JOIN {chat} chat ON (m.name = 'chat' AND cm.instance = chat.id)
             LEFT JOIN {data} d ON (m.name = 'data' AND cm.instance = d.id)
             LEFT JOIN {feedback} fb ON (m.name = 'feedback' AND cm.instance = fb.id)
             LEFT JOIN {survey} su ON (m.name = 'survey' AND cm.instance = su.id)
             LEFT JOIN {journal} j ON (m.name = 'journal' AND cm.instance = j.id)
             LEFT JOIN {questionnaire} qn ON (m.name = 'questionnaire' AND cm.instance = qn.id)
             LEFT JOIN {zoom} z ON (m.name = 'zoom' AND cm.instance = z.id)
             
             -- Third-party and additional modules
             LEFT JOIN {turnitintooltwo} t ON (m.name = 'turnitintooltwo' AND cm.instance = t.id)
             LEFT JOIN {checklist} ck ON (m.name = 'checklist' AND cm.instance = ck.id)
             LEFT JOIN {certificate} cr ON (m.name = 'certificate' AND cm.instance = cr.id)
             LEFT JOIN {customcert} cc ON (m.name = 'customcert' AND cm.instance = cc.id)
             LEFT JOIN {dialogue} cd ON (m.name = 'dialogue' AND cm.instance = cd.id)
             LEFT JOIN {etherpadlite} ce ON (m.name = 'etherpadlite' AND cm.instance = ce.id)
             
             WHERE cm.course = ? AND cm.visible = 1 AND cm.deletioninprogress = 0
             AND m.name IN (
                 'quiz', 'assign', 'lesson', 'forum', 'choice', 'glossary', 'wiki', 'workshop', 'scorm', 'hvp', 'lti',
                 'book', 'page', 'url', 'resource', 'label', 'chat', 'data', 'feedback', 'survey', 'journal', 
                 'questionnaire', 'zoom', 'game', 'hotpot', 'lightboxgallery', 'mindmap', 'oublog', 'ouwiki',
                 'recordingsbn', 'turnitintooltwo', 'videofile', 'checklist', 'certificate', 'customcert', 
                 'dialogue', 'etherpadlite', 'bigbluebuttonbn', 'attendance', 'attendance', 'checkmark'
             )
             ORDER BY cm.section, cm.sequence, cm.id",
            [$course->id]
        );
        
        // Debug: Log the number of activities found
        error_log("Elementary Activities: Found " . count($activities) . " activities in course {$course->fullname}");
        
        // If no activities found with the comprehensive query, try a simpler fallback query
        if (empty($activities)) {
            error_log("Elementary Activities: No activities found with comprehensive query, trying fallback query for course {$course->fullname}");
            
            $activities = $DB->get_records_sql(
                "SELECT cm.id as cmid, cm.instance, cm.completion, cm.completionview, cm.visible as cmvisible,
                        cm.section, cm.sequence, cm.availability,
                        m.name as modulename, m.id as moduleid,
                        c.id as courseid, c.fullname as coursename, c.shortname as courseshortname,
                        cs.name as sectionname, cs.summary as sectionsummary,
                        CONCAT('Activity ', cm.id) as activityname,
                        'Complete this activity to continue your learning journey!' as activityintro
                 FROM {course_modules} cm
                 JOIN {modules} m ON cm.module = m.id
                 JOIN {course} c ON cm.course = c.id
                 LEFT JOIN {course_sections} cs ON cm.section = cs.id
                 WHERE cm.course = ? AND cm.visible = 1 AND cm.deletioninprogress = 0
                 ORDER BY cm.section, cm.sequence, cm.id",
                [$course->id]
            );
            
            error_log("Elementary Activities: Fallback query found " . count($activities) . " activities in course {$course->fullname}");
        }
        
        // If still no activities, try the most basic query possible
        if (empty($activities)) {
            error_log("Elementary Activities: Still no activities found, trying basic query for course {$course->fullname}");
            
            $activities = $DB->get_records_sql(
                "SELECT cm.id as cmid, cm.instance, cm.completion, cm.completionview, cm.visible as cmvisible,
                        cm.section, cm.sequence, cm.availability,
                        m.name as modulename, m.id as moduleid,
                        c.id as courseid, c.fullname as coursename, c.shortname as courseshortname,
                        'Section ' || cm.section as sectionname, '' as sectionsummary,
                        CONCAT(m.name, ' ', cm.id) as activityname,
                        'Complete this activity to continue your learning journey!' as activityintro
                 FROM {course_modules} cm
                 JOIN {modules} m ON cm.module = m.id
                 JOIN {course} c ON cm.course = c.id
                 WHERE cm.course = ? AND cm.visible = 1
                 ORDER BY cm.section, cm.sequence, cm.id",
                [$course->id]
            );
            
            error_log("Elementary Activities: Basic query found " . count($activities) . " activities in course {$course->fullname}");
        }
        
        foreach ($activities as $activity) {
            // Skip if no valid cmid or activity name
            if (empty($activity->cmid) || empty($activity->activityname)) {
                continue;
            }
            
            try {
                // Get activity progress
                $progress = theme_remui_kids_get_activity_progress($USER->id, $activity->cmid, $activity->modulename);
                
                // Create activity URL that navigates to course section with activity highlighted
                $activityurl = '';
                $sectionurl = '';
                try {
                    // Create URL to course section with activity anchor
                    // This navigates to the course, shows the section, and highlights the activity
                    $sectionurl = (new moodle_url('/course/view.php', [
                        'id' => $activity->courseid,
                        'section' => $activity->section
                    ]))->out(false) . '#module-' . $activity->cmid;
                    
                    // Also create direct activity URL as fallback
                    $activityurl = (new moodle_url('/mod/' . $activity->modulename . '/view.php', ['id' => $activity->cmid]))->out();
                    
                    // Debug: Log the created URLs
                    error_log("Elementary Activities: Created section URL for '{$activity->activityname}': {$sectionurl}");
                    error_log("Elementary Activities: Created direct URL for '{$activity->activityname}': {$activityurl}");
                    
                } catch (Exception $e) {
                    error_log("Elementary Activities: Failed to create URL for activity '{$activity->activityname}': " . $e->getMessage());
                    
                    // Fallback: try to create a basic course module URL
                    try {
                        $sectionurl = (new moodle_url('/course/view.php', ['id' => $activity->courseid]))->out();
                        $activityurl = (new moodle_url('/mod/view.php', ['id' => $activity->cmid]))->out();
                        error_log("Elementary Activities: Using fallback URLs for '{$activity->activityname}'");
                    } catch (Exception $e2) {
                        error_log("Elementary Activities: Fallback URL also failed for '{$activity->activityname}': " . $e2->getMessage());
                        continue; // Skip this activity if URL creation fails completely
                    }
                }
                
                // Get activity icon
                $activityicon = theme_remui_kids_get_activity_icon($activity->modulename);
                
                // Clean activity intro text
                $intro = $activity->activityintro ? strip_tags($activity->activityintro) : 'Complete this activity to continue your learning journey!';
                if (strlen($intro) > 200) {
                    $intro = substr($intro, 0, 200) . '...';
                }
                
                // Get section information
                $section_name = $activity->sectionname ?: "Section {$activity->section}";
                
                // Get activity due date if available
                $due_date = '';
                $due_date_formatted = '';
                try {
                    // Try to get due date for assignments
                    if ($activity->modulename === 'assign') {
                        $assign_info = $DB->get_record('assign', ['id' => $activity->instance], 'duedate');
                        if ($assign_info && $assign_info->duedate > 0) {
                            $due_date = $assign_info->duedate;
                            $due_date_formatted = date('M j, Y', $assign_info->duedate);
                        }
                    }
                    // Try to get due date for quizzes
                    elseif ($activity->modulename === 'quiz') {
                        $quiz_info = $DB->get_record('quiz', ['id' => $activity->instance], 'timeclose');
                        if ($quiz_info && $quiz_info->timeclose > 0) {
                            $due_date = $quiz_info->timeclose;
                            $due_date_formatted = date('M j, Y', $quiz_info->timeclose);
                        }
                    }
                } catch (Exception $e) {
                    // Ignore due date errors
                }
                
                // Check if activity is overdue
                $is_overdue = false;
                if ($due_date && $due_date < time() && !$progress['completed']) {
                    $is_overdue = true;
                }
                
                // Calculate progress percentage for display
                $progress_percentage = $progress['percentage'];
                if ($progress['completed']) {
                    $progress_percentage = 100;
                } elseif ($progress['in_progress'] && $progress_percentage == 0) {
                    $progress_percentage = 25; // Show some progress if in progress but no percentage
                }
                
                // Create activity data structure matching the image format
                $studentactivities[] = [
                    'id' => $activity->cmid,
                    'name' => $activity->activityname,
                    'intro' => $intro,
                    'type' => $activity->modulename,
                    'type_display' => isset($activitytypes[$activity->modulename]) ? $activitytypes[$activity->modulename] : ucfirst($activity->modulename),
                    'typename' => isset($activitytypes[$activity->modulename]) ? $activitytypes[$activity->modulename] : ucfirst($activity->modulename),
                    'courseid' => $activity->courseid,
                    'coursename' => $activity->coursename,
                    'courseshortname' => $activity->courseshortname,
                    'section' => $activity->section,
                    'section_id' => $activity->section,
                    'sectionname' => $section_name,
                    'cmid' => $activity->cmid,
                    'progress' => $progress_percentage,
                    'progress_percentage' => $progress_percentage,
                    'completed' => $progress['completed'],
                    'in_progress' => $progress['in_progress'],
                    'not_started' => $progress['not_started'],
                    'url' => $sectionurl, // URL to course section with activity highlighted
                    'activityurl' => $activityurl, // Direct activity URL as fallback
                    'icon' => $activityicon,
                    'estimated_time' => theme_remui_kids_get_activity_estimated_time($activity->modulename),
                    'due_date' => $due_date,
                    'due_date_formatted' => $due_date_formatted,
                    'has_duedate' => !empty($due_date),
                    'is_overdue' => $is_overdue,
                    'completion_required' => $activity->completion > 0,
                    'view_required' => $activity->completionview > 0,
                    'categoryname' => $activity->categoryname ?? '',
                    
                    // Additional fields for better activity display
                    'is_clickable' => true,
                    'can_perform' => true,
                    'status_badge' => $progress['completed'] ? 'Completed' : ($progress['in_progress'] ? 'In Progress' : 'Not Started'),
                    'status_color' => $progress['completed'] ? 'success' : ($progress['in_progress'] ? 'warning' : 'info'),
                    'activity_icon_class' => $activityicon,
                    'short_description' => strlen($intro) > 100 ? substr($intro, 0, 100) . '...' : $intro
                ];
                
                // Debug: Log successful activity processing
                error_log("Elementary Activities: Successfully processed activity '{$activity->activityname}' ({$activity->modulename}) from course '{$activity->coursename}' - Section: {$activity->section}");
                
            } catch (Exception $e) {
                error_log("Elementary Activities: Error processing activity '{$activity->activityname}': " . $e->getMessage());
                continue; // Skip problematic activities
            }
        }
    }
} catch (Exception $e) {
    error_log("Elementary Activities: Error fetching activities for user {$USER->id}: " . $e->getMessage());
    $studentactivities = []; // Fallback to empty array
}

// Calculate activity statistics
$completed_activities_count = 0;
$in_progress_activities_count = 0;
$overdue_activities_count = 0;
$total_activities_count = count($studentactivities);

// Group activities by type
$activities_by_type = [];
$activities_by_course = [];

foreach ($studentactivities as $activity) {
    // Count by status
    if ($activity['completed']) {
        $completed_activities_count++;
    } elseif ($activity['in_progress']) {
        $in_progress_activities_count++;
    }
    
    if ($activity['is_overdue']) {
        $overdue_activities_count++;
    }
    
    // Group by type
    if (!isset($activities_by_type[$activity['type']])) {
        $activities_by_type[$activity['type']] = [];
    }
    $activities_by_type[$activity['type']][] = $activity;
    
    // Group by course
    if (!isset($activities_by_course[$activity['courseid']])) {
        $activities_by_course[$activity['courseid']] = [
            'coursename' => $activity['coursename'],
            'courseshortname' => $activity['courseshortname'],
            'activities' => []
        ];
    }
    $activities_by_course[$activity['courseid']]['activities'][] = $activity;
}

// Debug: Log total activities found
error_log("Elementary Activities: Total activities found for user {$USER->id}: {$total_activities_count}");

// Additional debugging - log all activities found
if (!empty($studentactivities)) {
    error_log("Elementary Activities: Activities found:");
    foreach ($studentactivities as $activity) {
        error_log("  - {$activity['name']} ({$activity['type']}) from {$activity['coursename']}");
    }
} else {
    error_log("Elementary Activities: NO ACTIVITIES FOUND - This might indicate a problem with the query or user enrollment");
    
    // Let's check if the user has any enrolled courses at all
    $enrolled_courses = enrol_get_all_users_courses($USER->id, true);
    error_log("Elementary Activities: User {$USER->id} has " . count($enrolled_courses) . " enrolled courses");
    
    foreach ($enrolled_courses as $course) {
        error_log("  - Course: {$course->fullname} (ID: {$course->id})");
        
        // Check if this course has any modules at all
        $module_count = $DB->count_records('course_modules', ['course' => $course->id, 'visible' => 1, 'deletioninprogress' => 0]);
        error_log("    - Has {$module_count} visible course modules");
    }
}

// Prepare template context for elementary dashboard integration
$templatecontext = [
    'custom_elementary_activities' => true,
    'dashboard_type' => 'elementary',
    'user_cohort_name' => $usercohortname,
    'user_cohort_id' => $usercohortid,
    'student_name' => $USER->firstname,
    'student_activities' => $studentactivities,
    'has_student_activities' => !empty($studentactivities),
    'total_activities_count' => $total_activities_count,
    'completed_activities_count' => $completed_activities_count,
    'in_progress_activities_count' => $in_progress_activities_count,
    'overdue_activities_count' => $overdue_activities_count,
    'activities_by_type' => $activities_by_type,
    'activities_by_course' => $activities_by_course,
    
    // URLs for sidebar navigation - pointing to elementary pages
    'dashboardurl' => (new moodle_url('/my/'))->out(),
    'mycoursesurl' => (new moodle_url('/theme/remui_kids/moodle_mycourses.php'))->out(),
    'lessonsurl' => (new moodle_url('/theme/remui_kids/elementary_lessons.php'))->out(),
    'activitiesurl' => (new moodle_url('/theme/remui_kids/elementary_activities.php'))->out(),
    'achievementsurl' => (new moodle_url('/badges/mybadges.php'))->out(),
    'competenciesurl' => (new moodle_url('/theme/remui_kids/elementary_competencies.php'))->out(),
    'scheduleurl' => (new moodle_url('/calendar/view.php'))->out(),
    'treeviewurl' => (new moodle_url('/theme/remui_kids/treeview.php'))->out(),
    'settingsurl' => (new moodle_url('/user/preferences.php'))->out(),
    'profileurl' => (new moodle_url('/user/profile.php', ['id' => $USER->id]))->out(),
    'logouturl' => (new moodle_url('/login/logout.php', ['sesskey' => sesskey()]))->out(),
];

// Set elementary flag
$templatecontext['elementary'] = true;

// Add body class for styling
$templatecontext['bodyattributes'] = 'class="elementary-activities-page has-student-sidebar"';

// Flag to hide the default navbar
$templatecontext['hide_default_navbar'] = true;

// Render the elementary activities page
echo $OUTPUT->render_from_template('theme_remui_kids/elementary_activities_page', $templatecontext);

