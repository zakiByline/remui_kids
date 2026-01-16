<?php
/**
 * Parent Competencies Page - Course-Wise View
 * Shows competencies organized by course for the selected child
 *
 * @package    theme_remui_kids
 * @copyright  2025 Kodeit
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Suppress error display to prevent output before headers/session start
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/lib/completionlib.php');
require_once($CFG->dirroot . '/cohort/lib.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->libdir . '/badgeslib.php');

// Load Moodle Competency APIs
require_once($CFG->dirroot . '/competency/classes/competency_framework.php');
require_once($CFG->dirroot . '/competency/classes/api.php');
require_once($CFG->dirroot . '/competency/classes/competency.php');
require_once($CFG->dirroot . '/competency/classes/user_competency.php');
require_once($CFG->dirroot . '/competency/classes/user_competency_course.php');

// FLTECH integration
$fltech_available = file_exists($CFG->dirroot . '/local/fltech/lib.php');
if ($fltech_available) {
    require_once($CFG->dirroot . '/local/fltech/lib.php');
}

require_login();

global $USER, $DB, $CFG, $OUTPUT, $PAGE;

// ========================================
// PARENT ACCESS CONTROL
// ========================================
$parent_role = $DB->get_record('role', ['shortname' => 'parent']);
$system_context = context_system::instance();

$is_parent = false;
if ($parent_role) {
    $is_parent = user_has_role_assignment($USER->id, $parent_role->id, $system_context->id);
    
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

if (!$is_parent) {
    redirect(
        new moodle_url('/'),
        get_string('nopermissions', 'error', 'Access parent competencies'),
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}

// Include child session manager
require_once(__DIR__ . '/../lib/child_session.php');

// Get selected child from URL parameter or session
$child_param = optional_param('child', null, PARAM_INT);
if ($child_param !== null) {
    set_selected_child($child_param);
    $selected_child_id = $child_param;
} else {
    $selected_child_id = get_selected_child();
}

// Set up page
$PAGE->set_context($system_context);
$PAGE->set_url(new moodle_url('/theme/remui_kids/parent/parent_competencies.php'));
$PAGE->set_pagelayout('standard');
$PAGE->set_title('Competencies by Course');
$PAGE->set_heading('Competencies by Course');

// ========================================
// HELPER FUNCTIONS
// ========================================
$slugify = function(string $text): string {
    $text = core_text::strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    return trim($text, '-');
};

$determine_status = function(array $records): array {
    $result = [
        'status' => 'notcompetent',
        'progress' => 0,
        'proficiency' => false
    ];

    if (empty($records)) {
        return $result;
    }

    $bestprogress = 0;
    $proficient = false;
    $inprogress = false;

    foreach ($records as $record) {
        $grade = isset($record->grade) ? (float)$record->grade : 0;
        if ($grade > 1 && $grade <= 100) {
            $bestprogress = max($bestprogress, $grade);
        } else if ($grade > 0 && $grade <= 1) {
            $bestprogress = max($bestprogress, $grade * 100);
        }

        if (!empty($record->proficiency)) {
            $proficient = true;
        }

        if (isset($record->status) && (int)$record->status === 1) {
            $inprogress = true;
        }
    }

    if ($proficient) {
        $result['status'] = 'competent';
        $result['proficiency'] = true;
        $result['progress'] = $bestprogress > 0 ? $bestprogress : 100;
        return $result;
    }

    if ($inprogress || $bestprogress > 0) {
        $result['status'] = 'inprogress';
        $result['progress'] = $bestprogress > 0 ? $bestprogress : 50;
        return $result;
    }

    return $result;
};

$ratingStylePalette = [
    'mastery' => ['chip' => 'rating-mastery', 'dot' => 'rating-mastery', 'color' => '#10b981'],
    'mastered' => ['chip' => 'rating-mastery', 'dot' => 'rating-mastery', 'color' => '#10b981'],
    'proficient' => ['chip' => 'rating-proficient', 'dot' => 'rating-proficient', 'color' => '#3b82f6'],
    'competent' => ['chip' => 'rating-proficient', 'dot' => 'rating-proficient', 'color' => '#3b82f6'],
    'developing' => ['chip' => 'rating-developing', 'dot' => 'rating-developing', 'color' => '#f59e0b'],
    'approaching' => ['chip' => 'rating-developing', 'dot' => 'rating-developing', 'color' => '#f59e0b'],
    'emerging' => ['chip' => 'rating-emerging', 'dot' => 'rating-emerging', 'color' => '#8b5cf6'],
    'novice' => ['chip' => 'rating-emerging', 'dot' => 'rating-emerging', 'color' => '#8b5cf6'],
    'beginning' => ['chip' => 'rating-emerging', 'dot' => 'rating-emerging', 'color' => '#8b5cf6'],
    'needs-improvement' => ['chip' => 'rating-warning', 'dot' => 'rating-warning', 'color' => '#ef4444'],
    'not-yet-competent' => ['chip' => 'rating-warning', 'dot' => 'rating-warning', 'color' => '#ef4444'],
    'in-progress' => ['chip' => 'rating-progress', 'dot' => 'rating-progress', 'color' => '#06b6d4'],
    'in-progress-mid' => ['chip' => 'rating-progress', 'dot' => 'rating-progress', 'color' => '#06b6d4'],
    'partially-mastered' => ['chip' => 'rating-progress', 'dot' => 'rating-progress', 'color' => '#06b6d4']
];

$resolveRatingStyle = function(string $label) use ($slugify, $ratingStylePalette): array {
    $slug = $slugify($label);
    if ($slug === '') {
        return ['chip' => 'rating-default', 'dot' => 'rating-default', 'color' => '#64748b'];
    }
    if (isset($ratingStylePalette[$slug])) {
        return $ratingStylePalette[$slug];
    }
    foreach ($ratingStylePalette as $key => $palette) {
        if (strpos($slug, $key) !== false) {
            return $palette;
        }
    }
    return ['chip' => 'rating-default', 'dot' => 'rating-default', 'color' => '#64748b'];
};

// ========================================
// FETCH PARENT'S CHILDREN (IOMAD Compatible)
// ========================================
$children_records = [];

try {
    // Method 1: IOMAD company_users approach (if table exists)
    if ($DB->get_manager()->table_exists('company_users')) {
        // Get parent's company
        $parent_company = $DB->get_record('company_users', ['userid' => $USER->id]);
        
        if ($parent_company) {
            // Get all students in same company with parent role assignment
            $sql = "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email, u.timecreated,
                           u.phone1, u.phone2, u.address, u.city, u.country,
                           u.picture, u.imagealt,
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
                'parentid' => $USER->id,
                'ctxlevel' => CONTEXT_USER
            ]);
        }
    }
    
    // Method 2: Standard Moodle role assignment (if Method 1 found nothing)
    if (empty($children_records)) {
        $sql = "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email, u.timecreated,
                       u.phone1, u.phone2, u.address, u.city, u.country,
                       u.picture, u.imagealt,
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
            'parentid' => $USER->id,
            'ctxlevel' => CONTEXT_USER
        ]);
    }
    
    // Method 3: Get mentees (if Methods 1 & 2 found nothing)
    if (empty($children_records)) {
        $sql_mentee = "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email, u.timecreated,
                              u.phone1, u.phone2, u.address, u.city, u.country,
                              u.picture, u.imagealt,
                              c.id as cohortid, c.name as cohortname
                       FROM {user} u
                       LEFT JOIN {cohort_members} cm ON cm.userid = u.id
                       LEFT JOIN {cohort} c ON c.id = cm.cohortid
                       WHERE u.id IN (
                           SELECT userid FROM {role_assignments} 
                           WHERE contextid IN (
                               SELECT id FROM {context} 
                               WHERE contextlevel = :ctxlevel
                               AND instanceid = :userid
                           )
                       )
                       AND u.deleted = 0
                       ORDER BY u.firstname, u.lastname";
        
        $children_records = $DB->get_records_sql($sql_mentee, [
            'userid' => $USER->id,
            'ctxlevel' => CONTEXT_USER
        ]);
    }
} catch (Exception $e) {
    debugging('Error fetching children: ' . $e->getMessage());
}

// ========================================
// FETCH COMPETENCIES BY COURSE FOR SELECTED CHILD
// ========================================
$child_data = null;
$courses_with_competencies = [];
$frameworkscales = [];
$frameworknames = [];
$total_stats = [
    'total_competencies' => 0,
    'competent' => 0,
    'inprogress' => 0,
    'notcompetent' => 0
];

if ($selected_child_id && $selected_child_id !== 'all' && isset($children_records[$selected_child_id])) {
    $child_data = $children_records[$selected_child_id];
    
    try {
        // Get child's enrolled courses
        $courses = enrol_get_all_users_courses($selected_child_id, true);
        $courseids = !empty($courses) ? array_keys($courses) : [];
        
        // ✅ STEP 1: FETCH ALL COURSE COMPETENCIES using Moodle API
        $coursecompetencies = [];
        if (!empty($courseids)) {
            try {
                // Use Moodle's native API to fetch course competencies
                foreach ($courseids as $courseid) {
                    try {
                        $course_competencies = \core_competency\api::list_course_competencies($courseid);
                        
                        foreach ($course_competencies as $coursecomp) {
                            $competency = $coursecomp->get_competency();
                            $framework = $competency->get_framework();
                            
                            $record = (object)[
                                'courseid' => $courseid,
                                'coursecompid' => $coursecomp->get_id(),
                                'competencyid' => $competency->get_id(),
                                'shortname' => $competency->get_shortname(),
                                'description' => $competency->get_description(),
                                'descriptionformat' => $competency->get_descriptionformat(),
                                'competencyframeworkid' => $competency->get_competencyframeworkid(),
                                'frameworkid' => $framework->get_id(),
                                'frameworkname' => $framework->get_shortname(),
                                'scaleid' => $framework->get_scaleid()
                            ];
                            
                            $coursecompetencies[] = $record;
                        }
                    } catch (Exception $e) {
                        // Fallback to SQL if API fails
                        debugging('Error using competency API for course ' . $courseid . ': ' . $e->getMessage());
                    }
                }
                
                // Fallback to SQL if API returned nothing
                if (empty($coursecompetencies)) {
                    list($coursesql, $courseparams) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'courseid');
                    
                    $coursecompetencies = $DB->get_records_sql(
                        "SELECT cc.courseid,
                               cc.id AS coursecompid,
                               c.id AS competencyid,
                               c.shortname,
                               c.description,
                               c.descriptionformat,
                               c.competencyframeworkid,
                               f.id AS frameworkid,
                               f.shortname AS frameworkname,
                               f.scaleid
                          FROM {competency_coursecomp} cc
                          JOIN {competency} c ON c.id = cc.competencyid
                          JOIN {competency_framework} f ON f.id = c.competencyframeworkid
                         WHERE cc.courseid $coursesql
                      ORDER BY cc.courseid, f.sortorder, c.sortorder, c.shortname",
                        $courseparams
                    );
                }
            } catch (Exception $e) {
                debugging('Error fetching course competencies: ' . $e->getMessage());
                // Fallback to SQL
                list($coursesql, $courseparams) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'courseid');
                
                $coursecompetencies = $DB->get_records_sql(
                    "SELECT cc.courseid,
                           cc.id AS coursecompid,
                           c.id AS competencyid,
                           c.shortname,
                           c.description,
                           c.descriptionformat,
                           c.competencyframeworkid,
                           f.id AS frameworkid,
                           f.shortname AS frameworkname,
                           f.scaleid
                      FROM {competency_coursecomp} cc
                      JOIN {competency} c ON c.id = cc.competencyid
                      JOIN {competency_framework} f ON f.id = c.competencyframeworkid
                     WHERE cc.courseid $coursesql
                  ORDER BY cc.courseid, f.sortorder, c.sortorder, c.shortname",
                    $courseparams
                );
            }
        }
        
        // ✅ STEP 2: FETCH ALL USER PROGRESS using Moodle API (course-level)
        $usercourseprogress = [];
        $competencyids = [];
        
        if (!empty($coursecompetencies)) {
            foreach ($coursecompetencies as $rec) {
                $competencyids[(int)$rec->competencyid] = true;
            }
            
            if (!empty($competencyids) && !empty($courseids)) {
                try {
                    // Use Moodle's native API to fetch user competency progress
                    foreach ($courseids as $courseid) {
                        foreach (array_keys($competencyids) as $competencyid) {
                            try {
                                $usercompcourse = \core_competency\api::get_user_competency_in_course(
                                    $courseid,
                                    $selected_child_id,
                                    $competencyid
                                );
                                
                                if ($usercompcourse) {
                                    $cid = (int)$competencyid;
                                    $courseid_int = (int)$courseid;
                                    
                                    if (!isset($usercourseprogress[$cid])) {
                                        $usercourseprogress[$cid] = [];
                                    }
                                    if (!isset($usercourseprogress[$cid][$courseid_int])) {
                                        $usercourseprogress[$cid][$courseid_int] = [];
                                    }
                                    
                                    // Convert API object to record format
                                    $record = (object)[
                                        'id' => $usercompcourse->get_id(),
                                        'userid' => $usercompcourse->get_userid(),
                                        'competencyid' => $usercompcourse->get_competencyid(),
                                        'courseid' => $usercompcourse->get_courseid(),
                                        'grade' => $usercompcourse->get_grade(),
                                        'proficiency' => $usercompcourse->get_proficiency(),
                                        'status' => $usercompcourse->get_status(),
                                        'reviewerid' => $usercompcourse->get_reviewerid(),
                                        'timecreated' => $usercompcourse->get_timecreated(),
                                        'timemodified' => $usercompcourse->get_timemodified()
                                    ];
                                    
                                    $usercourseprogress[$cid][$courseid_int][] = $record;
                                }
                            } catch (Exception $e) {
                                // API may return null if no progress exists, which is fine
                                continue;
                            }
                        }
                    }
                } catch (Exception $e) {
                    debugging('Error using competency API for user progress: ' . $e->getMessage());
                }
                
                // Fallback to SQL if API didn't return enough data
                if (empty($usercourseprogress)) {
                    list($compsql, $compparams) = $DB->get_in_or_equal(array_keys($competencyids), SQL_PARAMS_NAMED, 'compid');
                    list($coursesql2, $courseparams2) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'courseid2');
                    
                    $usercourseprogressrecords = $DB->get_records_sql(
                        "SELECT ucc.*
                           FROM {competency_usercompcourse} ucc
                          WHERE ucc.userid = :userid
                            AND ucc.courseid $coursesql2
                            AND ucc.competencyid $compsql",
                        array_merge(['userid' => $selected_child_id], $courseparams2, $compparams)
                    );
                    
                    foreach ($usercourseprogressrecords as $record) {
                        $cid = (int)$record->competencyid;
                        $courseid = (int)$record->courseid;
                        if (!isset($usercourseprogress[$cid])) {
                            $usercourseprogress[$cid] = [];
                        }
                        if (!isset($usercourseprogress[$cid][$courseid])) {
                            $usercourseprogress[$cid][$courseid] = [];
                        }
                        $usercourseprogress[$cid][$courseid][] = $record;
                    }
                }
            }
        }
        
        // ✅ STEP 3: FETCH ALL GLOBAL COMPETENCIES
        $userglobalprogress = [];
        if ($DB->get_manager()->table_exists('competency_usercomp')) {
            $userglobalprogressrecords = $DB->get_records('competency_usercomp', ['userid' => $selected_child_id]);
            foreach ($userglobalprogressrecords as $record) {
                $cid = (int)$record->competencyid;
                if (!isset($userglobalprogress[$cid])) {
                    $userglobalprogress[$cid] = [];
                }
                $userglobalprogress[$cid][] = $record;
            }
        }
        
        // ✅ STEP 4: FETCH GLOBAL COMPETENCIES NOT IN COURSES
        $all_course_comp_ids = [];
        foreach ($coursecompetencies as $rec) {
            $all_course_comp_ids[(int)$rec->competencyid] = true;
        }
        
        $global_comp_ids = array_diff(array_keys($userglobalprogress), $all_course_comp_ids);
        $global_competency_details = [];
        
        if (!empty($global_comp_ids)) {
            list($globalsql, $globalparams) = $DB->get_in_or_equal($global_comp_ids, SQL_PARAMS_NAMED, 'compid');
            
            $global_competency_details = $DB->get_records_sql(
                "SELECT c.id AS competencyid,
                        c.shortname,
                        c.description,
                        c.descriptionformat,
                        c.competencyframeworkid,
                        f.id AS frameworkid,
                        f.shortname AS frameworkname,
                        f.scaleid
                   FROM {competency} c
                   JOIN {competency_framework} f ON f.id = c.competencyframeworkid
                  WHERE c.id $globalsql
               ORDER BY f.sortorder, c.sortorder, c.shortname",
                $globalparams
            );
        }
        
        // ✅ STEP 5: FETCH ACTIVITIES LINKED TO COMPETENCIES using Moodle API
        // Helper: Check if activity is completed by user (using same logic as competencies.php)
        $is_activity_completed = function($cmid, $userid, $courseid) use ($DB) {
            try {
                $course = get_course($courseid);
                $completion = new completion_info($course);
                $modinfo = get_fast_modinfo($course, $userid);
                
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
        
        $competency_activities = [];
        
        if (!empty($competencyids) && !empty($courseids)) {
            try {
                // Use Moodle's native API to fetch activities linked to competencies
                foreach (array_keys($competencyids) as $competencyid) {
                    foreach ($courseids as $courseid) {
                        try {
                            $coursemodules = \core_competency\api::list_course_modules_using_competency($competencyid, $courseid);
                            
                            if (!empty($coursemodules)) {
                                $cid = (int)$competencyid;
                                $courseid_int = (int)$courseid;
                                
                                if (!isset($competency_activities[$cid])) {
                                    $competency_activities[$cid] = [];
                                }
                                if (!isset($competency_activities[$cid][$courseid_int])) {
                                    $competency_activities[$cid][$courseid_int] = [];
                                }
                                
                                foreach ($coursemodules as $cm) {
                                    try {
                                        $modinfo = get_fast_modinfo($courseid, $selected_child_id);
                                        $cm_info = $modinfo->get_cm($cm->get_id());
                                        
                                        $moduleicons = [
                                            'quiz' => 'fa-question-circle',
                                            'assign' => 'fa-file-alt',
                                            'forum' => 'fa-comments',
                                            'lesson' => 'fa-book-reader',
                                            'workshop' => 'fa-users',
                                            'choice' => 'fa-check-square',
                                            'feedback' => 'fa-clipboard-check',
                                            'scorm' => 'fa-graduation-cap',
                                            'page' => 'fa-file',
                                            'url' => 'fa-link',
                                            'resource' => 'fa-file-alt'
                                        ];
                                        
                                        $moduleicon = $moduleicons[$cm_info->modname] ?? 'fa-tasks';
                                        
                                        // Check completion status using robust completion_info class (same as competencies.php)
                                        $is_completed = $is_activity_completed($cm->get_id(), $selected_child_id, $courseid);
                                        
                                        // Get completion time if completed
                                        $completiontime = null;
                                        if ($is_completed) {
                                            $completion = $DB->get_record('course_modules_completion', [
                                                'coursemoduleid' => $cm->get_id(),
                                                'userid' => $selected_child_id
                                            ]);
                                            if ($completion) {
                                                $completiontime = $completion->timemodified;
                                            }
                                        }
                                        
                                        // Get activity URL - Use our custom parent theme page
                                        $activity_url = '';
                                        if (!empty($cm->get_id()) && !empty($courseid_int) && !empty($cid) && $cid !== 'all' && $cid != 0) {
                                            $activity_url = (new moodle_url('/theme/remui_kids/parent/parent_activity_preview.php', [
                                                'cmid' => $cm->get_id(),
                                                'child' => $cid,
                                                'courseid' => $courseid_int
                                            ]))->out(false);
                                        }
                                        
                                        $competency_activities[$cid][$courseid_int][] = [
                                            'cmid' => $cm->get_id(),
                                            'name' => $cm_info->name,
                                            'module' => $cm_info->modname,
                                            'instance' => $cm_info->instance,
                                            'completed' => $is_completed,
                                            'completionstate' => $is_completed ? COMPLETION_COMPLETE : 0,
                                            'completiontime' => $completiontime,
                                            'icon' => $moduleicon,
                                            'url' => $activity_url
                                        ];
                                    } catch (Exception $e) {
                                        debugging('Error getting module info: ' . $e->getMessage());
                                    }
                                }
                            }
                        } catch (Exception $e) {
                            // API may return empty array, which is fine
                            continue;
                        }
                    }
                }
            } catch (Exception $e) {
                debugging('Error using competency API for activities: ' . $e->getMessage());
            }
            
            // Fallback to SQL if API didn't return data
            if (empty($competency_activities)) {
                $hasmodulecomp = $DB->get_manager()->table_exists('competency_modulecomp');
                $hasactivity = $DB->get_manager()->table_exists('competency_activity');
                
                if (!empty($competencyids) && !empty($courseids)) {
                    list($compsql, $compparams) = $DB->get_in_or_equal(array_keys($competencyids), SQL_PARAMS_NAMED, 'compid');
                    list($coursesql3, $courseparams3) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'courseid3');
                    
                    if ($hasmodulecomp) {
                $activity_records = $DB->get_records_sql(
                    "SELECT mc.competencyid,
                            mc.cmid,
                            cm.id AS cmid,
                            cm.course AS courseid,
                            cm.module,
                            cm.instance,
                            m.name AS modulename,
                            cm.visible,
                            cm.section,
                            cm.idnumber
                       FROM {competency_modulecomp} mc
                       JOIN {course_modules} cm ON cm.id = mc.cmid
                       JOIN {modules} m ON m.id = cm.module
                      WHERE mc.competencyid $compsql
                        AND cm.course $coursesql3
                        AND cm.visible = 1",
                    array_merge($compparams, $courseparams3)
                );
                
                foreach ($activity_records as $ar) {
                    $cid = (int)$ar->competencyid;
                    $courseid = (int)$ar->courseid;
                    if (!isset($competency_activities[$cid])) {
                        $competency_activities[$cid] = [];
                    }
                    if (!isset($competency_activities[$cid][$courseid])) {
                        $competency_activities[$cid][$courseid] = [];
                    }
                    
                    // Get activity name and completion status
                    $activityname = '';
                    $completionstatus = 0;
                    $completiontime = null;
                    
                            try {
                                $modinfo = get_fast_modinfo($courseid, $selected_child_id);
                                if (isset($modinfo->cms[$ar->cmid])) {
                                    $cm = $modinfo->cms[$ar->cmid];
                                    $activityname = $cm->name;
                                    
                                    // Check completion status using robust completion_info class (same as competencies.php)
                                    $course_obj = get_course($courseid);
                                    $completion = new completion_info($course_obj);
                                    $is_completed = false;
                                    $completiontime = null;
                                    
                                    if ($completion->is_enabled($cm)) {
                                        $completion_data = $completion->get_data($cm, false, $selected_child_id);
                                        if ($completion_data && ($completion_data->completionstate == COMPLETION_COMPLETE || 
                                            $completion_data->completionstate == COMPLETION_COMPLETE_PASS)) {
                                            $is_completed = true;
                                            $completionstatus = (int)$completion_data->completionstate;
                                            $completiontime = $completion_data->timemodified;
                                        }
                                    }
                                } else {
                                    // Fallback: get from module table
                                    $activityname = $DB->get_field($ar->modulename, 'name', ['id' => $ar->instance]);
                                    $is_completed = false;
                                    $completionstatus = 0;
                                }
                            } catch (Exception $e) {
                                $activityname = $DB->get_field($ar->modulename, 'name', ['id' => $ar->instance]);
                                $is_completed = false;
                                $completionstatus = 0;
                            }
                    
                    // Get module icon
                    $moduleicons = [
                        'quiz' => 'fa-question-circle',
                        'assign' => 'fa-file-alt',
                        'forum' => 'fa-comments',
                        'lesson' => 'fa-book-reader',
                        'workshop' => 'fa-users',
                        'choice' => 'fa-check-square',
                        'feedback' => 'fa-clipboard-check',
                        'scorm' => 'fa-graduation-cap',
                        'page' => 'fa-file',
                        'url' => 'fa-link',
                        'resource' => 'fa-file-alt'
                    ];
                    $moduleicon = $moduleicons[$ar->modulename] ?? 'fa-tasks';
                    
                    // Get activity URL - Use our custom parent theme page
                    $activity_url = '';
                    if (!empty($ar->cmid) && !empty($courseid) && !empty($cid) && $cid !== 'all' && $cid != 0) {
                        $activity_url = (new moodle_url('/theme/remui_kids/parent/parent_activity_preview.php', [
                            'cmid' => $ar->cmid,
                            'child' => $cid,
                            'courseid' => $courseid
                        ]))->out(false);
                    }
                    
                    $competency_activities[$cid][$courseid][] = [
                        'cmid' => (int)$ar->cmid,
                        'name' => $activityname ?: 'Activity',
                        'module' => $ar->modulename,
                        'instance' => (int)$ar->instance,
                        'completed' => $is_completed,
                        'completionstate' => $completionstatus,
                        'completiontime' => $completiontime,
                        'icon' => $moduleicon,
                        'url' => $activity_url
                    ];
                }
                    } else if ($hasactivity) {
                        $activity_records = $DB->get_records_sql(
                            "SELECT ca.competencyid,
                                    ca.cmid,
                                    cm.id AS cmid,
                                    cm.course AS courseid,
                                    cm.module,
                                    cm.instance,
                                    m.name AS modulename,
                                    cm.visible,
                                    cm.section,
                                    cm.idnumber
                               FROM {competency_activity} ca
                               JOIN {course_modules} cm ON cm.id = ca.cmid
                               JOIN {modules} m ON m.id = cm.module
                              WHERE ca.competencyid $compsql
                                AND cm.course $coursesql3
                                AND cm.visible = 1",
                            array_merge($compparams, $courseparams3)
                        );
                        
                        foreach ($activity_records as $ar) {
                            $cid = (int)$ar->competencyid;
                            $courseid = (int)$ar->courseid;
                            if (!isset($competency_activities[$cid])) {
                                $competency_activities[$cid] = [];
                            }
                            if (!isset($competency_activities[$cid][$courseid])) {
                                $competency_activities[$cid][$courseid] = [];
                            }
                            
                            // Get activity name and completion status
                            $activityname = '';
                            $completionstatus = 0;
                            $completiontime = null;
                            
                            try {
                                $modinfo = get_fast_modinfo($courseid, $selected_child_id);
                                if (isset($modinfo->cms[$ar->cmid])) {
                                    $cm = $modinfo->cms[$ar->cmid];
                                    $activityname = $cm->name;
                                    
                                    // Check completion status using robust completion_info class (same as competencies.php)
                                    $course_obj = get_course($courseid);
                                    $completion = new completion_info($course_obj);
                                    $is_completed = false;
                                    $completiontime = null;
                                    
                                    if ($completion->is_enabled($cm)) {
                                        $completion_data = $completion->get_data($cm, false, $selected_child_id);
                                        if ($completion_data && ($completion_data->completionstate == COMPLETION_COMPLETE || 
                                            $completion_data->completionstate == COMPLETION_COMPLETE_PASS)) {
                                            $is_completed = true;
                                            $completionstatus = (int)$completion_data->completionstate;
                                            $completiontime = $completion_data->timemodified;
                                        }
                                    }
                                } else {
                                    $activityname = $DB->get_field($ar->modulename, 'name', ['id' => $ar->instance]);
                                    $is_completed = false;
                                    $completionstatus = 0;
                                }
                            } catch (Exception $e) {
                                $activityname = $DB->get_field($ar->modulename, 'name', ['id' => $ar->instance]);
                                $is_completed = false;
                                $completionstatus = 0;
                            }
                            
                            // Get module icon
                            $moduleicons = [
                                'quiz' => 'fa-question-circle',
                                'assign' => 'fa-file-alt',
                                'forum' => 'fa-comments',
                                'lesson' => 'fa-book-reader',
                                'workshop' => 'fa-users',
                                'choice' => 'fa-check-square',
                                'feedback' => 'fa-clipboard-check',
                                'scorm' => 'fa-graduation-cap',
                                'page' => 'fa-file',
                                'url' => 'fa-link',
                                'resource' => 'fa-file-alt'
                            ];
                            $moduleicon = $moduleicons[$ar->modulename] ?? 'fa-tasks';
                            
                            // Get activity URL - Use our custom parent theme page
                            $activity_url = '';
                            if (!empty($ar->cmid) && !empty($courseid) && !empty($cid) && $cid !== 'all' && $cid != 0) {
                                $activity_url = (new moodle_url('/theme/remui_kids/parent/parent_activity_preview.php', [
                                    'cmid' => $ar->cmid,
                                    'child' => $cid,
                                    'courseid' => $courseid
                                ]))->out(false);
                            }
                            
                            $competency_activities[$cid][$courseid][] = [
                                'cmid' => (int)$ar->cmid,
                                'name' => $activityname ?: 'Activity',
                                'module' => $ar->modulename,
                                'instance' => (int)$ar->instance,
                                'completed' => $is_completed,
                                'completionstate' => $completionstatus,
                                'completiontime' => $completiontime,
                                'icon' => $moduleicon,
                                'url' => $activity_url
                            ];
                        }
                    }
                }
            }
        }
        
        // ✅ STEP 6: PROCESS COURSE COMPETENCIES
        if (!empty($coursecompetencies)) {
            foreach ($coursecompetencies as $record) {
                $courseid = (int)$record->courseid;
                $competencyid = (int)$record->competencyid;
                $frameworkid = (int)$record->frameworkid;
                
                // Initialize course if not exists
                if (!isset($courses_with_competencies[$courseid])) {
                    if (isset($courses[$courseid])) {
                        $courses_with_competencies[$courseid] = [
                            'course' => $courses[$courseid],
                            'competencies' => [],
                            'stats' => [
                                'total' => 0,
                                'competent' => 0,
                                'inprogress' => 0,
                                'notcompetent' => 0
                            ]
                        ];
                    } else {
                        continue; // Skip if course doesn't exist
                    }
                }
                
                // Skip if competency already processed for this course
                if (isset($courses_with_competencies[$courseid]['competencies'][$competencyid])) {
                    continue;
                }
                
                // Store framework info
                if (!isset($frameworknames[$frameworkid])) {
                    $frameworknames[$frameworkid] = format_string($record->frameworkname);
                }
                
                // Load scale if not already loaded
                if (!isset($frameworkscales[$frameworkid]) && !empty($record->scaleid)) {
                    try {
                        $framework = new \core_competency\competency_framework($frameworkid);
                        $scale = $framework->get_scale();
                        if ($scale && !empty($scale->scale_items)) {
                            $frameworkscales[$frameworkid] = $scale->scale_items;
                        } else {
                            $scale_record = $DB->get_record('scale', ['id' => $record->scaleid]);
                            if ($scale_record) {
                                $frameworkscales[$frameworkid] = explode(',', $scale_record->scale);
                            }
                        }
                    } catch (Exception $e) {
                        try {
                            $scale_record = $DB->get_record('scale', ['id' => $record->scaleid]);
                            if ($scale_record) {
                                $frameworkscales[$frameworkid] = explode(',', $scale_record->scale);
                            }
                        } catch (Exception $e2) {
                            debugging('Error loading scale: ' . $e2->getMessage());
                        }
                    }
                }
                
                // ✅ GATHER ALL PROGRESS RECORDS (course + global)
                $allprogress = [];
                
                // Add course-level progress
                if (isset($usercourseprogress[$competencyid][$courseid])) {
                    $allprogress = array_merge($allprogress, $usercourseprogress[$competencyid][$courseid]);
                }
                
                // Add global progress
                if (isset($userglobalprogress[$competencyid])) {
                    $allprogress = array_merge($allprogress, $userglobalprogress[$competencyid]);
                }
                
                // Get activities for this competency in this course
                $activities = [];
                if (isset($competency_activities[$competencyid][$courseid])) {
                    $activities = $competency_activities[$competencyid][$courseid];
                }
                
                // Determine status
                $evaluation = $determine_status($allprogress);
                $status = $evaluation['status'];
                $progress = round($evaluation['progress']);
                
                // Get rating label from scale
                $rating_label = '';
                $rating_value = '';
                
                if (!empty($allprogress) && isset($frameworkscales[$frameworkid])) {
                    $latestRecord = null;
                    $latestTime = 0;
                    foreach ($allprogress as $pr) {
                        $time = $pr->timemodified ?? $pr->timecreated ?? 0;
                        if ($time > $latestTime) {
                            $latestTime = $time;
                            $latestRecord = $pr;
                        }
                    }
                    
                    if ($latestRecord && $latestRecord->grade !== null && $latestRecord->grade !== '') {
                        $index = (int)$latestRecord->grade - 1;
                        $scaleitems = $frameworkscales[$frameworkid];
                        if (isset($scaleitems[$index])) {
                            $rating_label = trim($scaleitems[$index]);
                            $rating_value = $latestRecord->grade;
                        }
                    } else if ($latestRecord && !empty($latestRecord->proficiency)) {
                        $rating_label = 'Mastered';
                    }
                }
                
                // Get rating style
                $rating_style = $resolveRatingStyle($rating_label);
                
                // Get latest timestamps
                $timecreated = 0;
                $timemodified = 0;
                foreach ($allprogress as $pr) {
                    if (($pr->timecreated ?? 0) > $timecreated) {
                        $timecreated = $pr->timecreated ?? 0;
                    }
                    if (($pr->timemodified ?? 0) > $timemodified) {
                        $timemodified = $pr->timemodified ?? 0;
                    }
                }
                
                // Count statistics
                $courses_with_competencies[$courseid]['stats']['total']++;
                $total_stats['total_competencies']++;
                
                if ($status === 'competent') {
                    $courses_with_competencies[$courseid]['stats']['competent']++;
                    $total_stats['competent']++;
                } else if ($status === 'inprogress') {
                    $courses_with_competencies[$courseid]['stats']['inprogress']++;
                    $total_stats['inprogress']++;
                } else {
                    $courses_with_competencies[$courseid]['stats']['notcompetent']++;
                    $total_stats['notcompetent']++;
                }
                
                // Store competency
                $courses_with_competencies[$courseid]['competencies'][$competencyid] = [
                    'id' => $competencyid,
                    'name' => format_string($record->shortname),
                    'description' => !empty($record->description) ? format_text($record->description, $record->descriptionformat) : '',
                    'framework' => $frameworknames[$frameworkid],
                    'framework_id' => $frameworkid,
                    'status' => $status,
                    'progress' => $progress,
                    'proficiency' => $evaluation['proficiency'],
                    'rating_label' => $rating_label,
                    'rating_value' => $rating_value,
                    'rating_color' => $rating_style['color'],
                    'rating_class' => $rating_style['chip'],
                    'timecreated' => $timecreated,
                    'timemodified' => $timemodified,
                    'activities' => $activities,
                    'activity_count' => count($activities)
                ];
            }
        }
        
        
        // ✅ STEP 7: ADD GLOBAL COMPETENCIES NOT IN ANY COURSE (as separate section)
        if (!empty($global_competency_details)) {
            $global_course_id = 0; // Use 0 for global
            $courses_with_competencies[$global_course_id] = [
                'course' => (object)[
                    'id' => 0,
                    'fullname' => 'Global Competencies',
                    'shortname' => 'global',
                    'summary' => 'Competencies not linked to specific courses'
                ],
                'competencies' => [],
                'stats' => [
                    'total' => 0,
                    'competent' => 0,
                    'inprogress' => 0,
                    'notcompetent' => 0
                ]
            ];
            
            foreach ($global_competency_details as $mc) {
                $competencyid = (int)$mc->competencyid;
                $frameworkid = (int)$mc->frameworkid;
                
                // Store framework info
                if (!isset($frameworknames[$frameworkid])) {
                    $frameworknames[$frameworkid] = format_string($mc->frameworkname);
                }
                
                // Load scale if not already loaded
                if (!isset($frameworkscales[$frameworkid]) && !empty($mc->scaleid)) {
                    try {
                        $framework = new \core_competency\competency_framework($frameworkid);
                        $scale = $framework->get_scale();
                        if ($scale && !empty($scale->scale_items)) {
                            $frameworkscales[$frameworkid] = $scale->scale_items;
                        } else {
                            $scale_record = $DB->get_record('scale', ['id' => $mc->scaleid]);
                            if ($scale_record) {
                                $frameworkscales[$frameworkid] = explode(',', $scale_record->scale);
                            }
                        }
                    } catch (Exception $e) {
                        try {
                            $scale_record = $DB->get_record('scale', ['id' => $mc->scaleid]);
                            if ($scale_record) {
                                $frameworkscales[$frameworkid] = explode(',', $scale_record->scale);
                            }
                        } catch (Exception $e2) {
                            debugging('Error loading scale: ' . $e2->getMessage());
                        }
                    }
                }
                
                // Get progress for this global competency
                $allprogress = $userglobalprogress[$competencyid] ?? [];
                
                $evaluation = $determine_status($allprogress);
                $status = $evaluation['status'];
                $progress = round($evaluation['progress']);
                
                $rating_label = '';
                $rating_value = '';
                
                if (!empty($allprogress) && isset($frameworkscales[$frameworkid])) {
                    $latestRecord = null;
                    $latestTime = 0;
                    foreach ($allprogress as $pr) {
                        $time = $pr->timemodified ?? $pr->timecreated ?? 0;
                        if ($time > $latestTime) {
                            $latestTime = $time;
                            $latestRecord = $pr;
                        }
                    }
                    
                    if ($latestRecord && $latestRecord->grade !== null && $latestRecord->grade !== '') {
                        $index = (int)$latestRecord->grade - 1;
                        $scaleitems = $frameworkscales[$frameworkid];
                        if (isset($scaleitems[$index])) {
                            $rating_label = trim($scaleitems[$index]);
                            $rating_value = $latestRecord->grade;
                        }
                    } else if ($latestRecord && !empty($latestRecord->proficiency)) {
                        $rating_label = 'Mastered';
                    }
                }
                
                $rating_style = $resolveRatingStyle($rating_label);
                
                // Get latest timestamps
                $timecreated = 0;
                $timemodified = 0;
                foreach ($allprogress as $pr) {
                    if (($pr->timecreated ?? 0) > $timecreated) {
                        $timecreated = $pr->timecreated ?? 0;
                    }
                    if (($pr->timemodified ?? 0) > $timemodified) {
                        $timemodified = $pr->timemodified ?? 0;
                    }
                }
                
                $courses_with_competencies[$global_course_id]['stats']['total']++;
                $total_stats['total_competencies']++;
                
                if ($status === 'competent') {
                    $courses_with_competencies[$global_course_id]['stats']['competent']++;
                    $total_stats['competent']++;
                } else if ($status === 'inprogress') {
                    $courses_with_competencies[$global_course_id]['stats']['inprogress']++;
                    $total_stats['inprogress']++;
                } else {
                    $courses_with_competencies[$global_course_id]['stats']['notcompetent']++;
                    $total_stats['notcompetent']++;
                }
                
                $courses_with_competencies[$global_course_id]['competencies'][$competencyid] = [
                    'id' => $competencyid,
                    'name' => format_string($mc->shortname),
                    'description' => !empty($mc->description) ? format_text($mc->description, $mc->descriptionformat) : '',
                    'framework' => $frameworknames[$frameworkid],
                    'framework_id' => $frameworkid,
                    'status' => $status,
                    'progress' => $progress,
                    'proficiency' => $evaluation['proficiency'],
                    'rating_label' => $rating_label,
                    'rating_value' => $rating_value,
                    'rating_color' => $rating_style['color'],
                    'rating_class' => $rating_style['chip'],
                    'timecreated' => $timecreated,
                    'timemodified' => $timemodified,
                    'activities' => [], // Global competencies don't have course activities
                    'activity_count' => 0
                ];
            }
        }
        
    } catch (Exception $e) {
        debugging('Error fetching competencies: ' . $e->getMessage());
    }
}

echo $OUTPUT->header();

// Get child's badges if a child is selected
$child_badges = [];
$child_certificates = [];

if ($selected_child_id && $selected_child_id !== 'all' && isset($children_records[$selected_child_id])) {
    // Fetch child's badges
    try {
        // Check if badges are enabled
        if (!empty($CFG->enablebadges)) {
            // Get all badges for the child (both site and course badges)
            $badges = badges_get_user_badges($selected_child_id, 0, 0, 0, '', false);
            
            foreach ($badges as $badge) {
                try {
                    // Get badge image URL - use correct context based on badge type
                    $badge_image_url = '';
                    $badge_url = '';
                    
                    // Determine context based on badge type (same as Moodle's renderer)
                    if (!empty($badge->courseid)) {
                        // Course badge
                        $context = context_course::instance($badge->courseid);
                    } else {
                        // Site badge
                        $context = context_system::instance();
                    }
                    
                    // Get badge image URL using pluginfile (f3 = medium size, same as Moodle renderer)
                    $badge_image_url = moodle_url::make_pluginfile_url(
                        $context->id, 
                        'badges', 
                        'badgeimage', 
                        $badge->id, 
                        '/', 
                        'f3',  // f3 = medium size badge image
                        false
                    )->out(false);
                    
                    // Badge view URL - use unique hash if available
                    if (!empty($badge->uniquehash)) {
                        $badge_url = new moodle_url('/badges/badge.php', [
                            'hash' => $badge->uniquehash
                        ]);
                    } else {
                        // Fallback: use badge ID
                        $badge_url = new moodle_url('/badges/badge.php', [
                            'id' => $badge->id
                        ]);
                    }
                    
                    // Get course name
                    $coursename = '';
                    if (!empty($badge->courseid)) {
                        $course = $DB->get_record('course', ['id' => $badge->courseid], 'fullname, shortname');
                        $coursename = $course ? format_string($course->fullname) : '';
                    } else {
                        $coursename = 'Site Badge';
                    }
                    
                    // Get badge description
                    $description = '';
                    if (!empty($badge->description)) {
                        $description = format_text($badge->description, $badge->descriptionformat ?? FORMAT_HTML);
                    }
                    
                    // Get badge expiry date if available
                    $dateexpire = '';
                    if (!empty($badge->dateexpire)) {
                        $dateexpire = userdate($badge->dateexpire, get_string('strftimedatefullshort', 'langconfig'));
                    }
                    
                    $child_badges[] = [
                        'id' => $badge->id,
                        'name' => format_string($badge->name),
                        'description' => $description,
                        'dateissued' => userdate($badge->dateissued, get_string('strftimedatefullshort', 'langconfig')),
                        'dateexpire' => $dateexpire,
                        'imageurl' => $badge_image_url,
                        'badgeurl' => $badge_url->out(),
                        'uniquehash' => $badge->uniquehash ?? '',
                        'courseid' => $badge->courseid ?? 0,
                        'coursename' => $coursename,
                        'type' => !empty($badge->courseid) ? 'course' : 'site',
                        'visible' => !empty($badge->visible)
                    ];
                } catch (Exception $e) {
                    debugging('Error processing badge ' . $badge->id . ': ' . $e->getMessage());
                    continue;
                }
            }
            
            // Sort badges by issue date (newest first)
            usort($child_badges, function($a, $b) {
                $time_a = strtotime($a['dateissued']);
                $time_b = strtotime($b['dateissued']);
                return $time_b <=> $time_a;
            });
        }
    } catch (Exception $e) {
        debugging('Error fetching badges: ' . $e->getMessage());
    }
    
    // Fetch child's certificates from IOMAD Certificate module
    try {
        // Method 1: IOMAD Certificate module (iomadcertificate_issues)
        if ($DB->get_manager()->table_exists('iomadcertificate_issues')) {
            $sql_iomad = "SELECT ci.id, ci.code, ci.timecreated, ci.certificateid,
                                 c.name as cert_name, c.course as courseid,
                                 co.fullname as coursename, co.shortname as courseshortname,
                                 cm.id as cmid
                          FROM {iomadcertificate_issues} ci
                          JOIN {iomadcertificate} c ON ci.certificateid = c.id
                          JOIN {course} co ON c.course = co.id
                          LEFT JOIN {course_modules} cm ON cm.instance = c.id AND cm.module = (SELECT id FROM {modules} WHERE name = 'iomadcertificate')
                          WHERE ci.userid = ?
                          ORDER BY ci.timecreated DESC";
            
            $iomad_certificates = $DB->get_records_sql($sql_iomad, [$selected_child_id]);
            
            foreach ($iomad_certificates as $cert) {
                // Generate certificate view URL
                $cert_url = '';
                if (!empty($cert->cmid)) {
                    // Use course module ID to view certificate
                    $cert_url = new moodle_url('/mod/iomadcertificate/view.php', ['id' => $cert->cmid]);
                    // For parent viewing child's certificate, add userid parameter if needed
                    if ($selected_child_id != $USER->id) {
                        $cert_url->param('userid', $selected_child_id);
                    }
                } else {
                    // Fallback: try to find course module
                    $cm = $DB->get_record_sql(
                        "SELECT cm.id FROM {course_modules} cm 
                         JOIN {modules} m ON m.id = cm.module 
                         WHERE m.name = 'iomadcertificate' AND cm.instance = ? AND cm.course = ? AND cm.visible = 1",
                        [$cert->certificateid, $cert->courseid]
                    );
                    if ($cm) {
                        $cert_url = new moodle_url('/mod/iomadcertificate/view.php', ['id' => $cm->id]);
                        if ($selected_child_id != $USER->id) {
                            $cert_url->param('userid', $selected_child_id);
                        }
                    }
                }
                
                $child_certificates[] = [
                    'id' => $cert->id,
                    'name' => !empty($cert->cert_name) ? format_string($cert->cert_name) : ($cert->coursename . ' Certificate'),
                    'code' => $cert->code ?? '',
                    'issuedate' => userdate($cert->timecreated, get_string('strftimedatefullshort', 'langconfig')),
                    'courseid' => $cert->courseid,
                    'coursename' => $cert->coursename,
                    'url' => $cert_url ? $cert_url->out() : '',
                    'type' => 'iomadcertificate'
                ];
            }
        }
        
        // Method 2: IOMAD Track Certificates (local_iomad_track_certs)
        if ($DB->get_manager()->table_exists('local_iomad_track_certs')) {
            $sql_track = "SELECT tc.id, tc.filename, tc.trackid, tc.timecreated,
                                 t.courseid, t.userid,
                                 co.fullname as coursename, co.shortname as courseshortname
                          FROM {local_iomad_track_certs} tc
                          JOIN {local_iomad_track} t ON tc.trackid = t.id
                          LEFT JOIN {course} co ON t.courseid = co.id
                          WHERE t.userid = ?
                          ORDER BY tc.timecreated DESC";
            
            $track_certificates = $DB->get_records_sql($sql_track, [$selected_child_id]);
            
            foreach ($track_certificates as $cert) {
                // Generate certificate download URL
                $usercontext = context_user::instance($selected_child_id);
                $cert_url = moodle_url::make_file_url(
                    '/pluginfile.php',
                    '/' . $usercontext->id . '/local_iomad_track/issue/' . $cert->trackid . '/' . $cert->filename,
                    true
                );
                
                $child_certificates[] = [
                    'id' => $cert->id,
                    'name' => ($cert->coursename ?? 'Course') . ' Certificate',
                    'code' => '',
                    'issuedate' => userdate($cert->timecreated, get_string('strftimedatefullshort', 'langconfig')),
                    'courseid' => $cert->courseid ?? 0,
                    'coursename' => $cert->coursename ?? 'Unknown Course',
                    'url' => $cert_url->out(),
                    'filename' => $cert->filename,
                    'type' => 'iomad_track'
                ];
            }
        }
        
        // Method 3: Standard Certificate module (if exists)
        if ($DB->get_manager()->table_exists('certificate_issues')) {
            $sql_standard = "SELECT ci.id, ci.code, ci.timecreated, ci.certificateid,
                                    c.name as cert_name, c.course as courseid,
                                    co.fullname as coursename,
                                    cm.id as cmid
                             FROM {certificate_issues} ci
                             JOIN {certificate} c ON ci.certificateid = c.id
                             JOIN {course} co ON c.course = co.id
                             LEFT JOIN {course_modules} cm ON cm.instance = c.id AND cm.module = (SELECT id FROM {modules} WHERE name = 'certificate')
                             WHERE ci.userid = ?
                             ORDER BY ci.timecreated DESC";
            
            $standard_certificates = $DB->get_records_sql($sql_standard, [$selected_child_id]);
            
            foreach ($standard_certificates as $cert) {
                $cert_url = '';
                if (!empty($cert->cmid)) {
                    $cert_url = new moodle_url('/mod/certificate/view.php', ['id' => $cert->cmid]);
                }
                
                $child_certificates[] = [
                    'id' => $cert->id,
                    'name' => !empty($cert->cert_name) ? format_string($cert->cert_name) : ($cert->coursename . ' Certificate'),
                    'code' => $cert->code ?? '',
                    'issuedate' => userdate($cert->timecreated, get_string('strftimedatefullshort', 'langconfig')),
                    'courseid' => $cert->courseid,
                    'coursename' => $cert->coursename,
                    'url' => $cert_url ? $cert_url->out() : '',
                    'type' => 'certificate'
                ];
            }
        }
        
        // Method 4: Custom Certificate module (mod_customcert)
        if ($DB->get_manager()->table_exists('customcert_issues')) {
            $sql_custom = "SELECT ci.id, ci.code, ci.timecreated, ci.customcertid,
                                  c.name as cert_name, c.course as courseid,
                                  co.fullname as coursename,
                                  cm.id as cmid
                           FROM {customcert_issues} ci
                           JOIN {customcert} c ON ci.customcertid = c.id
                           JOIN {course} co ON c.course = co.id
                           LEFT JOIN {course_modules} cm ON cm.instance = c.id AND cm.module = (SELECT id FROM {modules} WHERE name = 'customcert')
                           WHERE ci.userid = ?
                           ORDER BY ci.timecreated DESC";
            
            $custom_certificates = $DB->get_records_sql($sql_custom, [$selected_child_id]);
            
            foreach ($custom_certificates as $cert) {
                $cert_url = '';
                if (!empty($cert->cmid)) {
                    $cert_url = new moodle_url('/mod/customcert/view.php', ['id' => $cert->cmid]);
                }
                
                $child_certificates[] = [
                    'id' => $cert->id,
                    'name' => !empty($cert->cert_name) ? format_string($cert->cert_name) : ($cert->coursename . ' Certificate'),
                    'code' => $cert->code ?? '',
                    'issuedate' => userdate($cert->timecreated, get_string('strftimedatefullshort', 'langconfig')),
                    'courseid' => $cert->courseid,
                    'coursename' => $cert->coursename,
                    'url' => $cert_url ? $cert_url->out() : '',
                    'type' => 'customcert'
                ];
            }
        }
        
        // Sort all certificates by issue date (newest first)
        usort($child_certificates, function($a, $b) {
            $time_a = strtotime($a['issuedate']);
            $time_b = strtotime($b['issuedate']);
            return $time_b <=> $time_a;
        });
        
    } catch (Exception $e) {
        debugging('Error fetching certificates: ' . $e->getMessage());
    }
}

// Include Parent Sidebar
$currentparentpage = 'parent_competencies.php';
include_once(__DIR__ . '/../components/parent_sidebar.php');
?>

<style>
/* FLTECH Analytics Styles */
.fltech-analytics-container {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 1.5rem;
    padding: 1rem 0;
}

