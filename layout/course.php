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
 * RemUI Kids - Custom course layout
 *
 * @package    theme_remui_kids
 * @copyright  2025 Kodeit
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CFG, $COURSE, $USER, $DB;

if(!apply_latest_user_pref()){
    user_preference_allow_ajax_update('enable_focus_mode', PARAM_BOOL);
}

// Include parent theme's common layout setup
require_once($CFG->dirroot . '/theme/remui_kids/layout/common.php');
// Ensure lib.php is loaded for theme functions
$libfile = $CFG->dirroot . '/theme/remui_kids/lib.php';
if (file_exists($libfile)) {
    require_once($libfile);
}

// Set show_course_header flag for common_start template
$templatecontext['show_course_header'] = false;

if (isset($templatecontext['focusdata']['enabled']) && $templatecontext['focusdata']['enabled']) {
    list(
        $templatecontext['focusdata']['sections'],
        $templatecontext['focusdata']['active']
    ) = \theme_remui\utility::get_focus_mode_sections($COURSE);
}

$coursecontext = context_course::instance($COURSE->id);
// Disable old course stats - we're using our custom header instead
$templatecontext['iscoursestatsshow'] = false;

$templatecontext['pacing_guide_assistant_url'] =
    (new moodle_url('/theme/remui_kids/teacher/pacing_guide_assistant.php', ['courseid' => $COURSE->id]))->out();
$templatecontext['show_pacing_guide_assistant'] = false;

// Study Partner button - include course context
$study_partner_params = ['courseid' => $COURSE->id];
if (isset($section) && $section !== null && $section > 0) {
    // Get section ID from section number
    $sectionrecord = $DB->get_record('course_sections', ['course' => $COURSE->id, 'section' => $section], 'id', IGNORE_MISSING);
    if ($sectionrecord) {
        $study_partner_params['sectionid'] = $sectionrecord->id;
    }
}
$templatecontext['study_partner_url'] = (new moodle_url('/local/studypartner/index.php', $study_partner_params))->out();
$templatecontext['show_study_partner'] = false;

if (isloggedin() && !isguestuser()) {
    // Check for teacher roles for pacing guide assistant
    $teacherroles = $DB->get_records_select('role', "shortname IN ('editingteacher','teacher')");
    if (!empty($teacherroles)) {
        list($roleinsql, $roleparams) = $DB->get_in_or_equal(array_keys($teacherroles), SQL_PARAMS_NAMED, 'role');
        $roleparams['userid'] = $USER->id;
        $roleparams['contextid'] = $coursecontext->id;

        $templatecontext['show_pacing_guide_assistant'] = $DB->record_exists_select(
            'role_assignments',
            "roleid {$roleinsql} AND userid = :userid AND contextid = :contextid",
            $roleparams
        );
    }
    
    // Check for Study Partner capability (only if capability exists)
    $systemcontext = context_system::instance();
    if (get_capability_info('local/studypartner:view') && has_capability('local/studypartner:view', $systemcontext)) {
        $templatecontext['show_study_partner'] = true;
    }
}

$completion = new \completion_info($COURSE);
$templatecontext['completion'] = $completion->is_enabled();

// Only show certificate card on the main course view (not on competencies tab)
$currenturl = $PAGE->url->out_as_local_url(false);
$iscompetenciesview = (strpos($currenturl, 'competenc') !== false);

$roles = get_user_roles(context_course::instance($COURSE->id), $USER->id);
$key = array_search('student', array_column($roles, 'shortname'));
if ($key === false || is_siteadmin()) {
    $templatecontext['notstudent'] = true;
}

$templatecontext['courseid'] = $COURSE->id;

// Determine course page URL based on user role
// Priority: Admin > Teacher > Elementary Student (Grade 1-3) > Middle School (Grade 4-7) > High School (Grade 8-12) > Regular Student
$course_page_url = '/my/courses.php'; // Default for regular students

