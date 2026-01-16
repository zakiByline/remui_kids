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
 * Library of interface functions and constants for module codeeditor
 *
 * @package    mod_codeeditor
 * @copyright  2025 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Add admin menu items for codeeditor
 */
function codeeditor_extend_navigation_user_settings($navigation, $user, $usercontext, $course, $coursecontext) {
    // Add admin menu items if user has admin capabilities
    if (has_capability('moodle/site:config', context_system::instance())) {
        $navigation->add(
            get_string('admin_submissions', 'codeeditor'),
            new moodle_url('/mod/codeeditor/admin_submissions.php'),
            navigation_node::TYPE_SETTING,
            null,
            'codeeditor_admin_submissions'
        );
    }
}

/**
 * Add admin menu items to the admin tree
 */
function codeeditor_extend_navigation_category_settings($navigation, $categorycontext) {
    global $PAGE;
    
    if (has_capability('moodle/site:config', context_system::instance())) {
        $navigation->add(
            get_string('admin_submissions', 'codeeditor'),
            new moodle_url('/mod/codeeditor/admin_submissions.php'),
            navigation_node::TYPE_SETTING,
            null,
            'codeeditor_admin_submissions'
        );
    }
}

/**
 * Returns the information on whether the module supports a feature
 *
 * @param string $feature FEATURE_xx constant for requested feature
 * @return mixed true if the feature is supported, null if unknown
 */
function codeeditor_supports($feature) {
    switch($feature) {
        case FEATURE_MOD_INTRO:
            return true;
        case FEATURE_SHOW_DESCRIPTION:
            return true;
        case FEATURE_BACKUP_MOODLE2:
            return true;
        case FEATURE_COMPLETION_TRACKS_VIEWS:
            return true;
        case FEATURE_GRADE_HAS_GRADE:
            return true; // NOW SUPPORTS GRADING!
        case FEATURE_GRADE_OUTCOMES:
            return true; // Support outcomes
        case FEATURE_ADVANCED_GRADING:
            return true; // Support rubrics and advanced grading methods
        case FEATURE_GROUPS:
            return true; // Support groups
        case FEATURE_GROUPINGS:
            return true; // Support groupings
        case FEATURE_COMPLETION_HAS_RULES:
            return true; // Support completion rules
        case FEATURE_PLAGIARISM:
            return true; // Support plagiarism detection
        default:
            return null;
    }
}

/**
 * Saves a new instance of the codeeditor into the database
 *
 * @param stdClass $codeeditor Submitted data from the form in mod_form.php
 * @param mod_codeeditor_mod_form $mform The form instance itself (if needed)
 * @return int The id of the newly inserted codeeditor record
 */
function codeeditor_add_instance(stdClass $codeeditor, mod_codeeditor_mod_form $mform = null) {
    global $DB;

    $codeeditor->timecreated = time();
    $codeeditor->timemodified = time();
    
    // Handle description editor field if it exists and is an array
    if (isset($codeeditor->description) && is_array($codeeditor->description)) {
        $codeeditor->descriptionformat = $codeeditor->description['format'];
        $codeeditor->description = $codeeditor->description['text'];
    } else if (!isset($codeeditor->description)) {
        $codeeditor->description = '';
        $codeeditor->descriptionformat = FORMAT_HTML;
    }
    
    // Set default grading values if not set
    if (!isset($codeeditor->grade)) {
        $codeeditor->grade = 100;
    }
    if (!isset($codeeditor->duedate)) {
        $codeeditor->duedate = 0;
    }
    if (!isset($codeeditor->cutoffdate)) {
        $codeeditor->cutoffdate = 0;
    }
    if (!isset($codeeditor->allowsubmissionsfromdate)) {
        $codeeditor->allowsubmissionsfromdate = 0;
    }
    if (!isset($codeeditor->requiresubmit)) {
        $codeeditor->requiresubmit = 1;
    }
    if (!isset($codeeditor->blindmarking)) {
        $codeeditor->blindmarking = 0;
    }
    if (!isset($codeeditor->hidegrader)) {
        $codeeditor->hidegrader = 0;
    }
    if (!isset($codeeditor->markingworkflow)) {
        $codeeditor->markingworkflow = 0;
    }
    if (!isset($codeeditor->markingallocation)) {
        $codeeditor->markingallocation = 0;
    }

    $codeeditor->id = $DB->insert_record('codeeditor', $codeeditor);
    
    // Create grade item for this activity
    codeeditor_grade_item_update($codeeditor);

    return $codeeditor->id;
}