.fltech-metric-card {
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    padding: 1.5rem;
    border-left: 4px solid #3b82f6;
    transition: transform 0.2s, box-shadow 0.2s;
}

.fltech-metric-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}

.metric-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1rem;
}

.metric-header h4 {
    margin: 0;
    font-size: 1rem;
    color: #1f2937;
}

.metric-value {
    font-size: 2rem;
    font-weight: 700;
    color: #111827;
    margin: 0.5rem 0;
}

.metric-description {
    font-size: 0.875rem;
    color: #6b7280;
    margin-bottom: 1rem;
    line-height: 1.5;
}

.metric-progress {
    margin-top: 1rem;
}

.progress {
    background-color: #e5e7eb;
    border-radius: 4px;
    overflow: hidden;
    margin-bottom: 0.25rem;
}

.progress-bar {
    background-color: #3b82f6;
    transition: width 0.6s ease;
}

.progress-labels {
    display: flex;
    justify-content: space-between;
    font-size: 0.75rem;
    color: #6b7280;
}

.empty-state {
    text-align: center;
    padding: 2rem;
    color: #6c757d;
}

.empty-state i {
    font-size: 3rem;
    margin-bottom: 1rem;
    display: block;
}

.empty-state h3 {
    margin-bottom: 0.5rem;
    color: #343a40;
}

