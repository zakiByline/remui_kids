<?php
/**
 * Course Timeline Page
 * Interface for managing course sections and activities
 */

require_once('../../../config.php');
require_login();

// Check admin capabilities
$context = context_system::instance();
require_capability('moodle/site:config', $context);

// Get current user
global $USER, $DB, $OUTPUT;

// Get course ID from URL parameter
$course_id = isset($_GET['course_id']) ? intval($_GET['course_id']) : 0;
$course = null;

if ($course_id > 0) {
    $course = $DB->get_record('course', ['id' => $course_id, 'visible' => 1]);
}

// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    switch ($_GET['action']) {
        case 'get_course_sections':
            if ($course_id > 0 && $course) {
                require_once($CFG->dirroot . '/course/lib.php');
                $modinfo = get_fast_modinfo($course);
                $sections = $modinfo->get_section_info_all();
                
                $sections_data = [];
                foreach ($sections as $section) {
                    $sections_data[] = [
                        'id' => $section->id,
                        'section' => $section->section,
                        'name' => get_section_name($course, $section),
                        'summary' => $section->summary,
                        'visible' => $section->visible,
                        'available' => $section->available,
                        'uservisible' => $section->uservisible,
                        'sequence' => $section->sequence
                    ];
                }
                echo json_encode(['status' => 'success', 'sections' => $sections_data]);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Invalid course ID']);
            }
            exit;
            
        case 'get_section_activities':
            $section_num = intval($_GET['section_num']);
            if ($section_num >= 0 && $course) {
                require_once($CFG->dirroot . '/course/lib.php');
                $modinfo = get_fast_modinfo($course);
                
                $activities = [];
                if (isset($modinfo->sections[$section_num])) {
                    foreach ($modinfo->sections[$section_num] as $cmid) {
                        $cm = $modinfo->cms[$cmid];
                        if ($cm->uservisible) {
                            $activities[] = [
                                'id' => $cm->id,
                                'name' => $cm->name,
                                'modname' => $cm->modname,
                                'url' => $cm->url ? $cm->url->out() : '',
                                'icon' => $cm->get_icon_url() ? $cm->get_icon_url()->out() : '',
                                'description' => $cm->content ?? '',
                                'visible' => $cm->visible,
                                'availablefrom' => $cm->availablefrom,
                                'availableuntil' => $cm->availableuntil
                            ];
                        }
                    }
                }
                echo json_encode(['status' => 'success', 'activities' => $activities]);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Invalid section number']);
            }
            exit;
            
        case 'create_section':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $section_name = trim($_POST['section_name'] ?? '');
                if ($section_name && $course) {
                    require_once($CFG->dirroot . '/course/lib.php');
                    
                    // Use Moodle's proper function to create a section
                    $section_id = course_add_section($course, $section_name);
                    
                    if ($section_id) {
                        echo json_encode(['status' => 'success', 'section_id' => $section_id, 'message' => 'Section created successfully']);
                    } else {
                        echo json_encode(['status' => 'error', 'message' => 'Failed to create section']);
                    }
                } else {
                    echo json_encode(['status' => 'error', 'message' => 'Invalid section name or course ID']);
                }
            }
            exit;
            
        case 'update_section':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $section_id = intval($_POST['section_id'] ?? 0);
                $section_name = trim($_POST['section_name'] ?? '');
                if ($section_id > 0 && $section_name && $course) {
                    require_once($CFG->dirroot . '/course/lib.php');
                    
                    $section = $DB->get_record('course_sections', ['id' => $section_id, 'course' => $course->id]);
                    if ($section) {
                        $section->name = $section_name;
                        $section->timemodified = time();
                        $DB->update_record('course_sections', $section);
                        
                        // Rebuild course cache
                        rebuild_course_cache($course->id, true);
                        
                        echo json_encode(['status' => 'success', 'message' => 'Section updated successfully']);
                    } else {
                        echo json_encode(['status' => 'error', 'message' => 'Section not found']);
                    }
                } else {
                    echo json_encode(['status' => 'error', 'message' => 'Invalid section ID or name']);
                }
            }
            exit;
            
        case 'delete_section':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $section_id = intval($_POST['section_id'] ?? 0);
                if ($section_id > 0 && $course) {
                    require_once($CFG->dirroot . '/course/lib.php');
                    
                    $section = $DB->get_record('course_sections', ['id' => $section_id, 'course' => $course->id]);
                    if ($section) {
                        // Use Moodle's proper function to delete a section
                        $result = course_delete_section($course, $section->section, true);
                        
                        if ($result) {
                            // Rebuild course cache
                            rebuild_course_cache($course->id, true);
                            echo json_encode(['status' => 'success', 'message' => 'Section deleted successfully']);
                        } else {
                            echo json_encode(['status' => 'error', 'message' => 'Failed to delete section']);
                        }
                    } else {
                        echo json_encode(['status' => 'error', 'message' => 'Section not found']);
                    }
                } else {
                    echo json_encode(['status' => 'error', 'message' => 'Invalid section ID']);
                }
            }
            exit;
            
        case 'create_activity':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $section_id = intval($_POST['section_id'] ?? 0);
                $activity_type = trim($_POST['activity_type'] ?? '');
                $activity_title = trim($_POST['activity_title'] ?? '');
                $activity_description = trim($_POST['activity_description'] ?? '');
                
                if ($section_id > 0 && $activity_type && $activity_title && $course) {
                    require_once($CFG->dirroot . '/course/lib.php');
                    require_once($CFG->dirroot . '/course/modlib.php');
                    
                    // Get the section number from section ID
                    $section = $DB->get_record('course_sections', ['id' => $section_id, 'course' => $course->id]);
                    if (!$section) {
                        echo json_encode(['status' => 'error', 'message' => 'Section not found']);
                        exit;
                    }
                    
                    // Create module info object
                    $moduleinfo = new stdClass();
                    $moduleinfo->modulename = $activity_type;
                    $moduleinfo->course = $course->id;
                    $moduleinfo->section = $section->section;
                    $moduleinfo->name = $activity_title;
                    $moduleinfo->intro = $activity_description;
                    $moduleinfo->introformat = FORMAT_HTML;
                    $moduleinfo->visible = 1;
                    $moduleinfo->visibleoncoursepage = 1;
                    $moduleinfo->completion = 0;
                    $moduleinfo->completionview = 0;
                    $moduleinfo->completionexpected = 0;
                    $moduleinfo->showdescription = 0;
                    $moduleinfo->availability = null;
                    
                    // Add type-specific fields
                    switch ($activity_type) {
                        case 'page':
                            $moduleinfo->content = $activity_description;
                            $moduleinfo->contentformat = FORMAT_HTML;
                            break;
                        case 'url':
                            $moduleinfo->externalurl = $_POST['url'] ?? '';
                            $moduleinfo->display = RESOURCELIB_DISPLAY_AUTO;
                            break;
                        case 'file':
                            // Handle file upload if needed
                            break;
                        case 'assign':
                            $moduleinfo->duedate = 0;
                            $moduleinfo->cutoffdate = 0;
                            $moduleinfo->gradingduedate = 0;
                            $moduleinfo->grade = 100;
                            break;
                        case 'quiz':
                            $moduleinfo->timeopen = 0;
                            $moduleinfo->timeclose = 0;
                            $moduleinfo->timelimit = 0;
                            $moduleinfo->grade = 100;
                            break;
                    }
                    
                    try {
                        $moduleinfo = create_module($moduleinfo);
                        echo json_encode(['status' => 'success', 'cm_id' => $moduleinfo->coursemodule, 'message' => 'Activity created successfully']);
                    } catch (Exception $e) {
                        echo json_encode(['status' => 'error', 'message' => 'Failed to create activity: ' . $e->getMessage()]);
                    }
                } else {
                    echo json_encode(['status' => 'error', 'message' => 'Missing required fields']);
                }
            }
            exit;
            
        case 'update_activity':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $cm_id = intval($_POST['cm_id'] ?? 0);
                $activity_title = trim($_POST['activity_title'] ?? '');
                if ($cm_id > 0 && $activity_title && $course) {
                    require_once($CFG->dirroot . '/course/lib.php');
                    require_once($CFG->dirroot . '/course/modlib.php');
                    
                    $cm = $DB->get_record('course_modules', ['id' => $cm_id, 'course' => $course->id]);
                    if ($cm) {
                        $module = $DB->get_record('modules', ['id' => $cm->module]);
                        if ($module) {
                            $instance = $DB->get_record($module->name, ['id' => $cm->instance]);
                            if ($instance) {
                                $instance->name = $activity_title;
                                $instance->timemodified = time();
                                $DB->update_record($module->name, $instance);
                                
                                // Rebuild course cache
                                rebuild_course_cache($course->id, true);
                                
                                echo json_encode(['status' => 'success', 'message' => 'Activity updated successfully']);
                            } else {
                                echo json_encode(['status' => 'error', 'message' => 'Activity instance not found']);
                            }
                        } else {
                            echo json_encode(['status' => 'error', 'message' => 'Module not found']);
                        }
                    } else {
                        echo json_encode(['status' => 'error', 'message' => 'Course module not found']);
                    }
                } else {
                    echo json_encode(['status' => 'error', 'message' => 'Invalid course module ID or title']);
                }
            }
            exit;
            
        case 'delete_activity':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $cm_id = intval($_POST['cm_id'] ?? 0);
                if ($cm_id > 0 && $course) {
                    require_once($CFG->dirroot . '/course/lib.php');
                    require_once($CFG->dirroot . '/course/modlib.php');
                    
                    $cm = $DB->get_record('course_modules', ['id' => $cm_id, 'course' => $course->id]);
                    if ($cm) {
                        // Use Moodle's proper function to delete a course module
                        $result = course_delete_module($cm_id);
                        
                        if ($result) {
                            // Rebuild course cache
                            rebuild_course_cache($course->id, true);
                            echo json_encode(['status' => 'success', 'message' => 'Activity deleted successfully']);
                        } else {
                            echo json_encode(['status' => 'error', 'message' => 'Failed to delete activity']);
                        }
                    } else {
                        echo json_encode(['status' => 'error', 'message' => 'Course module not found']);
                    }
                } else {
                    echo json_encode(['status' => 'error', 'message' => 'Invalid course module ID']);
                }
            }
            exit;
    }
}