// Check if user is an admin first (highest priority)
$context_system = context_system::instance();
if (is_siteadmin() || has_capability('moodle/site:config', $context_system)) {
    // User is an admin, use admin courses page
    $course_page_url = '/theme/remui_kids/admin/view_all_courses.php';
} else {
    // Check if user is a teacher (but not admin)
    global $DB;
    $teacherroles = $DB->get_records_select('role', "shortname IN ('editingteacher','teacher','manager')");
    $roleids = array_keys($teacherroles);

    if (!empty($roleids)) {
        list($insql, $params) = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED, 'r');
        $params['userid'] = $USER->id;
        $params['ctxlevel'] = CONTEXT_COURSE;
        
        $teacher_courses = $DB->get_records_sql(
            "SELECT DISTINCT ctx.instanceid as courseid
             FROM {role_assignments} ra
             JOIN {context} ctx ON ra.contextid = ctx.id
             WHERE ra.userid = :userid AND ctx.contextlevel = :ctxlevel AND ra.roleid {$insql}
             LIMIT 1",
            $params
        );
        
        if (!empty($teacher_courses)) {
            // User is a teacher, use teacher courses page
            $course_page_url = '/theme/remui_kids/teacher/teacher_courses.php';
        }
    }
    
    // If not admin or teacher, check user's grade level based on cohort
    if ($course_page_url === '/my/courses.php') {
        // Ensure function exists before calling
        if (!function_exists('theme_remui_kids_get_user_cohort_info')) {
            // Fallback: get cohort info directly
            $cohort_info = array('cohorts' => array(), 'primary_cohort' => null, 'grade_level' => 'default');
            try {
                $userid = $USER->id;
                $usercohorts = $DB->get_records_sql(
                    "SELECT c.name, c.id, c.description
                     FROM {cohort} c 
                     JOIN {cohort_members} cm ON c.id = cm.cohortid 
                     WHERE cm.userid = ?",
                    array($userid)
                );
                if (!empty($usercohorts)) {
                    $cohort_info['cohorts'] = $usercohorts;
                    $primarycohort = reset($usercohorts);
                    if ($primarycohort) {
                        $cohort_info['primary_cohort'] = $primarycohort;
                    }
                }
            } catch (Exception $e) {
                // Silently fail and use defaults
                $cohort_info = array('cohorts' => array(), 'primary_cohort' => null, 'grade_level' => 'default');
            }
        } else {
            $cohort_info = theme_remui_kids_get_user_cohort_info($USER->id);
        }
        
        // Check all cohorts to find the correct grade level (prioritize most specific)
        // Check in reverse order (highschool first) to catch the most specific match
        $grade_level = 'default';
        if (is_array($cohort_info) && !empty($cohort_info['cohorts'])) {
            foreach ($cohort_info['cohorts'] as $cohort) {
                $cohortname = strtolower($cohort->name);
                // Check for high school first (most specific range)
                if (preg_match('/grade\s*[8-9]|grade\s*1[0-2]/i', $cohortname)) {
                    $grade_level = 'highschool';
                    break; // Found high school, stop checking
                } elseif (preg_match('/grade\s*[4-7]/i', $cohortname) && $grade_level !== 'highschool') {
                    $grade_level = 'middle';
                    // Continue checking in case there's a high school cohort
                } elseif (preg_match('/grade\s*[1-3]/i', $cohortname) && $grade_level === 'default') {
                    $grade_level = 'elementary';
                    // Continue checking in case there's a higher grade cohort
                }
            }
        }
        
        // Set course page URL based on detected grade level
        if ($grade_level === 'elementary') {
            // User is in Grade 1-3, use elementary course page
            $course_page_url = '/theme/remui_kids/elementary_my_course.php';
        } elseif ($grade_level === 'middle') {
            // User is in Grade 4-7, use middle school course page
            $course_page_url = '/theme/remui_kids/moodle_mycourses.php';
        } elseif ($grade_level === 'highschool') {
            // User is in Grade 8-12, use high school course page
            $course_page_url = '/theme/remui_kids/highschool_courses.php';
        }
    }
}

// Set the course page URL (renamed from dashboard_url for clarity)
$templatecontext['dashboard_url'] = (new moodle_url($course_page_url))->out();

// Check if we're viewing a specific section
$section = optional_param('section', null, PARAM_INT);

// Check if we're actively in edit mode (not just if user can edit)
$isediting = $PAGE->user_is_editing();