.empty-state p {
    margin-bottom: 0;
    color: #6c757d;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .fltech-analytics-container {
        grid-template-columns: 1fr;
    }
}

    /* General Styles */
    .competencies-main-content {
        padding: 20px;
        max-width: 1400px;
        margin: 0 auto;
    }

    /* Tab Styles */
    .nav-tabs {
        border-bottom: 2px solid #e9ecef;
        margin-bottom: 0;
    }

    .nav-tabs .nav-link {
        border: none;
        color: #6c757d;
        font-weight: 500;
        padding: 12px 20px;
        margin-right: 5px;
        border-radius: 4px 4px 0 0;
        transition: all 0.2s;
        display: flex;
        align-items: center;
    }

    .nav-tabs .nav-link:hover {
        border: none;
        color: #3b82f6;
        background-color: #f8f9fa;
    }

    .nav-tabs .nav-link.active {
        color: #3b82f6;
        background-color: #fff;
        border: none;
        border-bottom: 3px solid #3b82f6;
        font-weight: 600;
    }

    .tab-content {
        background: #fff;
        border-radius: 0 0 8px 8px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    }

    /* Badge and Certificate Cards */
    .card {
        border: none;
        border-radius: 8px;
        overflow: hidden;
        transition: transform 0.2s, box-shadow 0.2s;
        height: 100%;
    }

    .card:hover {
        transform: translateY(-3px);
        box-shadow: 0 5px 15px rgba(0,0,0,0.1);
    }

    /* Empty State Styling */
    .empty-state {
        text-align: center;
        padding: 40px 20px;
        color: #6c757d;
    }

    .empty-state i {
        margin-bottom: 15px;
        opacity: 0.7;
    }

    .empty-state h3 {
        color: #495057;
        margin-bottom: 10px;
    }

    .empty-state p {
        max-width: 500px;
        margin: 0 auto;
        line-height: 1.6;
    }

    /* Responsive Adjustments */
    @media (max-width: 768px) {
        .nav-tabs .nav-link {
            padding: 10px 15px;
            font-size: 14px;
        }
        
        .empty-state {
            padding: 30px 15px;
        }
    }