// Helper functions for activity icons
function get_activity_icon_class($modname) {
    $icon_map = [
        'assign' => 'assignment',
        'quiz' => 'quiz',
        'forum' => 'forum',
        'page' => 'document',
        'url' => 'url',
        'file' => 'file',
        'folder' => 'folder',
        'scorm' => 'scorm',
        'text' => 'text',
        'zoom' => 'video',
        'attendance' => 'attendance',
        'edwiservideoactivity' => 'video'
    ];
    return $icon_map[$modname] ?? 'default';
}

function get_activity_icon_symbol($modname) {
    $icon_map = [
        'assign' => 'fa-tasks',
        'quiz' => 'fa-question-circle',
        'forum' => 'fa-comments',
        'page' => 'fa-file-text',
        'url' => 'fa-external-link',
        'file' => 'fa-file',
        'folder' => 'fa-folder',
        'scorm' => 'fa-graduation-cap',
        'text' => 'fa-align-left',
        'zoom' => 'fa-video-camera',
        'attendance' => 'fa-check-square',
        'edwiservideoactivity' => 'fa-play-circle'
    ];
    return $icon_map[$modname] ?? 'fa-puzzle-piece';
}

$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/admin/course_timeline.php', ['course_id' => $course_id]);
$PAGE->set_title('Course Timeline');
$PAGE->set_heading('Course Timeline');

echo $OUTPUT->header();
?>

<style>
/* Course Timeline Page Styles */
.timeline-container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 20px;
    background: #1a1a1a;
    min-height: 100vh;
    color: white;
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
    padding: 20px;
    background: #2a2a2a;
    border-radius: 15px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.3);
}

.page-title {
    font-size: 2rem;
    font-weight: 700;
    color: white;
    margin: 0;
}

.header-actions {
    display: flex;
    gap: 15px;
}

.header-btn {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border: none;
    padding: 12px 20px;
    border-radius: 8px;
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    gap: 8px;
}

.header-btn:hover {
    background: linear-gradient(135deg, #5a6fd8 0%, #6a4190 100%);
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
}

.header-btn.secondary {
    background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
}

.header-btn.secondary:hover {
    background: linear-gradient(135deg, #218838 0%, #1e7e34 100%);
    box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3);
}

.sections-container {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.section-item {
    background: #2a2a2a;
    border-radius: 15px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.3);
    overflow: hidden;
}

.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px 25px;
    background: #333;
    border-bottom: 1px solid #444;
}

.section-title {
    font-size: 1.3rem;
    font-weight: 600;
    color: white;
    margin: 0;
}

.section-actions {
    display: flex;
    gap: 10px;
}

.section-action-btn {
    width: 35px;
    height: 35px;
    border: none;
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.3s ease;
    font-size: 0.9rem;
}

.edit-btn {
    background: #17a2b8;
    color: white;
}

.edit-btn:hover {
    background: #138496;
    transform: scale(1.1);
}

.delete-btn {
    background: #dc3545;
    color: white;
}

.delete-btn:hover {
    background: #c82333;
    transform: scale(1.1);
}

.section-content {
    padding: 25px;
}

.activities-list {
    display: flex;
    flex-direction: column;
    gap: 15px;
    margin-bottom: 20px;
}

.activity-item {
    display: flex;
    align-items: center;
    gap: 15px;
    padding: 15px 20px;
    background: #333;
    border-radius: 10px;
    border: 1px solid #444;
    transition: all 0.3s ease;
}

.activity-item:hover {
    background: #3a3a3a;
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(0,0,0,0.2);
}

.activity-icon {
    width: 40px;
    height: 40px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.2rem;
    flex-shrink: 0;
}

.activity-icon.document {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
}

