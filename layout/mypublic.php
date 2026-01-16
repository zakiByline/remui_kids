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
 * A drawer based layout for the remui theme.
 *
 * @package   theme_remui
 * @copyright (c) 2023 WisdmLabs (https://wisdmlabs.com/) <support@wisdmlabs.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $USER, $DB, $OUTPUT, $CFG, $SITE;

require_once($CFG->dirroot . '/theme/remui_kids/layout/common.php');

require_once($CFG->libdir . "/badgeslib.php");

$id = optional_param('id', 0, PARAM_INT); // User id.
$courseid = optional_param('course', SITEID, PARAM_INT); // Course id (defaults to Site).

$id = $id ? $id : $USER->id;

$user = $DB->get_record('user', array('id' => $id), '*', MUST_EXIST);
$course = $DB->get_record('course', array('id' => $courseid), '*', MUST_EXIST);

use theme_remui\usercontroller as usercontroller;

// Get user's object from page url.
$userobject = $DB->get_record('user', array('id' => $id));

$context = context_user::instance($id, MUST_EXIST);
if (user_can_view_profile($userobject, null, $context)) {
    $countries = get_string_manager()->get_list_of_countries();
    // Get the list of all country.
    if (!empty($userobject->country)) { // Country field in user object is empty.
        $temparray[] = array("keyName" => $userobject->country, "valName" => $countries[$userobject->country]);
        $temparray[] = array("keyName" => '', "valName" => get_string('selectcountrystring', 'theme_remui'));
    } else {
        $temparray[] = array("keyName" => '', "valName" => get_string('selectcountrystring', 'theme_remui'));
    }

    foreach ($countries as $key => $value) {
        $temparray[] = array("keyName" => $key, "valName" => $value);
    }

    $templatecontext['usercanmanage'] = \theme_remui\utility::check_user_admin_cap($userobject);
    $systemcontext = \context_system::instance();
    if ( has_capability('moodle/user:editownprofile', $systemcontext) ) {
        $templatecontext["haseditpermission"] = true;
    }
    $templatecontext['notcurrentuser'] = ($userobject->id != $USER->id) ? true : false;
    $templatecontext['countries'] = $temparray;

    // Prepare profile context.

    $hasinterests = false;
    $hasbadges = false;
    $onlypublic = true;
    $aboutme = false;
    $country = '';

    $userauth = get_auth_plugin($userobject->auth);
    $lockfields = array('field_lock_firstname', 'field_lock_lastname', 'field_lock_city', 'field_lock_country');
    foreach ($userauth->config as $key => $lockfield) {
        if ($lockfield == 'locked') {
            if (in_array($key, $lockfields)) {
                $userobject->$key = 'locked';
            }
        }

        $userfield = str_replace("field_lock_", "", $key);

        if ($lockfield == 'unlockedifempty' && isset($userobject->$userfield) && ($userobject->$userfield !== "")) {
            $userobject->$key = 'locked';
        }
    }
    $templatecontext['user'] = $userobject;
    $templatecontext['user']->profilepicture = $OUTPUT->user_picture($userobject, array('size' => 116));
    $templatecontext['user']->forumpostcount = usercontroller::get_user_forum_post_count($userobject);
    $templatecontext['user']->blogpostcount  = usercontroller::get_user_blog_post_count($userobject);
    $templatecontext['user']->contactscount  = usercontroller::get_user_contacts_count($userobject);
    $templatecontext['user']->description  = format_text($userobject->description,FORMAT_HTML);

    // About me tab data.
    $interests = \core_tag_tag::get_item_tags('core', 'user', $userobject->id);
    foreach ($interests as $interest) {
        $hasinterests = true;
        $aboutme = true;
        $templatecontext['user']->interests[] = $interest;
    }
    $templatecontext['user']->hasinterests    = $hasinterests;
    $badgedcount = 0;
    // Badges.
    if ($CFG->enablebadges) {
        if ($templatecontext['usercanmanage'] || ($userobject->id == $USER->id)) {
            $onlypublic = false;
        }
        $badges = badges_get_user_badges($userobject->id, 0, null, null, null, $onlypublic);
        if ($badges) {
            $hasbadges = true;
            $count = 0;
            foreach ($badges as $key => $badge) {
                $context = ($badge->type == BADGE_TYPE_SITE) ?
                context_system::instance() : context_course::instance($badge->courseid);
                $templatecontext['user']->badges[$count]['imageurl'] = moodle_url::make_pluginfile_url(
                    $context->id,
                    'badges',
                    'badgeimage',
                    $badge->id,
                    '/',
                    'f1',
                    false
                );
                $templatecontext['user']->badges[$count]['name'] = $badge->name;
                $templatecontext['user']->badges[$count]['link'] = new moodle_url('/badges/badge.php?hash=' . $badge->uniquehash);
                $templatecontext['user']->badges[$count]['desc'] = $badge->description;
                $count++;
            }
            $badgedcount = $count;
        }
    }

    $templatecontext['user']->hasbadges = $hasbadges;
    $templatecontext['user']->badgedcount = $badgedcount;
    $templatecontext['user']->badgedsettingstatus = $CFG->enablebadges;
    $templatecontext['user']->blogsettingstatus = $CFG->enableblogs;

    $templatecontext['countryname'] = isset($countries[$templatecontext['user']->country]) ? $countries[$templatecontext['user']->country] : '';
    if (isset( $templatecontext['user']->city)) {
        $templatecontext['user']->city  = format_text($templatecontext['user']->city, FORMAT_HTML);
    }
    $templatecontext['user']->usercourses = true;
    if (!empty($userobject->country)) {
        $country = get_string($userobject->country, 'countries');
    }

    $usercontext = context_user::instance($user->id, MUST_EXIST);
    $systemcontext = context_system::instance();
    $courseorusercontext = !empty($course) ? context_course::instance($course->id) : $usercontext;
    $templatecontext['user']->lastuseraccessdate = userdate($templatecontext['user']->lastaccess);
    // Contact details.

    // Contact details.

    if (has_capability('moodle/user:viewhiddendetails', $courseorusercontext)) {
        $hiddenfields = array();
    } else {
        $temparray = explode(',', $CFG->hiddenuserfields);
        $hiddenfields = [];
        foreach ($temparray as $value) {
            $hiddenfields[$value] = $value;
        }
    }

    $canviewuseridentity = has_capability('moodle/site:viewuseridentity', $courseorusercontext);
    if ($canviewuseridentity) {
        $temparray = explode(',', $CFG->showuseridentity);
        foreach ($temparray as $value) {
            $identityfields[$value] = $value;
        }
    } else {
        $identityfields = array();
    }

    $templatecontext['user']->location = "";
    $templatecontext['user']->editmodecity  = $templatecontext['user']->city;
    $templatecontext['user']->editmodeemail = $templatecontext['user']->email;
    $templatecontext['user']->editmodedescription = $templatecontext['user']->description;
    $templatecontext['user']->department = format_text($templatecontext['user']->department, FORMAT_HTML);
    if (isset($identityfields['address']) && $user->address) {
        $templatecontext['user']->location .= format_text($user->address, FORMAT_HTML);
    }
    if($user->address) {
        $templatecontext['user']->address = format_text($user->address, FORMAT_HTML);
    }

    $templatecontext['user']->instidept = "";
    if (isset($identityfields['department']) && $user->department) {
        $templatecontext['user']->instidept .= format_text($user->department, FORMAT_HTML);
    }

    if (isset($identityfields['institution']) && $user->institution) {
        $templatecontext['user']->instidept .= $user->institution;
    }

    if ($templatecontext['user']->location !== "" || $templatecontext['user']->instidept !== "") {
        $aboutme = true;
    }

    if (isset($hiddenfields['city'])) {
        $templatecontext['user']->city  = false;
    }

    if (isset($hiddenfields['country'])) {
        $templatecontext['countryname'] = false;
    }

    if (isset($hiddenfields['email'])) {
        $templatecontext['user']->email = false;
    }

    if (isset($hiddenfields['description'])) {
        $templatecontext['user']->description = false;
    }
    if (isset($hiddenfields['lastaccess'])) {
        $templatecontext['user']->lastuseraccessdate = false;
    }
    if (isset($hiddenfields['mycourses'])) {
        $templatecontext['user']->usercourses = false;
    }
    $templatecontext['user']->aboutme = $aboutme;

    // Courses tab data.
    $usercourses = array_values(usercontroller::get_users_courses_with_progress($userobject));
    $templatecontext['user']->hascourses = (count($usercourses)) ? true : false;
    $templatecontext['user']->courses = $usercourses;

    $ceertificatecount = 0;
    $templatecontext['user']->hascertificate = false;
    if (is_plugin_available('mod_customcert')) {
        $certificatedata = $DB->get_records('customcert_issues', ['userid' => $user->id]);
        $templatecontext['user']->hascertificate = true;
        $ceertificatecount = count($certificatedata);
    }
    $templatecontext['user']->certificatecountdata = $ceertificatecount;

    $templatecontext['user']->sitename = $SITE->fullname;
}

// Must be called before rendering the template.
// This will ease us to add body classes directly to the array.
require_once($CFG->dirroot . '/theme/remui/layout/common_end.php');

$PAGE->requires->strings_for_js(array(
    'detailssavedsuccessfully',
    'actioncouldnotbeperformed',
    'enterfirstname',
    'enterlastname',
    'entervalidphoneno',
    'enterproperemailid',
    'enteremailid'


), 'theme_remui');

// Ensure doctype() is called to set contenttype (required by Moodle core renderer).
$OUTPUT->doctype();

echo $OUTPUT->render_from_template('theme_remui/mypublic', $templatecontext);