/* Main Content Styles */
.competencies-main-content {
    padding: 24px;
    max-width: 1600px;
    margin: 0 auto;
}

.page-header {
    margin-bottom: 32px;
}

.page-title {
    font-size: 28px;
    font-weight: 800;
    color: #1e293b;
    margin: 0 0 8px 0;
    display: flex;
    align-items: center;
    gap: 12px;
}

.page-subtitle {
    font-size: 14px;
    color: #64748b;
    margin: 0;
}

/* Stats Grid */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 32px;
}

.stat-card {
    background: #ffffff;
    border-radius: 12px;
    padding: 24px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
    border: 1px solid #e2e8f0;
    transition: all 0.3s ease;
}

.stat-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
}

.stat-card.purple {
    border-left: 4px solid #8b5cf6;
}

.stat-card.green {
    border-left: 4px solid #10b981;
}

.stat-card.orange {
    border-left: 4px solid #f59e0b;
}

.stat-card.blue {
    border-left: 4px solid #3b82f6;
}

/* Course Section */
.course-section {
    background: #ffffff;
    border-radius: 12px;
    padding: 28px;
    margin-bottom: 32px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
    border: 1px solid #e2e8f0;
}

.course-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 24px;
    padding-bottom: 20px;
    border-bottom: 2px solid #f1f5f9;
    flex-wrap: wrap;
    gap: 20px;
}