.activity-icon.video {
    background: linear-gradient(135deg, #e91e63 0%, #f06292 100%);
    color: white;
}

.activity-icon.completed {
    background: linear-gradient(135deg, #4caf50 0%, #8bc34a 100%);
    color: white;
}

.activity-info {
    flex: 1;
}

.activity-title {
    font-size: 1.1rem;
    font-weight: 600;
    color: white;
    margin: 0 0 5px 0;
}

.activity-description {
    font-size: 0.9rem;
    color: #ccc;
    margin: 0;
    line-height: 1.4;
}

.activity-actions {
    display: flex;
    gap: 8px;
}

.activity-action-btn {
    width: 32px;
    height: 32px;
    border: none;
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.3s ease;
    font-size: 0.8rem;
}

.view-btn {
    background: #6c757d;
    color: white;
}

.view-btn:hover {
    background: #5a6268;
    transform: scale(1.1);
}

.edit-activity-btn {
    background: #17a2b8;
    color: white;
}

.edit-activity-btn:hover {
    background: #138496;
    transform: scale(1.1);
}

.delete-activity-btn {
    background: #dc3545;
    color: white;
}

.delete-activity-btn:hover {
    background: #c82333;
    transform: scale(1.1);
}

.add-activity-btn {
    background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
    color: white;
    border: none;
    padding: 12px 20px;
    border-radius: 8px;
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    gap: 8px;
    width: 100%;
    justify-content: center;
}

.add-activity-btn:hover {
    background: linear-gradient(135deg, #218838 0%, #1e7e34 100%);
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3);
}

.empty-section {
    text-align: center;
    padding: 40px 20px;
    color: #888;
}

.empty-icon {
    font-size: 3rem;
    margin-bottom: 15px;
    opacity: 0.5;
}

.empty-text {
    font-size: 1.1rem;
    font-weight: 500;
    margin: 0;
}

/* Modal Styles */
.modal {
    display: none;
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.7);
    backdrop-filter: blur(5px);
    /* Prevent positioning errors */
    overflow: hidden;
}

.modal-content {
    background-color: white;
    margin: 5% auto;
    padding: 20px;
    border-radius: 12px;
    width: 100%;
    max-width: 448px;
    box-shadow: 0 10px 25px rgba(0,0,0,0.1), 0 4px 6px rgba(0,0,0,0.05);
    animation: modalSlideIn 0.3s ease-out;
    position: relative;
    top: 50%;
    transform: translateY(-50%);
    max-height: 70vh;
    overflow-y: auto;
}

/* Dark mode support */
@media (prefers-color-scheme: dark) {
    .modal-content {
        background-color: #111827;
    }
}

@keyframes modalSlideIn {
    from { opacity: 0; transform: translateY(-50%) scale(0.9); }
    to { opacity: 1; transform: translateY(-50%) scale(1); }
}

.modal-header {
    background: transparent;
    color: #1f2937;
    padding: 0 0 15px 0;
    border-radius: 0;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 1px solid #e5e7eb;
    margin-bottom: 15px;
}

/* Dark mode support for header */
@media (prefers-color-scheme: dark) {
    .modal-header {
        color: #f9fafb;
        border-bottom-color: #374151;
    }
}

.modal-header h3 {
    margin: 0;
    font-size: 1.3rem;
    font-weight: 600;
}

.close {
    color: #6b7280;
    font-size: 24px;
    font-weight: bold;
    cursor: pointer;
    transition: opacity 0.2s ease;
}

.close:hover {
    opacity: 0.7;
    color: #374151;
}

/* Dark mode support for close button */
@media (prefers-color-scheme: dark) {
    .close {
        color: #9ca3af;
    }
    
    .close:hover {
        color: #d1d5db;
    }
}

.modal-body {
    padding: 0;
}

.modal-footer {
    padding: 15px 0 0 0;
    border-top: 1px solid #e5e7eb;
    display: flex;
    justify-content: flex-end;
    gap: 15px;
    background: transparent;
    border-radius: 0;
    margin-top: 15px;
}

/* Dark mode support for footer */
@media (prefers-color-scheme: dark) {
    .modal-footer {
        border-top-color: #374151;
    }
}

.form-group {
    margin-bottom: 12px;
}

.form-group label {
    display: block;
    margin-bottom: 4px;
    font-weight: 600;
    color: #374151;
    font-size: 0.85rem;
}

/* Dark mode support for labels */
@media (prefers-color-scheme: dark) {
    .form-group label {
        color: #d1d5db;
    }
}

.form-group label i {
    margin-right: 8px;
    color: #667eea;
}

.form-group input,
.form-group select,
.form-group textarea {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    font-size: 0.85rem;
    transition: border-color 0.3s ease;
    box-sizing: border-box;
    background: white;
    color: #1f2937;
}

/* Dark mode support for inputs */
@media (prefers-color-scheme: dark) {
    .form-group input,
    .form-group select,
    .form-group textarea {
        border-color: #4b5563;
        background: #374151;
        color: #f9fafb;
    }
}

.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.form-group input::placeholder,
.form-group textarea::placeholder {
    color: #9ca3af;
}

/* Dark mode support for placeholders */
@media (prefers-color-scheme: dark) {
    .form-group input::placeholder,
    .form-group textarea::placeholder {
        color: #6b7280;
    }
}

.form-group select[multiple] {
    min-height: 50px;
}

.form-help {
    display: block;
    margin-top: 5px;
    font-size: 0.8rem;
    color: #6b7280;
}

/* Dark mode support for help text */
@media (prefers-color-scheme: dark) {
    .form-help {
        color: #9ca3af;
    }
}

.file-upload-container {
    display: flex;
    align-items: center;
    gap: 15px;
}

.file-upload-btn {
    background: #3b82f6;
    color: white;
    border: none;
    padding: 6px 12px;
    border-radius: 6px;
    font-size: 0.85rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.3s ease;
}

.file-upload-btn:hover {
    background: #2563eb;
    transform: translateY(-1px);
}

.file-name {
    color: #6b7280;
    font-size: 0.85rem;
}

/* Dark mode support for file name */
@media (prefers-color-scheme: dark) {
    .file-name {
        color: #9ca3af;
    }
}

.btn {
    padding: 8px 16px;
    border: none;
    border-radius: 6px;
    font-size: 0.85rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.btn-primary {
    background: #3b82f6;
    color: white;
}

.btn-primary:hover {
    background: #2563eb;
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(59, 130, 246, 0.3);
}

.btn-secondary {
    background: #6b7280;
    color: white;
}

.btn-secondary:hover {
    background: #4b5563;
}

/* View Modal Styles */
.view-modal-content {
    max-width: 800px;
    max-height: 85vh;
}

.view-modal-body {
    padding: 0;
    max-height: 70vh;
    overflow-y: auto;
}

.content-section {
    background: #f8fafc;
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 20px;
    border: 1px solid #e2e8f0;
}

.section-header {
    display: flex;
    align-items: center;
    margin-bottom: 15px;
    gap: 12px;
}

.section-icon {
    width: 40px;
    height: 40px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.2rem;
    color: white;
}

.section-icon.video-icon {
    background: linear-gradient(135deg, #e91e63 0%, #f06292 100%);
}

.section-icon.document-icon {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
}

.section-icon.curriculum-icon {
    background: linear-gradient(135deg, #4caf50 0%, #8bc34a 100%);
}

.section-title {
    font-size: 1.2rem;
    font-weight: 600;
    color: #1e293b;
    margin: 0;
    flex: 1;
}

.section-actions {
    display: flex;
    gap: 8px;
}

.action-btn {
    width: 32px;
    height: 32px;
    border: none;
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.3s ease;
    font-size: 0.9rem;
}

.action-btn.edit-btn {
    background: #17a2b8;
    color: white;
}

.action-btn.edit-btn:hover {
    background: #138496;
    transform: scale(1.1);
}

.action-btn.view-btn {
    background: #3b82f6;
    color: white;
}

.action-btn.view-btn:hover {
    background: #2563eb;
    transform: scale(1.1);
}

.action-btn.delete-btn {
    background: #dc3545;
    color: white;
}

.action-btn.delete-btn:hover {
    background: #c82333;
    transform: scale(1.1);
}

.video-placeholder {
    background: white;
    border: 2px dashed #cbd5e1;
    border-radius: 12px;
    height: 200px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 15px;
}

.video-placeholder-content {
    text-align: center;
    color: #64748b;
}

.video-placeholder-content i {
    font-size: 3rem;
    margin-bottom: 10px;
    display: block;
}

.section-description {
    color: #475569;
    line-height: 1.6;
    margin: 0;
}

.section-content {
    background: white;
    border-radius: 8px;
    padding: 15px;
    border: 1px solid #e2e8f0;
}

.curriculum-module {
    background: white;
    border-radius: 8px;
    border: 1px solid #e2e8f0;
    margin-bottom: 10px;
    overflow: hidden;
}

.curriculum-module.expanded {
    border-color: #3b82f6;
    box-shadow: 0 2px 8px rgba(59, 130, 246, 0.1);
}

.module-header {
    display: flex;
    align-items: center;
    padding: 15px;
    gap: 12px;
    cursor: pointer;
    transition: background-color 0.3s ease;
}

.module-header:hover {
    background: #f8fafc;
}

.module-icon {
    width: 32px;
    height: 32px;
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
    color: white;
    background: #6b7280;
}

.module-icon.completed {
    background: linear-gradient(135deg, #4caf50 0%, #8bc34a 100%);
}

.module-title {
    font-size: 1rem;
    font-weight: 600;
    color: #1e293b;
    margin: 0;
    flex: 1;
}

.module-content {
    padding: 0 15px 15px 15px;
    border-top: 1px solid #e2e8f0;
}

.lecture-item {
    display: flex;
    align-items: center;
    padding: 10px 0;
    gap: 12px;
    border-bottom: 1px solid #f1f5f9;
}

.lecture-item:last-child {
    border-bottom: none;
}

.lecture-icon {
    width: 24px;
    height: 24px;
    border-radius: 4px;
    background: #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.8rem;
    color: #64748b;
}

.lecture-info {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex: 1;
}

.lecture-title {
    font-size: 0.9rem;
    color: #374151;
    font-weight: 500;
}

.lecture-duration {
    font-size: 0.8rem;
    color: #6b7280;
    background: #f1f5f9;
    padding: 2px 8px;
    border-radius: 4px;
}

/* Dark mode support for view modal */
@media (prefers-color-scheme: dark) {
    .content-section {
        background: #1e293b;
        border-color: #334155;
    }
    
    .section-title {
        color: #f1f5f9;
    }
    
    .section-description {
        color: #cbd5e1;
    }
    
    .section-content {
        background: #334155;
        border-color: #475569;
    }
    
    .curriculum-module {
        background: #334155;
        border-color: #475569;
    }
    
    .module-header:hover {
        background: #334155;
    }
    
    .module-title {
        color: #f1f5f9;
    }
    
    .lecture-title {
        color: #e2e8f0;
    }
    
    .lecture-duration {
        background: #475569;
        color: #cbd5e1;
    }
    
    .video-placeholder {
        background: #334155;
        border-color: #475569;
    }
    
    .video-placeholder-content {
        color: #94a3b8;
    }
}

/* Responsive Design */
@media (max-width: 768px) {
    .timeline-container {
        padding: 15px;
    }
    
    .page-header {
        flex-direction: column;
        gap: 15px;
        text-align: center;
    }
    
    .header-actions {
        width: 100%;
        justify-content: center;
    }
    
    .section-header {
        flex-direction: column;
        gap: 15px;
        text-align: center;
    }
    
    .activity-item {
        flex-direction: column;
        text-align: center;
        gap: 10px;
    }
    
    .activity-actions {
        justify-content: center;
    }
}
</style>

<div class="timeline-container">
    <div class="page-header">
        <h1 class="page-title">Course Timeline</h1>
        <div class="header-actions">
            <button class="header-btn" onclick="addNewSection()">
                <i class="fa fa-plus"></i>
                New Section
            </button>
            <button class="header-btn secondary" onclick="batchImport()">
                <i class="fa fa-upload"></i>
                Batch Import
            </button>
        </div>
    </div>

    <div class="sections-container" id="sectionsContainer">
        <?php
        if ($course) {
            require_once($CFG->dirroot . '/course/lib.php');
            $modinfo = get_fast_modinfo($course);
            $sections = $modinfo->get_section_info_all();
            
            foreach ($sections as $section) {
                $section_name = get_section_name($course, $section);
                $section_id = 'section-' . $section->id;
                ?>
                <div class="section-item" data-section-id="<?php echo $section_id; ?>" data-db-section-id="<?php echo $section->id; ?>">
                    <div class="section-header">
                        <h3 class="section-title"><?php echo htmlspecialchars($section_name); ?></h3>
                        <div class="section-actions">
                            <button class="section-action-btn edit-btn" onclick="editSection('<?php echo $section_id; ?>')" title="Edit Section">
                                <i class="fa fa-edit"></i>
                            </button>
                            <button class="section-action-btn delete-btn" onclick="deleteSection('<?php echo $section_id; ?>')" title="Delete Section">
                                <i class="fa fa-trash"></i>
                            </button>
                        </div>
                    </div>
                    <div class="section-content">
                        <div class="activities-list">
                            <?php
                            if (isset($modinfo->sections[$section->section])) {
                                foreach ($modinfo->sections[$section->section] as $cmid) {
                                    $cm = $modinfo->cms[$cmid];
                                    if ($cm->uservisible) {
                                        $activity_id = 'activity-' . $cm->id;
                                        $icon_class = get_activity_icon_class($cm->modname);
                                        $icon_symbol = get_activity_icon_symbol($cm->modname);
                                        ?>
                                        <div class="activity-item" data-activity-id="<?php echo $activity_id; ?>" data-db-activity-id="<?php echo $cm->id; ?>">
                                            <div class="activity-icon <?php echo $icon_class; ?>">
                                                <i class="fa <?php echo $icon_symbol; ?>"></i>
                                            </div>
                                            <div class="activity-info">
                                                <h4 class="activity-title"><?php echo htmlspecialchars($cm->name); ?></h4>
                                                <div class="activity-description"><?php echo $cm->content ?? 'Complete this activity to progress in your learning.'; ?></div>
                                            </div>
                                            <div class="activity-actions">
                                                <button class="activity-action-btn view-btn" onclick="viewActivity('<?php echo $activity_id; ?>')" title="View">
                                                    <i class="fa fa-eye"></i>
                                                </button>
                                                <button class="activity-action-btn edit-activity-btn" onclick="editActivity('<?php echo $activity_id; ?>')" title="Edit">
                                                    <i class="fa fa-edit"></i>
                                                </button>
                                                <button class="activity-action-btn delete-activity-btn" onclick="deleteActivity('<?php echo $activity_id; ?>')" title="Delete">
                                                    <i class="fa fa-trash"></i>
                                                </button>
                                            </div>
                                        </div>
                                        <?php
                                    }
                                }
                            }
                            
                            if (!isset($modinfo->sections[$section->section]) || empty($modinfo->sections[$section->section])) {
                                ?>
                                <div class="empty-section">
                                    <div class="empty-icon">
                                        <i class="fa fa-folder-open"></i>
                                    </div>
                                    <p class="empty-text">No activities in this section.</p>
                                </div>
                                <?php
                            }
                            ?>
                        </div>
                        <button class="add-activity-btn" onclick="addActivity('<?php echo $section_id; ?>')">
                            <i class="fa fa-plus"></i>
                            Add activity to this section
                        </button>
                    </div>
                </div>
                <?php
            }
        } else {
            ?>
            <div class="empty-state">
                <div class="empty-icon">
                    <i class="fa fa-exclamation-triangle"></i>
                </div>
                <p class="empty-text">Please select a valid course to view its timeline.</p>
            </div>
            <?php
        }
        ?>
    </div>
</div>

<!-- Add Activity Modal -->
<div id="addActivityModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Add Activity</h3>
            <span class="close" onclick="closeModal('addActivityModal')">&times;</span>
        </div>
        <form id="addActivityForm">
            <div class="modal-body">
                <div class="form-group">
                    <label for="activityType">Activity Type</label>
                    <select id="activityType" name="activityType" onchange="updateActivityFields()">
                        <option value="file">File</option>
                        <option value="page">Page</option>
                        <option value="url">URL</option>
                        <option value="folder">Folder</option>
                        <option value="scorm">SCORM Package</option>
                        <option value="assignment">Assignment</option>
                        <option value="quiz">Quiz</option>
                        <option value="text">Text and Media Area</option>
                        <option value="zoom">Zoom Meeting</option>
                        <option value="attendance">Attendance</option>
                    </select>
                </div>

                <!-- File Upload Field (shown for File type) -->
                <div class="form-group" id="fileUploadGroup">
                    <label for="fileUpload">File</label>
                    <div class="file-upload-container">
                        <button type="button" class="file-upload-btn" onclick="document.getElementById('fileInput').click()">
                            Choose file
                        </button>
                        <span class="file-name" id="fileName">No file chosen</span>
                        <input type="file" id="fileInput" style="display: none;" onchange="updateFileName()">
                    </div>
                </div>

                <!-- URL Field (shown for URL type) -->
                <div class="form-group" id="urlGroup" style="display: none;">
                    <label for="urlInput">URL</label>
                    <input type="url" id="urlInput" name="url" placeholder="Enter URL">
                </div>

                <!-- Content Field (shown for Page type) -->
                <div class="form-group" id="contentGroup" style="display: none;">
                    <label for="contentInput">Content (HTML)</label>
                    <textarea id="contentInput" name="content" rows="6" placeholder="Enter HTML content"></textarea>
                </div>

                <!-- Text/Media Field (shown for Text and Media Area type) -->
                <div class="form-group" id="textMediaGroup" style="display: none;">
                    <label for="textMediaInput">Text / Media (HTML)</label>
                    <textarea id="textMediaInput" name="textMedia" rows="6" placeholder="Enter HTML content"></textarea>
                </div>

                <!-- Assignment Fields (shown for Assignment type) -->
                <div class="form-group" id="dueDateGroup" style="display: none;">
                    <label for="dueDateInput">Due Date</label>
                    <input type="text" id="dueDateInput" name="dueDate" placeholder="mm/dd/yyyy --:-- --">
                </div>

                <div class="form-group" id="cutoffDateGroup" style="display: none;">
                    <label for="cutoffDateInput">Cutoff Date</label>
                    <input type="text" id="cutoffDateInput" name="cutoffDate" placeholder="mm/dd/yyyy --:-- --">
                </div>

                <div class="form-group" id="maxGradeGroup" style="display: none;">
                    <label for="maxGradeInput">Max Grade</label>
                    <input type="number" id="maxGradeInput" name="maxGrade" value="100" min="0" max="1000">
                </div>

                <!-- Title Field (shown for all types) -->
                <div class="form-group" id="titleGroup">
                    <label for="activityTitle">Title</label>
                    <input type="text" id="activityTitle" name="title" placeholder="Enter activity title">
                </div>

                <!-- Description Field (shown for File, URL, Page, Text, Assignment types) -->
                <div class="form-group" id="descriptionGroup">
                    <label for="activityDescription">Description</label>
                    <textarea id="activityDescription" name="description" rows="4" placeholder="Enter activity description"></textarea>
                </div>

                <div class="form-group">
                    <label for="visibleTo">
                        <i class="fa fa-users"></i>
                        Visible To (Optional)
                    </label>
                    <select id="visibleTo" name="visibleTo" multiple>
                        <option value="" disabled>No groups exist for this course.</option>
                    </select>
                    <small class="form-help">Hold Ctrl/Cmd to select multiple groups. If none, it's visible to all.</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('addActivityModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fa fa-plus"></i>
                    Add Activity
                </button>
            </div>
        </form>
    </div>
</div>

<!-- View Content Modal -->
<div id="viewContentModal" class="modal">
    <div class="modal-content view-modal-content">
        <div class="modal-header">
            <h3>Course Content</h3>
            <span class="close" onclick="closeModal('viewContentModal')">&times;</span>
        </div>
        <div class="modal-body view-modal-body">
            <!-- Course Introduction Video Section -->
            <div class="content-section">
                <div class="section-header">
                    <div class="section-icon video-icon">
                        <i class="fa fa-video-camera"></i>
                    </div>
                    <h4 class="section-title">Course Introduction Video</h4>
                </div>
                <div class="video-placeholder">
                    <div class="video-placeholder-content">
                        <i class="fa fa-play-circle"></i>
                        <p>Video Player Placeholder</p>
                    </div>
                </div>
                <p class="section-description">
                    Watch this short video to learn how inquiry-based learning can transform your classroom and empower your students through curiosity-driven instruction.
                </p>
            </div>

            <!-- Course Description Section -->
            <div class="content-section">
                <div class="section-header">
                    <div class="section-icon document-icon">
                        <i class="fa fa-file-text"></i>
                    </div>
                    <h4 class="section-title">Course Description</h4>
                </div>
                <div class="section-content">
                    <p class="section-description">
                        Classroom rules set the foundation for a structured, respectful, and productive learning environment. This course explores best practices for developing, communicating, and enforcing classroom rules that promote positive behavior and student accountability. Learn how to create age-appropriate rules, involve students in the rule-setting process, and ensure consistency in enforcement. Discover strategies for reinforcing expectations through positive discipline and creating a supportive classroom culture.
                    </p>
                </div>
            </div>

            <!-- Curriculum Section -->
            <div class="content-section">
                <div class="section-header">
                    <div class="section-icon curriculum-icon">
                        <i class="fa fa-graduation-cap"></i>
                    </div>
                    <h4 class="section-title">Curriculum</h4>
                    <div class="section-actions">
                        <button class="action-btn edit-btn" title="Edit">
                            <i class="fa fa-edit"></i>
                        </button>
                        <button class="action-btn view-btn" title="View">
                            <i class="fa fa-eye"></i>
                        </button>
                        <button class="action-btn delete-btn" title="Delete">
                            <i class="fa fa-trash"></i>
                        </button>
                    </div>
                </div>
                
                <!-- Expanded Module -->
                <div class="curriculum-module expanded">
                    <div class="module-header">
                        <div class="module-icon completed">
                            <i class="fa fa-check"></i>
                        </div>
                        <h5 class="module-title">Introduction to AI in Education (3 lectures)</h5>
                    </div>
                    <div class="module-content">
                        <div class="lecture-item">
                            <div class="lecture-icon">
                                <i class="fa fa-desktop"></i>
                            </div>
                            <div class="lecture-info">
                                <span class="lecture-title">Introduction: The Rise of AI in Education</span>
                                <span class="lecture-duration">10m</span>
                            </div>
                        </div>
                        <div class="lecture-item">
                            <div class="lecture-icon">
                                <i class="fa fa-desktop"></i>
                            </div>
                            <div class="lecture-info">
                                <span class="lecture-title">What is AI? Understanding the Basics for Educators</span>
                                <span class="lecture-duration">20m</span>
                            </div>
                        </div>
                        <div class="lecture-item">
                            <div class="lecture-icon">
                                <i class="fa fa-desktop"></i>
                            </div>
                            <div class="lecture-info">
                                <span class="lecture-title">The Benefits and Challenges of Using AI in the Classroom</span>
                                <span class="lecture-duration">25m</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Collapsed Modules -->
                <div class="curriculum-module collapsed">
                    <div class="module-header">
                        <div class="module-icon">
                            <i class="fa fa-compass"></i>
                        </div>
                        <h5 class="module-title">AI Tools for Lesson Planning and Content Creation (4 lectures)</h5>
                    </div>
                </div>

                <div class="curriculum-module collapsed">
                    <div class="module-header">
                        <div class="module-icon">
                            <i class="fa fa-certificate"></i>
                        </div>
                        <h5 class="module-title">AI for Assessment and Feedback (5 lectures)</h5>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add Section Modal -->
<div id="addSectionModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Add New Section</h3>
            <span class="close" onclick="closeModal('addSectionModal')">&times;</span>
        </div>
        <form id="addSectionForm">
            <div class="modal-body">
                <div class="form-group">
                    <label for="sectionName">Section Name</label>
                    <input type="text" id="sectionName" name="sectionName" placeholder="Enter section name" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('addSectionModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fa fa-plus"></i>
                    Add Section
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Section Modal -->
<div id="editSectionModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Edit Section</h3>
            <span class="close" onclick="closeModal('editSectionModal')">&times;</span>
        </div>
        <form id="editSectionForm">
            <div class="modal-body">
                <div class="form-group">
                    <label for="editSectionName">Section Name</label>
                    <input type="text" id="editSectionName" name="sectionName" placeholder="Enter section name" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('editSectionModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fa fa-save"></i>
                    Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Section Modal -->
<div id="deleteSectionModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Delete Section</h3>
            <span class="close" onclick="closeModal('deleteSectionModal')">&times;</span>
        </div>
        <div class="modal-body">
            <p>Are you sure you want to delete this section? This action cannot be undone.</p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal('deleteSectionModal')">Cancel</button>
            <button type="button" class="btn btn-primary" onclick="confirmDeleteSection()" style="background: #dc3545;">
                <i class="fa fa-trash"></i>
                Delete Section
            </button>
        </div>
    </div>
</div>

<!-- Edit Activity Modal -->
<div id="editActivityModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Edit Activity</h3>
            <span class="close" onclick="closeModal('editActivityModal')">&times;</span>
        </div>
        <form id="editActivityForm">
            <div class="modal-body">
                <div class="form-group">
                    <label for="editActivityName">Activity Title</label>
                    <input type="text" id="editActivityName" name="activityName" placeholder="Enter activity title" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('editActivityModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fa fa-save"></i>
                    Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Activity Modal -->
<div id="deleteActivityModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Delete Activity</h3>
            <span class="close" onclick="closeModal('deleteActivityModal')">&times;</span>
        </div>
        <div class="modal-body">
            <p>Are you sure you want to delete this activity?</p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal('deleteActivityModal')">Cancel</button>
            <button type="button" class="btn btn-primary" onclick="confirmDeleteActivity()" style="background: #dc3545;">
                <i class="fa fa-trash"></i>
                Delete Activity
            </button>
        </div>
    </div>
</div>

<!-- Batch Import Modal -->
<div id="batchImportModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Batch Import</h3>
            <span class="close" onclick="closeModal('batchImportModal')">&times;</span>
        </div>
        <div class="modal-body">
            <p>Batch Import functionality would be implemented here. This feature allows you to import multiple activities at once.</p>
            <div class="form-group">
                <label for="importFile">Import File</label>
                <input type="file" id="importFile" name="importFile" accept=".csv,.xlsx,.json">
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal('batchImportModal')">Cancel</button>
            <button type="button" class="btn btn-primary">
                <i class="fa fa-upload"></i>
                Import Activities
            </button>
        </div>
    </div>
</div>

<script>
// Section management functions
function addNewSection() {
    openModal('addSectionModal');
}

function createSectionElement(sectionName) {
    const sectionId = 'section-' + Date.now() + '-' + Math.random().toString(36).substr(2, 9);
    const sectionDiv = document.createElement('div');
    sectionDiv.className = 'section-item';
    sectionDiv.setAttribute('data-section-id', sectionId);
    sectionDiv.innerHTML = `
        <div class="section-header">
            <h3 class="section-title">${sectionName}</h3>
            <div class="section-actions">
                <button class="section-action-btn edit-btn" onclick="editSection('${sectionId}')" title="Edit Section">
                    <i class="fa fa-edit"></i>
                </button>
                <button class="section-action-btn delete-btn" onclick="deleteSection('${sectionId}')" title="Delete Section">
                    <i class="fa fa-trash"></i>
                </button>
            </div>
        </div>
        <div class="section-content">
            <div class="empty-section">
                <div class="empty-icon">
                    <i class="fa fa-folder-open"></i>
                </div>
                <p class="empty-text">No activities in this section.</p>
            </div>
            <button class="add-activity-btn" onclick="addActivity('${sectionId}')">
                <i class="fa fa-plus"></i>
                Add activity to this section
            </button>
        </div>
    `;
    return sectionDiv;
}

function editSection(sectionId) {
    const sectionElement = document.querySelector(`[data-section-id="${sectionId}"]`);
    if (sectionElement) {
        const sectionTitle = sectionElement.querySelector('.section-title');
        currentSectionId = sectionId;
        document.getElementById('editSectionName').value = sectionTitle.textContent;
        openModal('editSectionModal');
    }
}

function deleteSection(sectionId) {
    currentSectionId = sectionId;
    openModal('deleteSectionModal');
}

// Activity management functions
let currentSectionId = '';
let currentActivityId = '';

function addActivity(sectionId) {
    currentSectionId = sectionId;
    openModal('addActivityModal');
}

function openModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.style.display = 'block';
        document.body.style.overflow = 'hidden';
    } else {
        console.error('Modal not found:', modalId);
    }
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.style.display = 'none';
        document.body.style.overflow = 'auto';
    } else {
        console.error('Modal not found:', modalId);
    }
    
    // Reset forms when closing modals
    if (modalId === 'addActivityModal') {
        resetActivityForm();
    } else if (modalId === 'addSectionModal') {
        document.getElementById('addSectionForm').reset();
    } else if (modalId === 'editSectionModal') {
        document.getElementById('editSectionForm').reset();
    } else if (modalId === 'editActivityModal') {
        document.getElementById('editActivityForm').reset();
    }
}

function resetActivityForm() {
    document.getElementById('addActivityForm').reset();
    document.getElementById('fileName').textContent = 'No file chosen';
    updateActivityFields();
}

function updateActivityFields() {
    const activityType = document.getElementById('activityType').value;
    const titleInput = document.getElementById('activityTitle');
    
    // Get all field groups
    const fileUploadGroup = document.getElementById('fileUploadGroup');
    const urlGroup = document.getElementById('urlGroup');
    const contentGroup = document.getElementById('contentGroup');
    const textMediaGroup = document.getElementById('textMediaGroup');
    const dueDateGroup = document.getElementById('dueDateGroup');
    const cutoffDateGroup = document.getElementById('cutoffDateGroup');
    const maxGradeGroup = document.getElementById('maxGradeGroup');
    const titleGroup = document.getElementById('titleGroup');
    const descriptionGroup = document.getElementById('descriptionGroup');
    
    // Hide all conditional fields
    fileUploadGroup.style.display = 'none';
    urlGroup.style.display = 'none';
    contentGroup.style.display = 'none';
    textMediaGroup.style.display = 'none';
    dueDateGroup.style.display = 'none';
    cutoffDateGroup.style.display = 'none';
    maxGradeGroup.style.display = 'none';
    titleGroup.style.display = 'none';
    descriptionGroup.style.display = 'none';
    
    // Remove required attribute from title field initially
    titleInput.removeAttribute('required');
    
    // Show relevant fields based on activity type (exactly as shown in images)
    switch(activityType) {
        case 'file':
            fileUploadGroup.style.display = 'block';
            titleGroup.style.display = 'block';
            descriptionGroup.style.display = 'block';
            titleInput.setAttribute('required', 'required');
            break;
        case 'url':
            urlGroup.style.display = 'block';
            titleGroup.style.display = 'block';
            descriptionGroup.style.display = 'block';
            titleInput.setAttribute('required', 'required');
            break;
        case 'page':
            contentGroup.style.display = 'block';
            titleGroup.style.display = 'block';
            descriptionGroup.style.display = 'block';
            titleInput.setAttribute('required', 'required');
            break;
        case 'text':
            textMediaGroup.style.display = 'block';
            titleGroup.style.display = 'block';
            descriptionGroup.style.display = 'block';
            titleInput.setAttribute('required', 'required');
            break;
        case 'assignment':
            titleGroup.style.display = 'block';
            descriptionGroup.style.display = 'block';
            dueDateGroup.style.display = 'block';
            cutoffDateGroup.style.display = 'block';
            maxGradeGroup.style.display = 'block';
            titleInput.setAttribute('required', 'required');
            break;
        case 'quiz':
        case 'scorm':
        case 'folder':
        case 'zoom':
        case 'attendance':
            // These types only show Activity Type and Visible To fields (as shown in images)
            // Title field is not required for these types
            break;
    }
}

function updateFileName() {
    const fileInput = document.getElementById('fileInput');
    const fileName = document.getElementById('fileName');
    
    if (fileInput.files.length > 0) {
        fileName.textContent = fileInput.files[0].name;
    } else {
        fileName.textContent = 'No file chosen';
    }
}

function addActivityToSection(sectionId, activityType, activityTitle, activityDescription = '', additionalData = {}, dbActivityId = null) {
    const sectionElement = document.querySelector(`[data-section-id="${sectionId}"]`);
    if (!sectionElement) return;
    
    const activitiesList = sectionElement.querySelector('.activities-list');
    const emptySection = sectionElement.querySelector('.empty-section');
    
    // Remove empty section if it exists
    if (emptySection) {
        emptySection.remove();
    }
    
    // Get appropriate icon based on activity type
    let iconClass = 'document';
    let iconSymbol = 'fa-file-text';
    
    switch(activityType) {
        case 'file':
            iconClass = 'document';
            iconSymbol = 'fa-file';
            break;
        case 'page':
            iconClass = 'document';
            iconSymbol = 'fa-file-text';
            break;
        case 'text':
            iconClass = 'document';
            iconSymbol = 'fa-file-text';
            break;
        case 'url':
            iconClass = 'video';
            iconSymbol = 'fa-link';
            break;
        case 'quiz':
            iconClass = 'completed';
            iconSymbol = 'fa-question-circle';
            break;
        case 'assignment':
            iconClass = 'document';
            iconSymbol = 'fa-tasks';
            break;
        case 'scorm':
            iconClass = 'document';
            iconSymbol = 'fa-cube';
            break;
        case 'folder':
            iconClass = 'document';
            iconSymbol = 'fa-folder';
            break;
        case 'zoom':
            iconClass = 'video';
            iconSymbol = 'fa-video-camera';
            break;
        case 'attendance':
            iconClass = 'completed';
            iconSymbol = 'fa-check-circle';
            break;
    }
    
    // Build description with additional data
    let description = activityDescription || activityType.charAt(0).toUpperCase() + activityType.slice(1);
    
    if (additionalData.fileName) {
        description += ` - File: ${additionalData.fileName}`;
    } else if (additionalData.url) {
        description += ` - URL: ${additionalData.url}`;
    } else if (additionalData.dueDate) {
        description += ` - Due: ${additionalData.dueDate}`;
    } else if (additionalData.maxGrade) {
        description += ` - Max Grade: ${additionalData.maxGrade}`;
    }
    
    // Create activity element
    const activityId = 'activity-' + Date.now() + '-' + Math.random().toString(36).substr(2, 9);
    const activityDiv = document.createElement('div');
    activityDiv.className = 'activity-item';
    activityDiv.setAttribute('data-activity-id', activityId);
    if (dbActivityId) {
        activityDiv.setAttribute('data-db-activity-id', dbActivityId);
    }
    activityDiv.innerHTML = `
        <div class="activity-icon ${iconClass}">
            <i class="fa ${iconSymbol}"></i>
        </div>
        <div class="activity-info">
            <h4 class="activity-title">${activityTitle}</h4>
            <p class="activity-description">${description} - Click to view details</p>
        </div>
        <div class="activity-actions">
            <button class="activity-action-btn view-btn" onclick="viewActivity('${activityId}')" title="View">
                <i class="fa fa-eye"></i>
            </button>
            <button class="activity-action-btn edit-activity-btn" onclick="editActivity('${activityId}')" title="Edit">
                <i class="fa fa-edit"></i>
            </button>
            <button class="activity-action-btn delete-activity-btn" onclick="deleteActivity('${activityId}')" title="Delete">
                <i class="fa fa-trash"></i>
            </button>
        </div>
    `;
    
    activitiesList.appendChild(activityDiv);
}

function viewActivity(activityId) {
    // Find the activity element and get its content
    const activityElement = document.querySelector(`[data-activity-id="${activityId}"]`);
    if (activityElement) {
        const activityTitle = activityElement.querySelector('.activity-title').textContent;
        const activityDescription = activityElement.querySelector('.activity-description').innerHTML;
        
        // Update the modal content with actual activity data
        const modalBody = document.querySelector('.view-modal-body');
        modalBody.innerHTML = `
            <div class="content-section">
                <div class="section-header">
                    <div class="section-icon video-icon">
                        <i class="fa fa-file-text"></i>
                    </div>
                    <h4 class="section-title">${activityTitle}</h4>
                </div>
                <div class="section-content">
                    ${activityDescription}
                </div>
            </div>
        `;
        
        // Open the view content modal
        openModal('viewContentModal');
    }
}

function editActivity(activityId) {
    const activityElement = document.querySelector(`[data-activity-id="${activityId}"]`);
    if (activityElement) {
        const activityTitle = activityElement.querySelector('.activity-title');
        currentActivityId = activityId;
        document.getElementById('editActivityName').value = activityTitle.textContent;
        openModal('editActivityModal');
    }
}

function deleteActivity(activityId) {
    currentActivityId = activityId;
    openModal('deleteActivityModal');
}

function batchImport() {
    openModal('batchImportModal');
}

// Form submission handler
document.getElementById('addActivityForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const activityType = formData.get('activityType');
    const title = formData.get('title');
    const description = formData.get('description');
    
    // Validate form based on activity type
    const titleInput = document.getElementById('activityTitle');
    const isTitleRequired = titleInput.hasAttribute('required');
    
    if (isTitleRequired && (!title || !title.trim())) {
        // Show validation message for required title
        titleInput.focus();
        showMessage('Please enter a title for this activity.', 'error');
        return;
    }
    
    // Collect additional data based on activity type
    let additionalData = {};
    
    switch(activityType) {
        case 'file':
            const fileInput = document.getElementById('fileInput');
            if (fileInput.files.length > 0) {
                additionalData.fileName = fileInput.files[0].name;
            }
            break;
        case 'url':
            additionalData.url = formData.get('url');
            break;
        case 'page':
            additionalData.content = formData.get('content');
            break;
        case 'text':
            additionalData.textMedia = formData.get('textMedia');
            break;
        case 'assignment':
            additionalData.dueDate = formData.get('dueDate');
            additionalData.cutoffDate = formData.get('cutoffDate');
            additionalData.maxGrade = formData.get('maxGrade');
            break;
    }
    
    // For types that don't have title field, use a default title
    const activityTitle = title && title.trim() ? title.trim() : `${activityType.charAt(0).toUpperCase() + activityType.slice(1)} Activity`;
    
    // Get the database section ID
    const sectionElement = document.querySelector(`[data-section-id="${currentSectionId}"]`);
    const dbSectionId = sectionElement ? sectionElement.getAttribute('data-db-section-id') : null;
    
    if (dbSectionId) {
        // Send AJAX request to create activity in database
        const activityFormData = new FormData();
        activityFormData.append('section_id', dbSectionId);
        activityFormData.append('activity_type', activityType);
        activityFormData.append('activity_title', activityTitle);
        activityFormData.append('activity_description', description);
        
        // Add additional data
        Object.keys(additionalData).forEach(key => {
            activityFormData.append(key, additionalData[key]);
        });
        
        fetch('?action=create_activity', {
            method: 'POST',
            body: activityFormData
        })
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                // Add activity to the current section with database ID
                addActivityToSection(currentSectionId, activityType, activityTitle, description, additionalData, data.cm_id);
                closeModal('addActivityModal');
                showMessage('Activity added successfully!', 'success');
            } else {
                showMessage(data.message || 'Error creating activity', 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showMessage('Error creating activity', 'error');
        });
    } else {
        // Fallback for existing sections without database ID
        addActivityToSection(currentSectionId, activityType, activityTitle, description, additionalData);
        closeModal('addActivityModal');
        showMessage('Activity added successfully!', 'success');
    }
});

// Close modal when clicking outside
window.onclick = function(event) {
    const modals = [
        'addActivityModal',
        'viewContentModal',
        'addSectionModal',
        'editSectionModal',
        'deleteSectionModal',
        'editActivityModal',
        'deleteActivityModal',
        'batchImportModal'
    ];
    
    modals.forEach(modalId => {
        const modal = document.getElementById(modalId);
        if (event.target === modal) {
            closeModal(modalId);
        }
    });
}

// Show message function
function showMessage(message, type = 'info') {
    // Create message element
    const messageDiv = document.createElement('div');
    messageDiv.className = `message message-${type}`;
    messageDiv.textContent = message;
    
    let backgroundColor;
    switch(type) {
        case 'success':
            backgroundColor = '#10b981';
            break;
        case 'error':
            backgroundColor = '#ef4444';
            break;
        default:
            backgroundColor = '#3b82f6';
    }
    
    messageDiv.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        background: ${backgroundColor};
        color: white;
        padding: 15px 20px;
        border-radius: 8px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.3);
        z-index: 1001;
        animation: slideInRight 0.3s ease-out;
        max-width: 300px;
        word-wrap: break-word;
    `;
    
    document.body.appendChild(messageDiv);
    
    // Remove message after 3 seconds
    setTimeout(() => {
        messageDiv.style.animation = 'slideOutRight 0.3s ease-out';
        setTimeout(() => {
            if (messageDiv.parentNode) {
                messageDiv.parentNode.removeChild(messageDiv);
            }
        }, 300);
    }, 3000);
}

// Add CSS animations for messages
const style = document.createElement('style');
style.textContent = `
    @keyframes slideInRight {
        from { transform: translateX(100%); opacity: 0; }
        to { transform: translateX(0); opacity: 1; }
    }
    @keyframes slideOutRight {
        from { transform: translateX(0); opacity: 1; }
        to { transform: translateX(100%); opacity: 0; }
    }
`;
document.head.appendChild(style);

// Form submission handlers for modals
document.getElementById('addSectionForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const sectionName = document.getElementById('sectionName').value.trim();
    if (sectionName) {
        // Send AJAX request to create section in database
        const formData = new FormData();
        formData.append('section_name', sectionName);
        
        fetch('?action=create_section', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                const sectionsContainer = document.getElementById('sectionsContainer');
                const newSection = createSectionElement(sectionName);
                newSection.setAttribute('data-db-section-id', data.section_id);
                sectionsContainer.appendChild(newSection);
                closeModal('addSectionModal');
                showMessage('Section added successfully!', 'success');
            } else {
                showMessage(data.message || 'Error creating section', 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showMessage('Error creating section', 'error');
        });
    }
});

document.getElementById('editSectionForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const newName = document.getElementById('editSectionName').value.trim();
    if (newName) {
        const sectionElement = document.querySelector(`[data-section-id="${currentSectionId}"]`);
        if (sectionElement) {
            const dbSectionId = sectionElement.getAttribute('data-db-section-id');
            if (dbSectionId) {
                // Send AJAX request to update section in database
                const formData = new FormData();
                formData.append('section_id', dbSectionId);
                formData.append('section_name', newName);
                
                fetch('?action=update_section', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        const sectionTitle = sectionElement.querySelector('.section-title');
                        sectionTitle.textContent = newName;
                        closeModal('editSectionModal');
                        showMessage('Section updated successfully!', 'success');
                    } else {
                        showMessage(data.message || 'Error updating section', 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showMessage('Error updating section', 'error');
                });
            } else {
                // Fallback for existing sections without database ID
                const sectionTitle = sectionElement.querySelector('.section-title');
                sectionTitle.textContent = newName;
                closeModal('editSectionModal');
                showMessage('Section updated successfully!', 'success');
            }
        }
    }
});

document.getElementById('editActivityForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const newTitle = document.getElementById('editActivityName').value.trim();
    if (newTitle) {
        const activityElement = document.querySelector(`[data-activity-id="${currentActivityId}"]`);
        if (activityElement) {
            const dbActivityId = activityElement.getAttribute('data-db-activity-id');
            if (dbActivityId) {
                // Send AJAX request to update activity in database
                const formData = new FormData();
                formData.append('cm_id', dbActivityId);
                formData.append('activity_title', newTitle);
                
                fetch('?action=update_activity', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        const activityTitle = activityElement.querySelector('.activity-title');
                        activityTitle.textContent = newTitle;
                        closeModal('editActivityModal');
                        showMessage('Activity updated successfully!', 'success');
                    } else {
                        showMessage(data.message || 'Error updating activity', 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showMessage('Error updating activity', 'error');
                });
            } else {
                // Fallback for existing activities without database ID
                const activityTitle = activityElement.querySelector('.activity-title');
                activityTitle.textContent = newTitle;
                closeModal('editActivityModal');
                showMessage('Activity updated successfully!', 'success');
            }
        }
    }
});

// Confirmation functions
function confirmDeleteSection() {
    const sectionElement = document.querySelector(`[data-section-id="${currentSectionId}"]`);
    if (sectionElement) {
        const dbSectionId = sectionElement.getAttribute('data-db-section-id');
        if (dbSectionId) {
            // Send AJAX request to delete section from database
            const formData = new FormData();
            formData.append('section_id', dbSectionId);
            
            fetch('?action=delete_section', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    sectionElement.remove();
                    closeModal('deleteSectionModal');
                    showMessage('Section deleted successfully!', 'success');
                } else {
                    showMessage(data.message || 'Error deleting section', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showMessage('Error deleting section', 'error');
            });
        } else {
            // Fallback for existing sections without database ID
            sectionElement.remove();
            closeModal('deleteSectionModal');
            showMessage('Section deleted successfully!', 'success');
        }
    }
}

function confirmDeleteActivity() {
    const activityElement = document.querySelector(`[data-activity-id="${currentActivityId}"]`);
    if (activityElement) {
        const dbActivityId = activityElement.getAttribute('data-db-activity-id');
        if (dbActivityId) {
            // Send AJAX request to delete activity from database
            const formData = new FormData();
            formData.append('cm_id', dbActivityId);
            
            fetch('?action=delete_activity', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    activityElement.remove();
                    closeModal('deleteActivityModal');
                    showMessage('Activity deleted successfully!', 'success');
                } else {
                    showMessage(data.message || 'Error deleting activity', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showMessage('Error deleting activity', 'error');
            });
        } else {
            // Fallback for existing activities without database ID
            activityElement.remove();
            closeModal('deleteActivityModal');
            showMessage('Activity deleted successfully!', 'success');
        }
    }
}

// Global error handler to catch positioning errors
window.addEventListener('error', function(e) {
    if (e.message && e.message.includes('Cannot read properties of undefined (reading \'left\')')) {
        console.warn('Positioning error caught and handled:', e.message);
        e.preventDefault();
        return true;
    }
});

// Prevent jQuery positioning errors
if (typeof $ !== 'undefined' && typeof $.fn !== 'undefined') {
    $(document).ready(function() {
        // Override jQuery offset method to handle undefined elements
        const originalOffset = $.fn.offset;
        $.fn.offset = function(coordinates) {
            if (this.length === 0) {
                console.warn('jQuery offset called on empty selection');
                return coordinates ? this : { top: 0, left: 0 };
            }
            return originalOffset.apply(this, arguments);
        };
    });
}

// Initialize page
document.addEventListener('DOMContentLoaded', function() {
    // Initialize activity fields
    updateActivityFields();
    
    // Ensure all modals are properly initialized
    const modals = [
        'addActivityModal',
        'viewContentModal', 
        'addSectionModal',
        'editSectionModal',
        'deleteSectionModal',
        'editActivityModal',
        'deleteActivityModal',
        'batchImportModal'
    ];
    
    modals.forEach(modalId => {
        const modal = document.getElementById(modalId);
        if (!modal) {
            console.error('Modal not found during initialization:', modalId);
        }
    });
});
</script>

<?php
echo $OUTPUT->footer();
?>
