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
 * Admin Competency Maps - Comprehensive view of all competencies
 *
 * @package   theme_remui_kids
 * @copyright 2025 Kodeit
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../../config.php');

require_login();
$context = context_system::instance();

// Require admin capability
require_capability('moodle/site:config', $context);

$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/admin/competency_maps.php');
$PAGE->set_title('Competency Maps');
$PAGE->set_heading('Competency Maps');

// Handle AJAX requests
if (isset($_GET['action']) && $_GET['action'] === 'test') {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'ok', 'message' => 'AJAX is working']);
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'simple_test') {
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'success',
        'message' => 'Simple test working',
        'competency_id' => $_GET['competency_id'] ?? 'not_set',
        'course_id' => $_GET['course_id'] ?? 'not_set'
    ]);
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'test_activities') {
    try {
        $courseId = required_param('course_id', PARAM_INT);
        error_log('Testing activities query for course_id=' . $courseId);
        
        // Verify course exists (similar to coursecompetencies.php)
        $params = array('id' => $courseId);
        $course = $DB->get_record('course', $params, '*', MUST_EXIST);
        error_log('Course exists: ' . $course->fullname);
        
        // Test using Moodle's exact approach from module_navigation.php
        $modinfo = get_fast_modinfo($courseId);
        $activities = array();
        $count = 0;
        
        foreach ($modinfo->get_cms() as $cm) {
            if ($cm->uservisible && $count < 5) {
                $activity = new stdClass();
                $activity->cmid = $cm->id;
                $activity->module = $cm->module;
                $activity->instance = $cm->instance;
                $activity->section = $cm->sectionnum;
                $activity->cmname = $cm->name;
                $activity->modulename = $cm->modname;
                $activity->sectionname = 'Section ' . $cm->sectionnum;
                $activity->activityname = !empty($cm->name) ? $cm->name : ucfirst($cm->modname) . ' Activity';
                $activities[] = $activity;
                $count++;
            }
        }
        
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'success',
            'count' => count($activities),
            'activities' => array_values($activities)
        ]);
        exit;
    } catch (Exception $e) {
        error_log('Test activities error: ' . $e->getMessage());
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
}

if (isset($_GET['action']) && $_GET['action'] === 'debug_activities') {
    try {
        $courseId = required_param('course_id', PARAM_INT);
        
        // Debug: Get all course modules with their details
        $debugInfo = [];
        
        // Get basic course modules info
        $modules = $DB->get_records_sql(
            "SELECT cm.id as cmid, cm.module, cm.instance, cm.section, cm.name as cmname,
                    m.name as modulename, 
                    cs.name as sectionname
             FROM {course_modules} cm
             JOIN {modules} m ON m.id = cm.module
             LEFT JOIN {course_sections} cs ON cs.id = cm.section
             WHERE cm.course = ? AND cm.visible = 1
             ORDER BY cm.section, cm.id ASC
             LIMIT 10",
            [$courseId]
        );
        
        $debugInfo['modules'] = array_values($modules);
        
        // Check what tables exist
        $tables = ['assign', 'quiz', 'forum', 'resource', 'page', 'book', 'lesson', 'scorm', 'url', 'folder', 'subsection'];
        $existingTables = [];
        foreach ($tables as $table) {
            if ($DB->get_manager()->table_exists($table)) {
                $existingTables[] = $table;
            }
        }
        $debugInfo['existing_tables'] = $existingTables;
        
        header('Content-Type: application/json');
        echo json_encode($debugInfo);
        exit;
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
}

if (isset($_GET['action']) && $_GET['action'] === 'get_courses') {
    try {
        $competencyId = required_param('competency_id', PARAM_INT);
        
        // Get all courses except site course (id=1)
        $courses = $DB->get_records_sql(
            "SELECT c.id, c.fullname, c.shortname, c.category, cat.name as categoryname
             FROM {course} c
             LEFT JOIN {course_categories} cat ON cat.id = c.category
             WHERE c.id > 1 AND c.visible = 1
             ORDER BY c.fullname ASC"
        );
        
        // Get already linked courses for this competency (check if table exists first)
        $linkedCourseIds = [];
        if ($DB->get_manager()->table_exists('competency_coursecomp')) {
            $linkedCourses = $DB->get_records('competency_coursecomp', ['competencyid' => $competencyId], '', 'courseid');
            $linkedCourseIds = array_keys($linkedCourses);
        }
        
        // Add linked status to courses
        foreach ($courses as $course) {
            $course->already_linked = in_array($course->id, $linkedCourseIds);
        }
        
        header('Content-Type: application/json');
        echo json_encode(['courses' => array_values($courses)]);
        exit;
    } catch (Exception $e) {
        // Log the error for debugging
        error_log('Competency courses loading error: ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine());
        
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['error' => 'Failed to load courses: ' . $e->getMessage()]);
        exit;
    }
}

if (isset($_POST['action']) && $_POST['action'] === 'link_courses') {
    try {
        $competencyId = required_param('competency_id', PARAM_INT);
        $courseIds = json_decode(required_param('course_ids', PARAM_RAW), true);
        
        if (!is_array($courseIds)) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Invalid course IDs']);
            exit;
        }
        
        // Check if competency_coursecomp table exists
        if (!$DB->get_manager()->table_exists('competency_coursecomp')) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Competency course linking is not available in this Moodle installation']);
            exit;
        }
        
        $linkedCount = 0;
        $errors = [];
        
        foreach ($courseIds as $courseId) {
            $courseId = (int)$courseId;
            
            // Check if course exists and is valid
            if (!$DB->record_exists('course', ['id' => $courseId, 'visible' => 1])) {
                $errors[] = "Course ID $courseId not found or not visible";
                continue;
            }
            
            // Check if link already exists
            if ($DB->record_exists('competency_coursecomp', ['competencyid' => $competencyId, 'courseid' => $courseId])) {
                continue; // Skip if already linked
            }
            
            // Create the link
            $link = new stdClass();
            $link->competencyid = $competencyId;
            $link->courseid = $courseId;
            $link->ruleoutcome = 1; // OUTCOME_EVIDENCE
            $link->timecreated = time();
            $link->timemodified = time();
            $link->usermodified = $USER->id;
            
            // Get the next sort order for this course
            $maxSortOrder = $DB->get_field_sql(
                "SELECT MAX(sortorder) FROM {competency_coursecomp} WHERE courseid = ?",
                [$courseId]
            );
            $link->sortorder = ($maxSortOrder !== false) ? $maxSortOrder + 1 : 0;
            
            if ($DB->insert_record('competency_coursecomp', $link)) {
                $linkedCount++;
            } else {
                $errors[] = "Failed to link course ID $courseId";
            }
        }
        
        header('Content-Type: application/json');
        if ($linkedCount > 0) {
            echo json_encode([
                'success' => true, 
                'linked_count' => $linkedCount,
                'errors' => $errors
            ]);
        } else {
            echo json_encode([
                'success' => false, 
                'error' => 'No courses were linked. ' . implode('; ', $errors)
            ]);
        }
        exit;
    } catch (Exception $e) {
        // Log the error for debugging
        error_log('Competency course linking error: ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine());
        
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to link courses: ' . $e->getMessage()]);
        exit;
    }
}

if (isset($_GET['action']) && $_GET['action'] === 'get_activities') {
    try {
        error_log('Starting get_activities for competency_id=' . $_GET['competency_id'] . ', course_id=' . $_GET['course_id']);
        
        $competencyId = required_param('competency_id', PARAM_INT);
        $courseId = required_param('course_id', PARAM_INT);
        error_log('Parameters validated successfully');
        
        // Verify course exists (similar to coursecompetencies.php)
        $params = array('id' => $courseId);
        $course = $DB->get_record('course', $params, '*', MUST_EXIST);
        error_log('Course exists: ' . $course->fullname);
        
        // Get all course modules (activities/resources) for the course
        // Use Moodle's exact approach from module_navigation.php
        $modinfo = get_fast_modinfo($courseId);
        $activities = array();
        
        foreach ($modinfo->get_cms() as $cm) {
            if ($cm->uservisible) {
                $activity = new stdClass();
                $activity->cmid = $cm->id;
                $activity->module = $cm->module;
                $activity->instance = $cm->instance;
                $activity->section = $cm->sectionnum;
                $activity->cmname = $cm->name;
                $activity->modulename = $cm->modname;
                $activity->sectionname = 'Section ' . $cm->sectionnum;
                $activity->sectionsummary = '';
                $activity->activityname = !empty($cm->name) ? $cm->name : ucfirst($cm->modname) . ' Activity';
                $activities[] = $activity;
            }
        }
        
        error_log('Main query executed successfully, found ' . count($activities) . ' activities');
        
        // If no activities found, return empty array
        if (empty($activities)) {
            error_log('No activities found, returning empty array');
            header('Content-Type: application/json');
            echo json_encode(['activities' => []]);
            exit;
        }
        
        // Try to get better activity names (with error handling)
        try {
            $activityNames = [];
            
            // Get names from course_modules table first (most reliable)
            $cmNames = $DB->get_records_sql(
                "SELECT id, name FROM {course_modules} WHERE course = ? AND visible = 1 AND name IS NOT NULL AND name != ''",
                [$courseId]
            );
            
            foreach ($cmNames as $cm) {
                $activityNames[$cm->id] = $cm->name;
            }
            
            // Try to get names from common activity tables (simplified)
            $commonModules = ['assign', 'quiz', 'forum', 'resource', 'page', 'book', 'lesson', 'scorm', 'url', 'folder'];
            
            foreach ($commonModules as $moduleName) {
                try {
                    if ($DB->get_manager()->table_exists($moduleName)) {
                        $moduleActivities = $DB->get_records_sql(
                            "SELECT cm.id as cmid, {$moduleName}.name as activityname
                             FROM {course_modules} cm
                             JOIN {modules} m ON m.id = cm.module
                             JOIN {{$moduleName}} {$moduleName} ON {$moduleName}.id = cm.instance
                             WHERE cm.course = ? AND m.name = ? AND cm.visible = 1",
                            [$courseId, $moduleName]
                        );
                        
                        foreach ($moduleActivities as $activity) {
                            if (!empty($activity->activityname) && !isset($activityNames[$activity->cmid])) {
                                $activityNames[$activity->cmid] = $activity->activityname;
                            }
                        }
                    }
                } catch (Exception $e) {
                    // Skip this module if there's an error
                    error_log("Error getting activities for module {$moduleName}: " . $e->getMessage());
                    continue;
                }
            }
            
            // Update activity names with actual names where available
            foreach ($activities as $activity) {
                if (isset($activityNames[$activity->cmid]) && !empty($activityNames[$activity->cmid])) {
                    $activity->activityname = $activityNames[$activity->cmid];
                }
            }
        } catch (Exception $e) {
            // If name retrieval fails, just use the basic names from the main query
            error_log("Error in activity name retrieval: " . $e->getMessage());
        }
        
        // Get already linked activities for this competency using Moodle's API
        $linkedActivities = [];
        try {
            error_log('Attempting to use Moodle API to get linked activities');
            // Use Moodle's API to get course modules using this competency
            $coursemodules = \core_competency\api::list_course_modules_using_competency($competencyId, $courseId);
            $linkedActivities = $coursemodules;
            error_log('Moodle API call successful, found ' . count($linkedActivities) . ' linked activities');
        } catch (Exception $e) {
            error_log('Moodle API failed: ' . $e->getMessage() . ', falling back to direct DB query');
            // Fallback to direct database query if API fails
            if ($DB->get_manager()->table_exists('competency_modulecomp')) {
                $linkedModules = $DB->get_records('competency_modulecomp', ['competencyid' => $competencyId], '', 'cmid');
                $linkedActivities = array_keys($linkedModules);
                error_log('Direct DB query successful, found ' . count($linkedActivities) . ' linked activities');
            }
        }
        
        // Add linked status to activities
        foreach ($activities as $activity) {
            $activity->already_linked = in_array($activity->cmid, $linkedActivities);
        }
        
        error_log('About to return JSON response with ' . count($activities) . ' activities');
        header('Content-Type: application/json');
        echo json_encode(['activities' => array_values($activities)]);
        exit;
    } catch (Exception $e) {
        error_log('Competency activities loading error: ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine());
        
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['error' => 'Failed to load activities: ' . $e->getMessage()]);
        exit;
    }
}