.course-title-section {
    flex: 1;
    min-width: 300px;
}

.course-name {
    font-size: 22px;
    font-weight: 800;
    color: #1e293b;
    margin: 0 0 8px 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.course-stats {
    display: flex;
    gap: 24px;
    flex-wrap: wrap;
}

.course-stat-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
}

.course-stat-label {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #64748b;
}

.course-stat-value {
    font-size: 24px;
    font-weight: 800;
    color: #1e293b;
}

/* Competencies Grid */
.competencies-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 20px;
}

/* Competency Card */
.competency-card {
    background: #ffffff;
    border-radius: 12px;
    padding: 20px;
    border: 2px solid #e2e8f0;
    transition: all 0.3s ease;
    box-shadow: 0 1px 4px rgba(0, 0, 0, 0.04);
}

.competency-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
    border-color: #cbd5e1;
}

.competency-card.competent {
    border-left: 4px solid #10b981;
}

.competency-card.inprogress {
    border-left: 4px solid #f59e0b;
}

.competency-card.notcompetent {
    border-left: 4px solid #ef4444;
}

.competency-framework {
    font-size: 11px;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    gap: 6px;
}

.competency-name {
    font-size: 18px;
    font-weight: 700;
    color: #1e293b;
    margin: 0 0 12px 0;
    line-height: 1.3;
}

.competency-description {
    font-size: 13px;
    color: #64748b;
    line-height: 1.6;
    margin-bottom: 16px;
    max-height: 60px;
    overflow: hidden;
    text-overflow: ellipsis;
}

.progress-bar-container {
    width: 100%;
    height: 8px;
    background: #e5e7eb;
    border-radius: 999px;
    overflow: hidden;
    margin-bottom: 8px;
}

.progress-bar-fill {
    height: 100%;
    border-radius: 999px;
    transition: width 0.6s ease;
}

.progress-bar-fill.competent {
    background: linear-gradient(90deg, #10b981, #059669);
}

.progress-bar-fill.inprogress {
    background: linear-gradient(90deg, #f59e0b, #d97706);
}

.progress-bar-fill.notcompetent {
    background: linear-gradient(90deg, #ef4444, #dc2626);
}

.progress-text {
    font-size: 11px;
    font-weight: 600;
    color: #64748b;
    margin-bottom: 12px;
}

.rating-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 700;
    margin-bottom: 12px;
}

.competency-footer {
    font-size: 11px;
    color: #94a3b8;
    margin-top: 12px;
    padding-top: 12px;
    border-top: 1px solid #f1f5f9;
    display: flex;
    align-items: center;
    gap: 6px;
}

/* Child Selector */
.child-selector {
    background: #ffffff;
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 24px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
    border: 1px solid #e2e8f0;
}

/* Tabs Container */
.tabs-container {
    background: #ffffff;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
    border: 1px solid #e2e8f0;
    overflow: visible;
    width: 100%;
    margin-bottom: 0;
}

.nav-tabs {
    display: flex;
    border-bottom: 2px solid #e2e8f0;
    background: #f8fafc;
    padding: 8px 8px 0 8px;
    margin: 0;
    list-style: none;
    flex-wrap: wrap;
}

.nav-tabs .nav-item {
    margin: 0;
}

.nav-tabs .nav-link {
    border: none;
    color: #64748b;
    font-weight: 600;
    padding: 12px 20px;
    margin-right: 4px;
    border-radius: 8px 8px 0 0;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    background: transparent;
    cursor: pointer;
}

.nav-tabs .nav-link:hover {
    color: #3b82f6;
    background: rgba(59, 130, 246, 0.1);
}

.nav-tabs .nav-link.active {
    color: #3b82f6;
    background: #ffffff;
    border-bottom: 3px solid #3b82f6;
    font-weight: 700;
}

.tab-content {
    padding: 0;
    background: #ffffff;
    min-height: 500px;
    width: 100%;
}

.tab-pane {
    display: none;
    width: 100%;
    padding: 32px;
    box-sizing: border-box;
}

.tab-pane.active {
    display: block;
    width: 100%;
}

/* Full screen tab content */
.tab-pane#badges,
.tab-pane#certificates,
.tab-pane#fltech {
    padding: 32px;
    min-height: calc(100vh - 300px);
    width: 100%;
    box-sizing: border-box;
}

/* Ensure badges and certificates grids take full width */
.tab-pane#badges > div:first-child,
.tab-pane#certificates > div:first-child {
    width: 100%;
    max-width: 100%;
}

/* Hide competency-specific filters when badges/certificates tabs are active */
.competencies-main-content.hide-competency-filters .child-selector > div > div:nth-child(2),
.competencies-main-content.hide-competency-filters .child-selector > div > div:nth-child(3) {
    display: none !important;
}

/* Responsive */
@media (max-width: 768px) {
    .competencies-main-content {
        padding: 16px;
    }
    
    .stats-grid {
        grid-template-columns: 1fr;
    }
    
    .competencies-grid {
        grid-template-columns: 1fr;
    }
    
    .course-header {
        flex-direction: column;
    }
    
    .course-stats {
        width: 100%;
        justify-content: space-around;
    }
}
</style>