/**
 * Updates an instance of the codeeditor in the database
 *
 * @param stdClass $codeeditor An object from the form in mod_form.php
 * @param mod_codeeditor_mod_form $mform The form instance itself (if needed)
 * @return boolean Success/Fail
 */
function codeeditor_update_instance(stdClass $codeeditor, mod_codeeditor_mod_form $mform = null) {
    global $DB;

    $codeeditor->timemodified = time();
    $codeeditor->id = $codeeditor->instance;
    
    // Handle description editor field if it exists and is an array
    if (isset($codeeditor->description) && is_array($codeeditor->description)) {
        $codeeditor->descriptionformat = $codeeditor->description['format'];
        $codeeditor->description = $codeeditor->description['text'];
    } else if (!isset($codeeditor->description)) {
        $codeeditor->description = '';
        $codeeditor->descriptionformat = FORMAT_HTML;
    }
    
    // Set default grading values if not set
    if (!isset($codeeditor->grade)) {
        $codeeditor->grade = 100;
    }
    if (!isset($codeeditor->duedate)) {
        $codeeditor->duedate = 0;
    }
    if (!isset($codeeditor->cutoffdate)) {
        $codeeditor->cutoffdate = 0;
    }
    if (!isset($codeeditor->allowsubmissionsfromdate)) {
        $codeeditor->allowsubmissionsfromdate = 0;
    }
    if (!isset($codeeditor->requiresubmit)) {
        $codeeditor->requiresubmit = 1;
    }
    if (!isset($codeeditor->blindmarking)) {
        $codeeditor->blindmarking = 0;
    }
    if (!isset($codeeditor->hidegrader)) {
        $codeeditor->hidegrader = 0;
    }
    if (!isset($codeeditor->markingworkflow)) {
        $codeeditor->markingworkflow = 0;
    }
    if (!isset($codeeditor->markingallocation)) {
        $codeeditor->markingallocation = 0;
    }
    
    $result = $DB->update_record('codeeditor', $codeeditor);
    
    // Update grade item
    codeeditor_grade_item_update($codeeditor);
    
    return $result;
}

/**
 * Removes an instance of the codeeditor from the database
 *
 * @param int $id Id of the module instance
 * @return boolean Success/Failure
 */
function codeeditor_delete_instance($id) {
    global $DB;

    if (!$codeeditor = $DB->get_record('codeeditor', array('id' => $id))) {
        return false;
    }

    $DB->delete_records('codeeditor', array('id' => $codeeditor->id));

    return true;
}

/**
 * Returns a small object with summary information about what a
 * user has done with a given particular instance of this module
 *
 * @param stdClass $course The course record
 * @param stdClass $user The user record
 * @param cm_info|stdClass $mod The course module info object or record
 * @param stdClass $codeeditor The codeeditor instance record
 * @return stdClass|null
 */
function codeeditor_user_outline($course, $user, $mod, $codeeditor) {
    $result = new stdClass();
    $result->info = get_string('viewed', 'codeeditor');
    $result->time = time();
    return $result;
}

/**
 * Prints a detailed representation of what a user has done with
 * a given particular instance of this module, for user activity reports.
 *
 * @param stdClass $course the current course record
 * @param stdClass $user the record of the user we are generating report for
 * @param cm_info $mod course module info
 * @param stdClass $codeeditor the module instance record
 * @return void, is supposed to echo directly
 */
function codeeditor_user_complete($course, $user, $mod, $codeeditor) {
    echo get_string('viewed', 'codeeditor');
}

/**
 * Given a course and a time, this module should find recent activity
 * that has occurred in codeeditor activities and print it out.
 *
 * @param stdClass $course The course record
 * @param bool $viewfullnames Should we display full names
 * @param int $timestart Print activity since this timestamp
 * @return boolean True if anything was printed, otherwise false
 */
function codeeditor_print_recent_activity($course, $viewfullnames, $timestart) {
    return false;
}

/**
 * Returns all other caps used in the module
 *
 * @return array
 */
function codeeditor_get_extra_capabilities() {
    return array();
}

/**
 * This function is used by the reset_course_userdata function in moodlelib.
 *
 * @param stdClass $data the data submitted from the reset course.
 * @return array status array
 */
function codeeditor_reset_userdata($data) {
    return array();
}

/**
 * List of view style log actions
 *
 * @return array
 */
function codeeditor_get_view_actions() {
    return array('view', 'view all');
}

/**
 * List of update style log actions
 *
 * @return array
 */
function codeeditor_get_post_actions() {
    return array('update', 'add');
}

/**
 * Mark the activity completed (if required) and trigger the course_module_viewed event.
 *
 * @param stdClass $codeeditor codeeditor object
 * @param stdClass $course course object
 * @param stdClass $cm course module object
 * @param stdClass $context context object
 * @since Moodle 3.0
 */
