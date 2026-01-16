<?php
/**
 * AJAX endpoint for quiz metadata AI suggestions.
 *
 * @package   theme_remui_kids
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/includes/ai_helpers.php');

header('Content-Type: application/json');

require_login();
require_sesskey();

$courseid = required_param('courseid', PARAM_INT);
$coursename = optional_param('coursename', '', PARAM_TEXT);
$placementname = optional_param('placementname', '', PARAM_TEXT);
$placementpath = optional_param('placementpath', '', PARAM_TEXT);
$placementtype = optional_param('placementtype', '', PARAM_ALPHAEXT);
$sectionid = optional_param('sectionid', 0, PARAM_INT);
$moduleid = optional_param('moduleid', 0, PARAM_INT);
$existingname = optional_param('existingname', '', PARAM_TEXT);
$existingdescription = optional_param('existingdescription', '', PARAM_RAW_TRIMMED);
$count = optional_param('count', 3, PARAM_INT);

try {
    $course = get_course($courseid);
    $coursecontext = context_course::instance($course->id);
    require_capability('moodle/course:update', $coursecontext);

    if ($coursename === '') {
        $coursename = format_string($course->fullname, true, ['context' => $coursecontext]);
    }

    $sectionsummary = '';
    if ($sectionid) {
        $section = $DB->get_record('course_sections', ['id' => $sectionid], '*', IGNORE_MISSING);
        if ($section) {
            $sectionsummary = format_text(
                $section->summary ?? '',
                $section->summaryformat ?? FORMAT_HTML,
                ['context' => $coursecontext]
            );
            $sectionsummary = trim(html_to_text($sectionsummary, 0, false));
        }
    }

    $moduleintro = '';
    $modulelabel = '';
    if ($moduleid) {
        $cm = get_coursemodule_from_id(null, $moduleid, $courseid, false, IGNORE_MISSING);
        if ($cm) {
            $modulecontext = context_module::instance($cm->id);
            $modrecord = $DB->get_record($cm->modname, ['id' => $cm->instance], '*', IGNORE_MISSING);
            if ($modrecord) {
                $modulelabel = format_string($modrecord->name ?? '', true, ['context' => $modulecontext]);
                $intro = $modrecord->intro ?? '';
                $introformat = $modrecord->introformat ?? FORMAT_HTML;
                if ($intro !== '') {
                    $moduleintro = format_text($intro, $introformat, ['context' => $modulecontext]);
                    $moduleintro = trim(html_to_text($moduleintro, 0, false));
                }
            }
        }
    }

    $contextdata = [
        'courseid' => $courseid,
        'coursename' => $coursename,
        'placementname' => $placementname ?: $modulelabel,
        'placementpath' => $placementpath ?: $placementname ?: $modulelabel,
        'placementtype' => $placementtype,
        'sectionid' => $sectionid,
        'sectionsummary' => $sectionsummary,
        'moduleid' => $moduleid,
        'moduleintro' => $moduleintro,
        'existingname' => $existingname,
        'existingdescription' => $existingdescription,
        'audience' => get_user_preferences('theme_remui_kids_quiz_audience', 'students'),
    ];

    $suggestions = remui_kids_generate_quiz_meta_suggestions($contextdata, $count);

    echo json_encode([
        'success' => true,
        'message' => 'Suggestions generated',
        'suggestions' => $suggestions,
    ]);
} catch (moodle_exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'errorcode' => $e->errorcode ?? 'generalexceptionmessage',
    ]);
} catch (Exception $e) {
    debugging('Quiz metadata AI error: ' . $e->getMessage(), DEBUG_DEVELOPER);
    echo json_encode([
        'success' => false,
        'message' => 'Error generating AI suggestions.',
        'errorcode' => 'generalexceptionmessage',
    ]);
}