if (isset($_POST['action']) && $_POST['action'] === 'link_activities') {
    try {
        $competencyId = required_param('competency_id', PARAM_INT);
        $cmIds = json_decode(required_param('cm_ids', PARAM_RAW), true);
        
        if (!is_array($cmIds)) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Invalid activity IDs']);
            exit;
        }
        
        // Check if competency_modulecomp table exists
        if (!$DB->get_manager()->table_exists('competency_modulecomp')) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Competency activity linking is not available in this Moodle installation']);
            exit;
        }
        $competencyCourseCompAvailable = $DB->get_manager()->table_exists('competency_coursecomp');
        
        $linkedCount = 0;
        $errors = [];
        $coursesLinked = [];
        
        foreach ($cmIds as $cmId) {
            $cmId = (int)$cmId;
            
            // Fetch course module to get course context
            $cmRecord = $DB->get_record('course_modules', ['id' => $cmId]);
            if (!$cmRecord) {
                $errors[] = "Activity ID $cmId not found";
                continue;
            }
            $courseId = (int)$cmRecord->course;
            
            // Ensure the competency is linked to the course before linking to activities (matches Moodle core behaviour)
            if ($competencyCourseCompAvailable) {
                try {
                    if (!$DB->record_exists('competency_coursecomp', ['competencyid' => $competencyId, 'courseid' => $courseId])) {
                        \core_competency\api::add_competency_to_course($courseId, $competencyId);
                        $coursesLinked[$courseId] = true;
                    }
                } catch (Exception $e) {
                    $errors[] = "Failed to link competency to course ID $courseId before linking activity ID $cmId: " . $e->getMessage();
                    continue;
                }
            } else {
                $errors[] = "Competency course linking table not available. Cannot link competency to course for activity ID $cmId.";
                continue;
            }
            
            // Skip if activity already linked
            if ($DB->record_exists('competency_modulecomp', ['competencyid' => $competencyId, 'cmid' => $cmId])) {
                continue;
            }
            
            try {
                \core_competency\api::add_competency_to_course_module($cmId, $competencyId);
                $linkedCount++;
                
            } catch (Exception $e) {
                // Fallback to direct database insertion if API fails
                try {
                    // Check if link already exists
                    if ($DB->record_exists('competency_modulecomp', ['competencyid' => $competencyId, 'cmid' => $cmId])) {
                        continue; // Skip if already linked
                    }
                    
                    // Create the link
                    $link = new stdClass();
                    $link->competencyid = $competencyId;
                    $link->cmid = $cmId;
                    $link->ruleoutcome = 1; // OUTCOME_EVIDENCE
                    $link->timecreated = time();
                    $link->timemodified = time();
                    $link->usermodified = $USER->id;
                    $link->overridegrade = 0;
                    
                    // Get the next sort order for this competency
                    $maxSortOrder = $DB->get_field_sql(
                        "SELECT MAX(sortorder) FROM {competency_modulecomp} WHERE competencyid = ?",
                        [$competencyId]
                    );
                    $link->sortorder = ($maxSortOrder !== false) ? $maxSortOrder + 1 : 0;
                    
                    if ($DB->insert_record('competency_modulecomp', $link)) {
                        $linkedCount++;
                    } else {
                        $errors[] = "Failed to link activity ID $cmId";
                    }
                } catch (Exception $e2) {
                    $errors[] = "Failed to link activity ID $cmId: " . $e2->getMessage();
                }
            }
        }
        
        header('Content-Type: application/json');
        if ($linkedCount > 0) {
            echo json_encode([
                'success' => true, 
                'linked_count' => $linkedCount,
                'errors' => $errors,
                'courses_linked' => array_keys($coursesLinked)
            ]);
        } else {
            echo json_encode([
                'success' => false, 
                'error' => 'No activities were linked. ' . implode('; ', $errors)
            ]);
        }
        exit;
    } catch (Exception $e) {
        error_log('Competency activity linking error: ' . $e->getMessage() . ' in ' . $e->getFile() . ' in ' . $e->getLine());
        
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to link activities: ' . $e->getMessage()]);
        exit;
    }
}

// Handle AI Framework Generation
if (isset($_POST['action']) && $_POST['action'] === 'generate_framework') {
    require_once($CFG->dirroot . '/local/aiassistant/classes/gemini_api.php');
    require_once($CFG->dirroot . '/local/aiassistant/classes/competency_framework_helper.php');
    
    try {
        $userMessage = required_param('prompt', PARAM_TEXT);

        // STEP 1: Try intelligent URL discovery and automatic scraping first
        // This mirrors the chatbot's intelligent behavior
        if (\local_aiassistant\competency_framework_helper::is_framework_creation_query($userMessage)) {
            // Use reflection to call the private find_and_scrape_framework method
            // Or we can just do the URL discovery here directly
            $urlPrompt = \local_aiassistant\competency_framework_helper::get_url_search_prompt($userMessage);
            
            $apikey = get_config('local_aiassistant', 'apikey');
            $model = get_config('local_aiassistant', 'model');
            
            if (!empty($apikey)) {
                // Ask AI to find the official URL
                $url_api = "https://generativelanguage.googleapis.com/v1/models/{$model}:generateContent?key={$apikey}";
                $data = ['contents' => [['parts' => [['text' => $urlPrompt]]]]];
                
                $curl = new \curl(['CURLOPT_TIMEOUT' => 15, 'CURLOPT_CONNECTTIMEOUT' => 5]);
                $curl->setHeader(['Content-Type: application/json']);
                $response_url = $curl->post($url_api, json_encode($data));
                
                if (!$curl->get_errno()) {
                    $result = json_decode($response_url, true);
                    if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
                        $foundUrl = trim($result['candidates'][0]['content']['parts'][0]['text']);
                        $foundUrl = preg_replace('/[`"\'\[\]()]/','', $foundUrl);
                        $foundUrl = trim($foundUrl);
                        
                        // If valid URL found, try scraping
                        if (stripos($foundUrl, 'NO_URL_FOUND') === false && preg_match('/^https?:\/\//i', $foundUrl)) {
                            $scraped = \local_aiassistant\competency_framework_helper::generate_from_source_url($foundUrl, $userMessage);
                            if (!empty($scraped) && isset($scraped['framework']) && isset($scraped['competencies']) && count($scraped['competencies']) > 0) {
                                header('Content-Type: application/json');
                                echo json_encode([
                                    'success' => true,
                                    'framework' => $scraped,
                                    'source' => 'auto-scraped',
                                    'source_url' => $foundUrl
                                ]);
                                exit;
                            }
                        }
                    }
                }
            }
        }
        
        // STEP 2: If scraping failed or not applicable, use AI generation
        // Get the special prompt that instructs AI to return ONLY JSON
        $specialPrompt = \local_aiassistant\competency_framework_helper::get_framework_generation_prompt($userMessage);
        
        // Call AI directly with JSON prompt (bypass normal send_message)
        $apikey = get_config('local_aiassistant', 'apikey');
        $model = get_config('local_aiassistant', 'model');
        
        if (empty($apikey)) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error' => 'API key not configured'
            ]);
            exit;
        }
        
        $url = "https://generativelanguage.googleapis.com/v1/models/{$model}:generateContent?key={$apikey}";
        
        $data = [
            'contents' => [[
                'parts' => [[
                    'text' => $specialPrompt
                ]]
            ]]
        ];
        
        $payload = json_encode($data);
        
        // Make API request
        $curl = new \curl([
            'CURLOPT_TIMEOUT' => 30,
            'CURLOPT_CONNECTTIMEOUT' => 10
        ]);
        
        $curl->setHeader([
            'Content-Type: application/json'
        ]);
        
        $apiResponse = $curl->post($url, $payload);
        
        if ($curl->get_errno()) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error' => 'API Error: ' . $curl->error
            ]);
            exit;
        }
        
        $result = json_decode($apiResponse, true);
        
        if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
            $reply = $result['candidates'][0]['content']['parts'][0]['text'];
            
            // Try to parse the framework from the response
            $frameworkData = \local_aiassistant\competency_framework_helper::parse_framework_response($reply);
            
            if ($frameworkData) {
                // Validate the framework
                $validation = \local_aiassistant\competency_framework_helper::validate_framework_data($frameworkData);
                
                if ($validation['valid']) {
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => true,
                        'framework' => $frameworkData
                    ]);
                } else {
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => false,
                        'error' => 'Validation failed: ' . implode(', ', $validation['errors'])
                    ]);
                }
            } else {
                // AI didn't return proper JSON, return error with debug info
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'error' => 'AI could not generate valid JSON. Try again or use the diagnostic page.',
                    'raw_preview' => substr($reply, 0, 300)
                ]);
            }
        } else if (isset($result['error'])) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error' => 'API Error: ' . ($result['error']['message'] ?? 'Unknown error')
            ]);
        } else {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error' => 'No response from AI'
            ]);
        }
        exit;
    } catch (Exception $e) {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Error: ' . $e->getMessage()
        ]);
        exit;
    }
}

// Handle Add Generated Framework
if (isset($_POST['action']) && $_POST['action'] === 'add_generated_framework') {
    require_once($CFG->dirroot . '/local/aiassistant/classes/competency_framework_helper.php');
    
    try {
        $frameworkDataJson = required_param('frameworkdata', PARAM_RAW);
        $frameworkData = json_decode($frameworkDataJson, true);
        
        if (!$frameworkData) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error' => 'Invalid framework data'
            ]);
            exit;
        }
        
        // Validate
        $validation = \local_aiassistant\competency_framework_helper::validate_framework_data($frameworkData);
        
        if (!$validation['valid']) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error' => 'Validation failed: ' . implode(', ', $validation['errors'])
            ]);
            exit;
        }
        
        // Insert framework
        $result = \local_aiassistant\competency_framework_helper::insert_framework($frameworkData, $USER->id);
        
        header('Content-Type: application/json');
        echo json_encode($result);
        exit;
        
    } catch (Exception $e) {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Error: ' . $e->getMessage()
        ]);
        exit;
    }
}

echo $OUTPUT->header();

// Include admin sidebar from includes
require_once(__DIR__ . '/includes/admin_sidebar.php');

// Main content area with sidebar
echo "<div class='admin-main-content'>";

?>