<div class="competencies-main-content">
    <!-- Page Header -->
    <div class="page-header">
        <h1 class="page-title">
            <i class="fas fa-trophy" style="color: #3b82f6;"></i>
            Child Progress & Achievements
        </h1>
        <p class="page-subtitle">View your child's learning progress organized by course</p>
    </div>

    <!-- Child Selector and Filters -->
    <?php if (!empty($children_records)): ?>
    <div class="child-selector" style="margin-bottom: 24px;">
        <div style="display: flex; gap: 20px; flex-wrap: wrap; align-items: flex-end;">
            <div style="flex: 1; min-width: 250px;">
                <label style="display: block; font-size: 14px; font-weight: 600; color: #475569; margin-bottom: 8px;">
                    <i class="fas fa-user-graduate" style="color: #3b82f6; margin-right: 6px;"></i>
                    Select Child
                </label>
                <select id="child-selector" style="width: 100%; padding: 12px; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 14px; font-weight: 600; background: #fff;">
                    <option value="all" <?php echo ($selected_child_id === 'all' || !$selected_child_id) ? 'selected' : ''; ?>>Select a child...</option>
                    <?php foreach ($children_records as $child): ?>
                        <option value="<?php echo $child->id; ?>" <?php echo ($selected_child_id == $child->id) ? 'selected' : ''; ?>>
                            <?php echo fullname($child); ?><?php if (!empty($child->cohortname)): ?> (<?php echo htmlspecialchars($child->cohortname); ?>)<?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <?php if ($selected_child_id && $selected_child_id !== 'all' && $total_stats['total_competencies'] > 0): ?>
            <div style="flex: 1; min-width: 300px;">
                <label style="display: block; font-size: 14px; font-weight: 600; color: #475569; margin-bottom: 8px;">
                    <i class="fas fa-search" style="color: #3b82f6; margin-right: 6px;"></i>
                    Search Competencies
                </label>
                <input type="text" id="competency-search" placeholder="Search by name, description, or framework..." style="width: 100%; padding: 12px; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 14px; background: #fff;">
            </div>
            
            <div style="flex: 0 0 auto;">
                <label style="display: block; font-size: 14px; font-weight: 600; color: #475569; margin-bottom: 8px;">
                    <i class="fas fa-filter" style="color: #3b82f6; margin-right: 6px;"></i>
                    Filter by Status
                </label>
                <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                    <button class="filter-status-btn" data-status="all" onclick="filterByStatus('all')" style="padding: 8px 16px; border: none; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer; background: #3b82f6; color: #fff;">
                        All
                    </button>
                    <button class="filter-status-btn" data-status="competent" onclick="filterByStatus('competent')" style="padding: 8px 16px; border: none; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer; background: #f1f5f9; color: #475569;">
                        <i class="fas fa-check-circle" style="color: #10b981; margin-right: 4px;"></i>Competent
                    </button>
                    <button class="filter-status-btn" data-status="inprogress" onclick="filterByStatus('inprogress')" style="padding: 8px 16px; border: none; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer; background: #f1f5f9; color: #475569;">
                        <i class="fas fa-clock" style="color: #f59e0b; margin-right: 4px;"></i>In Progress
                    </button>
                    <button class="filter-status-btn" data-status="notcompetent" onclick="filterByStatus('notcompetent')" style="padding: 8px 16px; border: none; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer; background: #f1f5f9; color: #475569;">
                        <i class="fas fa-exclamation-circle" style="color: #ef4444; margin-right: 4px;"></i>Not Started
                    </button>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($selected_child_id && $selected_child_id !== 'all' && $child_data): ?>
    
    <!-- Tabs Navigation -->
    <div class="tabs-container mb-4">
        <ul class="nav nav-tabs" id="achievementTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="competencies-tab" data-bs-toggle="tab" data-bs-target="#competencies" type="button" role="tab" aria-controls="competencies" aria-selected="true">
                    <i class="fas fa-check-circle me-2"></i>Competencies
                    <?php if ($total_stats['total_competencies'] > 0): ?>
                    <span class="badge bg-primary ms-2"><?php echo $total_stats['total_competencies']; ?></span>
                    <?php endif; ?>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="badges-tab" data-bs-toggle="tab" data-bs-target="#badges" type="button" role="tab" aria-controls="badges" aria-selected="false">
                    <i class="fas fa-award me-2"></i>Badges
                    <?php if (!empty($child_badges)): ?>
                    <span class="badge bg-success ms-2"><?php echo count($child_badges); ?></span>
                    <?php endif; ?>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="certificates-tab" data-bs-toggle="tab" data-bs-target="#certificates" type="button" role="tab" aria-controls="certificates" aria-selected="false">
                    <i class="fas fa-certificate me-2"></i>Certificates
                    <?php if (!empty($child_certificates)): ?>
                    <span class="badge bg-info ms-2"><?php echo count($child_certificates); ?></span>
                    <?php endif; ?>
                </button>
            </li>
            <?php if ($fltech_available): ?>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="fltech-tab" data-bs-toggle="tab" data-bs-target="#fltech" type="button" role="tab" aria-controls="fltech" aria-selected="false">
                    <i class="fas fa-chart-line me-2"></i>FLTECH Analytics
                    <span class="badge bg-info ms-2" id="fltech-badge-count" style="display: none;"></span>
                </button>
            </li>
            <?php endif; ?>
        </ul>
        
        <div class="tab-content" style="background: #fff; width: 100%; min-height: 500px;">
            <!-- Competencies Tab -->
            <div class="tab-pane fade show active" id="competencies" role="tabpanel" aria-labelledby="competencies-tab" style="width: 100%; padding: 32px;">
        
        <!-- Overall Statistics -->
        <?php if ($total_stats['total_competencies'] > 0): ?>
        <div class="stats-grid">
            <div class="stat-card purple">
                <div style="font-size: 14px; opacity: 0.9; margin-bottom: 8px; font-weight: 600;">Total Competencies</div>
                <div style="font-size: 42px; font-weight: 800; line-height: 1;"><?php echo $total_stats['total_competencies']; ?></div>
                <div style="font-size: 12px; opacity: 0.8; margin-top: 8px;">Across all courses</div>
            </div>
            
            <div class="stat-card green">
                <div style="font-size: 14px; opacity: 0.9; margin-bottom: 8px; font-weight: 600;">Competent</div>
                <div style="font-size: 42px; font-weight: 800; line-height: 1;"><?php echo $total_stats['competent']; ?></div>
                <div style="font-size: 12px; opacity: 0.8; margin-top: 8px;">
                    <?php echo $total_stats['total_competencies'] > 0 ? round(($total_stats['competent'] / $total_stats['total_competencies']) * 100) : 0; ?>% mastered
                </div>
            </div>
            
            <div class="stat-card orange">
                <div style="font-size: 14px; opacity: 0.9; margin-bottom: 8px; font-weight: 600;">In Progress</div>
                <div style="font-size: 42px; font-weight: 800; line-height: 1;"><?php echo $total_stats['inprogress']; ?></div>
                <div style="font-size: 12px; opacity: 0.8; margin-top: 8px;">Currently developing</div>
            </div>
            
            <div class="stat-card blue">
                <div style="font-size: 14px; opacity: 0.9; margin-bottom: 8px; font-weight: 600;">Courses</div>
                <div style="font-size: 42px; font-weight: 800; line-height: 1;"><?php echo count($courses_with_competencies); ?></div>
                <div style="font-size: 12px; opacity: 0.8; margin-top: 8px;">With competencies</div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Course-Wise Competencies -->
        <?php if (!empty($courses_with_competencies)): ?>
            <?php foreach ($courses_with_competencies as $courseid => $course_data): 
                $course = $course_data['course'];
                $competencies = $course_data['competencies'];
                $stats = $course_data['stats'];
            ?>
            <div class="course-section">
                <div class="course-header">
                    <div class="course-title-section">
                        <h2 class="course-name">
                            <i class="fas fa-book" style="color: #3b82f6;"></i>
                            <?php echo format_string($course->fullname); ?>
                        </h2>
                        <?php if (!empty($course->summary)): ?>
                        <p style="font-size: 14px; color: #64748b; margin: 0;"><?php echo strip_tags(format_text($course->summary, FORMAT_HTML)); ?></p>
                        <?php endif; ?>
                    </div>
                    
                    <div class="course-stats">
                        <div class="course-stat-item">
                            <span class="course-stat-label">Total</span>
                            <span class="course-stat-value"><?php echo $stats['total']; ?></span>
                        </div>
                        <div class="course-stat-item">
                            <span class="course-stat-label" style="color: #10b981;">Competent</span>
                            <span class="course-stat-value" style="color: #10b981;"><?php echo $stats['competent']; ?></span>
                        </div>
                        <div class="course-stat-item">
                            <span class="course-stat-label" style="color: #f59e0b;">In Progress</span>
                            <span class="course-stat-value" style="color: #f59e0b;"><?php echo $stats['inprogress']; ?></span>
                        </div>
                        <div class="course-stat-item">
                            <span class="course-stat-label" style="color: #ef4444;">Not Started</span>
                            <span class="course-stat-value" style="color: #ef4444;"><?php echo $stats['notcompetent']; ?></span>
                        </div>
                    </div>
                </div>
                
                <div class="competencies-grid">
                    <?php foreach ($competencies as $comp): ?>
                    <div class="competency-card <?php echo $comp['status']; ?>">
                        <div class="competency-framework">
                            <i class="fas fa-layer-group"></i>
                            <?php echo htmlspecialchars($comp['framework']); ?>
                        </div>
                        
                        <h3 class="competency-name"><?php echo htmlspecialchars($comp['name']); ?></h3>
                        
                        <?php if (!empty($comp['description'])): ?>
                        <div class="competency-description">
                            <?php echo strip_tags($comp['description']); ?>
                        </div>
                        <?php endif; ?>
                        
                        <div class="progress-bar-container">
                            <div class="progress-bar-fill <?php echo $comp['status']; ?>" style="width: <?php echo $comp['progress']; ?>%;"></div>
                        </div>
                        <div class="progress-text"><?php echo round($comp['progress']); ?>% Complete</div>
                        
                        <?php if (!empty($comp['rating_label'])): ?>
                        <div>
                            <span class="rating-badge" style="background-color: <?php echo $comp['rating_color']; ?>15; color: <?php echo $comp['rating_color']; ?>;">
                                <i class="fas fa-star"></i> <?php echo htmlspecialchars($comp['rating_label']); ?>
                            </span>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($comp['activities']) && count($comp['activities']) > 0): 
                            $completed_activities = array_filter($comp['activities'], function($a) { return !empty($a['completed']); });
                            $completed_count = count($completed_activities);
                            $total_activities = count($comp['activities']);
                            $activity_progress = $total_activities > 0 ? round(($completed_count / $total_activities) * 100) : 0;
                        ?>
                        <div class="competency-activities" style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #e2e8f0;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                                <div style="font-size: 11px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;">
                                    <i class="fas fa-tasks" style="margin-right: 4px;"></i>
                                    Activities (<?php echo $completed_count; ?>/<?php echo $total_activities; ?>)
                                </div>
                                <button class="toggle-activities-btn" onclick="toggleActivities(this, 'comp-<?php echo $comp['id']; ?>-<?php echo $courseid; ?>')" style="background: none; border: none; color: #3b82f6; cursor: pointer; font-size: 11px; font-weight: 600; padding: 4px 8px;">
                                    <i class="fas fa-chevron-down"></i> View All
                                </button>
                            </div>
                            
                            <!-- Activity Progress Bar -->
                            <div style="background: #e5e7eb; height: 6px; border-radius: 999px; overflow: hidden; margin-bottom: 10px;">
                                <div class="progress-bar" 
                                     role="progressbar" 
                                     style="width: <?php echo $activity_progress; ?>%; background-color: #10b981;" 
                                     aria-valuenow="<?php echo $activity_progress; ?>" 
                                     aria-valuemin="0" 
                                     aria-valuemax="100">
                                </div>
                            </div>
                            
                            <!-- Activity List (Collapsed by default) -->
                            <div class="activities-list" id="comp-<?php echo $comp['id']; ?>-<?php echo $courseid; ?>" style="display: none; max-height: 200px; overflow-y: auto;">
                                <?php foreach ($comp['activities'] as $activity): 
                                    $activity_url = !empty($activity['url']) ? $activity['url'] : (new moodle_url('/mod/' . $activity['module'] . '/view.php', ['id' => $activity['cmid']]))->out(false);
                                ?>
                                <div class="activity-item" style="display: flex; align-items: center; justify-content: space-between; padding: 8px 10px; margin-bottom: 6px; background: #f8fafc; border-radius: 6px; border-left: 3px solid <?php echo $activity['completed'] ? '#10b981' : '#cbd5e1'; ?>;">
                                    <div style="display: flex; align-items: center; gap: 10px; flex: 1;">
                                        <i class="fas <?php echo $activity['icon'] ?? 'fa-tasks'; ?>" style="color: <?php echo $activity['completed'] ? '#10b981' : '#94a3b8'; ?>; font-size: 14px;"></i>
                                        <a href="<?php echo htmlspecialchars($activity_url); ?>" target="_blank" style="font-size: 12px; color: #475569; font-weight: 500; flex: 1; text-decoration: none; transition: color 0.2s;" onmouseover="this.style.color='#3b82f6';" onmouseout="this.style.color='#475569';">
                                            <?php echo htmlspecialchars($activity['name']); ?>
                                        </a>
                                        <span style="font-size: 10px; color: #94a3b8; text-transform: uppercase;">
                                            <?php echo htmlspecialchars($activity['module']); ?>
                                        </span>
                                    </div>
                                    <div style="display: flex; align-items: center; gap: 6px; margin-left: 10px;">
                                        <?php if ($activity['completed']): ?>
                                        <span style="display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; background: #d1fae5; color: #065f46; border-radius: 12px; font-size: 10px; font-weight: 600;">
                                            <i class="fas fa-check-circle"></i> Done
                                        </span>
                                        <?php if ($activity['completiontime']): ?>
                                        <span style="font-size: 10px; color: #94a3b8;">
                                            <?php echo userdate($activity['completiontime'], '%d %b'); ?>
                                        </span>
                                        <?php endif; ?>
                                        <?php else: ?>
                                        <span style="display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; background: #f1f5f9; color: #64748b; border-radius: 12px; font-size: 10px; font-weight: 600;">
                                            <i class="fas fa-clock"></i> Pending
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <!-- Quick Preview (Always visible) -->
                            <div class="activities-preview" style="display: flex; flex-wrap: wrap; gap: 6px;">
                                <?php foreach (array_slice($comp['activities'], 0, 3) as $activity): 
                                    $activity_url = !empty($activity['url']) ? $activity['url'] : (new moodle_url('/mod/' . $activity['module'] . '/view.php', ['id' => $activity['cmid']]))->out(false);
                                ?>
                                <a href="<?php echo htmlspecialchars($activity_url); ?>" target="_blank" style="display: inline-flex; align-items: center; gap: 4px; padding: 4px 8px; background: <?php echo $activity['completed'] ? '#d1fae5' : '#f1f5f9'; ?>; border-radius: 6px; font-size: 11px; color: <?php echo $activity['completed'] ? '#065f46' : '#475569'; ?>; border: 1px solid <?php echo $activity['completed'] ? '#a7f3d0' : '#e2e8f0'; ?>; text-decoration: none; transition: all 0.2s;" onmouseover="this.style.background='<?php echo $activity['completed'] ? '#a7f3d0' : '#e2e8f0'; ?>'; this.style.transform='translateY(-1px)';" onmouseout="this.style.background='<?php echo $activity['completed'] ? '#d1fae5' : '#f1f5f9'; ?>'; this.style.transform='translateY(0)';">
                                    <i class="fas <?php echo $activity['icon'] ?? 'fa-tasks'; ?>" style="font-size: 10px;"></i>
                                    <span style="max-width: 120px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                        <?php echo htmlspecialchars($activity['name']); ?>
                                    </span>
                                    <?php if ($activity['completed']): ?>
                                    <i class="fas fa-check-circle" style="font-size: 9px; color: #10b981;"></i>
                                    <?php endif; ?>
                                </a>
                                <?php endforeach; ?>
                                <?php if (count($comp['activities']) > 3): ?>
                                <span style="display: inline-flex; align-items: center; padding: 4px 8px; background: #f1f5f9; border-radius: 6px; font-size: 11px; color: #64748b; cursor: pointer;" onclick="toggleActivities(this.closest('.competency-activities').querySelector('.toggle-activities-btn'), 'comp-<?php echo $comp['id']; ?>-<?php echo $courseid; ?>')">
                                    +<?php echo count($comp['activities']) - 3; ?> more
                                </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($comp['timemodified']): ?>
                        <div class="competency-footer">
                            <i class="fas fa-clock"></i>
                            Updated <?php echo userdate($comp['timemodified'], '%d %b %Y'); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <!-- Empty State for Competencies -->
            <div class="course-section">
                <div class="empty-state">
                    <i class="fas fa-clipboard-list"></i>
                    <h3>No Competencies Found</h3>
                    <p>This child doesn't have any competencies assigned yet.</p>
                </div>
            </div>
        <?php endif; ?>
            </div>
            
            <!-- Badges Tab -->
            <div class="tab-pane fade" id="badges" role="tabpanel" aria-labelledby="badges-tab" style="width: 100%; padding: 32px;">
                <?php if (!empty($child_badges)): ?>
                    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 24px; width: 100%;">
                        <?php foreach ($child_badges as $badge): 
                            $badge_type_color = $badge['type'] === 'site' ? '#8b5cf6' : '#3b82f6';
                            $badge_type_label = $badge['type'] === 'site' ? 'Site Badge' : 'Course Badge';
                        ?>
                            <div style="background: #ffffff; border-radius: 16px; padding: 24px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06); border: 2px solid #e2e8f0; transition: all 0.3s ease; display: flex; flex-direction: column; align-items: center; text-align: center; position: relative; overflow: hidden;" onmouseover="this.style.transform='translateY(-6px)'; this.style.boxShadow='0 8px 24px rgba(0, 0, 0, 0.12)'; this.style.borderColor='<?php echo $badge_type_color; ?>';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 8px rgba(0, 0, 0, 0.06)'; this.style.borderColor='#e2e8f0';">
                                <!-- Badge Type Indicator -->
                                <div style="position: absolute; top: 12px; right: 12px; background: linear-gradient(135deg, <?php echo $badge_type_color; ?>, <?php echo $badge_type_color; ?>dd); color: white; padding: 4px 10px; border-radius: 12px; font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; box-shadow: 0 2px 6px <?php echo $badge_type_color; ?>40;">
                                    <?php echo $badge_type_label; ?>
                                </div>
                                
                                <!-- Badge Image -->
                                <div style="width: 120px; height: 120px; margin: 0 auto 20px; position: relative; display: flex; align-items: center; justify-content: center; background: linear-gradient(135deg, #f8fafc, #f1f5f9); border-radius: 50%; border: 4px solid <?php echo $badge_type_color; ?>20; box-shadow: 0 4px 16px <?php echo $badge_type_color; ?>20;">
                                    <?php if (!empty($badge['imageurl'])): ?>
                                        <img src="<?php echo htmlspecialchars($badge['imageurl']); ?>" alt="<?php echo htmlspecialchars($badge['name']); ?>" style="width: 100px; height: 100px; object-fit: contain; border-radius: 50%;">
                                    <?php else: ?>
                                        <i class="fas fa-award" style="font-size: 48px; color: <?php echo $badge_type_color; ?>;"></i>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Badge Name -->
                                <h5 style="font-size: 18px; font-weight: 700; color: #1e293b; margin: 0 0 12px 0; line-height: 1.3; min-height: 54px; display: flex; align-items: center; justify-content: center;">
                                    <?php echo htmlspecialchars($badge['name']); ?>
                                </h5>
                                
                                <!-- Course Name -->
                                <?php if (!empty($badge['coursename']) && $badge['type'] === 'course'): ?>
                                <div style="display: flex; align-items: center; justify-content: center; gap: 6px; margin-bottom: 12px; padding: 6px 12px; background: #f8fafc; border-radius: 8px; width: 100%;">
                                    <i class="fas fa-book" style="color: #64748b; font-size: 12px;"></i>
                                    <span style="font-size: 12px; color: #64748b; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                        <?php echo htmlspecialchars($badge['coursename']); ?>
                                    </span>
                                </div>
                                <?php endif; ?>
                                
                                <!-- Badge Description -->
                                <?php if (!empty($badge['description'])): ?>
                                <div style="font-size: 12px; color: #64748b; line-height: 1.5; margin-bottom: 16px; max-height: 60px; overflow: hidden; text-overflow: ellipsis;">
                                    <?php echo strip_tags($badge['description']); ?>
                                </div>
                                <?php endif; ?>
                                
                                <!-- Badge Info -->
                                <div style="background: #f8fafc; border-radius: 10px; padding: 12px; width: 100%; margin-bottom: 16px;">
                                    <div style="display: flex; align-items: center; justify-content: center; gap: 8px; margin-bottom: 8px;">
                                        <i class="far fa-calendar-alt" style="color: #64748b; font-size: 13px;"></i>
                                        <div>
                                            <div style="font-size: 10px; color: #94a3b8; font-weight: 600; text-transform: uppercase; margin-bottom: 2px;">Earned</div>
                                            <div style="font-size: 12px; color: #1e293b; font-weight: 600;"><?php echo $badge['dateissued']; ?></div>
                                        </div>
                                    </div>
                                    <?php if (!empty($badge['dateexpire'])): ?>
                                    <div style="display: flex; align-items: center; justify-content: center; gap: 8px; padding-top: 8px; border-top: 1px solid #e2e8f0;">
                                        <i class="fas fa-clock" style="color: #f59e0b; font-size: 13px;"></i>
                                        <div>
                                            <div style="font-size: 10px; color: #94a3b8; font-weight: 600; text-transform: uppercase; margin-bottom: 2px;">Expires</div>
                                            <div style="font-size: 12px; color: #f59e0b; font-weight: 600;"><?php echo $badge['dateexpire']; ?></div>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- View Badge Button -->
                                <?php if (!empty($badge['badgeurl'])): ?>
                                <a href="<?php echo htmlspecialchars($badge['badgeurl']); ?>" target="_blank" style="display: flex; align-items: center; justify-content: center; gap: 8px; background: linear-gradient(135deg, <?php echo $badge_type_color; ?>, <?php echo $badge_type_color; ?>dd); color: white; padding: 12px 24px; border-radius: 10px; text-decoration: none; font-weight: 700; font-size: 14px; transition: all 0.2s; box-shadow: 0 4px 12px <?php echo $badge_type_color; ?>40; width: 100%;" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 16px <?php echo $badge_type_color; ?>60';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 12px <?php echo $badge_type_color; ?>40';">
                                    <i class="fas fa-eye"></i>
                                    View Badge
                                </a>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-award" style="font-size: 64px; margin-bottom: 16px; opacity: 0.3; color: #cbd5e1;"></i>
                        <h3 style="color: #1e293b; margin-bottom: 8px;">No Badges Yet</h3>
                        <p style="color: #64748b; max-width: 500px; margin: 0 auto; line-height: 1.6;">This child hasn't earned any badges yet. Encourage them to complete more activities and courses to earn recognition badges!</p>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Certificates Tab -->
            <div class="tab-pane fade" id="certificates" role="tabpanel" aria-labelledby="certificates-tab" style="width: 100%; padding: 32px;">
                <?php if (!empty($child_certificates)): ?>
                    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 20px; width: 100%;">
                        <?php foreach ($child_certificates as $cert): 
                            $cert_type_icon = 'fa-certificate';
                            $cert_type_color = '#f59e0b';
                            if (isset($cert['type'])) {
                                switch($cert['type']) {
                                    case 'iomadcertificate':
                                        $cert_type_icon = 'fa-certificate';
                                        $cert_type_color = '#3b82f6';
                                        break;
                                    case 'iomad_track':
                                        $cert_type_icon = 'fa-file-pdf';
                                        $cert_type_color = '#ef4444';
                                        break;
                                    case 'customcert':
                                        $cert_type_icon = 'fa-award';
                                        $cert_type_color = '#8b5cf6';
                                        break;
                                }
                            }
                        ?>
                            <div style="background: #ffffff; border-radius: 12px; padding: 24px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06); border: 1px solid #e2e8f0; transition: all 0.3s ease; display: flex; flex-direction: column;" onmouseover="this.style.transform='translateY(-4px)'; this.style.boxShadow='0 4px 16px rgba(0, 0, 0, 0.1)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 8px rgba(0, 0, 0, 0.06)';">
                                <div style="display: flex; align-items: flex-start; gap: 16px; margin-bottom: 16px;">
                                    <div style="width: 56px; height: 56px; background: linear-gradient(135deg, <?php echo $cert_type_color; ?>, <?php echo $cert_type_color; ?>dd); border-radius: 12px; display: flex; align-items: center; justify-content: center; color: white; font-size: 24px; flex-shrink: 0; box-shadow: 0 4px 12px <?php echo $cert_type_color; ?>40;">
                                        <i class="fas <?php echo $cert_type_icon; ?>"></i>
                                    </div>
                                    <div style="flex: 1; min-width: 0;">
                                        <h5 style="font-size: 18px; font-weight: 700; color: #1e293b; margin: 0 0 8px 0; line-height: 1.3;"><?php echo htmlspecialchars($cert['name']); ?></h5>
                                        <?php if (!empty($cert['coursename'])): ?>
                                        <p style="font-size: 13px; color: #64748b; margin: 0 0 4px 0; display: flex; align-items: center; gap: 6px;">
                                            <i class="fas fa-book" style="font-size: 11px;"></i>
                                            <?php echo htmlspecialchars($cert['coursename']); ?>
                                        </p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <div style="background: #f8fafc; border-radius: 8px; padding: 12px; margin-bottom: 16px;">
                                    <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 8px;">
                                        <i class="far fa-calendar-alt" style="color: #64748b; font-size: 14px;"></i>
                                        <div>
                                            <div style="font-size: 11px; color: #94a3b8; font-weight: 600; text-transform: uppercase; margin-bottom: 2px;">Issued Date</div>
                                            <div style="font-size: 13px; color: #1e293b; font-weight: 600;"><?php echo $cert['issuedate']; ?></div>
                                        </div>
                                    </div>
                                    <?php if (!empty($cert['code'])): ?>
                                    <div style="display: flex; align-items: center; gap: 12px; padding-top: 8px; border-top: 1px solid #e2e8f0;">
                                        <i class="fas fa-hashtag" style="color: #64748b; font-size: 14px;"></i>
                                        <div>
                                            <div style="font-size: 11px; color: #94a3b8; font-weight: 600; text-transform: uppercase; margin-bottom: 2px;">Certificate ID</div>
                                            <div style="font-size: 12px; color: #475569; font-weight: 600; font-family: monospace;"><?php echo htmlspecialchars($cert['code']); ?></div>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                
                                <?php if (!empty($cert['url'])): ?>
                                <div style="margin-top: auto;">
                                    <a href="<?php echo htmlspecialchars($cert['url']); ?>" target="_blank" style="display: flex; align-items: center; justify-content: center; gap: 8px; background: linear-gradient(135deg, <?php echo $cert_type_color; ?>, <?php echo $cert_type_color; ?>dd); color: white; padding: 12px 20px; border-radius: 10px; text-decoration: none; font-weight: 700; font-size: 14px; transition: all 0.2s; box-shadow: 0 4px 12px <?php echo $cert_type_color; ?>40;" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 16px <?php echo $cert_type_color; ?>60';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 12px <?php echo $cert_type_color; ?>40';">
                                        <i class="fas <?php echo $cert['type'] === 'iomad_track' ? 'fa-download' : 'fa-eye'; ?>"></i>
                                        <?php echo $cert['type'] === 'iomad_track' ? 'Download Certificate' : 'View Certificate'; ?>
                                    </a>
                                </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-certificate" style="font-size: 64px; margin-bottom: 16px; opacity: 0.3; color: #cbd5e1;"></i>
                        <h3 style="color: #1e293b; margin-bottom: 8px;">No Certificates Yet</h3>
                        <p style="color: #64748b; max-width: 500px; margin: 0 auto; line-height: 1.6;">This child hasn't earned any certificates yet. They'll appear here once they complete courses with certificate requirements.</p>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- FLTECH Analytics Tab -->
            <?php if ($fltech_available): ?>
            <div class="tab-pane fade" id="fltech" role="tabpanel" aria-labelledby="fltech-tab" style="width: 100%; padding: 32px;">
                <div id="fltech-loading" class="text-center py-5" style="width: 100%;">
                    <div class="spinner-border text-primary" role="status">
                        <span class="sr-only">Loading...</span>
                    </div>
                    <p class="mt-2">Loading FLTECH analytics...</p>
                </div>
                <div id="fltech-content" style="display: none;">
                    <!-- Content will be loaded via AJAX -->
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php else: ?>
        <!-- No Child Selected -->
        <div class="course-section">
            <div class="empty-state">
                <i class="fas fa-user-graduate"></i>
                <h3>No Child Selected</h3>
                <p>Please select a child from the dropdown to view their progress and achievements.</p>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