// Add AI Assistant chatbot to course pages (for all modes)
if (isloggedin() && !isguestuser()) {
    $context = context_system::instance();
    if (has_capability('local/aiassistant:use', $context)) {
        $enabled = get_config('local_aiassistant', 'enabled');
        $showfloatingchat = get_config('local_aiassistant', 'showfloatingchat');
        
        if ($enabled && $showfloatingchat) {
            // Load JavaScript module for floating chat
            $PAGE->requires->js_call_amd('local_aiassistant/chatbot', 'init');
            
            // Get user's first name for personalized greeting
            $firstname = !empty($USER->firstname) ? $USER->firstname : $USER->username;
            
            // Default values
            $rolename = 'User';
            $welcomemessage = "<strong>Hello {$firstname}!</strong><br><br>I'm your AI assistant. How can I help you today?";
            
            // Try to detect user role for personalized welcome
            try {
                require_once($CFG->dirroot . '/local/aiassistant/classes/role_helper.php');
                $userrole = \local_aiassistant\role_helper::get_primary_role($USER->id);
                
                // Create personalized welcome message
                $rolegreetings = [
                    'admin' => 'As an administrator, I can help you with system management, user administration, and technical support.',
                    'teacher' => 'As your teaching assistant, I can help you with course management, student engagement, and pedagogical strategies.',
                    'student' => 'I\'m here to help you with your learning journey, assignments, and study strategies.',
                    'companymanager' => 'I can assist you with training management, employee enrollment, and company reports.',
                    'guest' => 'I\'m here to help you navigate the platform and answer your questions.'
                ];
                
                $rolename = ucfirst($userrole);
                $greeting = isset($rolegreetings[$userrole]) ? $rolegreetings[$userrole] : $rolegreetings['guest'];
                
                // Create personalized welcome message with HTML formatting
                $welcomemessage = "<strong>Hello {$firstname}!</strong><br><br>{$greeting}<br><br>What can I help you with today?";
            } catch (\Exception $e) {
                // If role detection fails, use default message
                debugging('Error detecting user role for welcome message: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
            
            // Render chatbot HTML directly (for editing mode, we'll add it after template)
            $ai_chatbot_html = $OUTPUT->render_from_template('local_aiassistant/floating_chatbot', [
                'username' => fullname($USER),
                'firstname' => $firstname,
                'userrole' => $rolename,
                'welcomemessage' => $welcomemessage
            ]);
            
            // Store for later output
            $templatecontext['ai_chatbot_html'] = $ai_chatbot_html;
        }
    }
}

// If actively editing, use parent theme's course layout
if ($isediting) {
    // Use parent theme's course layout for editing
    require_once($CFG->dirroot . '/theme/remui/layout/common_end.php');
    
    // Ensure doctype() is called to set contenttype (required by Moodle core renderer).
    $OUTPUT->doctype();
    
    echo $OUTPUT->render_from_template('theme_remui/course', $templatecontext);
    
    // Add chatbot after template (for editing mode)
    if (isset($templatecontext['ai_chatbot_html'])) {
        echo $templatecontext['ai_chatbot_html'];
    }
} else if ($section && $section > 0) {
    // If viewing a specific section, show section activities
    $templatecontext['custom_section_view'] = true;
    $templatecontext['current_section'] = $section;
    $templatecontext['section_activities'] = theme_remui_kids_get_section_activities($COURSE, $section);
    
    // Check if we're viewing a subsection's delegated section
    // If so, find the parent section that contains the subsection and link back to that
    $parent_section = null; // Default to null
    $is_subsection_view = false; // Track if we're in a subsection
    try {
        // Get the section ID for the current section number
        $current_section_record = $DB->get_record('course_sections', 
            ['course' => $COURSE->id, 'section' => $section], 
            '*', 
            IGNORE_MISSING
        );
        
        if ($current_section_record) {
            $current_section_id = $current_section_record->id;
            
            // Check if this section is a subsection's delegated section
            // Look for subsection modules whose delegated section matches current section
            $parent_section_info = $DB->get_record_sql(
                "SELECT cs_parent.section as parent_section_num
                 FROM {course_sections} cs_delegated
                 JOIN {course_modules} cm ON cm.instance = cs_delegated.itemid
                 JOIN {modules} m ON m.id = cm.module AND m.name = 'subsection'
                 JOIN {course_sections} cs_parent ON cs_parent.id = cm.section
                 WHERE cs_delegated.component = 'mod_subsection'
                 AND cs_delegated.id = :current_section_id
                 AND cm.course = :courseid
                 LIMIT 1",
                ['current_section_id' => $current_section_id, 'courseid' => $COURSE->id]
            );
            
            if ($parent_section_info && isset($parent_section_info->parent_section_num)) {
                // We found a parent section - this IS a subsection view
                $is_subsection_view = true;
                $parent_section = $parent_section_info->parent_section_num;
            } else {
                // Also check component field directly as fallback
                if (!empty($current_section_record->component) && $current_section_record->component === 'mod_subsection' && !empty($current_section_record->itemid)) {
                    $is_subsection_view = true;
                    // Try to find parent using itemid
                    $subsection_instance_id = $current_section_record->itemid;
                    $subsection_cm = $DB->get_record_sql(
                        "SELECT cm.id, cm.section
                         FROM {course_modules} cm
                         JOIN {modules} m ON m.id = cm.module
                         WHERE cm.course = :courseid 
                         AND m.name = 'subsection'
                         AND cm.instance = :instanceid
                         LIMIT 1",
                        ['courseid' => $COURSE->id, 'instanceid' => $subsection_instance_id]
                    );
                    
                    if ($subsection_cm && $subsection_cm->section) {
                        $parent_section_record = $DB->get_record('course_sections', 
                            ['id' => $subsection_cm->section, 'course' => $COURSE->id], 
                            'section', 
                            IGNORE_MISSING
                        );
                        if ($parent_section_record && isset($parent_section_record->section)) {
                            $parent_section = $parent_section_record->section;
                        }
                    }
                }
            }
        }
    } catch (Exception $e) {
        // If anything fails, parent_section stays null
        error_log("Error finding parent section: " . $e->getMessage());
    }
    
    // Build the course URL
    // If we're in a subsection AND parent is not null AND parent != current → go to parent section
    // Otherwise (subsection with no parent, parent same as current, or not a subsection) → go to course root
    if ($is_subsection_view && $parent_section !== null && $parent_section != $section) {
        // Found a valid parent section that's different from current → go to parent
        $templatecontext['course_url'] = new moodle_url('/course/view.php', ['id' => $COURSE->id, 'section' => $parent_section]);
    } else {
        // Subsection with no parent, parent same as current, or not a subsection → go to course root
        $templatecontext['course_url'] = new moodle_url('/course/view.php', ['id' => $COURSE->id]);
    }
    
    // Add course header data for section view
    $templatecontext['course_header_data'] = theme_remui_kids_get_course_header_data($COURSE);
    $templatecontext['show_course_header'] = true;
    
    // Add certificate completion card if course is completed (skip competencies tab)
    if (!$iscompetenciesview) {
        require_once($CFG->dirroot . '/theme/remui_kids/lib/certificate_completion.php');
        $certificate_card = theme_remui_kids_get_certificate_completion_card($COURSE, $USER->id);
        if (!empty($certificate_card)) {
            $templatecontext['certificate_completion_card'] = $certificate_card;
        } else {
            // Show status check link even when certificate is not available
            $templatecontext['certificate_status_check_link'] = theme_remui_kids_get_certificate_status_check_link($COURSE);
        }
    }
    
    // Must be called before rendering the template
    require_once($CFG->dirroot . '/theme/remui/layout/common_end.php');
    
    // Ensure doctype() is called to set contenttype (required by Moodle core renderer).
    $OUTPUT->doctype();
    
    echo $OUTPUT->render_from_template('theme_remui_kids/course', $templatecontext);
} else {
    // Use our custom course cards for students (course overview)
    $templatecontext['custom_course_cards'] = true;
    $templatecontext['course_sections'] = theme_remui_kids_get_course_sections_data($COURSE);
    
    // Add course header data for the beautiful header
    $templatecontext['course_header_data'] = theme_remui_kids_get_course_header_data($COURSE);
    $templatecontext['show_course_header'] = true;
    
    // Add certificate completion card if course is completed (skip competencies tab)
    if (!$iscompetenciesview) {
        require_once($CFG->dirroot . '/theme/remui_kids/lib/certificate_completion.php');
        $certificate_card = theme_remui_kids_get_certificate_completion_card($COURSE, $USER->id);
        if (!empty($certificate_card)) {
            $templatecontext['certificate_completion_card'] = $certificate_card;
        } else {
            // Show status check link even when certificate is not available
            $templatecontext['certificate_status_check_link'] = theme_remui_kids_get_certificate_status_check_link($COURSE);
        }
    }
    
    // Force disable any old header elements
    $templatecontext['iscoursestatsshow'] = false;
    $templatecontext['notstudent'] = false; // This might be causing issues
    
    // Must be called before rendering the template
    require_once($CFG->dirroot . '/theme/remui/layout/common_end.php');
    
    // Ensure doctype() is called to set contenttype (required by Moodle core renderer).
    $OUTPUT->doctype();
    
    echo $OUTPUT->render_from_template('theme_remui_kids/course', $templatecontext);
}
