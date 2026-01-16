<?php
/**
 * Get Course Structure API
 * Returns course sections (lessons) and modules in JSON format
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

// Security checks
require_login();
$context = context_system::instance();

// Restrict to teachers/admins
if (!has_capability('moodle/course:update', $context) && !has_capability('moodle/site:config', $context)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

$courseid = required_param('courseid', PARAM_INT);

try {
    // Get course
    $course = get_course($courseid);
    $coursecontext = context_course::instance($course->id);
    
    // Check if user has access to this course
    require_capability('moodle/course:update', $coursecontext);
    
    // Get course sections - only get main lesson sections (1-8)
    $sections = $DB->get_records_sql("
        SELECT * FROM {course_sections} 
        WHERE course = ? 
        AND section >= 1
        AND visible = 1
        AND component IS NULL
        ORDER BY section ASC
    ", [$courseid]);
    
    $structure = [];
    
    foreach ($sections as $section) {
        error_log("Processing section - ID: " . $section->id . ", Section Number: " . $section->section . ", Name: " . ($section->name ?: "Lesson " . $section->section));
        
        $sectionData = [
            'id' => $section->id,
            'section' => $section->section,
            'name' => $section->name ?: "Lesson " . $section->section,
            'modules' => []
        ];
        
        // Get subsections for this section
        // Subsections are course_sections with component='mod_subsection'
        // They are referenced in the parent section's sequence via course_modules
        $subsections = [];
        if (!empty($section->sequence)) {
            $moduleIds = explode(',', $section->sequence);
            $subsections = $DB->get_records_sql("
                SELECT cs.id, cs.section, cs.name, cs.visible, cm.id as cmid
                FROM {course_modules} cm
                JOIN {course_sections} cs ON cs.component = 'mod_subsection' AND cs.itemid = cm.instance
                JOIN {modules} m ON m.id = cm.module AND m.name = 'subsection'
                WHERE cm.id IN (" . implode(',', array_map('intval', $moduleIds)) . ")
                AND cm.course = ?
                ORDER BY cs.section
            ", [$courseid]);
        }
        
        if (!empty($subsections)) {
            // Add subsections as modules
            foreach ($subsections as $subsection) {
                error_log("  Adding subsection - Subsection ID: " . $subsection->id . ", Name: " . $subsection->name);
                
                $sectionData['modules'][] = [
                    'id' => $subsection->id,  // This is the course_sections.id (e.g., 21)
                    'section_id' => $subsection->id,  // Same as id for subsections
                    'name' => $subsection->name,
                    'type' => 'subsection',
                    'visible' => $subsection->visible
                ];
            }
        }
        
        // Also get actual modules/activities in this section (not subsections)
        if (!empty($section->sequence)) {
            $moduleIds = explode(',', $section->sequence);
            $modules = $DB->get_records_sql("
                SELECT cm.id, cm.section, cm.visible, m.name as modname, cm.instance
                FROM {course_modules} cm
                JOIN {modules} m ON m.id = cm.module
                WHERE cm.id IN (" . implode(',', array_map('intval', $moduleIds)) . ")
                AND cm.course = ?
                AND m.name != 'subsection'
                ORDER BY cm.section, cm.id
            ", [$courseid]);
            
            foreach ($modules as $module) {
                $moduleName = '';
                
                // Get module instance name based on module type
                switch ($module->modname) {
                    case 'assign':
                        $assign = $DB->get_record('assign', ['id' => $module->instance]);
                        $moduleName = $assign ? $assign->name : 'Assignment';
                        break;
                    case 'quiz':
                        $quiz = $DB->get_record('quiz', ['id' => $module->instance]);
                        $moduleName = $quiz ? $quiz->name : 'Quiz';
                        break;
                    case 'scorm':
                        $scorm = $DB->get_record('scorm', ['id' => $module->instance]);
                        $moduleName = $scorm ? $scorm->name : 'SCORM Package';
                        break;
                    case 'h5pactivity':
                        $h5p = $DB->get_record('h5pactivity', ['id' => $module->instance]);
                        $moduleName = $h5p ? $h5p->name : 'H5P Activity';
                        break;
                    case 'workshop':
                        $workshop = $DB->get_record('workshop', ['id' => $module->instance]);
                        $moduleName = $workshop ? $workshop->name : 'Workshop';
                        break;
                    case 'forum':
                        $forum = $DB->get_record('forum', ['id' => $module->instance]);
                        $moduleName = $forum ? $forum->name : 'Forum';
                        break;
                    case 'resource':
                        $resource = $DB->get_record('resource', ['id' => $module->instance]);
                        $moduleName = $resource ? $resource->name : 'Resource';
                        break;
                    default:
                        $moduleName = ucfirst($module->modname);
                        break;
                }
                
                error_log("  Adding module - Module ID: " . $module->id . ", Name: " . $moduleName . ", Section ID: " . $module->section);
                
                $sectionData['modules'][] = [
                    'id' => $module->id,
                    'section_id' => $module->section,  // Add the actual section ID
                    'name' => $moduleName,
                    'type' => $module->modname,
                    'visible' => $module->visible
                ];
            }
        }
        
        $structure[] = $sectionData;
    }
    
    echo json_encode([
        'success' => true,
        'structure' => $structure,
        'course' => [
            'id' => $course->id,
            'name' => $course->fullname
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Course structure error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error loading course structure: ' . $e->getMessage()
    ]);
}
?>