<style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
    
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    body {
        font-family: 'Inter', sans-serif;
        background: linear-gradient(135deg, #fef7f7 0%, #f0f9ff 50%, #f0fdf4 100%);
        min-height: 100vh;
        overflow-x: hidden;
    }

    /* Admin Sidebar Navigation - Sticky on all pages */
    .admin-sidebar {
        position: fixed !important;
        top: 0;
        left: 0;
        width: 280px;
        height: 100vh;
        background: white;
        border-right: 1px solid #e9ecef;
        z-index: 1000;
        overflow-y: auto;
        box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1);
        will-change: transform;
        backface-visibility: hidden;
    }
    
    .admin-sidebar .sidebar-content {
        padding: 6rem 0 2rem 0;
    }
    
    .admin-sidebar .sidebar-section {
        margin-bottom: 2rem;
    }
    
    .admin-sidebar .sidebar-category {
        font-size: 0.75rem;
        font-weight: 700;
        color: #6c757d;
        text-transform: uppercase;
        letter-spacing: 1px;
        margin-bottom: 1rem;
        padding: 0 2rem;
        margin-top: 0;
    }
    
    .admin-sidebar .sidebar-menu {
        list-style: none;
        padding: 0;
        margin: 0;
    }
    
    .admin-sidebar .sidebar-item {
        margin-bottom: 0.25rem;
    }
    
    .admin-sidebar .sidebar-link {
        display: flex;
        align-items: center;
        padding: 0.75rem 2rem;
        color: #495057;
        text-decoration: none;
        transition: all 0.3s ease;
        border-left: 3px solid transparent;
    }
    
    .admin-sidebar .sidebar-link:hover {
        background-color: #f8f9fa;
        color: #2c3e50;
        text-decoration: none;
        border-left-color: #667eea;
    }
    
    .admin-sidebar .sidebar-icon {
        width: 20px;
        height: 20px;
        margin-right: 1rem;
        font-size: 1rem;
        color: #6c757d;
        text-align: center;
    }
    
    .admin-sidebar .sidebar-text {
        font-size: 0.9rem;
        font-weight: 500;
    }
    
    .admin-sidebar .sidebar-item.active .sidebar-link {
        background-color: #e3f2fd;
        color: #1976d2;
        border-left-color: #1976d2;
    }
    
    .admin-sidebar .sidebar-item.active .sidebar-icon {
        color: #1976d2;
    }
    
    /* Scrollbar styling */
    .admin-sidebar::-webkit-scrollbar {
        width: 6px;
    }
    
    .admin-sidebar::-webkit-scrollbar-track {
        background: #f1f1f1;
    }
    
    .admin-sidebar::-webkit-scrollbar-thumb {
        background: #c1c1c1;
        border-radius: 3px;
    }
    
    .admin-sidebar::-webkit-scrollbar-thumb:hover {
        background: #a8a8a8;
    }

    /* Main content area with sidebar - FULL SCREEN */
    .admin-main-content {
        position: fixed;
        top: 0;
        left: 280px;
        width: calc(100vw - 280px);
        height: 100vh;
        background-color: #ffffff;
        overflow-y: auto;
        z-index: 99;
        will-change: transform;
        backface-visibility: hidden;
        padding-top: 80px; /* Add padding to account for topbar */
    }
    
    /* Mobile responsive */
    @media (max-width: 768px) {
        .admin-sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: 280px;
            height: 100vh;
            transform: translateX(-100%);
            transition: transform 0.3s ease;
            z-index: 1001;
        }
        
        .admin-sidebar.sidebar-open {
            transform: translateX(0);
        }
        
        .admin-main-content {
            position: relative;
            left: 0;
            width: 100vw;
            height: auto;
            min-height: 100vh;
            padding-top: 20px;
        }
    }

    .page-header {
        background: rgba(255, 255, 255, 0.95);
        border-radius: 20px;
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
        backdrop-filter: blur(10px);
        overflow: hidden;
        margin: 30px auto 30px;
        position: relative;
        padding-top: 10px;
    }
    
    .header-background {
        background: linear-gradient(135deg, #e1f5fe 0%, #f3e5f5 100%);
        min-height: 160px;
        position: relative;
        overflow: hidden;
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        text-align: center;
        padding: 30px 24px 40px;
    }
    
    .header-content {
        position: relative;
        z-index: 2;
        color: #1976d2;
    }
    
    .header-background::before {
        content: '';
        position: absolute;
        top: -50%;
        left: -50%;
        width: 200%;
        height: 200%;
        background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
        animation: rotate 20s linear infinite;
    }
    
    @keyframes rotate {
        from { transform: rotate(0deg); }
        to { transform: rotate(360deg); }
    }
    
    .page-content {
        padding: 40px;
        position: relative;
    }
    
    .page-title {
        font-size: 2rem;
        font-weight: 800;
        color: #1976d2;
        margin-bottom: 8px;
        animation: fadeInUp 1s ease-out 0.3s both;
    }
    
    .page-subtitle {
        font-size: 1.3rem;
        color: #546e7a;
        margin: 0;
        font-weight: 500;
        animation: fadeInUp 1s ease-out 0.4s both;
        opacity: 0.9;
    }

    /* Framework Actions Styles */
    .framework-actions {
        margin-bottom: 30px;
        display: flex;
        justify-content: flex-end;
    }

    .action-buttons {
        display: flex;
        gap: 12px;
        align-items: center;
    }

    .frameworks-btn, .import-btn, .export-btn {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 12px 20px;
        border-radius: 8px;
        text-decoration: none;
        font-weight: 500;
        font-size: 14px;
        transition: all 0.3s ease;
        border: none;
        cursor: pointer;
    }

    .frameworks-btn {
        background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);
        color: white;
        box-shadow: 0 4px 12px rgba(23, 162, 184, 0.3);
    }

    .frameworks-btn:hover {
        background: linear-gradient(135deg, #138496 0%, #117a8b 100%);
        box-shadow: 0 6px 16px rgba(23, 162, 184, 0.4);
        transform: translateY(-2px);
        color: white;
        text-decoration: none;
    }

    .import-btn {
        background: linear-gradient(135deg, #4caf50 0%, #45a049 100%);
        color: white;
        box-shadow: 0 4px 12px rgba(76, 175, 80, 0.3);
    }

    .import-btn:hover {
        background: linear-gradient(135deg, #45a049 0%, #3d8b40 100%);
        box-shadow: 0 6px 16px rgba(76, 175, 80, 0.4);
        transform: translateY(-2px);
        color: white;
        text-decoration: none;
    }

    .export-btn {
        background: linear-gradient(135deg, #2196f3 0%, #1976d2 100%);
        color: white;
        box-shadow: 0 4px 12px rgba(33, 150, 243, 0.3);
    }

    .export-btn:hover {
        background: linear-gradient(135deg, #1976d2 0%, #1565c0 100%);
        box-shadow: 0 6px 16px rgba(33, 150, 243, 0.4);
        transform: translateY(-2px);
        color: white;
        text-decoration: none;
    }

    .frameworks-btn i, .import-btn i, .export-btn i {
        font-size: 16px;
    }
    
    @keyframes fadeInUp {
        from {
            opacity: 0;
            transform: translateY(30px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    /* Statistics Cards */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 24px;
        margin-bottom: 40px;
    }

    .stat-card {
        background: white;
        border-radius: 16px;
        padding: 24px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        display: flex;
        align-items: center;
        transition: transform 0.2s, box-shadow 0.2s;
    }

    .stat-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
    }

    .stat-icon {
        width: 56px;
        height: 56px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 24px;
        margin-right: 16px;
    }

    .stat-icon.purple {
        background: linear-gradient(135deg, #e1f5fe 0%, #f3e5f5 100%);
        color: #1976d2;
    }

    .stat-icon.blue {
        background: linear-gradient(135deg, #e1f5fe 0%, #f3e5f5 100%);
        color: #1976d2;
    }

    .stat-icon.green {
        background: linear-gradient(135deg, #e8f5e8 0%, #f1f8e9 100%);
        color: #2e7d32;
    }

    .stat-icon.orange {
        background: linear-gradient(135deg, #fff3e0 0%, #fce4ec 100%);
        color: #f57c00;
    }

    .stat-content {
        flex: 1;
    }

    .stat-value {
        font-size: 32px;
        font-weight: 700;
        color: #37474f;
        line-height: 1;
        margin-bottom: 4px;
    }

    .stat-label {
        font-size: 14px;
        color: #546e7a;
        font-weight: 500;
    }

    /* AI Assistant Section */
    .ai-assistant-section {
        background: white;
        border-radius: 16px;
        margin-bottom: 32px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        border: 2px solid #e1f5fe;
        overflow: hidden;
        transition: all 0.3s ease;
    }

    .ai-assistant-section:hover {
        box-shadow: 0 4px 16px rgba(0, 0, 0, 0.12);
    }

    .ai-assistant-header {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 24px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        cursor: pointer;
        transition: all 0.3s ease;
    }

    .ai-assistant-header:hover {
        background: linear-gradient(135deg, #764ba2 0%, #667eea 100%);
    }

    .ai-header-left {
        display: flex;
        align-items: center;
        gap: 16px;
    }

    .ai-icon-wrapper {
        width: 56px;
        height: 56px;
        background: rgba(255, 255, 255, 0.2);
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 28px;
    }

    .ai-header-text {
        flex: 1;
    }

    .ai-section-title {
        font-size: 1.5rem;
        font-weight: 700;
        margin: 0 0 4px 0;
        color: white;
    }

    .ai-section-subtitle {
        font-size: 0.95rem;
        margin: 0;
        opacity: 0.9;
    }

    .ai-toggle-btn {
        background: rgba(255, 255, 255, 0.2);
        border: none;
        color: white;
        width: 40px;
        height: 40px;
        border-radius: 50%;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.3s ease;
        font-size: 20px;
    }

    .ai-toggle-btn:hover {
        background: rgba(255, 255, 255, 0.3);
        transform: scale(1.1);
    }

    .ai-toggle-btn.expanded i {
        transform: rotate(180deg);
    }

    .ai-assistant-body {
        padding: 32px;
        animation: slideDown 0.3s ease-out;
    }

    @keyframes slideDown {
        from {
            opacity: 0;
            transform: translateY(-20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .ai-input-section {
        margin-bottom: 24px;
    }

    .ai-label {
        display: block;
        font-size: 1rem;
        font-weight: 600;
        color: #37474f;
        margin-bottom: 12px;
    }

    .ai-input-wrapper {
        margin-bottom: 16px;
    }

    .ai-framework-input {
        width: 100%;
        padding: 16px;
        border: 2px solid #e1f5fe;
        border-radius: 12px;
        font-size: 15px;
        font-family: inherit;
        resize: vertical;
        transition: border-color 0.2s;
        min-height: 100px;
    }

    .ai-framework-input:focus {
        outline: none;
        border-color: #667eea;
        box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
    }

    .ai-quick-suggestions {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 8px;
        margin-top: 12px;
    }

    .ai-suggestion-label {
        font-size: 0.9rem;
        color: #546e7a;
        font-weight: 500;
        margin-right: 4px;
    }

    .ai-suggestion-btn {
        background: #f0f4ff;
        color: #667eea;
        border: 1px solid #d1d9ff;
        padding: 6px 14px;
        border-radius: 20px;
        font-size: 0.85rem;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.2s;
    }

    .ai-suggestion-btn:hover {
        background: #667eea;
        color: white;
        border-color: #667eea;
        transform: translateY(-2px);
    }

    .ai-generate-btn {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border: none;
        padding: 16px 32px;
        border-radius: 12px;
        font-size: 1.05rem;
        font-weight: 600;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 12px;
        transition: all 0.3s ease;
        box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
    }

    .ai-generate-btn:hover:not(:disabled) {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
        background: linear-gradient(135deg, #764ba2 0%, #667eea 100%);
    }

    .ai-generate-btn:disabled {
        opacity: 0.6;
        cursor: not-allowed;
        transform: none;
    }

    .ai-generate-btn i {
        font-size: 18px;
    }

    .ai-status-message {
        padding: 16px;
        border-radius: 12px;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 12px;
        animation: fadeIn 0.3s ease-out;
    }

    @keyframes fadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
    }

    .ai-status-message.loading {
        background: #e3f2fd;
        border: 2px solid #90caf9;
        color: #1976d2;
    }

    .ai-status-message.success {
        background: #e8f5e9;
        border: 2px solid #81c784;
        color: #2e7d32;
    }

    .ai-status-message.error {
        background: #ffebee;
        border: 2px solid #e57373;
        color: #c62828;
    }

    .ai-spinner {
        width: 20px;
        height: 20px;
        border: 3px solid #90caf9;
        border-top-color: #1976d2;
        border-radius: 50%;
        animation: spin 1s linear infinite;
    }

    @keyframes spin {
        to { transform: rotate(360deg); }
    }

    .ai-generated-framework-container {
        margin-top: 24px;
    }

    .generated-framework {
        background: linear-gradient(135deg, #f0f9ff 0%, #f0fdf4 100%);
        border-radius: 16px;
        padding: 24px;
        border: 2px solid #e1f5fe;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        animation: slideUp 0.5s ease-out;
    }

    @keyframes slideUp {
        from {
            opacity: 0;
            transform: translateY(30px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    :root {
        --primary: #0f172a;
        --primary-light: #1f3784;
        --surface: #f4f6fb;
        --card: #ffffff;
        --border: #dde3ec;
        --text: #1e2533;
        --muted: #6c7a92;
    }

    body {
        background: var(--surface);
        color: var(--text);
        font-family: 'Inter', 'Segoe UI', system-ui, -apple-system, BlinkMacSystemFont, sans-serif;
    }

    .page-title {
        color: var(--primary);
        font-weight: 700;
    }

    .page-subtitle {
        color: var(--muted);
        font-size: 1rem;
        margin-bottom: 32px;
    }

    .framework-actions,
    .ai-assistant-section,
    .filter-controls,
    .framework-card,
    .modal-content,
    .generated-framework,
    .course-list .course-item,
    .competency-row,
    .stat-card {
        background: var(--card);
        border: 1px solid var(--border);
        box-shadow: 0 8px 24px rgba(15, 23, 42, 0.05);
    }

    .stat-card {
        border-radius: 18px;
    }

    .stat-icon {
        background: rgba(15, 35, 82, 0.08) !important;
        border-radius: 10px;
        color: var(--primary) !important;
    }

    .stat-value {
        color: var(--primary);
    }

    .stat-label {
        color: var(--muted);
    }

    .action-buttons .btn,
    .filter-btn,
    .ai-generate-btn,
    .btn-add-generated-framework,
    .btn-regenerate-framework,
    .btn-clear-framework,
    .modal-footer .btn-primary {
        background: var(--primary);
        color: #fff;
        border: none;
        box-shadow: none;
        transition: background 0.2s, transform 0.2s;
    }

    .btn-secondary,
    .filter-btn.secondary,
    .modal-footer .btn-secondary {
        background: var(--primary-light);
        color: #fff;
    }

    .action-buttons .btn:hover,
    .filter-btn:hover,
    .ai-generate-btn:hover:not(:disabled),
    .btn-add-generated-framework:hover:not(:disabled),
    .btn-regenerate-framework:hover,
    .btn-clear-framework:hover,
    .modal-footer .btn-primary:hover {
        background: #10204b;
        transform: translateY(-1px);
    }

    .ai-assistant-section {
        border-radius: 20px;
    }

    .ai-assistant-header {
        padding: 28px;
        background: var(--card);
        border-bottom: 1px solid var(--border);
    }

    .ai-section-title {
        color: var(--primary);
        margin: 0;
    }

    .ai-section-subtitle,
    .ai-label,
    .filter-label {
        color: var(--muted);
    }

    .ai-quick-suggestions,
    .filter-controls,
    .framework-body,
    .modal-body {
        background: var(--card);
    }

    .ai-suggestion-btn {
        background: rgba(15, 23, 42, 0.05);
        color: var(--primary);
        border-color: transparent;
    }

    .ai-suggestion-btn:hover {
        background: var(--primary);
        color: #fff;
    }

    .filter-controls,
    .framework-card,
    .modal-content {
        border-radius: 18px;
    }

    .framework-header .framework-title {
        color: var(--primary);
    }

    .framework-id,
    .framework-stat-label,
    .competency-meta,
    .competency-description {
        color: var(--muted);
    }

    .framework-toggle,
    .competency-toggle {
        background: rgba(15, 23, 42, 0.07);
        color: var(--primary);
    }

    .competency-name {
        color: var(--primary);
        font-weight: 600;
    }

    .modal-overlay {
        background: rgba(8, 12, 30, 0.7);
    }

    .frameworks-btn,
    .import-btn,
    .export-btn {
        background: var(--primary) !important;
        border: none;
        color: #fff;
    }

    .frameworks-btn:hover,
    .import-btn:hover,
    .export-btn:hover {
        background: #10204b !important;
    }

    .framework-preview-box {
        background: white;
        border-radius: 12px;
        padding: 24px;
    }

    .framework-preview-header {
        border-bottom: 2px solid #e1f5fe;
        padding-bottom: 16px;
        margin-bottom: 20px;
    }

    .framework-preview-header h3 {
        font-size: 1.5rem;
        font-weight: 700;
        color: #1976d2;
        margin: 0 0 8px 0;
    }

    .framework-preview-id {
        font-size: 0.9rem;
        color: #546e7a;
        font-family: 'Courier New', monospace;
        margin: 0 0 8px 0;
    }

    .framework-preview-description {
        font-size: 0.95rem;
        color: #37474f;
        line-height: 1.5;
        margin: 8px 0 0 0;
    }

    .competencies-preview-list {
        margin: 20px 0;
    }

    .competencies-preview-list h4 {
        font-size: 1.2rem;
        font-weight: 600;
        color: #37474f;
        margin: 0 0 16px 0;
    }

    .competencies-preview-list ul {
        list-style: none;
        padding: 0;
        margin: 0;
    }

    .competencies-preview-list li {
        background: #f8f9fa;
        border-left: 4px solid #4fc3f7;
        padding: 14px 18px;
        margin-bottom: 12px;
        border-radius: 8px;
        transition: all 0.2s;
    }

    .competencies-preview-list li.parent-comp {
        background: linear-gradient(135deg, #e3f2fd 0%, #f3e5f5 100%);
        border-left: 4px solid #1976d2;
        font-weight: 600;
    }

    .competencies-preview-list li.child-comp {
        background: #f8f9fa;
        border-left: 4px solid #4fc3f7;
        margin-left: 40px;
    }

    .competencies-preview-list li:hover {
        background: #e9ecef;
        border-left-color: #1976d2;
        transform: translateX(4px);
    }

    .competencies-preview-list li.parent-comp:hover {
        background: linear-gradient(135deg, #bbdefb 0%, #e1bee7 100%);
    }

    .competencies-preview-list li strong {
        font-size: 1.05rem;
        color: #1976d2;
        display: inline;
        margin-bottom: 6px;
    }

    .hierarchy-marker {
        color: #90a4ae;
        font-weight: bold;
        margin-right: 8px;
        font-size: 1.1rem;
    }

    .comp-preview-id {
        font-size: 0.85rem;
        color: #6c757d;
        font-family: 'Courier New', monospace;
        font-weight: normal;
    }

    .comp-preview-description {
        font-size: 0.9rem;
        color: #546e7a;
        line-height: 1.4;
        margin: 8px 0 0 0;
    }

    .framework-preview-actions {
        margin-top: 24px;
        padding-top: 20px;
        border-top: 2px solid #e1f5fe;
        display: flex;
        gap: 12px;
        justify-content: center;
    }

    .btn-add-generated-framework {
        background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
        color: white;
        border: none;
        padding: 14px 32px;
        border-radius: 12px;
        font-size: 1.05rem;
        font-weight: 600;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 10px;
        transition: all 0.3s ease;
        box-shadow: 0 4px 12px rgba(40, 167, 69, 0.3);
    }

    .btn-add-generated-framework:hover:not(:disabled) {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(40, 167, 69, 0.4);
        background: linear-gradient(135deg, #20c997 0%, #28a745 100%);
    }

    .btn-add-generated-framework:disabled {
        opacity: 0.7;
        cursor: not-allowed;
        transform: none;
    }

    .btn-regenerate-framework {
        background: linear-gradient(135deg, #f59e0b 0%, #f97316 100%);
        color: white;
        border: none;
        padding: 14px 28px;
        border-radius: 12px;
        font-size: 1rem;
        font-weight: 600;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        transition: all 0.3s ease;
        box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
    }

    .btn-regenerate-framework:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(245, 158, 11, 0.4);
        background: linear-gradient(135deg, #f97316 0%, #f59e0b 100%);
    }

    /* Filter Controls */
    .filter-controls {
        background: white;
        border-radius: 16px;
        padding: 24px;
        margin-bottom: 32px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        border: 1px solid #e1f5fe;
    }

    .filter-row {
        display: flex;
        gap: 16px;
        flex-wrap: wrap;
        align-items: flex-end;
    }

    .filter-group {
        flex: 1;
        min-width: 200px;
    }

    .filter-label {
        display: block;
        font-size: 14px;
        font-weight: 600;
        color: #37474f;
        margin-bottom: 8px;
    }

    .filter-select,
    .filter-input {
        width: 100%;
        padding: 12px 16px;
        border: 2px solid #e1f5fe;
        border-radius: 10px;
        font-size: 14px;
        font-weight: 500;
        color: #37474f;
        background: white;
        transition: border-color 0.2s;
    }

    .filter-select:focus,
    .filter-input:focus {
        outline: none;
        border-color: #4fc3f7;
    }

    .filter-btn {
        padding: 12px 24px;
        background: linear-gradient(135deg, #81d4fa 0%, #4fc3f7 100%);
        color: white;
        border: none;
        border-radius: 10px;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        transition: transform 0.2s, box-shadow 0.2s;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }

    .filter-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(129, 212, 250, 0.4);
        text-decoration: none;
        color: white;
    }

    .filter-btn.secondary {
        background: #e9ecef;
        color: #37474f;
        border: none;
    }

    .filter-btn.secondary:hover {
        background: #dee2e6;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    }

    /* Competency Framework Cards */
    .frameworks-grid {
        display: grid;
        gap: 24px;
        margin-bottom: 40px;
    }

    .framework-card {
        background: white;
        border-radius: 16px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        border: 1px solid #e1f5fe;
        overflow: hidden;
        transition: transform 0.2s, box-shadow 0.2s;
    }

    .framework-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
    }

    .framework-header {
        background: linear-gradient(135deg, #e1f5fe 0%, #f3e5f5 100%);
        color: #1976d2;
        padding: 24px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-bottom: 1px solid #b3e5fc;
    }

    .framework-title {
        font-size: 20px;
        font-weight: 700;
        margin-bottom: 4px;
    }

    .framework-id {
        font-size: 12px;
        opacity: 0.9;
    }

    .framework-toggle {
        background: rgba(255, 255, 255, 0.2);
        border: none;
        color: #1976d2;
        width: 36px;
        height: 36px;
        border-radius: 8px;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: background 0.2s;
    }

    .framework-toggle:hover {
        background: rgba(255, 255, 255, 0.3);
    }

    .framework-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 16px;
        padding: 24px;
        border-bottom: 1px solid #e1f5fe;
    }

    .framework-stat {
        text-align: center;
    }

    .framework-stat-value {
        font-size: 24px;
        font-weight: 700;
        color: #1976d2;
        margin-bottom: 4px;
    }

    .framework-stat-label {
        font-size: 12px;
        color: #546e7a;
        font-weight: 500;
    }

    .framework-body {
        padding: 24px;
        display: none;
    }

    .framework-body.expanded {
        display: block;
    }

    /* Competency Tree */
    .competency-tree {
        list-style: none;
    }

    .competency-item {
        margin-bottom: 8px;
    }

    .competency-row {
        display: flex;
        align-items: center;
        padding: 12px 16px;
        background: #f8f9fa;
        border-radius: 10px;
        transition: background 0.2s;
    }

    .competency-row:hover {
        background: #e9ecef;
    }

    .competency-toggle {
        background: none;
        border: none;
        color: #6c757d;
        cursor: pointer;
        width: 24px;
        height: 24px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-right: 8px;
        transition: transform 0.2s;
    }

    .competency-toggle.expanded {
        transform: rotate(90deg);
    }

    .competency-name {
        flex: 1;
        font-size: 14px;
        font-weight: 600;
        color: #37474f;
    }

    .competency-meta {
        display: flex;
        gap: 16px;
        margin-right: 16px;
    }

    .meta-item {
        display: flex;
        align-items: center;
        gap: 4px;
        font-size: 12px;
        color: #6c757d;
    }

    .meta-item i {
        font-size: 14px;
    }

    .competency-actions {
        display: flex;
        gap: 8px;
    }

    .action-btn {
        padding: 6px 12px;
        border: none;
        border-radius: 6px;
        font-size: 12px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }

    .action-btn.primary {
        background: #4fc3f7;
        color: white;
        border: none;
    }

    .action-btn.primary:hover {
        background: #29b6f6;
        text-decoration: none;
        color: white;
    }

    .action-btn.secondary {
        background: #e9ecef;
        color: #37474f;
        border: none;
    }

    .action-btn.secondary:hover {
        background: #dee2e6;
        text-decoration: none;
        color: #37474f;
    }

    .action-btn.link-btn {
        background: #28a745;
        color: white;
        border: none;
    }

    .action-btn.link-btn:hover {
        background: #218838;
        text-decoration: none;
        color: white;
    }

    .action-btn.activity-btn {
        background: #6f42c1;
        color: white;
        border: none;
    }

    .action-btn.activity-btn:hover {
        background: #5a2d91;
        text-decoration: none;
        color: white;
    }

    .competency-children {
        margin-left: 32px;
        margin-top: 8px;
        display: none;
    }

    .competency-children.expanded {
        display: block;
    }

    /* Empty State */
    .empty-state {
        text-align: center;
        padding: 60px 20px;
    }

    .empty-state-icon {
        font-size: 64px;
        color: #dee2e6;
        margin-bottom: 16px;
    }

    .empty-state-title {
        font-size: 20px;
        font-weight: 700;
        color: #37474f;
        margin-bottom: 8px;
    }

    .empty-state-text {
        font-size: 14px;
        color: #6c757d;
        margin-bottom: 24px;
    }

    /* Responsive */
    @media (max-width: 768px) {
        .admin-sidebar {
            transform: translateX(-100%);
        }

        .admin-sidebar.open {
            transform: translateX(0);
        }

        .admin-main-content {
            margin-left: 0;
            padding: 20px;
        }

        .stats-grid {
            grid-template-columns: 1fr;
        }

        .filter-row {
            flex-direction: column;
        }

        .filter-group {
            width: 100%;
        }
    }

    /* Loading State */
    .loading {
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 60px 20px;
    }

    .spinner {
        width: 48px;
        height: 48px;
        border: 4px solid #e9ecef;
        border-top-color: #667eea;
        border-radius: 50%;
        animation: spin 1s linear infinite;
    }

    @keyframes spin {
        to { transform: rotate(360deg); }
    }

    /* Modal Styles */
    .modal-overlay {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.5);
        z-index: 2000;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 20px;
        overflow-y: auto;
    }

    .modal-overlay.show {
        display: flex !important;
    }

    .modal-content {
        background: white;
        border-radius: 16px;
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
        max-width: 600px;
        width: 90%;
        max-height: 85vh;
        overflow-y: auto;
        animation: modalSlideIn 0.3s ease-out;
        position: relative;
        flex-shrink: 0;
    }

    @keyframes modalSlideIn {
        from {
            opacity: 0;
            transform: translateY(-50px) scale(0.9);
        }
        to {
            opacity: 1;
            transform: translateY(0) scale(1);
        }
    }

    .modal-header {
        background: linear-gradient(135deg, #e1f5fe 0%, #f3e5f5 100%);
        color: #1976d2;
        padding: 24px;
        border-radius: 16px 16px 0 0;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .modal-title {
        font-size: 20px;
        font-weight: 700;
        margin: 0;
    }

    .modal-close {
        background: none;
        border: none;
        font-size: 24px;
        color: #1976d2;
        cursor: pointer;
        width: 32px;
        height: 32px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        transition: background 0.2s;
    }

    .modal-close:hover {
        background: rgba(255, 255, 255, 0.2);
    }

    .modal-body {
        padding: 24px;
    }

    .modal-section {
        margin-bottom: 24px;
    }

    .modal-section:last-child {
        margin-bottom: 0;
    }

    .section-title {
        font-size: 16px;
        font-weight: 600;
        color: #37474f;
        margin-bottom: 12px;
    }

    .course-search {
        position: relative;
        margin-bottom: 16px;
    }

    .course-search-input {
        width: 100%;
        padding: 12px 16px 12px 40px;
        border: 2px solid #e1f5fe;
        border-radius: 10px;
        font-size: 14px;
        font-weight: 500;
        color: #37474f;
        background: white;
        transition: border-color 0.2s;
    }

    .course-search-input:focus {
        outline: none;
        border-color: #4fc3f7;
    }

    .course-search-icon {
        position: absolute;
        left: 12px;
        top: 50%;
        transform: translateY(-50%);
        color: #6c757d;
        font-size: 16px;
    }

    .course-list {
        max-height: 300px;
        overflow-y: auto;
        border: 1px solid #e1f5fe;
        border-radius: 10px;
        background: white;
    }

    .course-item {
        padding: 12px 16px;
        border-bottom: 1px solid #f8f9fa;
        cursor: pointer;
        transition: background 0.2s;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    .course-item:last-child {
        border-bottom: none;
    }

    .course-item:hover {
        background: #f8f9fa;
    }

    .course-item.selected {
        background: #e3f2fd;
        border-left: 4px solid #1976d2;
    }

    .course-info {
        flex: 1;
    }

    .course-name {
        font-size: 14px;
        font-weight: 600;
        color: #37474f;
        margin-bottom: 4px;
    }

    .course-meta {
        font-size: 12px;
        color: #6c757d;
    }

    .course-checkbox {
        width: 18px;
        height: 18px;
        margin-left: 12px;
    }

    .modal-footer {
        padding: 24px;
        border-top: 1px solid #e1f5fe;
        display: flex;
        justify-content: flex-end;
        gap: 12px;
    }

    .btn {
        padding: 12px 24px;
        border: none;
        border-radius: 10px;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }

    .btn-primary {
        background: linear-gradient(135deg, #81d4fa 0%, #4fc3f7 100%);
        color: white;
    }

    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(129, 212, 250, 0.4);
        text-decoration: none;
        color: white;
    }

    .btn-secondary {
        background: #e9ecef;
        color: #37474f;
    }

    .btn-secondary:hover {
        background: #dee2e6;
        text-decoration: none;
        color: #37474f;
    }

    .btn:disabled {
        opacity: 0.6;
        cursor: not-allowed;
        transform: none !important;
        box-shadow: none !important;
    }

    .loading-spinner {
        width: 16px;
        height: 16px;
        border: 2px solid transparent;
        border-top-color: currentColor;
        border-radius: 50%;
        animation: spin 1s linear infinite;
    }

    /* Activity List Styles - Simplified without sections */



</style>

<?php

// Get overall statistics
$totalFrameworks = $DB->count_records('competency_framework');
$totalCompetencies = $DB->count_records('competency');

// Check if competency_coursecomp table exists
$competencyCourseCompAvailable = $DB->get_manager()->table_exists('competency_coursecomp');
$totalCourseLinks = $competencyCourseCompAvailable ? $DB->count_records('competency_coursecomp') : 0;

// Check if competency_modulecomp table exists for activity links
$competencyModuleCompAvailable = $DB->get_manager()->table_exists('competency_modulecomp');
$totalActivityLinks = $competencyModuleCompAvailable ? $DB->count_records('competency_modulecomp') : 0;

// Debug: Add a comment to show if the table is available
if ($competencyCourseCompAvailable) {
    echo "<!-- DEBUG: competency_coursecomp table is available -->";
} else {
    echo "<!-- DEBUG: competency_coursecomp table is NOT available -->";
}

// Count active students (users enrolled in at least one course)
$activeStudents = $DB->count_records_sql(
    "SELECT COUNT(DISTINCT ue.userid)
       FROM {user_enrolments} ue
       JOIN {enrol} e ON e.id = ue.enrolid
       JOIN {course} c ON c.id = e.courseid
      WHERE c.id > 1 AND c.visible = 1"
);

// Get proficiency data
$proficiencyCount = $DB->count_records('competency_usercompcourse', ['proficiency' => 1]);

// Page Header
echo "<div class='page-header'>";
echo "<div class='header-background'>";
echo "<div class='header-content'>";
echo "<h1 class='page-title'>Competency Maps</h1>";
echo "<p class='page-subtitle'>Comprehensive overview of competency frameworks, competencies, and student proficiency across the platform</p>";
echo "</div>";
echo "</div>";
echo "</div>";

// Import/Export functionality
echo '<div class="framework-actions">';
echo '<div class="action-buttons">';

// Competency frameworks management button
$frameworksUrl = new moodle_url('/admin/tool/lp/competencyframeworks.php', ['pagecontextid' => 1]);
echo '<a href="' . $frameworksUrl . '" class="btn btn-info frameworks-btn">';
echo '<i class="fa fa-layer-group"></i> Competency Frameworks';
echo '</a>';

// Import competency framework button
$importUrl = new moodle_url('/admin/tool/lpimportcsv/index.php');
echo '<a href="' . $importUrl . '" class="btn btn-primary import-btn">';
echo '<i class="fa fa-upload"></i> Import competency framework';
echo '</a>';

// Export competency framework button
$exportUrl = new moodle_url('/admin/tool/lpimportcsv/export.php');
echo '<a href="' . $exportUrl . '" class="btn btn-secondary export-btn">';
echo '<i class="fa fa-download"></i> Export competency framework';
echo '</a>';

echo '</div>';
echo '</div>';

// Statistics cards
echo '<div class="stats-grid">';
echo '<div class="stat-card">';
echo '<div class="stat-icon purple"><i class="fa fa-layer-group"></i></div>';
echo '<div class="stat-content">';
echo '<div class="stat-value">' . $totalFrameworks . '</div>';
echo '<div class="stat-label">Competency Frameworks</div>';
echo '</div>';
echo '</div>';

echo '<div class="stat-card">';
echo '<div class="stat-icon blue"><i class="fa fa-sitemap"></i></div>';
echo '<div class="stat-content">';
echo '<div class="stat-value">' . $totalCompetencies . '</div>';
echo '<div class="stat-label">Total Competencies</div>';
echo '</div>';
echo '</div>';

echo '<div class="stat-card">';
echo '<div class="stat-icon green"><i class="fa fa-tasks"></i></div>';
echo '<div class="stat-content">';
echo '<div class="stat-value">' . $totalActivityLinks . '</div>';
echo '<div class="stat-label">Activity Links</div>';
echo '</div>';
echo '</div>';

echo '<div class="stat-card">';
echo '<div class="stat-icon orange"><i class="fa fa-award"></i></div>';
echo '<div class="stat-content">';
echo '<div class="stat-value">' . $proficiencyCount . '</div>';
echo '<div class="stat-label">Proficient Students</div>';
echo '</div>';
echo '</div>';
echo '</div>';

// AI Assistant Section
echo '<div class="ai-assistant-section">';
echo '<div class="ai-assistant-header" onclick="toggleAIAssistant()">';
echo '<div class="ai-header-left">';
echo '<div class="ai-icon-wrapper">';
echo '<i class="fa fa-robot"></i>';
echo '</div>';
echo '<div class="ai-header-text">';
echo '<h2 class="ai-section-title">AI Framework Generator</h2>';
echo '<p class="ai-section-subtitle">Generate complete competency frameworks using AI</p>';
echo '</div>';
echo '</div>';
echo '<button class="ai-toggle-btn" id="aiToggleBtn" aria-label="Toggle AI Assistant">';
echo '<i class="fa fa-chevron-down"></i>';
echo '</button>';
echo '</div>';

echo '<div class="ai-assistant-body" id="aiAssistantBody" style="display: none;">';
echo '<div class="ai-input-section">';
echo '<div class="ai-prompt-area">';
echo '<label class="ai-label">What competency framework do you want to create?</label>';
echo '<div class="ai-input-wrapper">';
echo '<textarea id="aiFrameworkPrompt" class="ai-framework-input" placeholder="E.g., Create a competency framework for ISTE Standards for Students, Common Core Math, digital literacy, project management, etc." rows="3"></textarea>';
echo '<div class="ai-quick-suggestions">';
echo '<span class="ai-suggestion-label">Quick suggestions:</span>';
echo '<button class="ai-suggestion-btn" onclick="useAISuggestion(\'Create a competency framework for digital literacy\')">Digital Literacy</button>';
echo '<button class="ai-suggestion-btn" onclick="useAISuggestion(\'Generate a framework for project management skills\')">Project Management</button>';
echo '<button class="ai-suggestion-btn" onclick="useAISuggestion(\'Build a competency framework for nursing education\')">Nursing</button>';
echo '<button class="ai-suggestion-btn" onclick="useAISuggestion(\'Create competencies for software development\')">Software Development</button>';
echo '</div>';
echo '</div>';
echo '<button id="generateFrameworkBtn" class="ai-generate-btn" onclick="generateFramework()">';
echo '<i class="fa fa-magic"></i> Generate Framework with AI';
echo '</button>';
echo '</div>';
echo '</div>';

echo '<div id="aiGenerationStatus" class="ai-status-message" style="display: none;"></div>';
echo '<div id="aiGeneratedFramework" class="ai-generated-framework-container"></div>';
echo '</div>';
echo '</div>';

// Filter controls
echo '<div class="filter-controls">';
echo '<div class="filter-row">';
echo '<div class="filter-group">';
echo '<label class="filter-label">Search Competencies</label>';
echo '<input type="text" id="searchCompetencies" class="filter-input" placeholder="Search by name or ID...">';
echo '</div>';
echo '<div class="filter-group">';
echo '<label class="filter-label">Framework</label>';
echo '<select id="filterFramework" class="filter-select">';
echo '<option value="">All Frameworks</option>';

$frameworks = $DB->get_records('competency_framework', null, 'shortname ASC');
foreach ($frameworks as $fw) {
    echo '<option value="' . $fw->id . '">' . format_string($fw->shortname) . '</option>';
}

echo '</select>';
echo '</div>';
echo '<div class="filter-group">';
echo '<label class="filter-label">Actions</label>';
echo '<div style="display: flex; gap: 8px;">';
echo '<button class="filter-btn" onclick="applyFilters()"><i class="fa fa-filter"></i> Apply Filters</button>';
echo '<button class="filter-btn secondary" onclick="resetFilters()"><i class="fa fa-redo"></i> Reset</button>';
echo '</div>';
echo '</div>';
echo '</div>';
echo '</div>';

// Competency Frameworks
echo '<div class="frameworks-grid" id="frameworksContainer">';

if (empty($frameworks)) {
    echo '<div class="empty-state">';
    echo '<div class="empty-state-icon"><i class="fa fa-layer-group"></i></div>';
    echo '<div class="empty-state-title">No Competency Frameworks Found</div>';
    echo '<div class="empty-state-text">Create your first competency framework to get started with competency-based learning.</div>';
    echo '<a href="' . $CFG->wwwroot . '/admin/tool/lp/competencyframeworks.php" class="filter-btn"><i class="fa fa-plus"></i> Create Framework</a>';
    echo '</div>';
} else {
    foreach ($frameworks as $framework) {
        // Get competencies for this framework
        $competencies = $DB->get_records('competency', ['competencyframeworkid' => $framework->id], 'sortorder, shortname');
        
        // Get stats for this framework
        $compIds = array_keys($competencies);
        $activityLinkCount = 0;
        $proficientCount = 0;
        
        if (!empty($compIds)) {
            list($inSql, $params) = $DB->get_in_or_equal($compIds, SQL_PARAMS_NAMED);
            $activityLinkCount = $competencyModuleCompAvailable ? $DB->count_records_sql(
                "SELECT COUNT(1) FROM {competency_modulecomp} WHERE competencyid $inSql",
                $params
            ) : 0;
            $proficientCount = $DB->count_records_sql(
                "SELECT COUNT(1) FROM {competency_usercompcourse} WHERE competencyid $inSql AND proficiency = 1",
                $params
            );
        }
        
        echo '<div class="framework-card" data-framework-id="' . $framework->id . '">';
        echo '<div class="framework-header">';
        echo '<div>';
        echo '<div class="framework-title">' . format_string($framework->shortname) . '</div>';
        echo '<div class="framework-id">ID: ' . $framework->idnumber . '</div>';
        echo '</div>';
        echo '<button class="framework-toggle" onclick="toggleFramework(this)"><i class="fa fa-chevron-down"></i></button>';
        echo '</div>';
        
        echo '<div class="framework-stats">';
        echo '<div class="framework-stat">';
        echo '<div class="framework-stat-value">' . count($competencies) . '</div>';
        echo '<div class="framework-stat-label">Competencies</div>';
        echo '</div>';
        echo '<div class="framework-stat">';
        echo '<div class="framework-stat-value">' . $activityLinkCount . '</div>';
        echo '<div class="framework-stat-label">Activity Links</div>';
        echo '</div>';
        echo '<div class="framework-stat">';
        echo '<div class="framework-stat-value">' . $proficientCount . '</div>';
        echo '<div class="framework-stat-label">Proficient</div>';
        echo '</div>';
        echo '</div>';
        
        echo '<div class="framework-body">';
        
        if (empty($competencies)) {
            echo '<div class="empty-state">';
            echo '<div class="empty-state-icon"><i class="fa fa-sitemap"></i></div>';
            echo '<div class="empty-state-title">No Competencies</div>';
            echo '<div class="empty-state-text">Add competencies to this framework.</div>';
            echo '</div>';
        } else {
            // Build hierarchy
            $byParent = [];
            foreach ($competencies as $comp) {
                $parentId = $comp->parentid ?? 0;
                if (!isset($byParent[$parentId])) {
                    $byParent[$parentId] = [];
                }
                $byParent[$parentId][] = $comp;
            }
            
            // Render tree
            echo '<ul class="competency-tree">';
            renderCompetencyTree(0, $byParent, $DB);
            echo '</ul>';
        }
        
        echo '</div>';
        echo '</div>';
    }
}

echo '</div>';

// Course Link Modal
echo '<div class="modal-overlay" id="linkCourseModal">';
echo '<div class="modal-content">';
echo '<div class="modal-header">';
echo '<h3 class="modal-title" id="modalTitle">Link Competency to Course</h3>';
echo '<button class="modal-close" onclick="closeLinkCourseModal()">&times;</button>';
echo '</div>';
echo '<div class="modal-body">';
echo '<div class="modal-section">';
echo '<div class="section-title">Select Courses</div>';
echo '<div class="course-search">';
echo '<i class="fa fa-search course-search-icon"></i>';
echo '<input type="text" id="courseSearchInput" class="course-search-input" placeholder="Search courses...">';
echo '</div>';
echo '<div class="course-list" id="courseList">';
echo '<div class="loading">';
echo '<div class="spinner"></div>';
echo '</div>';
echo '</div>';
echo '</div>';
echo '</div>';
echo '<div class="modal-footer">';
echo '<button class="btn btn-secondary" onclick="closeLinkCourseModal()">Cancel</button>';
echo '<button class="btn btn-primary" id="linkCoursesBtn" onclick="linkSelectedCourses()" disabled>';
echo '<i class="fa fa-link"></i> Link Selected Courses';
echo '</button>';
echo '</div>';
echo '</div>';
echo '</div>';

// Activity Link Modal
echo '<div class="modal-overlay" id="linkActivityModal">';
echo '<div class="modal-content">';
echo '<div class="modal-header">';
echo '<h3 class="modal-title" id="activityModalTitle">Link Competency to Activity</h3>';
echo '<button class="modal-close" onclick="closeLinkActivityModal()">&times;</button>';
echo '</div>';
echo '<div class="modal-body">';
echo '<div class="modal-section">';
echo '<div class="section-title">Select Course</div>';
echo '<div class="course-search">';
echo '<i class="fa fa-search course-search-icon"></i>';
echo '<input type="text" id="activityCourseSearchInput" class="course-search-input" placeholder="Search courses...">';
echo '</div>';
echo '<div class="course-list" id="activityCourseList">';
echo '<div class="loading">';
echo '<div class="spinner"></div>';
echo '</div>';
echo '</div>';
echo '</div>';
echo '<div class="modal-section" id="activitySection" style="display: none;">';
echo '<div class="section-title">Select Activities/Resources</div>';
echo '<div class="course-search">';
echo '<i class="fa fa-search course-search-icon"></i>';
echo '<input type="text" id="activitySearchInput" class="course-search-input" placeholder="Search activities...">';
echo '</div>';
echo '<div class="course-list" id="activityList">';
echo '</div>';
echo '</div>';
echo '</div>';
echo '<div class="modal-footer">';
echo '<button class="btn btn-secondary" onclick="closeLinkActivityModal()">Cancel</button>';
echo '<button class="btn btn-primary" id="linkActivitiesBtn" onclick="linkSelectedActivities()" disabled>';
echo '<i class="fa fa-tasks"></i> Link Selected Activities';
echo '</button>';
echo '</div>';
echo '</div>';
echo '</div>';

// Helper function to render competency tree
function renderCompetencyTree($parentId, $byParent, $DB) {
    if (!isset($byParent[$parentId])) {
        return;
    }
    
    foreach ($byParent[$parentId] as $comp) {
        // Get stats for this competency
        $courseLinks = $competencyCourseCompAvailable ? $DB->count_records('competency_coursecomp', ['competencyid' => $comp->id]) : 0;
        
        // Check if competency has module links
        $hasModuleComp = $DB->get_manager()->table_exists('competency_modulecomp');
        $activityLinks = 0;
        if ($hasModuleComp) {
            $activityLinks = $DB->count_records('competency_modulecomp', ['competencyid' => $comp->id]);
        }
        
        $proficientCount = $DB->count_records('competency_usercompcourse', [
            'competencyid' => $comp->id,
            'proficiency' => 1
        ]);
        
        $hasChildren = isset($byParent[$comp->id]) && !empty($byParent[$comp->id]);
        
        echo '<li class="competency-item" data-competency-id="' . $comp->id . '">';
        echo '<div class="competency-row">';
        
        if ($hasChildren) {
            echo '<button class="competency-toggle" onclick="toggleCompetency(this)"><i class="fa fa-chevron-right"></i></button>';
        } else {
            echo '<span style="width: 24px; margin-right: 8px;"></span>';
        }
        
        echo '<span class="competency-name">' . format_string($comp->shortname) . '</span>';
        echo '<div class="competency-meta">';
        echo '<span class="meta-item"><i class="fa fa-tasks"></i> ' . $activityLinks . ' activities</span>';
        echo '<span class="meta-item"><i class="fa fa-award"></i> ' . $proficientCount . ' proficient</span>';
        echo '</div>';
        echo '<div class="competency-actions">';
        
        // Check if competency_modulecomp table exists
        $competencyModuleCompAvailable = $DB->get_manager()->table_exists('competency_modulecomp');
        if ($competencyModuleCompAvailable) {
            echo '<button class="action-btn activity-btn" onclick="const mc=document.querySelector(\'.admin-main-content\'); if(mc) mc.scrollTop=0; openLinkActivityModal(' . $comp->id . ', \'' . format_string($comp->shortname) . '\')"><i class="fa fa-tasks"></i> Link to Activity</button>';
        }
        echo '</div>';
        echo '</div>';
        
        if ($hasChildren) {
            echo '<ul class="competency-children">';
            renderCompetencyTree($comp->id, $byParent, $DB);
            echo '</ul>';
        }
        
        echo '</li>';
    }
}

?>

</div>

<script>
function toggleFramework(button) {
    const card = button.closest('.framework-card');
    const body = card.querySelector('.framework-body');
    const icon = button.querySelector('i');
    
    body.classList.toggle('expanded');
    
    if (body.classList.contains('expanded')) {
        icon.className = 'fa fa-chevron-up';
    } else {
        icon.className = 'fa fa-chevron-down';
    }
}

function toggleCompetency(button) {
    const item = button.closest('.competency-item');
    const children = item.querySelector('.competency-children');
    const icon = button.querySelector('i');
    
    if (children) {
        children.classList.toggle('expanded');
        button.classList.toggle('expanded');
        
        if (children.classList.contains('expanded')) {
            icon.className = 'fa fa-chevron-down';
        } else {
            icon.className = 'fa fa-chevron-right';
        }
    }
}

function applyFilters() {
    const searchTerm = document.getElementById('searchCompetencies').value.toLowerCase();
    const frameworkId = document.getElementById('filterFramework').value;
    
    const frameworkCards = document.querySelectorAll('.framework-card');
    
    frameworkCards.forEach(card => {
        const cardFrameworkId = card.getAttribute('data-framework-id');
        let showCard = true;
        
        // Filter by framework
        if (frameworkId && cardFrameworkId !== frameworkId) {
            showCard = false;
        }
        
        // Filter by search term
        if (searchTerm && showCard) {
            const competencyItems = card.querySelectorAll('.competency-item');
            let hasMatch = false;
            
            competencyItems.forEach(item => {
                const name = item.querySelector('.competency-name').textContent.toLowerCase();
                if (name.includes(searchTerm)) {
                    item.style.display = '';
                    hasMatch = true;
                } else {
                    item.style.display = 'none';
                }
            });
            
            if (!hasMatch) {
                showCard = false;
            }
        } else {
            // Show all competencies if no search term
            const competencyItems = card.querySelectorAll('.competency-item');
            competencyItems.forEach(item => {
                item.style.display = '';
            });
        }
        
        card.style.display = showCard ? '' : 'none';
    });
}

function resetFilters() {
    document.getElementById('searchCompetencies').value = '';
    document.getElementById('filterFramework').value = '';
    
    const frameworkCards = document.querySelectorAll('.framework-card');
    frameworkCards.forEach(card => {
        card.style.display = '';
        const competencyItems = card.querySelectorAll('.competency-item');
        competencyItems.forEach(item => {
            item.style.display = '';
        });
    });
}

// Enable real-time search
document.getElementById('searchCompetencies').addEventListener('input', function() {
    applyFilters();
});

document.getElementById('filterFramework').addEventListener('change', function() {
    applyFilters();
});

// Course linking functionality
let currentCompetencyId = null;
let currentCompetencyName = '';
let allCourses = [];
let selectedCourses = new Set();

function openLinkCourseModal(competencyId, competencyName) {
    currentCompetencyId = competencyId;
    currentCompetencyName = competencyName;
    selectedCourses.clear();
    
    // Scroll the main content area to top
    const mainContent = document.querySelector('.admin-main-content');
    if (mainContent) {
        mainContent.scrollTop = 0;
    }
    window.scrollTo(0, 0);
    document.documentElement.scrollTop = 0;
    document.body.scrollTop = 0;
    
    document.getElementById('modalTitle').textContent = `Link "${competencyName}" to Course`;
    
    // Lock scrolling and show modal
    if (mainContent) {
        mainContent.style.overflow = 'hidden';
    }
    document.body.style.overflow = 'hidden';
    document.getElementById('linkCourseModal').classList.add('show');
    document.getElementById('linkCoursesBtn').disabled = true;
    
    loadCourses();
}

function closeLinkCourseModal() {
    document.getElementById('linkCourseModal').classList.remove('show');
    document.getElementById('courseSearchInput').value = '';
    selectedCourses.clear();
    currentCompetencyId = null;
    currentCompetencyName = '';
    
    // Unlock scrolling
    const mainContent = document.querySelector('.admin-main-content');
    if (mainContent) {
        mainContent.style.overflow = '';
    }
    document.body.style.overflow = '';
}

function loadCourses() {
    const courseList = document.getElementById('courseList');
    courseList.innerHTML = '<div class="loading"><div class="spinner"></div></div>';
    
    // Fetch courses via AJAX
    const url = new URL(window.location.href);
    url.searchParams.set('action', 'get_courses');
    url.searchParams.set('competency_id', currentCompetencyId);
    
    fetch(url.toString())
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            if (data.error) {
                throw new Error(data.error);
            }
            allCourses = data.courses || [];
            renderCourseList(allCourses);
        })
        .catch(error => {
            console.error('Error loading courses:', error);
            courseList.innerHTML = `<div class="empty-state">
                <div class="empty-state-text">Error loading courses: ${error.message}</div>
                <button class="btn btn-secondary" onclick="loadCourses()" style="margin-top: 16px;">Retry</button>
            </div>`;
        });
}

function renderCourseList(courses) {
    const courseList = document.getElementById('courseList');
    
    if (courses.length === 0) {
        courseList.innerHTML = '<div class="empty-state"><div class="empty-state-text">No courses found.</div></div>';
        return;
    }
    
    courseList.innerHTML = courses.map(course => {
        const isAlreadyLinked = course.already_linked;
        const isSelected = selectedCourses.has(course.id);
        const isDisabled = isAlreadyLinked;
        
        return `
            <div class="course-item ${isSelected ? 'selected' : ''} ${isAlreadyLinked ? 'already-linked' : ''}" 
                 onclick="${isDisabled ? '' : 'toggleCourseSelection(' + course.id + ')'}" 
                 style="${isDisabled ? 'opacity: 0.6; cursor: not-allowed;' : ''}">
                <div class="course-info">
                    <div class="course-name">${course.fullname} ${isAlreadyLinked ? '<span style="color: #28a745; font-size: 12px;">(Already Linked)</span>' : ''}</div>
                    <div class="course-meta">ID: ${course.id} | Category: ${course.categoryname}</div>
                </div>
                <input type="checkbox" class="course-checkbox" 
                       ${isSelected ? 'checked' : ''} 
                       ${isDisabled ? 'disabled' : ''} 
                       onchange="${isDisabled ? '' : 'toggleCourseSelection(' + course.id + ')'}">
            </div>
        `;
    }).join('');
}

function toggleCourseSelection(courseId) {
    // Find the course in allCourses to check if it's already linked
    const course = allCourses.find(c => c.id == courseId);
    if (course && course.already_linked) {
        return; // Don't allow selection of already linked courses
    }
    
    if (selectedCourses.has(courseId)) {
        selectedCourses.delete(courseId);
    } else {
        selectedCourses.add(courseId);
    }
    
    // Update UI
    const courseItem = document.querySelector(`[onclick*="toggleCourseSelection(${courseId})"]`);
    if (courseItem) {
        const checkbox = courseItem.querySelector('.course-checkbox');
        
        if (selectedCourses.has(courseId)) {
            courseItem.classList.add('selected');
            checkbox.checked = true;
        } else {
            courseItem.classList.remove('selected');
            checkbox.checked = false;
        }
    }
    
    // Update button state
    document.getElementById('linkCoursesBtn').disabled = selectedCourses.size === 0;
}

function linkSelectedCourses() {
    if (selectedCourses.size === 0) return;
    
    const btn = document.getElementById('linkCoursesBtn');
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<div class="loading-spinner"></div> Linking...';
    
    const courseIds = Array.from(selectedCourses);
    
    // Send AJAX request to link courses
    const url = new URL(window.location.href);
    fetch(url.toString(), {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=link_courses&competency_id=${currentCompetencyId}&course_ids=${JSON.stringify(courseIds)}`
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            // Show success message
            let message = 'Successfully linked competency to ' + data.linked_count + ' course(s)';
            if (data.errors && data.errors.length > 0) {
                message += ' (Some courses could not be linked: ' + data.errors.join(', ') + ')';
            }
            showNotification(message, 'success');
            
            // Update course link counts in the UI
            updateCourseLinkCounts();
            
            // Close modal
            closeLinkCourseModal();
        } else {
            showNotification('Error linking courses: ' + (data.error || 'Unknown error'), 'error');
        }
    })
    .catch(error => {
        console.error('Error linking courses:', error);
        showNotification('Error linking courses: ' + error.message, 'error');
    })
    .finally(() => {
        btn.disabled = false;
        btn.innerHTML = originalText;
    });
}

function updateCourseLinkCounts() {
    // Reload the page to update all counts
    window.location.reload();
}

function showNotification(message, type) {
    // Create notification element
    const notification = document.createElement('div');
    notification.className = `notification notification-${type}`;
    notification.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        background: ${type === 'success' ? '#28a745' : '#dc3545'};
        color: white;
        padding: 16px 24px;
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        z-index: 3000;
        font-weight: 500;
        max-width: 400px;
        animation: slideInRight 0.3s ease-out;
    `;
    notification.textContent = message;
    
    document.body.appendChild(notification);
    
    // Remove after 5 seconds
    setTimeout(() => {
        notification.style.animation = 'slideOutRight 0.3s ease-out';
        setTimeout(() => {
            if (notification.parentNode) {
                notification.parentNode.removeChild(notification);
            }
        }, 300);
    }, 5000);
}

// Add CSS for notifications
const notificationCSS = `
    @keyframes slideInRight {
        from { transform: translateX(100%); opacity: 0; }
        to { transform: translateX(0); opacity: 1; }
    }
    @keyframes slideOutRight {
        from { transform: translateX(0); opacity: 1; }
        to { transform: translateX(100%); opacity: 0; }
    }
`;
const style = document.createElement('style');
style.textContent = notificationCSS;
document.head.appendChild(style);

// Course search functionality
document.getElementById('courseSearchInput').addEventListener('input', function() {
    const searchTerm = this.value.toLowerCase();
    const filteredCourses = allCourses.filter(course => 
        course.fullname.toLowerCase().includes(searchTerm) ||
        course.shortname.toLowerCase().includes(searchTerm) ||
        course.categoryname.toLowerCase().includes(searchTerm)
    );
    renderCourseList(filteredCourses);
});

// Close modal when clicking outside
document.getElementById('linkCourseModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeLinkCourseModal();
    }
});

// Activity linking functionality
let currentActivityCompetencyId = null;
let currentActivityCompetencyName = '';
let allActivityCourses = [];
let allActivities = [];
let selectedActivityCourse = null;
let selectedActivities = new Set();

function openLinkActivityModal(competencyId, competencyName) {
    currentActivityCompetencyId = competencyId;
    currentActivityCompetencyName = competencyName;
    selectedActivities.clear();
    selectedActivityCourse = null;
    
    // Scroll the main content area to top
    const mainContent = document.querySelector('.admin-main-content');
    if (mainContent) {
        mainContent.scrollTop = 0;
    }
    window.scrollTo(0, 0);
    document.documentElement.scrollTop = 0;
    document.body.scrollTop = 0;
    
    document.getElementById('activityModalTitle').textContent = `Link "${competencyName}" to Activity`;
    
    // Lock scrolling and show modal
    if (mainContent) {
        mainContent.style.overflow = 'hidden';
    }
    document.body.style.overflow = 'hidden';
    document.getElementById('linkActivityModal').classList.add('show');
    document.getElementById('linkActivitiesBtn').disabled = true;
    document.getElementById('activitySection').style.display = 'none';
    
    loadActivityCourses();
}

function closeLinkActivityModal() {
    document.getElementById('linkActivityModal').classList.remove('show');
    document.getElementById('activityCourseSearchInput').value = '';
    document.getElementById('activitySearchInput').value = '';
    selectedActivities.clear();
    selectedActivityCourse = null;
    currentActivityCompetencyId = null;
    currentActivityCompetencyName = '';
    document.getElementById('activitySection').style.display = 'none';
    
    // Unlock scrolling
    const mainContent = document.querySelector('.admin-main-content');
    if (mainContent) {
        mainContent.style.overflow = '';
    }
    document.body.style.overflow = '';
}

function loadActivityCourses() {
    const courseList = document.getElementById('activityCourseList');
    courseList.innerHTML = '<div class="loading"><div class="spinner"></div></div>';
    
    // Fetch courses via AJAX (reuse the same endpoint as course linking)
    const url = new URL(window.location.href);
    url.searchParams.set('action', 'get_courses');
    url.searchParams.set('competency_id', currentActivityCompetencyId);
    
    fetch(url.toString())
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            if (data.error) {
                throw new Error(data.error);
            }
            allActivityCourses = data.courses || [];
            renderActivityCourseList(allActivityCourses);
        })
        .catch(error => {
            console.error('Error loading courses for activities:', error);
            courseList.innerHTML = `<div class="empty-state">
                <div class="empty-state-text">Error loading courses: ${error.message}</div>
                <button class="btn btn-secondary" onclick="loadActivityCourses()" style="margin-top: 16px;">Retry</button>
            </div>`;
        });
}

function renderActivityCourseList(courses) {
    const courseList = document.getElementById('activityCourseList');
    
    if (courses.length === 0) {
        courseList.innerHTML = '<div class="empty-state"><div class="empty-state-text">No courses found.</div></div>';
        return;
    }
    
    courseList.innerHTML = courses.map(course => `
        <div class="course-item ${selectedActivityCourse === course.id ? 'selected' : ''}" data-course-id="${course.id}" onclick="selectActivityCourse(${course.id})">
            <div class="course-info">
                <div class="course-name">${course.fullname}</div>
                <div class="course-meta">ID: ${course.id} | Category: ${course.categoryname}</div>
            </div>
            <input type="radio" name="activityCourse" class="course-checkbox" data-course-id="${course.id}" ${selectedActivityCourse === course.id ? 'checked' : ''} onchange="selectActivityCourse(${course.id})">
        </div>
    `).join('');
}

function selectActivityCourse(courseId) {
    selectedActivityCourse = courseId;
    selectedActivities.clear();
    
    // Update UI
    const courseItems = document.querySelectorAll('#activityCourseList .course-item');
    courseItems.forEach(item => {
        item.classList.remove('selected');
        const radio = item.querySelector('input[type="radio"]');
        radio.checked = false;
    });
    
    const selectedItem = document.querySelector(`#activityCourseList .course-item[data-course-id="${courseId}"]`);
    if (selectedItem) {
        selectedItem.classList.add('selected');
        const radio = selectedItem.querySelector('input[type="radio"]');
        radio.checked = true;
    }
    
    // Show activity section and load activities
    document.getElementById('activitySection').style.display = 'block';
    loadActivities(courseId);
}

function loadActivities(courseId) {
    const activityList = document.getElementById('activityList');
    activityList.innerHTML = '<div class="loading"><div class="spinner"></div></div>';
    
    const url = new URL(window.location.href);
    url.searchParams.set('action', 'get_activities');
    url.searchParams.set('competency_id', currentActivityCompetencyId);
    url.searchParams.set('course_id', courseId);
    
    fetch(url.toString())
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            if (data.error) {
                throw new Error(data.error);
            }
            allActivities = data.activities || [];
            renderActivityList(allActivities);
        })
        .catch(error => {
            console.error('Error loading activities:', error);
            activityList.innerHTML = `<div class="empty-state">
                <div class="empty-state-text">Error loading activities: ${error.message}</div>
                <button class="btn btn-secondary" onclick="loadActivities(${courseId})" style="margin-top: 16px;">Retry</button>
            </div>`;
        });
}

function renderActivityList(activities) {
    const activityList = document.getElementById('activityList');
    
    if (activities.length === 0) {
        activityList.innerHTML = '<div class="empty-state"><div class="empty-state-text">No activities found in this course.</div></div>';
        return;
    }
    
    // Render activities in a simple list without section grouping
    const html = activities.map(activity => {
        const isAlreadyLinked = activity.already_linked;
        const isSelected = selectedActivities.has(activity.cmid);
        const isDisabled = isAlreadyLinked;
        
        return `
            <div class="course-item ${isSelected ? 'selected' : ''} ${isAlreadyLinked ? 'already-linked' : ''}" 
                 onclick="${isDisabled ? '' : 'toggleActivitySelection(' + activity.cmid + ')'}" 
                 style="${isDisabled ? 'opacity: 0.6; cursor: not-allowed;' : ''}">
                <div class="course-info">
                    <div class="course-name">${activity.activityname} ${isAlreadyLinked ? '<span style="color: #28a745; font-size: 12px;">(Already Linked)</span>' : ''}</div>
                    <div class="course-meta">Type: ${activity.modulename} | ID: ${activity.cmid}</div>
                </div>
                <input type="checkbox" class="course-checkbox" 
                       ${isSelected ? 'checked' : ''} 
                       ${isDisabled ? 'disabled' : ''} 
                       onchange="${isDisabled ? '' : 'toggleActivitySelection(' + activity.cmid + ')'}">
            </div>
        `;
    }).join('');
    
    activityList.innerHTML = html;
}

function toggleActivitySelection(activityId) {
    // Find the activity in allActivities to check if it's already linked
    const activity = allActivities.find(a => a.cmid == activityId);
    if (activity && activity.already_linked) {
        return; // Don't allow selection of already linked activities
    }
    
    if (selectedActivities.has(activityId)) {
        selectedActivities.delete(activityId);
    } else {
        selectedActivities.add(activityId);
    }
    
    // Update UI
    const activityItem = document.querySelector(`[onclick*="toggleActivitySelection(${activityId})"]`);
    if (activityItem) {
        const checkbox = activityItem.querySelector('.course-checkbox');
        
        if (selectedActivities.has(activityId)) {
            activityItem.classList.add('selected');
            checkbox.checked = true;
        } else {
            activityItem.classList.remove('selected');
            checkbox.checked = false;
        }
    }
    
    // Update button state
    document.getElementById('linkActivitiesBtn').disabled = selectedActivities.size === 0;
}

function linkSelectedActivities() {
    if (selectedActivities.size === 0) return;
    
    const btn = document.getElementById('linkActivitiesBtn');
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<div class="loading-spinner"></div> Linking...';
    
    const activityIds = Array.from(selectedActivities);
    
    const url = new URL(window.location.href);
    fetch(url.toString(), {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=link_activities&competency_id=${currentActivityCompetencyId}&cm_ids=${JSON.stringify(activityIds)}`
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            let message = 'Successfully linked competency to ' + data.linked_count + ' activity(ies)';
            if (data.errors && data.errors.length > 0) {
                message += ' (Some activities could not be linked: ' + data.errors.join(', ') + ')';
            }
            showNotification(message, 'success');
            
            // Update activity link counts in the UI
            updateActivityLinkCounts();
            
            // Close modal
            closeLinkActivityModal();
        } else {
            showNotification('Error linking activities: ' + (data.error || 'Unknown error'), 'error');
        }
    })
    .catch(error => {
        console.error('Error linking activities:', error);
        showNotification('Error linking activities: ' + error.message, 'error');
    })
    .finally(() => {
        btn.disabled = false;
        btn.innerHTML = originalText;
    });
}

function updateActivityLinkCounts() {
    // Reload the page to update all counts
    window.location.reload();
}

// Activity course search functionality
document.getElementById('activityCourseSearchInput').addEventListener('input', function() {
    const searchTerm = this.value.toLowerCase();
    const filteredCourses = allActivityCourses.filter(course => 
        course.fullname.toLowerCase().includes(searchTerm) ||
        course.shortname.toLowerCase().includes(searchTerm) ||
        course.categoryname.toLowerCase().includes(searchTerm)
    );
    renderActivityCourseList(filteredCourses);
});

// Activity search functionality
document.getElementById('activitySearchInput').addEventListener('input', function() {
    const searchTerm = this.value.toLowerCase();
    const filteredActivities = allActivities.filter(activity => 
        activity.activityname.toLowerCase().includes(searchTerm) ||
        activity.modulename.toLowerCase().includes(searchTerm) ||
        (activity.sectionname && activity.sectionname.toLowerCase().includes(searchTerm))
    );
    renderActivityList(filteredActivities);
});

// Close activity modal when clicking outside
document.getElementById('linkActivityModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeLinkActivityModal();
    }
});

// ================== AI FRAMEWORK GENERATION ==================

// Toggle AI Assistant Section
function toggleAIAssistant() {
    const body = document.getElementById('aiAssistantBody');
    const toggleBtn = document.getElementById('aiToggleBtn');
    
    if (body.style.display === 'none') {
        body.style.display = 'block';
        toggleBtn.classList.add('expanded');
    } else {
        body.style.display = 'none';
        toggleBtn.classList.remove('expanded');
    }
}

// Use AI Suggestion
function useAISuggestion(text) {
    document.getElementById('aiFrameworkPrompt').value = text;
    document.getElementById('aiFrameworkPrompt').focus();
}

// Generate Framework with AI
let currentGeneratedFramework = null;

// Load persisted framework on page load
document.addEventListener('DOMContentLoaded', function() {
    const savedFramework = sessionStorage.getItem('aiGeneratedFramework');
    if (savedFramework) {
        try {
            currentGeneratedFramework = JSON.parse(savedFramework);
            displayGeneratedFramework(currentGeneratedFramework);
            
            // Check if framework was already added to system
            if (currentGeneratedFramework.addedToSystem) {
                showAIStatus('success', '✅ This framework has been added to your system and remains visible for reference.');
                
                // Update the UI to show it's already added
                setTimeout(() => {
                    const addBtn = document.querySelector('.btn-add-generated-framework');
                    if (addBtn && !addBtn.disabled) {
                        const contextId = currentGeneratedFramework.contextId || 1;
                        const viewUrl = '<?php echo $CFG->wwwroot; ?>/admin/tool/lp/editcompetencyframework.php?id=' + currentGeneratedFramework.frameworkId + '&pagecontextid=' + contextId;
                        
                        const actionsDiv = addBtn.parentNode;
                        actionsDiv.innerHTML = '<div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">' +
                            '<button class="btn-add-generated-framework" disabled style="background: linear-gradient(135deg, #28a745 0%, #20c997 100%);">' +
                            '<i class="fa fa-check-circle"></i> Already Added to System' +
                            '</button>' +
                            '<a href="' + viewUrl + '" class="btn-add-generated-framework" style="background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);" target="_blank">' +
                            '<i class="fa fa-external-link-alt"></i> View Framework' +
                            '</a>' +
                            '<button class="btn-regenerate-framework" onclick="generateFramework()">' +
                            '<i class="fa fa-sync-alt"></i> Create New Framework' +
                            '</button>' +
                            '<button class="btn-clear-framework" onclick="clearGeneratedFramework()" style="background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);">' +
                            '<i class="fa fa-times-circle"></i> Clear' +
                            '</button>' +
                            '</div>';
                        
                        // Add badge to header
                        const frameworkHeader = document.querySelector('.framework-preview-header h3');
                        if (frameworkHeader && !frameworkHeader.querySelector('.added-badge')) {
                            frameworkHeader.innerHTML += ' <span class="added-badge" style="background: linear-gradient(135deg, #28a745 0%, #20c997 100%); color: white; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; margin-left: 10px;">✓ In System</span>';
                        }
                    }
                }, 100);
            } else {
                showAIStatus('success', '✨ Previously generated framework loaded. You can add it or create a new one.');
            }
        } catch (e) {
            console.error('Failed to load saved framework:', e);
            sessionStorage.removeItem('aiGeneratedFramework');
        }
    }
});

function generateFramework() {
    const prompt = document.getElementById('aiFrameworkPrompt').value.trim();
    
    if (!prompt) {
        showAIStatus('error', 'Please enter a description for the framework you want to create.');
        return;
    }
    
    // Disable button
    const btn = document.getElementById('generateFrameworkBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Generating with AI...';
    
    // Show loading status
    showAIStatus('loading', 'AI is generating your competency framework. This may take 15-20 seconds...');
    
    // Clear previous framework (both UI and storage)
    document.getElementById('aiGeneratedFramework').innerHTML = '';
    currentGeneratedFramework = null;
    sessionStorage.removeItem('aiGeneratedFramework');
    
    // Make AJAX call to generate framework
    fetch(window.location.href, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=generate_framework&prompt=' + encodeURIComponent(prompt)
    })
    .then(response => response.json())
    .then(data => {
        // Re-enable button
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-magic"></i> Generate Framework with AI';
        
        if (data.success && data.framework) {
            currentGeneratedFramework = data.framework;
            
            // Save to sessionStorage for persistence
            try {
                sessionStorage.setItem('aiGeneratedFramework', JSON.stringify(data.framework));
            } catch (e) {
                console.warn('Could not save framework to storage:', e);
            }
            
            showAIStatus('success', '✨ Framework generated successfully! Review it below.');
            displayGeneratedFramework(data.framework);
        } else {
            showAIStatus('error', data.error || 'Failed to generate framework. Please try again.');
        }
    })
    .catch(error => {
        console.error('Framework generation error:', error);
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-magic"></i> Generate Framework with AI';
        showAIStatus('error', 'Connection error. Please check your internet connection and try again.');
    });
}

// Show AI Status Message
function showAIStatus(type, message) {
    const statusDiv = document.getElementById('aiGenerationStatus');
    statusDiv.className = 'ai-status-message ' + type;
    
    if (type === 'loading') {
        statusDiv.innerHTML = '<div class="ai-spinner"></div><span>' + message + '</span>';
    } else if (type === 'success') {
        statusDiv.innerHTML = '<i class="fa fa-check-circle" style="font-size: 20px;"></i><span>' + message + '</span>';
    } else if (type === 'error') {
        statusDiv.innerHTML = '<i class="fa fa-exclamation-circle" style="font-size: 20px;"></i><span>' + message + '</span>';
    }
    
    statusDiv.style.display = 'flex';
    
    // Auto-hide success/error messages after 5 seconds
    if (type !== 'loading') {
        setTimeout(() => {
            statusDiv.style.display = 'none';
        }, 5000);
    }
}

// Display Generated Framework
function displayGeneratedFramework(framework) {
    const container = document.getElementById('aiGeneratedFramework');
    
    let html = '<div class="generated-framework">';
    html += '<div class="framework-preview-box">';
    
    // Framework header
    html += '<div class="framework-preview-header">';
    html += '<h3>' + escapeHtml(framework.framework.shortname) + '</h3>';
    html += '<p class="framework-preview-id">ID: ' + escapeHtml(framework.framework.idnumber) + '</p>';
    if (framework.framework.description) {
        html += '<p class="framework-preview-description">' + escapeHtml(framework.framework.description) + '</p>';
    }
    html += '</div>';
    
    // Competencies list with hierarchy
    html += '<div class="competencies-preview-list">';
    html += '<h4>Competencies (' + framework.competencies.length + '):</h4>';
    html += '<ul class="hierarchy-list">';
    
    framework.competencies.forEach((comp, index) => {
        const isParent = (comp.parentid == 0);
        const cssClass = isParent ? 'parent-comp' : 'child-comp';
        
        html += '<li class="' + cssClass + '">';
        
        // Add visual hierarchy marker for child items
        if (!isParent) {
            html += '<span class="hierarchy-marker">└─</span> ';
        }
        
        html += '<strong>' + escapeHtml(comp.shortname) + '</strong> ';
        html += '<span class="comp-preview-id">(' + escapeHtml(comp.idnumber) + ')</span>';
        if (comp.description) {
            html += '<p class="comp-preview-description">' + escapeHtml(comp.description) + '</p>';
        }
        html += '</li>';
    });
    
    html += '</ul>';
    html += '</div>';
    
    // Action buttons
    html += '<div class="framework-preview-actions">';
    html += '<button class="btn-add-generated-framework" onclick="addGeneratedFrameworkToSystem(event)">';
    html += '<i class="fa fa-plus-circle"></i> Add This Framework to My System';
    html += '</button>';
    html += '<button class="btn-regenerate-framework" onclick="generateFramework()">';
    html += '<i class="fa fa-sync-alt"></i> Regenerate';
    html += '</button>';
    html += '<button class="btn-clear-framework" onclick="clearGeneratedFramework()" style="background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);">';
    html += '<i class="fa fa-times-circle"></i> Clear';
    html += '</button>';
    html += '</div>';
    
    html += '</div>';
    html += '</div>';
    
    container.innerHTML = html;
}

// Add Generated Framework to System
function addGeneratedFrameworkToSystem(event) {
    // Prevent default behavior
    if (event && event.preventDefault) {
        event.preventDefault();
        event.stopPropagation();
    }
    
    if (!currentGeneratedFramework) {
        showNotification('No framework to add', 'error');
        return;
    }
    
    // Get the button element safely
    const btn = event && event.target ? event.target.closest('button') : event;
    if (!btn) {
        console.error('Button element not found');
        return;
    }
    
    btn.disabled = true;
    const originalHtml = btn.innerHTML;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Adding to System...';
    
    // Make AJAX call to add framework
    fetch(window.location.href, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=add_generated_framework&frameworkdata=' + encodeURIComponent(JSON.stringify(currentGeneratedFramework))
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('Framework added successfully! Framework ID: ' + data.frameworkid, 'success');
            
            // Mark framework as added (keep in storage but mark it)
            if (currentGeneratedFramework) {
                currentGeneratedFramework.addedToSystem = true;
                currentGeneratedFramework.frameworkId = data.frameworkid;
                currentGeneratedFramework.contextId = data.contextid || 1;
                
                try {
                    sessionStorage.setItem('aiGeneratedFramework', JSON.stringify(currentGeneratedFramework));
                } catch (e) {
                    console.warn('Could not update framework in storage:', e);
                }
            }
            
            // Update the framework display to show it's been added
            showAIStatus('success', '✅ Framework added successfully! It\'s now in your system and will remain visible.');
            
            // Update button to show it's added
            btn.innerHTML = '<i class="fa fa-check-circle"></i> Already Added to System';
            btn.disabled = true;
            btn.style.background = 'linear-gradient(135deg, #28a745 0%, #20c997 100%)';
            
            // Add view framework link and reload button (include pagecontextid parameter)
            const contextId = data.contextid || 1;
            const viewUrl = '<?php echo $CFG->wwwroot; ?>/admin/tool/lp/editcompetencyframework.php?id=' + data.frameworkid + '&pagecontextid=' + contextId;
            
            const actionsDiv = btn.parentNode;
            actionsDiv.innerHTML = '<div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">' +
                '<button class="btn-add-generated-framework" disabled style="background: linear-gradient(135deg, #28a745 0%, #20c997 100%);">' +
                '<i class="fa fa-check-circle"></i> Already Added to System' +
                '</button>' +
                '<a href="' + viewUrl + '" class="btn-add-generated-framework" style="background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);" target="_blank">' +
                '<i class="fa fa-external-link-alt"></i> View Framework' +
                '</a>' +
                '<button class="btn-regenerate-framework" onclick="generateFramework()">' +
                '<i class="fa fa-sync-alt"></i> Create New Framework' +
                '</button>' +
                '<button class="btn-clear-framework" onclick="clearGeneratedFramework()" style="background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);">' +
                '<i class="fa fa-times-circle"></i> Clear' +
                '</button>' +
                '</div>';
            
            // Add a badge to the framework header showing it's in the system
            const frameworkHeader = document.querySelector('.framework-preview-header h3');
            if (frameworkHeader && !frameworkHeader.querySelector('.added-badge')) {
                frameworkHeader.innerHTML += ' <span class="added-badge" style="background: linear-gradient(135deg, #28a745 0%, #20c997 100%); color: white; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; margin-left: 10px;">✓ In System</span>';
            }
        } else {
            showNotification('Error: ' + (data.error || 'Failed to add framework'), 'error');
            btn.disabled = false;
            btn.innerHTML = originalHtml;
        }
    })
    .catch(error => {
        console.error('Add framework error:', error);
        showNotification('Connection error. Please try again.', 'error');
        btn.disabled = false;
        btn.innerHTML = originalHtml;
    });
}

// Clear Generated Framework
function clearGeneratedFramework() {
    if (confirm('Are you sure you want to clear this framework? This action cannot be undone.')) {
        // Clear from storage
        sessionStorage.removeItem('aiGeneratedFramework');
        currentGeneratedFramework = null;
        
        // Clear from UI
        document.getElementById('aiGeneratedFramework').innerHTML = '';
        document.getElementById('aiGenerationStatus').innerHTML = '';
        
        // Reset prompt
        document.getElementById('aiFrameworkPrompt').value = '';
        
        showAIStatus('success', 'Framework cleared. You can now create a new one.');
        
        // Auto-hide status after 3 seconds
        setTimeout(() => {
            document.getElementById('aiGenerationStatus').innerHTML = '';
        }, 3000);
    }
}

// Escape HTML to prevent XSS
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>

<?php

echo $OUTPUT->footer();

