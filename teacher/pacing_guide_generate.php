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
 * AJAX endpoint to generate pacing guide drafts via AI.
 *
 * @package   theme_remui_kids
 * @copyright 2025
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/course/lib.php');

header('Content-Type: application/json');

$courseid = required_param('courseid', PARAM_INT);
$scope = required_param('scope', PARAM_ALPHA);
$timeframe = required_param('timeframe', PARAM_ALPHA);
$lessonid = optional_param('lessonid', 0, PARAM_INT);
$lessonname = optional_param('lessonname', '', PARAM_TEXT);
$hours = optional_param('hours', '', PARAM_RAW_TRIMMED);
$weeks = optional_param('weeks', '', PARAM_RAW_TRIMMED);
$years = optional_param('years', '', PARAM_RAW_TRIMMED);
$notes = optional_param('notes', '', PARAM_RAW);

try {
    $course = get_course($courseid);

    require_login($course);
    require_sesskey();

    $context = context_course::instance($course->id);
    require_capability('moodle/course:update', $context);

    $scope = strtolower($scope);
    $timeframe = strtolower($timeframe);

    $validscopes = ['course', 'lesson'];
    $validtimeframes = ['year', 'month', 'week', 'day'];

    if (!in_array($scope, $validscopes, true)) {
        throw new moodle_exception('invalidscope', 'theme_remui_kids');
    }

    if (!in_array($timeframe, $validtimeframes, true)) {
        throw new moodle_exception('invalidtimeframe', 'theme_remui_kids');
    }

    $modinfo = get_fast_modinfo($course);
    $sectionsinfo = $modinfo->get_section_info_all();

    $targetsections = [];

    if ($scope === 'course') {
        foreach ($sectionsinfo as $section) {
            if ((int)$section->section === 0 || empty($section->uservisible)) {
                continue;
            }
            $targetsections[] = $section;
        }
    } else {
        if (!$lessonid) {
            throw new moodle_exception('invalidrecord');
        }
        $found = null;
        foreach ($sectionsinfo as $section) {
            if ((int)$section->id === $lessonid) {
                $found = $section;
                break;
            }
        }
        if (!$found || empty($found->uservisible) || (int)$found->section === 0) {
            throw new moodle_exception('invalidrecord');
        }
        $targetsections[] = $found;
    }

    if (empty($targetsections)) {
        throw new moodle_exception('nosections', 'theme_remui_kids');
    }

$coursecategory = core_course_category::get($course->category, MUST_EXIST);
$gradelevel = format_string($coursecategory->name);

$coursedata = build_course_outline($course, $modinfo, $targetsections, $scope, $lessonname, $gradelevel);

$prompt = build_ai_prompt($course, $scope, $timeframe, $hours, $weeks, $years, $gradelevel, $notes, $coursedata);
    $response = call_gemini_api($prompt);

    if (!$response['success']) {
        echo json_encode($response);
        exit;
    }

    echo json_encode([
        'success' => true,
        'guide' => $response['content'],
        'statusmessage' => $response['statusmessage'],
    ]);
} catch (Exception $e) {
    debugging('Pacing guide generation failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
    echo json_encode([
        'success' => false,
        'message' => get_string('errorprocessingrequest', 'error', $e->getMessage()),
    ]);
}

/**
 * Prepare a structured outline of sections and activities.
 *
 * @param stdClass $course
 * @param course_modinfo $modinfo
 * @param array $sections
 * @param string $scope
 * @param string $lessonname
 * @return array
 */
function build_course_outline(stdClass $course, course_modinfo $modinfo, array $sections, string $scope, string $lessonname, string $gradelevel): array {
    $outline = [
        'coursefullname' => format_string($course->fullname),
        'courseshortname' => format_string($course->shortname),
        'lessonlabel' => $scope === 'lesson' ? format_string($lessonname ?: get_section_name($course, reset($sections))) : '',
        'gradelevel' => $gradelevel,
        'sections' => [],
    ];

    foreach ($sections as $section) {
        $sectionname = format_string(get_section_name($course, $section));
        $sectionsummary = clean_summary_text($section->summary ?? '', $section->summaryformat ?? FORMAT_HTML);

        $activities = [];
        $sectionnumber = (int)$section->section;
        $cmids = $modinfo->sections[$sectionnumber] ?? [];

        foreach ($cmids as $cmid) {
            if (empty($modinfo->cms[$cmid])) {
                continue;
            }
            $cm = $modinfo->cms[$cmid];
            if (!$cm->uservisible) {
                continue;
            }

            $activity = [
                'name' => format_string($cm->name),
                'type' => map_mod_type($cm->modname),
                'description' => clean_activity_description($cm),
            ];

            $activities[] = $activity;
        }

        $outline['sections'][] = [
            'name' => $sectionname,
            'summary' => $sectionsummary,
            'activities' => $activities,
        ];
    }

    return $outline;
}

/**
 * Build the AI prompt text.
 *
 * @param stdClass $course
 * @param string $scope
 * @param string $timeframe
 * @param string $hours
 * @param array $outline
 * @return string
 */
function build_ai_prompt(stdClass $course, string $scope, string $timeframe, string $hours, string $weeks, string $years, string $gradelevel, string $notes, array $outline): string {
    $timeframelabels = [
        'year' => 'a full academic year',
        'month' => 'a multi-week or monthly window',
        'week' => 'one focused week',
        'day' => 'a single instructional day',
    ];

    $scopephrase = $scope === 'lesson'
        ? "a single lesson titled \"{$outline['lessonlabel']}\" within the course"
        : 'the entire course';

    $hoursphrase = '';
    $hoursnumeric = null;
    if ($hours !== '' && is_numeric($hours)) {
        $hoursnumeric = (float)$hours;
        $hoursphrase = " Aim for approximately {$hoursnumeric} instructional hours.";
    }

    $weeksphrase = '';
    $weeksnumeric = null;
    if ($timeframe === 'month' && $weeks !== '' && is_numeric($weeks)) {
        $weeksnumeric = (float)$weeks;
        $weeksphrase = " Estimated duration: about {$weeksnumeric} week(s).";
    }

    $yearsphrase = '';
    $yearsnumeric = null;
    if ($timeframe === 'year' && $years !== '' && is_numeric($years)) {
        $yearsnumeric = (float)$years;
        $yearsphrase = " Estimated duration: roughly {$yearsnumeric} academic year(s).";
    }

    $prompt = "You are an instructional coach designing a pacing roadmap for {$scopephrase} \"{$outline['coursefullname']}\".\n";
    if (!empty($gradelevel)) {
        $prompt .= "Learners are in {$gradelevel}. Use language, expectations, and examples that are age-appropriate for this grade level.\n";
    }
    $prompt .= "Create a professional pacing guide for {$timeframelabels[$timeframe]}.{$hoursphrase}{$weeksphrase}{$yearsphrase}\n";
    if (!empty($notes)) {
        $prompt .= "The teacher added the following notes or preferences. Incorporate these where appropriate: \"{$notes}\"\n";
    }
    $prompt .= "The guide must be actionable, balanced, and scaffolded for student mastery.\n\n";

    $prompt .= "Available learning resources (titles, descriptions, and activity types) are below. Use these to infer the key ideas that must be taught.\n";
    $prompt .= "Do NOT list the actual activity titles in the final guide. Instead, translate them into general learning experiences (e.g., \"interactive simulation\", \"short reading\", \"guided practice\").\n\n";

    foreach ($outline['sections'] as $section) {
        $prompt .= "### Section: {$section['name']}\n";
        if (!empty($section['summary'])) {
            $prompt .= "Section overview: {$section['summary']}\n";
        }
        if (!empty($section['activities'])) {
            $prompt .= "Learning assets:\n";
            foreach ($section['activities'] as $activity) {
                $prompt .= "- {$activity['type']}: {$activity['description']}\n";
            }
        }
        $prompt .= "\n";
    }

    $prompt .= "Your output must be valid HTML, starting with a single <table> element. Never use Markdown code fences or backticks anywhere in the response. use mins if the hours are less than 1 hour\n";
    $prompt .= "Use this structure exactly:\n";
    $prompt .= "<table>\n";
    $prompt .= "  <thead><tr><th>Segment</th><th>Learning Focus</th><th>Experiences & Resources</th><th>Practice & Assessment</th><th>Teacher Moves & Notes</th><th>Hours</th></tr></thead>\n";
    $prompt .= "  <tbody>...generated rows...</tbody>\n";
    $prompt .= "</table>\n";
    $prompt .= "Each row's Segment should align with the chosen timeframe (e.g., Week 1, Week 2, or Day Part 1). Cover the entire timeframe logically.\n";
    if ($timeframe === 'year') {
        $prompt .= "When the timeframe spans a year, create one row per instructional week. Summarise the entire week's focus, experiences, assessments, and teacher moves instead of day-level detail.\n";
        $prompt .= "The Segment column must follow this exact pattern: Month [number] - Week [number]: [short weekly title]. Example rows:\n";
        $prompt .= "<table><tbody><tr><td>Month 1 - Week 1: Launching the Year</td><td>...</td></tr><tr><td>Month 1 - Week 2: Community Norms</td><td>...</td></tr></tbody></table>\n";
        $prompt .= "Do not include day numbers in the Segment label when building a year-long arc.\n";
    } elseif ($timeframe === 'month' || $timeframe === 'week') {
        $prompt .= "When the timeframe is multi-week or weekly, every segment label MUST follow this exact pattern: Week [number] - Day [number]: [short title]. Example rows:\n";
        $prompt .= "<table><tbody><tr><td>Week 1 - Day 1: Launch Lesson</td><td>...</td></tr><tr><td>Week 1 - Day 2: Guided Practice</td><td>...</td></tr></tbody></table>\n";
        $prompt .= "Do not vary from this structure.\n";
    } else {
        $prompt .= "For single-day guides, you may use \"Day [number]: [short title]\".\n";
    }
    if ($hoursnumeric !== null) {
        $prompt .= "Distribute approximately {$hoursnumeric} total instructional hours across the rows. The Hours column must include numeric durations (e.g., 1.5h, 45m) that add up closely to {$hoursnumeric}h overall.\n";
    } else {
        $prompt .= "Provide an estimate in the Hours column for each row (e.g., 90m, 2h) even if total time is unspecified; keep duration realistic for the segment length.\n";
    }
    if ($weeksnumeric !== null) {
        $prompt .= "Structure the roadmap to span about {$weeksnumeric} week(s). Make sure segment titles or ordering convey progression across those weeks.\n";
    }
    if ($yearsnumeric !== null) {
        $prompt .= "Ensure the pacing reflects roughly {$yearsnumeric} academic year(s), acknowledging natural breaks or terms if helpful.\n";
    }
    $prompt .= "Follow the table with one or two <p> or <ul> blocks summarising enrichment ideas, differentiation strategies, and data checks.\n";
    $prompt .= "Focus on concept mastery, cumulative practice, and formative/summative assessment rhythm.\n";
    $prompt .= "Never include raw HTML from the course or mention Moodle-specific terminology.\n";

    return $prompt;
}

/**
 * Call Gemini API and return response.
 *
 * @param string $prompt
 * @return array
 */
function call_gemini_api(string $prompt): array {
    global $CFG;

    $apikey = get_config('local_aiassistant', 'apikey');
    if (empty($apikey)) {
        return [
            'success' => false,
            'message' => get_string('missingapikey', 'local_aiassistant'),
        ];
    }

    $model = get_config('local_aiassistant', 'model') ?: 'gemini-2.0-flash-exp';
    $url = "https://generativelanguage.googleapis.com/v1/models/{$model}:generateContent?key={$apikey}";

    $payload = json_encode([
        'contents' => [[
            'parts' => [[
                'text' => $prompt,
            ]],
        ]],
        'generationConfig' => [
            'temperature' => 0.4,
            'maxOutputTokens' => 2048,
        ],
    ]);

    try {
        $curl = new curl([
            'CURLOPT_TIMEOUT' => 40,
            'CURLOPT_CONNECTTIMEOUT' => 10,
        ]);
        $curl->setHeader(['Content-Type: application/json']);
        $response = $curl->post($url, $payload);

        if ($curl->get_errno()) {
            throw new moodle_exception('curlerror', 'error', '', null, $curl->error);
        }

        $result = json_decode($response, true);

        if (!empty($result['candidates'][0]['content']['parts'][0]['text'])) {
            return [
        'success' => true,
        'content' => normalize_ai_html($result['candidates'][0]['content']['parts'][0]['text']),
        'statusmessage' => 'Pacing guide generated ' . userdate(time()),
            ];
        }

        if (!empty($result['error']['message'])) {
            throw new moodle_exception('aigenerationfailed', 'theme_remui_kids', '', null, $result['error']['message']);
        }

        throw new moodle_exception('aigenerationfailed', 'theme_remui_kids');
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => get_string('errorprocessingrequest', 'error', $e->getMessage()),
        ];
    }
}