// Simple Tab System (without Bootstrap dependency)
function initTabs() {
    const tabButtons = document.querySelectorAll('.nav-link[data-bs-toggle="tab"]');
    const tabPanes = document.querySelectorAll('.tab-pane');
    
    // Store the active tab in localStorage
    let activeTab = localStorage.getItem('activeTab') || '#competencies';
    
    // Show initial tab
    showTab(activeTab);
    
    // Add click handlers
    tabButtons.forEach(function(button) {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            const target = this.getAttribute('data-bs-target');
            showTab(target);
            localStorage.setItem('activeTab', target);
        });
    });
}

function showTab(targetId) {
    // Hide all tabs
    document.querySelectorAll('.tab-pane').forEach(function(pane) {
        pane.classList.remove('active', 'show');
        pane.style.display = 'none';
    });
    
    // Remove active from all buttons
    document.querySelectorAll('.nav-link').forEach(function(btn) {
        btn.classList.remove('active');
        btn.setAttribute('aria-selected', 'false');
    });
    
    // Show selected tab
    const targetPane = document.querySelector(targetId);
    const targetButton = document.querySelector('[data-bs-target="' + targetId + '"]');
    
    if (targetPane) {
        targetPane.classList.add('active', 'show');
        targetPane.style.display = 'block';
        targetPane.setAttribute('aria-hidden', 'false');
    }
    
    if (targetButton) {
        targetButton.classList.add('active');
        targetButton.setAttribute('aria-selected', 'true');
    }
    
    // Hide/show competency-specific filters based on active tab
    const mainContent = document.querySelector('.competencies-main-content');
    if (mainContent) {
        if (targetId === '#badges' || targetId === '#certificates' || targetId === '#fltech') {
            // Hide competency search and filter when badges/certificates/fltech tab is active
            mainContent.classList.add('hide-competency-filters');
            
            // Also hide competency stats if they exist
            const statsGrid = document.querySelector('.stats-grid');
            if (statsGrid && targetId !== '#competencies') {
                statsGrid.style.display = 'none';
            }
        } else {
            // Show filters for competencies tab
            mainContent.classList.remove('hide-competency-filters');
            
            // Show stats grid for competencies tab
            const statsGrid = document.querySelector('.stats-grid');
            if (statsGrid) {
                statsGrid.style.display = 'grid';
            }
        }
    }
    
    // Scroll to top of tab content for better UX
    if (targetPane) {
        targetPane.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

// Child selector
document.getElementById('child-selector')?.addEventListener('change', function() {
    const childId = this.value;
    
    // Use the same session management as parent_dashboard
    if (childId === 'all' || childId === '0') {
        // Clear selection
        fetch('<?php echo $CFG->wwwroot; ?>/theme/remui_kids/parent/parent_dashboard.php?child=all', {
            method: 'GET'
        }).then(() => {
            window.location.href = window.location.pathname + '?child=all';
        });
    } else {
        // Set child selection
        window.location.href = window.location.pathname + '?child=' + childId;
    }
});

// Toggle activities list
function toggleActivities(btn, targetId) {
    const activitiesList = document.getElementById(targetId);
    const icon = btn.querySelector('i');
    
    if (activitiesList.style.display === 'none' || !activitiesList.style.display) {
        activitiesList.style.display = 'block';
        icon.classList.remove('fa-chevron-down');
        icon.classList.add('fa-chevron-up');
        btn.innerHTML = '<i class="fas fa-chevron-up"></i> Hide';
    } else {
        activitiesList.style.display = 'none';
        icon.classList.remove('fa-chevron-up');
        icon.classList.add('fa-chevron-down');
        btn.innerHTML = '<i class="fas fa-chevron-down"></i> View All';
    }
}

// Search/Filter functionality
function initSearchFilter() {
    const searchInput = document.getElementById('competency-search');
    if (!searchInput) return;
    
    searchInput.addEventListener('input', function() {
        const searchTerm = this.value.toLowerCase();
        const competencyCards = document.querySelectorAll('.competency-card');
        
        competencyCards.forEach(card => {
            const competencyName = card.querySelector('.competency-name')?.textContent.toLowerCase() || '';
            const competencyDesc = card.querySelector('.competency-description')?.textContent.toLowerCase() || '';
            const framework = card.querySelector('.competency-framework')?.textContent.toLowerCase() || '';
            
            const matches = competencyName.includes(searchTerm) || 
                           competencyDesc.includes(searchTerm) || 
                           framework.includes(searchTerm);
            
            card.style.display = matches ? 'block' : 'none';
        });
        
        // Hide empty course sections
        document.querySelectorAll('.course-section').forEach(section => {
            const visibleCards = section.querySelectorAll('.competency-card[style*="block"], .competency-card:not([style*="none"])');
            if (visibleCards.length === 0 && searchTerm) {
                section.style.display = 'none';
            } else {
                section.style.display = 'block';
            }
        });
    });
}

// Filter by status
function filterByStatus(status) {
    const competencyCards = document.querySelectorAll('.competency-card');
    const filterButtons = document.querySelectorAll('.filter-status-btn');
    
    // Update button states
    filterButtons.forEach(btn => {
        if (btn.dataset.status === status) {
            btn.style.background = '#3b82f6';
            btn.style.color = '#fff';
        } else {
            btn.style.background = '#f1f5f9';
            btn.style.color = '#475569';
        }
    });
    
    // Filter cards
    competencyCards.forEach(card => {
        if (status === 'all') {
            card.style.display = 'block';
        } else {
            const cardStatus = card.classList.contains(status) ? 'block' : 'none';
            card.style.display = cardStatus;
        }
    });
    
    // Hide empty course sections
    document.querySelectorAll('.course-section').forEach(section => {
        const visibleCards = section.querySelectorAll('.competency-card[style*="block"], .competency-card:not([style*="none"])');
        if (visibleCards.length === 0 && status !== 'all') {
            section.style.display = 'none';
        } else {
            section.style.display = 'block';
        }
    });
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    initTabs();
    initSearchFilter();
    
    // Check initial tab and hide/show filters accordingly
    const activeTab = localStorage.getItem('activeTab') || '#competencies';
    const mainContent = document.querySelector('.competencies-main-content');
    if (mainContent && (activeTab === '#badges' || activeTab === '#certificates' || activeTab === '#fltech')) {
        mainContent.classList.add('hide-competency-filters');
        const statsGrid = document.querySelector('.stats-grid');
        if (statsGrid) {
            statsGrid.style.display = 'none';
        }
    }
    
    // Load FLTECH analytics if tab exists
    const fltechTab = document.getElementById('fltech-tab');
    if (fltechTab) {
        fltechTab.addEventListener('click', function() {
            loadFLTECHAnalytics();
        });
    }
});

// FLTECH Analytics Loader
function loadFLTECHAnalytics() {
    const loadingDiv = document.getElementById('fltech-loading');
    const contentDiv = document.getElementById('fltech-content');
    const childId = document.getElementById('child-selector')?.value;
    
    if (!childId || childId === 'all' || childId === '0') {
        if (contentDiv) {
            contentDiv.innerHTML = '<div class="empty-state"><i class="fas fa-exclamation-circle"></i><h3>No Child Selected</h3><p>Please select a child to view FLTECH analytics.</p></div>';
            contentDiv.style.display = 'block';
            if (loadingDiv) loadingDiv.style.display = 'none';
        }
        return;
    }
    
    if (loadingDiv) loadingDiv.style.display = 'block';
    if (contentDiv) contentDiv.style.display = 'none';
    
    // Load FLTECH data via AJAX
    fetch('<?php echo $CFG->wwwroot; ?>/local/fltech/get_analytics.php?userid=' + childId)
        .then(response => response.json())
        .then(data => {
            if (loadingDiv) loadingDiv.style.display = 'none';
            if (contentDiv) {
                if (data && data.success) {
                    contentDiv.innerHTML = renderFLTECHContent(data.data);
                } else {
                    contentDiv.innerHTML = '<div class="empty-state"><i class="fas fa-chart-line"></i><h3>No Analytics Available</h3><p>FLTECH analytics data is not available for this child.</p></div>';
                }
                contentDiv.style.display = 'block';
            }
        })
        .catch(error => {
            console.error('Error loading FLTECH analytics:', error);
            if (loadingDiv) loadingDiv.style.display = 'none';
            if (contentDiv) {
                contentDiv.innerHTML = '<div class="empty-state"><i class="fas fa-exclamation-triangle"></i><h3>Error Loading Analytics</h3><p>Unable to load FLTECH analytics. Please try again later.</p></div>';
                contentDiv.style.display = 'block';
            }
        });
}

function renderFLTECHContent(data) {
    if (!data || !data.metrics) {
        return '<div class="empty-state"><i class="fas fa-chart-line"></i><h3>No Data Available</h3></div>';
    }
    
    let html = '<div class="fltech-analytics-container">';
    for (const [key, metric] of Object.entries(data.metrics)) {
        html += `
            <div class="fltech-metric-card">
                <div class="metric-header">
                    <h4>${metric.label || key}</h4>
                </div>
                <div class="metric-value">${metric.value || 'N/A'}</div>
                ${metric.description ? `<div class="metric-description">${metric.description}</div>` : ''}
                ${metric.progress !== undefined ? `
                    <div class="metric-progress">
                        <div class="progress">
                            <div class="progress-bar" style="width: ${metric.progress}%"></div>
                        </div>
                        <div class="progress-labels">
                            <span>0%</span>
                            <span>100%</span>
                        </div>
                    </div>
                ` : ''}
            </div>
        `;
    }
    html += '</div>';
    return html;
}
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

<?php
echo $OUTPUT->footer();
?>




