<?php
define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/local/aiassistant/classes/gemini_api.php');
require_once($CFG->dirroot . '/theme/remui_kids/teacher/includes/ai_helpers.php');
require_once($CFG->libdir . '/pdflib.php');

require_login();

$action = required_param('action', PARAM_ALPHA);
require_sesskey();

$systemcontext = context_system::instance();
if (!has_capability('moodle/course:update', $systemcontext) && !has_capability('moodle/site:config', $systemcontext)) {
    throw new required_capability_exception($systemcontext, 'moodle/course:update', 'nopermissions', '');
}

header('Content-Type: application/json');

try {
    switch ($action) {
        case 'courses':
            echo json_encode([
                'success' => true,
                'courses' => theme_remui_kids_lessonplan_get_courses($USER->id)
            ]);
            break;

        case 'lessons':
            $courseid = required_param('courseid', PARAM_INT);
            echo json_encode(theme_remui_kids_lessonplan_get_lessons($courseid, $USER->id));
            break;

        case 'generate':
            $courseid = required_param('courseid', PARAM_INT);
            $plantype = optional_param('plantype', 'lesson', PARAM_ALPHA);
            $lessonid = optional_param('lessonid', 0, PARAM_INT);
            $lessonname = optional_param('lessonname', '', PARAM_TEXT);
            $unitname = optional_param('unitname', '', PARAM_TEXT);
            echo json_encode(theme_remui_kids_lessonplan_generate_plan($courseid, $lessonid, $lessonname, $unitname, $plantype, $USER->id));
            break;

        case 'lessoncontext':
            $courseid = required_param('courseid', PARAM_INT);
            $lessonid = required_param('lessonid', PARAM_INT);
            $course = get_course($courseid);
            $coursecontext = context_course::instance($courseid);
            require_capability('moodle/course:update', $coursecontext);
            $contextdata = theme_remui_kids_lessonplan_build_context($course, $lessonid);
            echo json_encode([
                'success' => true,
                'context' => $contextdata
            ]);
            break;

        case 'downloadpdf':
            $plan = required_param('plan', PARAM_RAW);
            echo json_encode(theme_remui_kids_lessonplan_download_pdf($plan));
            break;

        case 'savelesson':
            $courseid = required_param('courseid', PARAM_INT);
            $lessonid = optional_param('lessonid', 0, PARAM_INT);
            $lessonname = required_param('lessonname', PARAM_TEXT);
            $planjson = required_param('plan', PARAM_RAW);
            echo json_encode(theme_remui_kids_lessonplan_save_plan($USER->id, $courseid, $lessonid, $lessonname, $planjson, 'lesson'));
            break;

        case 'savecourseplan':
            $courseid = required_param('courseid', PARAM_INT);
            $lessonid = optional_param('lessonid', 0, PARAM_INT);
            $lessonname = required_param('lessonname', PARAM_TEXT);
            $planjson = required_param('plan', PARAM_RAW);
            echo json_encode(theme_remui_kids_lessonplan_save_plan($USER->id, $courseid, $lessonid, $lessonname, $planjson, 'course'));
            break;

        case 'getsaved':
            echo json_encode(theme_remui_kids_lessonplan_get_saved_plans($USER->id));
            break;

        case 'deletelesson':
            $planid = required_param('planid', PARAM_INT);
            echo json_encode(theme_remui_kids_lessonplan_delete_plan($USER->id, $planid));
            break;

        case 'generatecourseplan':
            $courseid = required_param('courseid', PARAM_INT);
            echo json_encode(theme_remui_kids_lessonplan_generate_course_plan($courseid, $USER->id));
            break;

        default:
            throw new moodle_exception('invalidaction', 'error');
    }
} catch (moodle_exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

/**
 * Fetch courses where the user has a teaching role.
 *
 * @param int $userid
 * @return array
 */
function theme_remui_kids_lessonplan_get_courses(int $userid): array {
    global $DB;

    $sql = "SELECT DISTINCT c.id, c.fullname, c.shortname
              FROM {course} c
              JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = ?
              JOIN {role_assignments} ra ON ra.contextid = ctx.id
              JOIN {role} r ON r.id = ra.roleid
             WHERE ra.userid = ?
               AND r.shortname IN ('teacher', 'editingteacher', 'manager')
               AND c.id > 1
          ORDER BY c.fullname ASC";

    $records = $DB->get_records_sql($sql, [CONTEXT_COURSE, $userid]);
    $courses = [];
    foreach ($records as $record) {
        $courses[] = [
            'id' => (int)$record->id,
            'fullname' => format_string($record->fullname),
            'shortname' => format_string($record->shortname)
        ];
    }

    return $courses;
}

/**
 * Fetch lessons/topics for the selected course.
 *
 * @param int $courseid
 * @param int $userid
 * @return array
 */
function theme_remui_kids_lessonplan_get_lessons(int $courseid, int $userid): array {
    global $DB;

    $course = get_course($courseid);
    $coursecontext = context_course::instance($courseid);
    require_capability('moodle/course:update', $coursecontext);

    $lessons = [];
    $lessonmoduleid = $DB->get_field('modules', 'id', ['name' => 'lesson'], IGNORE_MISSING);

    if ($lessonmoduleid) {
        $sql = "SELECT l.id, l.name
                  FROM {course_modules} cm
             LEFT JOIN {lesson} l ON l.id = cm.instance
                 WHERE cm.course = ?
                   AND cm.module = ?
                   AND cm.deletioninprogress = 0
                   AND l.id IS NOT NULL
              ORDER BY l.name ASC";
        $records = $DB->get_records_sql($sql, [$courseid, $lessonmoduleid]);
        foreach ($records as $record) {
            $lessons[] = [
                'id' => (int)$record->id,
                'name' => format_string($record->name),
                'type' => 'lesson'
            ];
        }
    }

    if (empty($lessons)) {
        // Fallback to course sections/topics.
        $sections = $DB->get_records_select(
            'course_sections',
            'course = ? AND (component IS NULL OR component = ?)',
            [$courseid, ''],
            'section ASC',
            'id, name, section, summary'
        );
        foreach ($sections as $section) {
            if ((int)$section->section === 0) {
                continue;
            }
            $name = trim($section->name);
            if ($name === '') {
                $name = get_string('topic') . ' ' . $section->section;
            }
            $lessons[] = [
                'id' => (int)$section->id,
                'name' => format_string($name),
                'type' => 'topic'
            ];
        }
    }

    if (empty($lessons)) {
        return [
            'success' => false,
            'message' => get_string('nothingtodisplay')
        ];
    }

    return [
        'success' => true,
        'lessons' => $lessons
    ];
}

/**
 * Generate lesson plan via AI.
 *
 * @param int $courseid
 * @param int $lessonid
 * @param string $lessonname
 * @param int $userid
 * @return array
 */
function theme_remui_kids_lessonplan_generate_plan(int $courseid, int $lessonid = 0, string $lessonname = '', string $unitname = '', string $plantype = 'lesson', int $userid = 0): array {
    global $DB;

    $course = get_course($courseid);
    $coursecontext = context_course::instance($courseid);
    require_capability('moodle/course:update', $coursecontext);

    $plantype = $plantype === 'unit' ? 'unit' : 'lesson';

    if ($plantype === 'lesson' && $lessonid && $lessonname === '') {
        $record = $DB->get_record('lesson', ['id' => $lessonid], 'id, name', IGNORE_MISSING);
        if ($record) {
            $lessonname = format_string($record->name);
        } else {
            $section = $DB->get_record('course_sections', ['id' => $lessonid, 'course' => $courseid], 'id, name, section');
            if ($section) {
                $lessonname = $section->name ? format_string($section->name) : get_string('topic') . ' ' . $section->section;
            }
        }
    }

    if ($plantype === 'lesson' && $lessonname === '') {
        $lessonname = get_string('lesson') . ' ' . userdate(time(), '%d %b %Y');
    }
    $lessoncontextdata = null;
    if ($plantype === 'unit') {
        $unitname = trim($unitname);
        if ($unitname === '') {
            $unitname = get_string('unit') . ' ' . userdate(time(), '%d %b %Y');
        }
    } else {
        if ($lessonid) {
            $lessoncontextdata = theme_remui_kids_lessonplan_build_context($course, $lessonid);
        }
    }

    $coursename = format_string($course->fullname);

    if ($plantype === 'unit') {
        $prompt = "Generate a detailed JSON unit planner for the following context.\n\n" .
            "Course: {$coursename}\nUnit or Theme: {$unitname}\n\n" .
            "Return ONLY valid JSON with the following keys:\n" .
            "{\n" .
            "  \"overview\": \"Short paragraph\",\n" .
            "  \"learningobjectives\": [\"objective\"],\n" .
            "  \"keyconcepts\": [\"concept\"],\n" .
            "  \"timeline\": [\n" .
            "    {\"week\": \"Week #\", \"focus\": \"summary\", \"activities\": [\"activity\"]}\n" .
            "  ],\n" .
            "  \"activities\": [\"activity\"],\n" .
            "  \"resources\": [\"resource\"],\n" .
            "  \"assessments\": [\"assessment\"],\n" .
            "  \"differentiation\": [\"support\"],\n" .
            "  \"duration\": \"e.g. 4 weeks\"\n" .
            "}\n\n" .
            "Be specific and classroom-ready.";
    } else {
        $contextnotes = '';
        $structuredactivities = [];
        if ($lessoncontextdata) {
            if (!empty($lessoncontextdata['sectiontitle'])) {
                $contextnotes .= "Lesson/topic title: {$lessoncontextdata['sectiontitle']}\n";
            }
            if (!empty($lessoncontextdata['sectionsummary'])) {
                $contextnotes .= "Topic summary: {$lessoncontextdata['sectionsummary']}\n";
            }
            if (!empty($lessoncontextdata['activities'])) {
                $contextnotes .= "Existing activities and modules inside this lesson:\n";
                foreach ($lessoncontextdata['activities'] as $activity) {
                    $contextnotes .= "- {$activity['type']}: {$activity['name']}\n";
                }
            }
            if (!empty($lessoncontextdata['modules'])) {
                $contextnotes .= "\nStructured module list:\n";
                foreach ($lessoncontextdata['modules'] as $module) {
                    $contextnotes .= "Module: {$module['name']}\n";
                    if (!empty($module['activities'])) {
                        foreach ($module['activities'] as $activity) {
                            $contextnotes .= "  • Activity: {$activity['name']} ({$activity['type']})\n";
                        }
                    } else {
                        $contextnotes .= "  • Activity: (none listed)\n";
                    }
                }
                $structuredactivities = $lessoncontextdata['modules'];
            }
        }

        $activityguidance = '';
        if (!empty($structuredactivities)) {
            $activityguidance .= "Use ONLY the provided module and activity names in the table.\n";
            $activityguidance .= "Module/Activity reference:\n";
            foreach ($structuredactivities as $module) {
                $activityguidance .= "- Module: {$module['name']}\n";
                if (!empty($module['activities'])) {
                    foreach ($module['activities'] as $activity) {
                        $activityguidance .= "    Activity: {$activity['name']}\n";
                    }
                }
            }
        }

        $prompt = "Generate a detailed JSON lesson plan for the following information.\n\n" .
            "Course: {$coursename}\nLesson/Topic: {$lessonname}\n" .
            ($contextnotes ? "\n{$contextnotes}\n" : "\n") .
            ($activityguidance ? "\n{$activityguidance}\n" : "") .
            "Write every section as teacher-facing guidance (e.g., 'Ask students to...', 'Guide learners to...'). Avoid congratulating students; instead tell the teacher what to do. Do not use 'N/A'; always provide a meaningful entry.\n" .
            "The lesson plan must be teacher-ready and directly reference the listed activities. For each activity, craft the listed objectives and supports, including a specific Social and Emotional Learning objective every time. If the provided context includes standards or competencies, incorporate them directly.\n" .
            "Return ONLY valid JSON with the following structure:\n" .
            "{\n" .
            "  \"unitopener\": {\"title\": \"Hook/Opener\", \"description\": \"short paragraph\"},\n" .
            "  \"probe\": {\"title\": \"Formative check\", \"prompts\": [\"question\"]},\n" .
            "  \"rows\": [\n" .
            "    {\n" .
            "      \"module\": \"Module name (must match provided names)\",\n" .
            "      \"activity\": \"Activity name (must match provided names)\",\n" .
            "      \"objective\": \"learning objective\",\n" .
            "      \"languageObjective\": \"language objective\",\n" .
            "      \"selObjective\": \"social & emotional learning objective\",\n" .
            "      \"keyVocabulary\": [\"word\"],\n" .
            "      \"materials\": [\"material\"],\n" .
            "      \"rigorFocus\": \"how the task pushes thinking\",\n" .
            "      \"standards\": [\"standard reference\"]\n" .
            "    }\n" .
            "  ],\n" .
            "  \"lessonreview\": {\"title\": \"closure\", \"description\": \"summary\"},\n" .
            "  \"lessonassessment\": {\"title\": \"assessment\", \"description\": \"assessment approach\"},\n" .
            "  \"teachersuggestions\": {\n" .
            "    \"activitySuggestions\": [\"specific activity idea with implementation guidance\"],\n" .
            "    \"assessmentSuggestions\": [\"assessment method with how to implement and manage it\"],\n" .
            "    \"assignmentSuggestions\": [\"assignment idea with creation and management instructions\"]\n" .
            "  }\n" .
            "}\n\n" .
            "Ensure the JSON strictly follows the schema and avoids any additional commentary. The teachersuggestions section should provide practical, actionable guidance for implementing activities, assessments, and assignments.";
    }

    $response = \local_aiassistant\gemini_api::send_message($prompt, '');
    if (empty($response['success'])) {
        return [
            'success' => false,
            'message' => $response['reply'] ?? get_string('errorunexpected', 'error')
        ];
    }

    $reply = trim($response['reply']);
    $json = remui_kids_extract_first_json($reply);
    if (!$json) {
        $json = $reply;
    }

    $plan = json_decode($json, true);
    if (!is_array($plan)) {
        $plan = [];
    }

    if ($plantype === 'unit') {
        $normalized = [
            'type' => 'unit',
            'coursename' => $coursename,
            'unitname' => clean_param($unitname, PARAM_TEXT),
            'overview' => clean_param($plan['overview'] ?? $plan['summary'] ?? '', PARAM_TEXT),
            'learningobjectives' => theme_remui_kids_lessonplan_normalize_list($plan, ['learningobjectives', 'objectives']),
            'keyconcepts' => theme_remui_kids_lessonplan_normalize_list($plan, ['keyconcepts', 'concepts']),
            'timeline' => theme_remui_kids_lessonplan_normalize_timeline($plan),
            'activities' => theme_remui_kids_lessonplan_normalize_list($plan, ['activities', 'studentactivities']),
            'resources' => theme_remui_kids_lessonplan_normalize_list($plan, ['resources', 'materials']),
            'assessments' => theme_remui_kids_lessonplan_normalize_list($plan, ['assessments', 'assessment']),
            'differentiation' => theme_remui_kids_lessonplan_normalize_list($plan, ['differentiation', 'support']),
            'duration' => clean_param($plan['duration'] ?? $plan['timeframe'] ?? '', PARAM_TEXT)
        ];
    } else {
        $normalized = theme_remui_kids_lessonplan_transform_structured_plan($plan, $coursename, $lessonname);
        if (!empty($lessoncontextdata)) {
            $normalized['rows'] = theme_remui_kids_lessonplan_merge_context_standards($normalized['rows'], $lessoncontextdata);
        }
    }

    return [
        'success' => true,
        'plan' => $normalized
    ];
}

/**
 * Normalize list-like fields.
 *
 * @param array $plan
 * @param array $keys
 * @return array
 */
function theme_remui_kids_lessonplan_normalize_list(array $plan, array $keys): array {
    foreach ($keys as $key) {
        if (!isset($plan[$key])) {
            continue;
        }
        $value = $plan[$key];
        if (is_string($value)) {
            $value = [clean_param($value, PARAM_TEXT)];
        } else if (is_array($value)) {
            $temp = [];
            foreach ($value as $entry) {
                if (is_string($entry)) {
                    $temp[] = clean_param($entry, PARAM_TEXT);
                } else if (is_array($entry)) {
                    $temp[] = clean_param(implode(' - ', $entry), PARAM_TEXT);
                }
            }
            $value = $temp;
        } else {
            $value = [];
        }
        return array_filter($value, static function($item) {
            return $item !== '';
        });
    }
    return [];
}

/**
 * Normalize timeline blocks for unit planners.
 *
 * @param array $plan
 * @return array
 */
function theme_remui_kids_lessonplan_normalize_timeline(array $plan): array {
    $timeline = [];
    $candidates = $plan['timeline'] ?? $plan['sequence'] ?? $plan['weeks'] ?? [];

    if (!is_array($candidates)) {
        return [];
    }

    $index = 1;
    foreach ($candidates as $block) {
        if (is_string($block)) {
            $timeline[] = [
                'week' => 'Segment ' . $index,
                'focus' => clean_param($block, PARAM_TEXT),
                'activities' => []
            ];
            $index++;
            continue;
        }

        if (!is_array($block)) {
            continue;
        }

        $label = clean_param($block['week'] ?? $block['phase'] ?? $block['title'] ?? 'Segment ' . $index, PARAM_TEXT);
        $focus = clean_param($block['focus'] ?? $block['summary'] ?? '', PARAM_TEXT);
        $activities = theme_remui_kids_lessonplan_normalize_list($block, ['activities', 'tasks', 'experiences']);

        $timeline[] = [
            'week' => $label,
            'focus' => $focus,
            'activities' => $activities
        ];
        $index++;
    }

    return $timeline;
}

/**
 * Build contextual information for a selected lesson/topic, including existing activities.
 *
 * @param stdClass $course
 * @param int $lessonid
 * @return array|null
 */
function theme_remui_kids_lessonplan_build_context(stdClass $course, int $lessonid): ?array {
    global $DB;

    if (empty($lessonid)) {
        return null;
    }

    $modinfo = get_fast_modinfo($course);
    $sectionrecord = null;

    // Try to map lesson module to its section.
    $lessonmoduleid = $DB->get_field('modules', 'id', ['name' => 'lesson'], IGNORE_MISSING);
    if ($lessonmoduleid) {
        $sectionid = $DB->get_field('course_modules', 'section', [
            'course' => $course->id,
            'module' => $lessonmoduleid,
            'instance' => $lessonid
        ], IGNORE_MISSING);
        if ($sectionid) {
            $sectionrecord = $DB->get_record('course_sections', ['id' => $sectionid], '*', IGNORE_MISSING);
        }
    }

    if (!$sectionrecord) {
        $sectionrecord = $DB->get_record('course_sections', ['id' => $lessonid, 'course' => $course->id], '*', IGNORE_MISSING);
    }

    if (!$sectionrecord) {
        return null;
    }

    $sectiontitle = $sectionrecord->name ? format_string($sectionrecord->name) :
        get_string('topic') . ' ' . $sectionrecord->section;
    $sectionsummary = '';
    if (!empty($sectionrecord->summary)) {
        $sectionsummary = trim(clean_text($sectionrecord->summary, $sectionrecord->summaryformat));
    }

    $sectionnumber = (int)$sectionrecord->section;
    $modules = [];
    $activities = [];
    $flatlimit = 18;

    if (isset($modinfo->sections[$sectionnumber])) {
        foreach ($modinfo->sections[$sectionnumber] as $cmid) {
            if (!isset($modinfo->cms[$cmid])) {
                continue;
            }
            $cm = $modinfo->cms[$cmid];
            if (!$cm->uservisible) {
                continue;
            }

            if ($cm->modname === 'subsection') {
                $subsectionsection = $DB->get_record('course_sections', [
                    'component' => 'mod_subsection',
                    'itemid' => $cm->instance
                ], '*', IGNORE_MISSING);

                $moduleactivities = theme_remui_kids_lessonplan_collect_subsection_activities(
                    $subsectionsection,
                    $modinfo,
                    $activities,
                    $flatlimit
                );

                $modules[] = [
                    'id' => $subsectionsection->id ?? $cm->id,
                    'cmid' => $cm->id,
                    'type' => 'subsection',
                    'name' => $subsectionsection && $subsectionsection->name ?
                        format_string($subsectionsection->name) : format_string($cm->name),
                    'summary' => theme_remui_kids_lessonplan_clean_summary($subsectionsection),
                    'activities' => $moduleactivities
                ];
                continue;
            }

            $activitydata = theme_remui_kids_lessonplan_format_activity($cm);
            theme_remui_kids_lessonplan_append_flat_activity($activities, $activitydata, $flatlimit);

            $modules[] = [
                'id' => $cm->id,
                'cmid' => $cm->id,
                'type' => $cm->modname,
                'name' => $activitydata['name'],
                'summary' => $activitydata['summary'],
                'activities' => [$activitydata]
            ];
        }
    }

    return [
        'sectiontitle' => $sectiontitle,
        'sectionsummary' => $sectionsummary,
        'activities' => $activities,
        'modules' => $modules
    ];
}

/**
 * Create downloadable PDF for the provided plan.
 *
 * @param string $planjson
 * @return array
 */
function theme_remui_kids_lessonplan_download_pdf(string $planjson): array {
    $plan = json_decode($planjson, true);
    if (!is_array($plan)) {
        return [
            'success' => false,
            'message' => get_string('invaliddata', 'error')
        ];
    }

    $pdf = new pdf();
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->AddPage();
    $pdf->SetFont('helvetica', '', 11);

    $type = $plan['type'] ?? 'lesson';
    $html = '<h2>' . ($type === 'unit' ? 'Unit Planner' : 'Lesson Plan') . '</h2>';
    $html .= '<p><strong>Course:</strong> ' . format_string($plan['coursename'] ?? '') . '<br />';
    if ($type === 'unit') {
        $html .= '<strong>Unit:</strong> ' . format_string($plan['unitname'] ?? '') . '</p>';
        $rows = [
            'Unit Overview' => [$plan['overview'] ?? ''],
            'Learning Objectives' => $plan['learningobjectives'] ?? [],
            'Key Concepts' => $plan['keyconcepts'] ?? [],
            'Timeline & Lesson Sequence' => $plan['timeline'] ?? [],
            'Learning Activities' => $plan['activities'] ?? [],
            'Resources & Materials' => $plan['resources'] ?? [],
            'Assessments' => $plan['assessments'] ?? [],
            'Differentiation & Support' => $plan['differentiation'] ?? [],
            'Estimated Duration' => [$plan['duration'] ?? '']
        ];
    } else {
        $html .= '<strong>Lesson:</strong> ' . format_string($plan['lessonname'] ?? '') . '</p>';
        $html .= theme_remui_kids_lessonplan_render_pdf_cards($plan);
        $rows = $plan['rows'] ?? [];

        if (!empty($rows)) {
            $html .= '<h3>Lesson Activity Planner</h3>';
            $html .= '<table border="1" cellpadding="6" cellspacing="0" width="100%">';
            $html .= '<thead><tr>'
                . '<th width="18%">Module & Activity</th>'
                . '<th width="16%">Objective</th>'
                . '<th width="16%">Language Objective</th>'
                . '<th width="16%">Social and Emotional Learning Objective</th>'
                . '<th width="12%">Key Vocabulary</th>'
                . '<th width="12%">Materials</th>'
                . '<th width="10%">Rigor Focus</th>'
                . '<th width="10%">Standards</th>'
                . '</tr></thead><tbody>';
            foreach ($rows as $row) {
                $html .= '<tr>';
                $html .= '<td><strong>' . format_string($row['module'] ?? '') . '</strong><br />'
                    . format_string($row['activity'] ?? '') . '</td>';
                $html .= '<td>' . s($row['objective'] ?? '') . '</td>';
                $html .= '<td>' . s($row['languageobjective'] ?? '') . '</td>';
                $html .= '<td>' . s($row['selobjective'] ?? '') . '</td>';
                $html .= '<td>' . theme_remui_kids_lessonplan_render_pdf_list($row['keyvocabulary'] ?? []) . '</td>';
                $html .= '<td>' . theme_remui_kids_lessonplan_render_pdf_list($row['materials'] ?? []) . '</td>';
                $html .= '<td>' . s($row['rigorfocus'] ?? '') . '</td>';
                $html .= '<td>' . theme_remui_kids_lessonplan_render_pdf_list($row['standards'] ?? []) . '</td>';
                $html .= '</tr>';
            }
            $html .= '</tbody></table>';
        }
        $rows = [];
    }

    $html .= '<table border="1" cellpadding="6" cellspacing="0" width="100%">';
    foreach ($rows as $label => $items) {
        $html .= '<tr><td width="30%"><strong>' . $label . '</strong></td><td width="70%">';
        if ($label === 'Timeline & Lesson Sequence') {
            if (empty($items)) {
                $html .= 'Not provided.';
            } else {
                $html .= '<ol style="margin:0; padding-left:16px;">';
                foreach ($items as $block) {
                    if (!is_array($block)) {
                        continue;
                    }
                    $html .= '<li><strong>' . s($block['week'] ?? '') . '</strong>';
                    if (!empty($block['focus'])) {
                        $html .= '<div>' . s($block['focus']) . '</div>';
                    }
                    if (!empty($block['activities'])) {
                        $html .= '<ul>';
                        foreach ($block['activities'] as $activity) {
                            $html .= '<li>' . s($activity) . '</li>';
                        }
                        $html .= '</ul>';
                    }
                    $html .= '</li>';
                }
                $html .= '</ol>';
            }
        } else if (empty($items)) {
            $html .= 'Not provided.';
        } else {
            $html .= '<ul style="margin:0; padding-left:14px;">';
            foreach ($items as $item) {
                $html .= '<li>' . s($item) . '</li>';
            }
            $html .= '</ul>';
        }
        $html .= '</td></tr>';
    }
    $html .= '</table>';

    $pdf->writeHTML($html, true, false, true, false, '');
    $content = $pdf->Output('lessonplan.pdf', 'S');

    return [
        'success' => true,
        'filename' => 'lesson-plan-' . userdate(time(), '%Y%m%d-%H%M') . '.pdf',
        'filedata' => base64_encode($content)
    ];
}

/**
 * Transform structured AI response into normalized lesson plan.
 *
 * @param array $plan
 * @param string $coursename
 * @param string $lessonname
 * @return array
 */
function theme_remui_kids_lessonplan_transform_structured_plan(array $plan, string $coursename, string $lessonname): array {
    $rows = [];
    $planrows = [];
    if (isset($plan['rows']) && is_array($plan['rows'])) {
        $planrows = $plan['rows'];
    } else if (isset($plan['table']) && is_array($plan['table'])) {
        $planrows = $plan['table'];
    }

    foreach ($planrows as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $rows[] = [
            'module' => clean_param($entry['module'] ?? '', PARAM_TEXT),
            'activity' => clean_param($entry['activity'] ?? '', PARAM_TEXT),
            'objective' => clean_param($entry['objective'] ?? '', PARAM_TEXT),
            'languageobjective' => clean_param($entry['languageObjective'] ?? $entry['languageobjective'] ?? '', PARAM_TEXT),
            'selobjective' => clean_param($entry['selObjective'] ?? $entry['selobjective'] ?? '', PARAM_TEXT),
            'keyvocabulary' => theme_remui_kids_lessonplan_normalize_list($entry, ['keyVocabulary', 'vocabulary', 'keyvocabulary']),
            'materials' => theme_remui_kids_lessonplan_normalize_list($entry, ['materials', 'resources']),
            'rigorfocus' => clean_param($entry['rigorFocus'] ?? $entry['rigorfocus'] ?? '', PARAM_TEXT),
            'standards' => theme_remui_kids_lessonplan_normalize_list($entry, ['standards', 'standard'])
        ];
    }

    return [
        'type' => 'lesson',
        'coursename' => $coursename,
        'lessonname' => $lessonname,
        'unitopener' => theme_remui_kids_lessonplan_normalize_block($plan['unitopener'] ?? null, 'Lesson Opener'),
        'probe' => theme_remui_kids_lessonplan_normalize_block($plan['probe'] ?? null, 'Mid-lesson Probe', ['prompts', 'questions']),
        'lessonreview' => theme_remui_kids_lessonplan_normalize_block($plan['lessonreview'] ?? null, 'Lesson Review', ['steps', 'questions']),
        'lessonassessment' => theme_remui_kids_lessonplan_normalize_block($plan['lessonassessment'] ?? null, 'Lesson Assessment', ['methods', 'criteria']),
        'teachersuggestions' => theme_remui_kids_lessonplan_normalize_teacher_suggestions($plan['teachersuggestions'] ?? null),
        'rows' => $rows
    ];
}

/**
 * Normalize a block that may include title, description, and items.
 *
 * @param array|null $block
 * @param string $fallbacktitle
 * @param array $listkeys
 * @return array
 */
function theme_remui_kids_lessonplan_normalize_block(?array $block, string $fallbacktitle = '', array $listkeys = []): array {
    if (!is_array($block)) {
        $block = [];
    }
    $title = trim((string)($block['title'] ?? ''));
    $description = trim((string)($block['description'] ?? $block['summary'] ?? ''));
    $items = $listkeys ? theme_remui_kids_lessonplan_normalize_list($block, $listkeys) : [];

    if ($title === '' && $description === '' && empty($items)) {
        return [];
    }

    return [
        'title' => clean_param($title !== '' ? $title : $fallbacktitle, PARAM_TEXT),
        'description' => clean_param($description, PARAM_TEXT),
        'items' => $items
    ];
}

/**
 * Normalize teacher suggestions structure.
 *
 * @param array|null $suggestions
 * @return array
 */
function theme_remui_kids_lessonplan_normalize_teacher_suggestions(?array $suggestions): array {
    if (!is_array($suggestions)) {
        return [
            'activitySuggestions' => [],
            'assessmentSuggestions' => [],
            'assignmentSuggestions' => []
        ];
    }

    return [
        'activitySuggestions' => theme_remui_kids_lessonplan_normalize_list($suggestions, ['activitySuggestions', 'activities']),
        'assessmentSuggestions' => theme_remui_kids_lessonplan_normalize_list($suggestions, ['assessmentSuggestions', 'assessments']),
        'assignmentSuggestions' => theme_remui_kids_lessonplan_normalize_list($suggestions, ['assignmentSuggestions', 'assignments'])
    ];
}

/**
 * Render helper for PDF bullet lists.
 *
 * @param array $items
 * @return string
 */
function theme_remui_kids_lessonplan_render_pdf_list(array $items): string {
    if (empty($items)) {
        return 'Not provided.';
    }
    $html = '<ul style="margin:0; padding-left:14px;">';
    foreach ($items as $item) {
        $html .= '<li>' . s($item) . '</li>';
    }
    $html .= '</ul>';
    return $html;
}

/**
 * Render opener/probe/review cards in the PDF.
 *
 * @param array $plan
 * @return string
 */
function theme_remui_kids_lessonplan_render_pdf_cards(array $plan): string {
    $sections = [
        'unitopener' => 'Unit/Lesson Opener',
        'probe' => 'Mid-Lesson Probe',
        'lessonreview' => 'Lesson Review',
        'lessonassessment' => 'Lesson Assessment'
    ];

    $html = '';
    foreach ($sections as $key => $label) {
        if (empty($plan[$key])) {
            continue;
        }
        $block = $plan[$key];
        $html .= '<h3>' . s($label) . '</h3>';
        if (!empty($block['title'])) {
            $html .= '<p><strong>' . s($block['title']) . '</strong></p>';
        }
        if (!empty($block['description'])) {
            $html .= '<p>' . s($block['description']) . '</p>';
        }
        if (!empty($block['items'])) {
            $html .= theme_remui_kids_lessonplan_render_pdf_list($block['items']);
        }
    }
    return $html;
}

/**
 * Fetch competencies linked to a course module.
 *
 * @param int $cmid
 * @return array
 */
function theme_remui_kids_lessonplan_get_competencies_for_cm(int $cmid): array {
    global $DB;

    static $cache = [];
    if (array_key_exists($cmid, $cache)) {
        return $cache[$cmid];
    }

    $records = $DB->get_records_sql(
        "SELECT c.idnumber, c.shortname, c.description
           FROM {competency_modulecomp} cmc
           JOIN {competency} c ON c.id = cmc.competencyid
          WHERE cmc.cmid = ?
       ORDER BY c.idnumber ASC, c.shortname ASC",
        [$cmid]
    );

    $standards = [];
    foreach ($records as $record) {
        $label = trim(($record->idnumber ?? '') . ' ' . ($record->shortname ?? ''));
        if ($label === '') {
            $label = clean_param($record->description ?? '', PARAM_TEXT);
        }
        if ($label !== '') {
            $standards[] = $label;
        }
    }

    $cache[$cmid] = $standards;
    return $standards;
}

/**
 * Merge contextual standards into AI plan rows.
 *
 * @param array $planrows
 * @param array|null $context
 * @return array
 */
function theme_remui_kids_lessonplan_merge_context_standards(array $planrows, ?array $context): array {
    if (empty($context['modules'])) {
        return $planrows;
    }

    $lookup = [];
    foreach ($context['modules'] as $module) {
        if (empty($module['activities'])) {
            continue;
        }
        foreach ($module['activities'] as $activity) {
            $key = core_text::strtolower(trim($activity['name'] ?? ''));
            if ($key === '') {
                continue;
            }
            if (!empty($activity['standards'])) {
                $lookup[$key] = $activity['standards'];
            }
        }
    }

    foreach ($planrows as &$row) {
        $key = core_text::strtolower(trim($row['activity'] ?? ''));
        if ($key !== '' && !empty($lookup[$key])) {
            $row['standards'] = $lookup[$key];
        } else if (!isset($row['standards']) || !is_array($row['standards'])) {
            $row['standards'] = [];
        }
    }

    return $planrows;
}

/**
 * Persist a teacher's lesson plan or course planner.
 *
 * @param int $userid
 * @param int $courseid
 * @param int $lessonid
 * @param string $lessonname
 * @param string $planjson
 * @param string $plantype
 * @return array
 */
function theme_remui_kids_lessonplan_save_plan(int $userid, int $courseid, int $lessonid, string $lessonname, string $planjson, string $plantype = 'lesson'): array {
    global $DB;

    $plan = json_decode($planjson, true);
    if (!is_array($plan)) {
        return [
            'success' => false,
            'message' => get_string('invaliddata', 'error')
        ];
    }

    $course = get_course($courseid);
    $coursecontext = context_course::instance($courseid);
    require_capability('moodle/course:update', $coursecontext);

    // Determine plan type from plan data if not provided
    if ($plantype === 'lesson' && isset($plan['type']) && $plan['type'] === 'course') {
        $plantype = 'course';
    }

    $record = (object)[
        'userid' => $userid,
        'courseid' => $courseid,
        'lessonid' => $lessonid,
        'grade' => theme_remui_kids_lessonplan_resolve_grade_label($course),
        'coursename' => format_string($course->fullname),
        'lessonname' => format_string($lessonname),
        'planjson' => json_encode($plan),
        'timecreated' => time()
    ];

    // Store plan type in the plan JSON for retrieval later
    $plan['plantype'] = $plantype;
    $record->planjson = json_encode($plan);

    $record->id = $DB->insert_record('theme_remui_kids_lessonplans', $record);

    $message = $plantype === 'course' 
        ? 'Course planner saved successfully.'
        : 'Lesson plan saved successfully.';

    return [
        'success' => true,
        'message' => $message,
        'planid' => $record->id
    ];
}

/**
 * Fetch saved plans for a teacher.
 *
 * @param int $userid
 * @return array
 */
function theme_remui_kids_lessonplan_get_saved_plans(int $userid): array {
    global $DB;

    $records = $DB->get_records('theme_remui_kids_lessonplans', ['userid' => $userid], 'timecreated DESC');
    $plans = [];

    foreach ($records as $record) {
        $plan = json_decode($record->planjson, true);
        if (!is_array($plan)) {
            continue;
        }
        // Determine plan type from stored plan data
        $plantype = $plan['plantype'] ?? ($plan['type'] === 'course' ? 'course' : 'lesson');
        $plans[] = [
            'id' => (int)$record->id,
            'courseid' => (int)$record->courseid,
            'lessonid' => (int)$record->lessonid,
            'lessonname' => $record->lessonname,
            'coursename' => $record->coursename,
            'grade' => $record->grade,
            'timecreated' => (int)$record->timecreated,
            'plantype' => $plantype,
            'plan' => $plan
        ];
    }

    return [
        'success' => true,
        'plans' => $plans
    ];
}

/**
 * Delete a saved lesson plan.
 *
 * @param int $userid
 * @param int $planid
 * @return array
 */
function theme_remui_kids_lessonplan_delete_plan(int $userid, int $planid): array {
    global $DB;

    $record = $DB->get_record('theme_remui_kids_lessonplans', ['id' => $planid, 'userid' => $userid], '*', MUST_EXIST);
    
    // Verify the user owns this plan
    if ($record->userid != $userid) {
        return [
            'success' => false,
            'message' => get_string('nopermissions', 'error')
        ];
    }

    $DB->delete_records('theme_remui_kids_lessonplans', ['id' => $planid]);

    return [
        'success' => true,
        'message' => get_string('lessonplan_deleted', 'theme_remui_kids')
    ];
}

/**
 * Resolve grade/level label for a course.
 *
 * @param stdClass $course
 * @return string
 */
function theme_remui_kids_lessonplan_resolve_grade_label(stdClass $course): string {
    global $DB;

    $grade = '';
    if (!empty($course->category)) {
        $category = $DB->get_record('course_categories', ['id' => $course->category], 'id, name', IGNORE_MISSING);
        if ($category && trim($category->name) !== '') {
            $grade = format_string($category->name);
        }
    }

    if ($grade === '') {
        $grade = get_string('lessonplan_grade_unknown', 'theme_remui_kids');
    }

    return $grade;
}

/**
 * Normalize activity type name for display.
 *
 * @param string $modname
 * @return string
 */
function theme_remui_kids_lessonplan_normalize_activity_type(string $modname): string {
    $modname = strtolower($modname);
    $normalized = [
        'edwiservideoactivity' => 'Video Activity',
        'edwiservideo' => 'Video Activity',
        'scorm' => 'SCORM',
        'assign' => 'Assignment',
        'quiz' => 'Quiz',
        'forum' => 'Forum',
        'page' => 'Page',
        'url' => 'URL',
        'resource' => 'Resource',
        'h5pactivity' => 'H5P Activity',
        'workshop' => 'Workshop',
    ];
    return $normalized[$modname] ?? ucfirst($modname);
}

/**
 * Format a course module into a normalized activity array.
 *
 * @param cm_info $cm
 * @return array
 */
function theme_remui_kids_lessonplan_format_activity(cm_info $cm): array {
    $summary = '';
    if (!empty($cm->content)) {
        $summary = trim(clean_text($cm->content, FORMAT_HTML));
    }

    return [
        'id' => $cm->id,
        'cmid' => $cm->id,
        'type' => theme_remui_kids_lessonplan_normalize_activity_type($cm->modname),
        'name' => format_string($cm->name),
        'url' => $cm->url ? $cm->url->out(false) : '',
        'summary' => $summary,
        'standards' => theme_remui_kids_lessonplan_get_competencies_for_cm($cm->id)
    ];
}

/**
 * Append a simplified activity entry for the overview grid.
 *
 * @param array $activities
 * @param array $activitydata
 * @param int $limit
 * @return void
 */
function theme_remui_kids_lessonplan_append_flat_activity(array &$activities, array $activitydata, int $limit): void {
    if (count($activities) >= $limit) {
        return;
    }
    $activities[] = [
        'type' => $activitydata['type'],
        'name' => $activitydata['name']
    ];
}

/**
 * Collect activities that live inside a subsection.
 *
 * @param stdClass|null $subsectionsection
 * @param course_modinfo $modinfo
 * @param array $flatactivities
 * @param int $limit
 * @return array
 */
function theme_remui_kids_lessonplan_collect_subsection_activities(?stdClass $subsectionsection, course_modinfo $modinfo,
        array &$flatactivities, int $limit): array {
    if (!$subsectionsection || empty($subsectionsection->sequence)) {
        return [];
    }

    $activities = [];
    $cmids = array_filter(array_map('intval', explode(',', $subsectionsection->sequence)));
    foreach ($cmids as $childcmid) {
        if (!isset($modinfo->cms[$childcmid])) {
            continue;
        }
        $childcm = $modinfo->cms[$childcmid];
        if (!$childcm->uservisible || $childcm->modname === 'subsection') {
            continue;
        }
        $activitydata = theme_remui_kids_lessonplan_format_activity($childcm);
        $activities[] = $activitydata;
        theme_remui_kids_lessonplan_append_flat_activity($flatactivities, $activitydata, $limit);
    }

    return $activities;
}

/**
 * Clean a subsection summary if available.
 *
 * @param stdClass|null $subsection
 * @return string
 */
function theme_remui_kids_lessonplan_clean_summary(?stdClass $subsection): string {
    if (!$subsection || empty($subsection->summary)) {
        return '';
    }

    return trim(clean_text($subsection->summary, $subsection->summaryformat));
}

/**
 * Fetch all course structure including lessons, modules, and activities.
 *
 * @param int $courseid
 * @return array
 */
function theme_remui_kids_lessonplan_get_course_structure(int $courseid): array {
    global $DB;
    
    $course = get_course($courseid);
    $coursecontext = context_course::instance($courseid);
    require_capability('moodle/course:update', $coursecontext);
    
    $modinfo = get_fast_modinfo($course);
    $lessons = [];
    
    // Get all sections (lessons/topics)
    $sections = $DB->get_records('course_sections', 
        ['course' => $courseid], 
        'section ASC', 
        'id, name, section, summary'
    );
    
    foreach ($sections as $section) {
        if ((int)$section->section === 0) {
            continue; // Skip general section
        }
        
        $sectiontitle = $section->name ? format_string($section->name) :
            get_string('topic') . ' ' . $section->section;
        $sectionsummary = '';
        if (!empty($section->summary)) {
            $sectionsummary = trim(clean_text($section->summary, $section->summaryformat));
        }
        
        $sectionnumber = (int)$section->section;
        $modules = [];
        $activities = [];
        
        if (isset($modinfo->sections[$sectionnumber])) {
            foreach ($modinfo->sections[$sectionnumber] as $cmid) {
                if (!isset($modinfo->cms[$cmid])) {
                    continue;
                }
                $cm = $modinfo->cms[$cmid];
                if (!$cm->uservisible) {
                    continue;
                }
                
                if ($cm->modname === 'subsection') {
                    $subsectionsection = $DB->get_record('course_sections', [
                        'component' => 'mod_subsection',
                        'itemid' => $cm->instance
                    ], '*', IGNORE_MISSING);
                    
                    $moduleactivities = theme_remui_kids_lessonplan_collect_subsection_activities(
                        $subsectionsection,
                        $modinfo,
                        $activities,
                        100 // Higher limit for course planner
                    );
                    
                    $modules[] = [
                        'id' => $subsectionsection->id ?? $cm->id,
                        'cmid' => $cm->id,
                        'type' => 'subsection',
                        'name' => $subsectionsection && $subsectionsection->name ?
                            format_string($subsectionsection->name) : format_string($cm->name),
                        'summary' => theme_remui_kids_lessonplan_clean_summary($subsectionsection),
                        'activities' => $moduleactivities
                    ];
                    continue;
                }
                
                $activitydata = theme_remui_kids_lessonplan_format_activity($cm);
                theme_remui_kids_lessonplan_append_flat_activity($activities, $activitydata, 100);
                
                $modules[] = [
                    'id' => $cm->id,
                    'cmid' => $cm->id,
                    'type' => $cm->modname,
                    'name' => $activitydata['name'],
                    'summary' => $activitydata['summary'],
                    'activities' => [$activitydata]
                ];
            }
        }
        
        $lessons[] = [
            'id' => (int)$section->id,
            'name' => $sectiontitle,
            'summary' => $sectionsummary,
            'section' => (int)$section->section,
            'modules' => $modules,
            'activities' => $activities
        ];
    }
    
    return [
        'course' => [
            'id' => (int)$course->id,
            'fullname' => format_string($course->fullname),
            'shortname' => format_string($course->shortname)
        ],
        'lessons' => $lessons
    ];
}

/**
 * Generate comprehensive course planner using AI.
 *
 * @param int $courseid
 * @param int $userid
 * @return array
 */
function theme_remui_kids_lessonplan_generate_course_plan(int $courseid, int $userid): array {
    global $DB;
    
    $course = get_course($courseid);
    $coursecontext = context_course::instance($courseid);
    require_capability('moodle/course:update', $coursecontext);
    
    $coursename = format_string($course->fullname);
    $structure = theme_remui_kids_lessonplan_get_course_structure($courseid);
    
    if (empty($structure['lessons'])) {
        return [
            'success' => false,
            'message' => get_string('nothingtodisplay')
        ];
    }
    
    // Build comprehensive context for AI
    $contextnotes = "Course: {$coursename}\n\n";
    $contextnotes .= "This course contains " . count($structure['lessons']) . " lessons/topics:\n\n";
    
    foreach ($structure['lessons'] as $lesson) {
        $contextnotes .= "Lesson/Topic: {$lesson['name']}\n";
        if (!empty($lesson['summary'])) {
            $contextnotes .= "Summary: {$lesson['summary']}\n";
        }
        
        if (!empty($lesson['modules'])) {
            $contextnotes .= "Modules and Activities:\n";
            foreach ($lesson['modules'] as $module) {
                $contextnotes .= "  - Module: {$module['name']} ({$module['type']})\n";
                if (!empty($module['summary'])) {
                    $contextnotes .= "    Summary: {$module['summary']}\n";
                }
                if (!empty($module['activities'])) {
                    foreach ($module['activities'] as $activity) {
                        $contextnotes .= "    • Activity: {$activity['name']} ({$activity['type']})\n";
                    }
                }
            }
        }
        $contextnotes .= "\n";
    }
    
    $prompt = "Generate a comprehensive course planner JSON for the following course structure.\n\n" .
        "{$contextnotes}\n" .
        "Return ONLY valid JSON with the following structure:\n" .
        "{\n" .
        "  \"coursename\": \"Course Name\",\n" .
        "  \"overview\": \"Course overview paragraph\",\n" .
        "  \"learningobjectives\": [\"objective\"],\n" .
        "  \"keyconcepts\": [\"concept\"],\n" .
        "  \"lessons\": [\n" .
        "    {\n" .
        "      \"lessonname\": \"Lesson Name\",\n" .
        "      \"unitopener\": {\"title\": \"Hook/Opener\", \"description\": \"paragraph\"},\n" .
        "      \"rows\": [\n" .
        "        {\n" .
        "          \"module\": \"Module name\",\n" .
        "          \"activity\": \"Activity name\",\n" .
        "          \"objective\": \"learning objective\",\n" .
        "          \"languageObjective\": \"language objective\",\n" .
        "          \"selObjective\": \"SEL objective\",\n" .
        "          \"keyVocabulary\": [\"word\"],\n" .
        "          \"materials\": [\"material\"],\n" .
        "          \"rigorFocus\": \"rigor focus\",\n" .
        "          \"standards\": [\"standard\"]\n" .
        "        }\n" .
        "      ],\n" .
        "      \"probe\": {\"title\": \"Formative check\", \"prompts\": [\"question\"]},\n" .
        "      \"lessonreview\": {\"title\": \"closure\", \"description\": \"summary\"},\n" .
        "      \"lessonassessment\": {\"title\": \"assessment\", \"description\": \"assessment approach\"}\n" .
        "    }\n" .
        "  ],\n" .
        "  \"resources\": [\"resource\"],\n" .
        "  \"assessments\": [\"assessment\"],\n" .
        "  \"differentiation\": [\"support\"],\n" .
        "  \"duration\": \"e.g. 16 weeks\",\n" .
        "  \"teachersuggestions\": {\n" .
        "    \"activitySuggestions\": [\"specific activity ideas with step-by-step implementation guidance\"],\n" .
        "    \"assessmentSuggestions\": [\"assessment methods with detailed instructions on how to create, implement, and manage them\"],\n" .
        "    \"assignmentSuggestions\": [\"assignment ideas with clear instructions on how to create, set up, and manage assignments in the learning management system\"]\n" .
        "  }\n" .
        "}\n\n" .
        "Ensure the JSON strictly follows the schema. Create detailed plans for each lesson based on the provided modules and activities. The teachersuggestions section should provide comprehensive, practical guidance for implementing activities, assessments, and assignments throughout the course.";
    
    $response = \local_aiassistant\gemini_api::send_message($prompt, '');
    if (empty($response['success'])) {
        return [
            'success' => false,
            'message' => $response['reply'] ?? get_string('errorunexpected', 'error')
        ];
    }
    
    $reply = trim($response['reply']);
    $json = remui_kids_extract_first_json($reply);
    if (!$json) {
        $json = $reply;
    }
    
    $plan = json_decode($json, true);
    if (!is_array($plan)) {
        $plan = [];
    }
    
    // Normalize the course plan structure
    $normalized = [
        'type' => 'course',
        'coursename' => $coursename,
        'overview' => clean_param($plan['overview'] ?? '', PARAM_TEXT),
        'learningobjectives' => theme_remui_kids_lessonplan_normalize_list($plan, ['learningobjectives', 'objectives']),
        'keyconcepts' => theme_remui_kids_lessonplan_normalize_list($plan, ['keyconcepts', 'concepts']),
        'resources' => theme_remui_kids_lessonplan_normalize_list($plan, ['resources', 'materials']),
        'assessments' => theme_remui_kids_lessonplan_normalize_list($plan, ['assessments', 'assessment']),
        'differentiation' => theme_remui_kids_lessonplan_normalize_list($plan, ['differentiation', 'support']),
        'duration' => clean_param($plan['duration'] ?? '', PARAM_TEXT),
        'teachersuggestions' => theme_remui_kids_lessonplan_normalize_teacher_suggestions($plan['teachersuggestions'] ?? null),
        'lessons' => []
    ];
    
    // Process lessons
    if (isset($plan['lessons']) && is_array($plan['lessons'])) {
        foreach ($plan['lessons'] as $lessonplan) {
            $lessonrows = [];
            if (isset($lessonplan['rows']) && is_array($lessonplan['rows'])) {
                foreach ($lessonplan['rows'] as $row) {
                    $lessonrows[] = [
                        'module' => clean_param($row['module'] ?? '', PARAM_TEXT),
                        'activity' => clean_param($row['activity'] ?? '', PARAM_TEXT),
                        'objective' => clean_param($row['objective'] ?? '', PARAM_TEXT),
                        'languageobjective' => clean_param($row['languageObjective'] ?? $row['languageobjective'] ?? '', PARAM_TEXT),
                        'selobjective' => clean_param($row['selObjective'] ?? $row['selobjective'] ?? '', PARAM_TEXT),
                        'keyvocabulary' => theme_remui_kids_lessonplan_normalize_list($row, ['keyVocabulary', 'vocabulary', 'keyvocabulary']),
                        'materials' => theme_remui_kids_lessonplan_normalize_list($row, ['materials', 'resources']),
                        'rigorfocus' => clean_param($row['rigorFocus'] ?? $row['rigorfocus'] ?? '', PARAM_TEXT),
                        'standards' => theme_remui_kids_lessonplan_normalize_list($row, ['standards', 'standard'])
                    ];
                }
            }
            
            $normalized['lessons'][] = [
                'lessonname' => clean_param($lessonplan['lessonname'] ?? '', PARAM_TEXT),
                'unitopener' => theme_remui_kids_lessonplan_normalize_block($lessonplan['unitopener'] ?? null, 'Lesson Opener'),
                'probe' => theme_remui_kids_lessonplan_normalize_block($lessonplan['probe'] ?? null, 'Mid-lesson Probe', ['prompts', 'questions']),
                'lessonreview' => theme_remui_kids_lessonplan_normalize_block($lessonplan['lessonreview'] ?? null, 'Lesson Review', ['steps', 'questions']),
                'lessonassessment' => theme_remui_kids_lessonplan_normalize_block($lessonplan['lessonassessment'] ?? null, 'Lesson Assessment', ['methods', 'criteria']),
                'rows' => $lessonrows
            ];
        }
    }
    
    return [
        'success' => true,
        'plan' => $normalized
    ];
}