/**
 * Map Moodle module type to a friendly instructional asset label.
 *
 * @param string $modname
 * @return string
 */
function map_mod_type(string $modname): string {
    $map = [
        'assign' => 'applied assignment',
        'quiz' => 'quiz or check-in',
        'scorm' => 'interactive module',
        'book' => 'multi-page reading',
        'page' => 'short reading',
        'resource' => 'downloadable resource',
        'h5pactivity' => 'interactive activity',
        'forum' => 'discussion prompt',
        'url' => 'external link',
        'lesson' => 'guided lesson',
        'workshop' => 'peer feedback task',
        'glossary' => 'class glossary',
        'data' => 'data collection activity',
        'videotime' => 'video lesson',
    ];

    return $map[strtolower($modname)] ?? ($modname . ' activity');
}

/**
 * Normalize raw AI HTML response.
 *
 * @param string $raw
 * @return string
 */
function normalize_ai_html(string $raw): string {
    $clean = trim($raw);
    $clean = preg_replace('/```(?:html)?/i', '', $clean);
    $clean = str_replace('```', '', $clean);
    return trim($clean);
}

/**
 * Clean section summary text.
 *
 * @param string $text
 * @param int $format
 * @return string
 */
function clean_summary_text(string $text, int $format = FORMAT_HTML): string {
    if ($text === '') {
        return '';
    }
    $formatted = format_text($text, $format, ['filter' => false, 'trusted' => false]);
    $stripped = trim(preg_replace('/\s+/', ' ', html_to_text($formatted, 0)));
    return shorten_text($stripped, 420);
}

/**
 * Clean and shorten activity descriptions.
 *
 * @param cm_info $cm
 * @return string
 */
function clean_activity_description(cm_info $cm): string {
    $description = '';

    try {
        $description = $cm->get_formatted_content(['overflowdiv' => false]);
    } catch (Throwable $e) {
        if (!empty($cm->content)) {
            $description = $cm->content;
        }
    }

    if ($description === '') {
        return shorten_text(format_string($cm->name), 120);
    }

    $text = trim(preg_replace('/\s+/', ' ', html_to_text($description, 0)));
    return shorten_text($text, 320);
}