function codeeditor_view($codeeditor, $course, $cm, $context) {
    // Trigger course_module_viewed event.
    $params = array(
        'context' => $context,
        'objectid' => $codeeditor->id
    );

    $event = \mod_codeeditor\event\course_module_viewed::create($params);
    $event->add_record_snapshot('course_modules', $cm);
    $event->add_record_snapshot('course', $course);
    $event->add_record_snapshot('codeeditor', $codeeditor);
    $event->trigger();

    // Completion.
    $completion = new completion_info($course);
    $completion->set_module_viewed($cm);
}

/**
 * Create or update grade item for the given codeeditor
 *
 * @param stdClass $codeeditor object with extra cmidnumber
 * @param mixed $grades optional array/object of grade(s); 'reset' means reset grades in gradebook
 * @return int 0 if ok, error code otherwise
 */
function codeeditor_grade_item_update($codeeditor, $grades=null) {
    global $CFG;
    require_once($CFG->libdir.'/gradelib.php');

    $params = array('itemname' => $codeeditor->name);
    
    if (isset($codeeditor->cmidnumber)) {
        $params['idnumber'] = $codeeditor->cmidnumber;
    }

    if ($codeeditor->grade > 0) {
        $params['gradetype'] = GRADE_TYPE_VALUE;
        $params['grademax']  = $codeeditor->grade;
        $params['grademin']  = 0;
    } else if ($codeeditor->grade < 0) {
        $params['gradetype'] = GRADE_TYPE_SCALE;
        $params['scaleid']   = -$codeeditor->grade;
    } else {
        $params['gradetype'] = GRADE_TYPE_NONE;
    }

    if ($grades === 'reset') {
        $params['reset'] = true;
        $grades = null;
    }

    return grade_update('mod/codeeditor', $codeeditor->course, 'mod', 'codeeditor', $codeeditor->id, 0, $grades, $params);
}

/**
 * Update grades in the gradebook
 *
 * @param stdClass $codeeditor The code editor instance
 * @param int $userid specific user only, 0 means all participants
 * @param bool $nullifnone If true and the user has no grade, a grade item with a null rawgrade will be inserted
 * @return void
 */
function codeeditor_update_grades($codeeditor, $userid=0, $nullifnone=true) {
    global $CFG, $DB;
    require_once($CFG->libdir.'/gradelib.php');

    if ($codeeditor->grade == 0) {
        codeeditor_grade_item_update($codeeditor);
    } else if ($userid and $nullifnone) {
        $grade = new stdClass();
        $grade->userid   = $userid;
        $grade->rawgrade = null;
        codeeditor_grade_item_update($codeeditor, $grade);
    } else {
        codeeditor_grade_item_update($codeeditor);
    }
}

/**
 * Delete grade item for given codeeditor
 *
 * @param stdClass $codeeditor object
 * @return int Returns GRADE_UPDATE_OK, GRADE_UPDATE_FAILED, GRADE_UPDATE_MULTIPLE or GRADE_UPDATE_ITEM_LOCKED
 */
function codeeditor_grade_item_delete($codeeditor) {
    global $CFG;
    require_once($CFG->libdir.'/gradelib.php');

    return grade_update('mod/codeeditor', $codeeditor->course, 'mod', 'codeeditor', $codeeditor->id, 0, null, array('deleted' => 1));
}

/**
 * Return grade for given user or all users.
 *
 * @param stdClass $codeeditor object
 * @param int $userid optional user id, 0 means all users
 * @return array array of grades, false if none
 */
function codeeditor_get_user_grades($codeeditor, $userid=0) {
    global $DB;

    $params = array('codeeditorid' => $codeeditor->id, 'latest' => 1);

    if ($userid) {
        $params['userid'] = $userid;
    }

    $submissions = $DB->get_records('codeeditor_submissions', $params);

    $grades = array();
    foreach ($submissions as $submission) {
        if ($submission->grade !== null && $submission->status === 'submitted') {
            $grades[$submission->userid] = new stdClass();
            $grades[$submission->userid]->userid = $submission->userid;
            $grades[$submission->userid]->rawgrade = $submission->grade;
            $grades[$submission->userid]->dategraded = $submission->timegraded;
            $grades[$submission->userid]->datesubmitted = $submission->timecreated;
        }
    }

    return $grades;
}

/**
 * Return the list of grading areas in the codeeditor module
 *
 * @return array of grading area information
 */
function codeeditor_grading_areas_list() {
    return array(
        'submissions' => get_string('submissions', 'codeeditor')
    );
}
