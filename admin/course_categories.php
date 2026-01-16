<?php
/**
 * Course Categories & Courses Management Page
 * Full-featured interface similar to Moodle's course/management.php
 */

require_once('../../../config.php');
require_once($CFG->dirroot.'/course/lib.php');
require_login();

// Check admin capabilities
$context = context_system::instance();
require_capability('moodle/site:config', $context);

// Get current user
global $USER, $DB, $OUTPUT, $PAGE;

// Parameters
$categoryid = optional_param('categoryid', null, PARAM_INT);
$selectedcategoryid = optional_param('selectedcategoryid', null, PARAM_INT);
$courseid = optional_param('courseid', null, PARAM_INT);
$action = optional_param('action', false, PARAM_ALPHANUMEXT);
$page = optional_param('page', 0, PARAM_INT);
$perpage = optional_param('perpage', 20, PARAM_INT);
$viewmode = optional_param('view', 'combined', PARAM_ALPHA); // combined, categories, courses
$search = optional_param('search', '', PARAM_RAW);
$sortby = optional_param('sortby', 'sortorder', PARAM_ALPHANUMEXT);

// Validate viewmode
if (!in_array($viewmode, array('combined', 'categories', 'courses'))) {
    $viewmode = 'combined';
}

$issearching = ($search !== '');
if ($issearching) {
    $viewmode = 'courses';
}

$url = new moodle_url('/theme/remui_kids/admin/course_categories.php');

// Get category and course context
$course = null;
$category = null;

if ($courseid) {
    $record = $DB->get_record('course', ['id' => $courseid]);
    if ($record) {
        $course = $record;
        $categoryid = $course->category;
    }
}

if ($categoryid) {
    $category = $DB->get_record('course_categories', ['id' => $categoryid]);
} else {
    // Get first top-level category
    $categories = $DB->get_records('course_categories', ['parent' => 0, 'visible' => 1], 'sortorder ASC', '*', 0, 1);
    if (!empty($categories)) {
        $category = reset($categories);
        $categoryid = $category->id;
    }
}

if ($categoryid) {
    $url->param('categoryid', $categoryid);
}
if ($courseid) {
    $url->param('courseid', $courseid);
}
if ($viewmode !== 'combined') {
    $url->param('view', $viewmode);
}
if ($page !== 0) {
    $url->param('page', $page);
}
if ($search !== '') {
    $url->param('search', $search);
}

// Handle AJAX requests for quick actions
if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    header('Content-Type: application/json');
    
    switch ($action) {
        case 'toggle_course_visibility':
            $courseid = required_param('courseid', PARAM_INT);
            $course = $DB->get_record('course', ['id' => $courseid]);
            if ($course && $course->id > 1) {
                $course->visible = $course->visible ? 0 : 1;
                $course->timemodified = time();
                $DB->update_record('course', $course);
                echo json_encode(['status' => 'success', 'visible' => $course->visible]);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Cannot modify course']);
            }
            exit;
            
        case 'toggle_category_visibility':
            $catid = required_param('categoryid', PARAM_INT);
            $cat = $DB->get_record('course_categories', ['id' => $catid]);
            if ($cat) {
                $cat->visible = $cat->visible ? 0 : 1;
                $cat->timemodified = time();
                $DB->update_record('course_categories', $cat);
                echo json_encode(['status' => 'success', 'visible' => $cat->visible]);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Cannot modify category']);
            }
            exit;
            
        case 'delete_course':
            $courseid = required_param('courseid', PARAM_INT);
            if ($courseid > 1) {
                $course = $DB->get_record('course', ['id' => $courseid]);
                if ($course) {
                    $course->visible = 0;
                    $DB->update_record('course', $course);
                    echo json_encode(['status' => 'success']);
                } else {
                    echo json_encode(['status' => 'error', 'message' => 'Course not found']);
                }
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Cannot delete system course']);
            }
            exit;
            
        case 'get_category_courses':
            $catid = required_param('categoryid', PARAM_INT);
            $courses = $DB->get_records('course', ['category' => $catid, 'visible' => 1], 'sortorder ASC');
            echo json_encode(['status' => 'success', 'courses' => array_values($courses)]);
            exit;
            
        case 'get_course_details':
            $courseid = required_param('courseid', PARAM_INT);
            $course = $DB->get_record('course', ['id' => $courseid]);
            if ($course) {
                // Get category name
                $cat = $DB->get_record('course_categories', ['id' => $course->category]);
                $course->category_name = $cat ? $cat->name : 'Unknown';
                $course->created_date = userdate($course->timecreated, '%d %B %Y');
                echo json_encode(['status' => 'success', 'course' => $course]);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Course not found']);
            }
            exit;
            
        case 'delete_category':
            $catid = required_param('categoryid', PARAM_INT);
            if ($catid > 0) {
                // Check if category has courses
                $coursecount = $DB->count_records('course', ['category' => $catid, 'visible' => 1]);
                if ($coursecount > 0) {
                    echo json_encode(['status' => 'error', 'message' => 'Cannot delete category with ' . $coursecount . ' course(s). Please move or delete courses first.']);
                } else {
                    // Check if category has subcategories
                    $subcatcount = $DB->count_records('course_categories', ['parent' => $catid, 'visible' => 1]);
                    if ($subcatcount > 0) {
                        echo json_encode(['status' => 'error', 'message' => 'Cannot delete category with ' . $subcatcount . ' subcategor' . ($subcatcount == 1 ? 'y' : 'ies') . '. Please move or delete subcategories first.']);
                    } else {
                        // Safe to delete
                        if ($DB->delete_records('course_categories', ['id' => $catid])) {
                        echo json_encode(['status' => 'success', 'message' => 'Category deleted successfully']);
                    } else {
                        echo json_encode(['status' => 'error', 'message' => 'Failed to delete category']);
                        }
                    }
                }
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Invalid category ID']);
            }
            exit;
    }
    
    echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
    exit;
}

// Handle form submissions (non-AJAX)
$message = '';
$messagetype = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    switch ($action) {
            case 'create_course':
            require_once($CFG->dirroot.'/course/lib.php');
            
            $fullname = trim(required_param('fullname', PARAM_TEXT));
            $shortname = trim(required_param('shortname', PARAM_TEXT));
            $summary = optional_param('summary', '', PARAM_RAW);
            $catid = required_param('category', PARAM_INT);
            $format = optional_param('format', 'topics', PARAM_ALPHA);
            $visible = optional_param('visible', 1, PARAM_INT);
            $startdate = optional_param('startdate', '', PARAM_RAW);
            $enddate = optional_param('enddate', '', PARAM_RAW);
            $idnumber = optional_param('idnumber', '', PARAM_RAW);
            $numsections = optional_param('numsections', 10, PARAM_INT);
            $newsitems = optional_param('newsitems', 5, PARAM_INT);
            $showgrades = optional_param('showgrades', 1, PARAM_INT);
            $showreports = optional_param('showreports', 1, PARAM_INT);
            $maxbytes = optional_param('maxbytes', 10485760, PARAM_INT);
            $enablecompletion = optional_param('enablecompletion', 1, PARAM_INT);
            $groupmode = optional_param('groupmode', 0, PARAM_INT);
            $groupmodeforce = optional_param('groupmodeforce', 0, PARAM_INT);
            $lang = optional_param('lang', '', PARAM_LANG);
            
            if ($fullname && $shortname && $catid) {
                // Check if shortname already exists
                if ($DB->record_exists('course', ['shortname' => $shortname])) {
                    $message = "A course with short name '{$shortname}' already exists!";
                    $messagetype = 'error';
                    } else {
                    $coursedata = new stdClass();
                    $coursedata->fullname = $fullname;
                    $coursedata->shortname = $shortname;
                    $coursedata->summary = $summary;
                    $coursedata->summaryformat = FORMAT_HTML;
                    $coursedata->category = $catid;
                    $coursedata->format = $format;
                    $coursedata->numsections = $numsections;
                    $coursedata->visible = $visible;
                    $coursedata->idnumber = $idnumber;
                    $coursedata->newsitems = $newsitems;
                    $coursedata->showgrades = $showgrades;
                    $coursedata->showreports = $showreports;
                    $coursedata->maxbytes = $maxbytes;
                    $coursedata->enablecompletion = $enablecompletion;
                    $coursedata->groupmode = $groupmode;
                    $coursedata->groupmodeforce = $groupmodeforce;
                    $coursedata->lang = $lang;
                    
                    // Handle dates
                    if ($startdate) {
                        $coursedata->startdate = strtotime($startdate);
                    } else {
                        $coursedata->startdate = time();
                    }
                    
                    if ($enddate) {
                        $coursedata->enddate = strtotime($enddate);
                } else {
                        $coursedata->enddate = 0;
                    }
                    
                    try {
                        // Use Moodle's create_course function for proper course creation
                        $newcourse = create_course($coursedata);
                        $message = "Course '{$fullname}' created successfully!";
                        $messagetype = 'success';
                        redirect(new moodle_url($url, ['categoryid' => $catid]), $message, null, \core\output\notification::NOTIFY_SUCCESS);
                    } catch (Exception $e) {
                        $message = "Failed to create course: " . $e->getMessage();
                        $messagetype = 'error';
                    }
                }
            }
            break;
            
        case 'update_course':
            require_once($CFG->dirroot.'/course/lib.php');
            
            $courseid = required_param('courseid', PARAM_INT);
            $fullname = trim(required_param('fullname', PARAM_TEXT));
            $shortname = trim(required_param('shortname', PARAM_TEXT));
            $summary = optional_param('summary', '', PARAM_RAW);
            $catid = required_param('category', PARAM_INT);
            $format = optional_param('format', 'topics', PARAM_ALPHA);
            $visible = optional_param('visible', 1, PARAM_INT);
            $startdate = optional_param('startdate', '', PARAM_RAW);
            $enddate = optional_param('enddate', '', PARAM_RAW);
            $idnumber = optional_param('idnumber', '', PARAM_RAW);
            $numsections = optional_param('numsections', 10, PARAM_INT);
            $newsitems = optional_param('newsitems', 5, PARAM_INT);
            $showgrades = optional_param('showgrades', 1, PARAM_INT);
            $showreports = optional_param('showreports', 1, PARAM_INT);
            $maxbytes = optional_param('maxbytes', 10485760, PARAM_INT);
            $enablecompletion = optional_param('enablecompletion', 1, PARAM_INT);
            $groupmode = optional_param('groupmode', 0, PARAM_INT);
            $groupmodeforce = optional_param('groupmodeforce', 0, PARAM_INT);
            $lang = optional_param('lang', '', PARAM_LANG);
            
            if ($fullname && $shortname && $catid && $courseid) {
                // Check if shortname already exists for different course
                if ($existing = $DB->get_record('course', ['shortname' => $shortname])) {
                    if ($existing->id != $courseid) {
                        $message = "A course with short name '{$shortname}' already exists!";
                        $messagetype = 'error';
                        break;
                    }
                }
                
                $coursedata = new stdClass();
                $coursedata->id = $courseid;
                $coursedata->fullname = $fullname;
                $coursedata->shortname = $shortname;
                $coursedata->summary = $summary;
                $coursedata->summaryformat = FORMAT_HTML;
                $coursedata->category = $catid;
                $coursedata->format = $format;
                $coursedata->numsections = $numsections;
                $coursedata->visible = $visible;
                $coursedata->idnumber = $idnumber;
                $coursedata->newsitems = $newsitems;
                $coursedata->showgrades = $showgrades;
                $coursedata->showreports = $showreports;
                $coursedata->maxbytes = $maxbytes;
                $coursedata->enablecompletion = $enablecompletion;
                $coursedata->groupmode = $groupmode;
                $coursedata->groupmodeforce = $groupmodeforce;
                $coursedata->lang = $lang;
                
                // Handle dates
                if ($startdate) {
                    $coursedata->startdate = strtotime($startdate);
                }
                
                if ($enddate) {
                    $coursedata->enddate = strtotime($enddate);
                } else {
                    $coursedata->enddate = 0;
                }
                
                try {
                    // Use Moodle's update_course function
                    update_course($coursedata);
                    $message = "Course '{$fullname}' updated successfully!";
                    $messagetype = 'success';
                    redirect(new moodle_url($url, ['categoryid' => $catid]), $message, null, \core\output\notification::NOTIFY_SUCCESS);
                } catch (Exception $e) {
                    $message = "Failed to update course: " . $e->getMessage();
                    $messagetype = 'error';
                }
                }
                break;
                
            case 'create_category':
            $name = trim(required_param('name', PARAM_TEXT));
            $description = optional_param('description', '', PARAM_RAW);
            $parent = optional_param('parent_category', 0, PARAM_INT);
                
                if ($name) {
                $newcat = new stdClass();
                $newcat->name = $name;
                $newcat->description = $description;
                $newcat->parent = $parent;
                $newcat->sortorder = 0;
                $newcat->timecreated = time();
                $newcat->timemodified = time();
                $newcat->visible = 1;
                $newcat->depth = 1;
                $newcat->path = '';
                
                $catid = $DB->insert_record('course_categories', $newcat);
                if ($catid) {
                    // Update path
                    if ($parent == 0) {
                        $path = '/' . $catid;
                    } else {
                        $parentcat = $DB->get_record('course_categories', ['id' => $parent]);
                        $path = $parentcat->path . '/' . $catid;
                        $newcat->depth = $parentcat->depth + 1;
                    }
                    $DB->set_field('course_categories', 'path', $path, ['id' => $catid]);
                    $DB->set_field('course_categories', 'depth', $newcat->depth, ['id' => $catid]);
                    
                    $message = "Category '{$name}' created successfully!";
                    $messagetype = 'success';
                    redirect($url, $message, null, \core\output\notification::NOTIFY_SUCCESS);
                } else {
                    $message = "Failed to create category.";
                    $messagetype = 'error';
                }
                }
                break;
            
        case 'bulk_move_courses':
            $courseids = optional_param_array('selected_courses', [], PARAM_INT);
            $movetocatid = required_param('move_to_category', PARAM_INT);
            
            if (!empty($courseids) && $movetocatid > 0) {
                $movecount = 0;
                foreach ($courseids as $cid) {
                    if ($cid > 1) { // Don't move site course
                        $DB->set_field('course', 'category', $movetocatid, ['id' => $cid]);
                        $movecount++;
                    }
                }
                $message = "Successfully moved {$movecount} course(s).";
                $messagetype = 'success';
                redirect(new moodle_url($url, ['categoryid' => $movetocatid]), $message, null, \core\output\notification::NOTIFY_SUCCESS);
            }
            break;
            
        case 'bulk_move_categories':
            $catids = optional_param_array('selected_categories', [], PARAM_INT);
            $movetocatid = required_param('move_to_category', PARAM_INT);
            
            if (!empty($catids)) {
                $movecount = 0;
                foreach ($catids as $cid) {
                    if ($cid != $movetocatid) {
                        $DB->set_field('course_categories', 'parent', $movetocatid, ['id' => $cid]);
                        $movecount++;
                    }
                }
                $message = "Successfully moved {$movecount} categor" . ($movecount == 1 ? 'y' : 'ies') . ".";
                $messagetype = 'success';
                redirect($url, $message, null, \core\output\notification::NOTIFY_SUCCESS);
            }
            break;
            
        case 'bulk_delete_categories':
            $catids = optional_param_array('selected_categories', [], PARAM_INT);
            
            if (!empty($catids)) {
                $deletecount = 0;
                $skipped = [];
                
                foreach ($catids as $cid) {
                    // Check if category has courses
                    $coursecount = $DB->count_records('course', ['category' => $cid, 'visible' => 1]);
                    if ($coursecount > 0) {
                        $cat = $DB->get_record('course_categories', ['id' => $cid]);
                        $skipped[] = $cat->name . " (has {$coursecount} course" . ($coursecount == 1 ? '' : 's') . ")";
                        continue;
                    }
                    
                    // Check if category has subcategories
                    $subcatcount = $DB->count_records('course_categories', ['parent' => $cid, 'visible' => 1]);
                    if ($subcatcount > 0) {
                        $cat = $DB->get_record('course_categories', ['id' => $cid]);
                        $skipped[] = $cat->name . " (has {$subcatcount} subcategor" . ($subcatcount == 1 ? 'y' : 'ies') . ")";
                        continue;
                    }
                    
                    // Safe to delete
                    if ($DB->delete_records('course_categories', ['id' => $cid])) {
                        $deletecount++;
                    }
                }
                
                $message = "Deleted {$deletecount} categor" . ($deletecount == 1 ? 'y' : 'ies') . ".";
                if (!empty($skipped)) {
                    $message .= " Skipped: " . implode(', ', $skipped);
                }
                $messagetype = $deletecount > 0 ? 'success' : 'error';
                redirect($url, $message, null, $deletecount > 0 ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_ERROR);
            }
            break;
            
        case 'bulk_delete_courses':
            $courseids = optional_param_array('selected_courses', [], PARAM_INT);
            
            if (!empty($courseids)) {
                $deletecount = 0;
                
                foreach ($courseids as $cid) {
                    if ($cid > 1) { // Don't delete site course
                        $course = $DB->get_record('course', ['id' => $cid]);
                        if ($course) {
                            $course->visible = 0;
                            $course->timemodified = time();
                            $DB->update_record('course', $course);
                            $deletecount++;
                        }
                    }
                }
                
                $message = "Successfully hidden {$deletecount} course" . ($deletecount == 1 ? '' : 's') . ".";
                $messagetype = 'success';
                redirect($url, $message, null, \core\output\notification::NOTIFY_SUCCESS);
            }
            break;
    }
}

$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_title('Course Categories & Courses Management');
$PAGE->set_heading('Course Categories & Courses Management');

echo $OUTPUT->header();

// Admin Sidebar Navigation
require_once('includes/admin_sidebar.php');

// Main content wrapper
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
    background: linear-gradient(135deg, #f7fafc 0%, #edf2f7 50%, #e2e8f0 100%);
    overflow-x: hidden;
}

/* Admin Sidebar - Fixed */
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
}

/* Main content area */
.admin-main-content {
    position: fixed;
    top: 0;
    left: 280px;
    width: calc(100vw - 280px);
    height: 100vh;
    background-color: #f8f9fa;
    overflow-y: auto;
    z-index: 99;
    padding-top: 20px;
}

/* Sidebar Toggle Button */
.sidebar-toggle {
    display: none;
    position: fixed;
    top: 15px;
    left: 15px;
    z-index: 1100;
    width: 45px;
    height: 45px;
    background: linear-gradient(135deg, #3182ce 0%, #2c5282 100%);
    color: white;
    border: none;
    border-radius: 10px;
    cursor: pointer;
    box-shadow: 0 4px 12px rgba(49, 130, 206, 0.3);
    transition: all 0.3s ease;
    align-items: center;
    justify-content: center;
    font-size: 1.2rem;
}

.sidebar-toggle:hover {
    transform: scale(1.05);
    box-shadow: 0 6px 16px rgba(49, 130, 206, 0.5);
}

/* Management Container */
.management-container {
    max-width: 100%;
    height: calc(100vh - 20px);
    padding: 20px;
    display: flex;
    flex-direction: column;
}

/* Header Section */
.management-header {
    background: linear-gradient(135deg, #e0f2fe 0%, #bae6fd 100%);
    padding: 20px 30px;
    border-radius: 15px;
    margin-bottom: 20px;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
}

.header-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
}

.header-title {
        display: flex;
        align-items: center;
    gap: 15px;
}

.header-icon {
    width: 50px;
    height: 50px;
    background: rgba(30, 58, 138, 0.1);
    border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
    font-size: 1.5rem;
    color: #1e3a8a;
}

.header-title h1 {
    font-size: 2rem;
    font-weight: 700;
    color: #1e3a8a;
    margin: 0;
}

.header-actions {
    display: flex;
    gap: 10px;
}

.header-btn {
    background: rgba(30, 58, 138, 0.1);
    color: #1e3a8a;
    border: 1px solid rgba(30, 58, 138, 0.2);
    padding: 10px 20px;
    border-radius: 10px;
    font-size: 0.9rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    gap: 8px;
}

.header-btn:hover {
    background: rgba(30, 58, 138, 0.2);
    transform: translateY(-2px);
}

/* Search Bar */
.search-bar {
    margin-top: 15px;
    display: flex;
    gap: 10px;
}

.search-input {
    flex: 1;
    padding: 12px 20px;
    border: 2px solid rgba(30, 58, 138, 0.2);
    border-radius: 10px;
    background: white;
    font-size: 0.95rem;
    color: #333;
}

.search-input:focus {
    outline: none;
    border-color: #3182ce;
    background: white;
    box-shadow: 0 0 0 3px rgba(49, 130, 206, 0.1);
}

.search-btn {
    padding: 12px 25px;
    background: #3182ce;
    color: white;
    border: none;
    border-radius: 10px;
    font-size: 0.95rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
}

.search-btn:hover {
    background: #2c5282;
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(49, 130, 206, 0.3);
}

/* Management Grid - Two Row Layout */
.management-grid {
    flex: 1;
    display: grid;
    gap: 20px;
    min-height: 0;
    overflow: auto;
}

.management-grid.view-combined {
    grid-template-columns: 450px 1fr;
    grid-template-areas: 
        "categories courses";
}

.management-grid.view-categories {
    grid-template-columns: 1fr;
    grid-template-areas: "categories";
}

.management-grid.view-courses {
    grid-template-columns: 1fr;
    grid-template-areas: "courses";
}

.categories-panel {
    grid-area: categories;
}

.courses-panel {
    grid-area: courses;
}

/* Panel Styles */
.panel {
    background: white;
    border-radius: 15px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    min-height: 0;
}

.panel-header {
    padding: 20px;
    border-bottom: 2px solid #f0f0f0;
    background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
}

.panel-header-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
}

.panel-title {
    font-size: 1.3rem;
    font-weight: 600;
    color: #2c3e50;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.panel-title i {
    color: #3182ce;
}

.panel-count {
    font-size: 0.85rem;
    color: #6c757d;
    margin-top: 5px;
}

.panel-action {
    padding: 8px 16px;
    background: linear-gradient(135deg, #3182ce 0%, #2c5282 100%);
    color: white;
    border: none;
    border-radius: 8px;
    font-size: 0.85rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    gap: 6px;
}

.panel-action:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(49, 130, 206, 0.4);
}

.panel-action-sm {
    padding: 8px 10px;
    min-width: auto;
}

.panel-content {
    flex: 1;
    overflow-y: auto;
    padding: 15px;
    min-height: 0;
}

/* Category List */
.category-tree {
    list-style: none;
    padding: 0;
    margin: 0;
}

.category-tree-item {
    margin-bottom: 5px;
}

.category-item {
    display: flex;
    align-items: center;
    padding: 12px 10px;
    border-radius: 10px;
    cursor: pointer;
    transition: all 0.3s ease;
    border: 2px solid transparent;
    background: transparent;
}

.category-item:hover {
    background: transparent;
    transform: translateX(3px);
}

.category-item.selected {
    background: linear-gradient(135deg, #e0f2fe 0%, #bae6fd 100%);
    color: #1e3a8a;
    border: 2px solid #3b82f6 !important;
    box-shadow: 0 4px 8px rgba(59, 130, 246, 0.2);
}

.category-item.hidden {
    opacity: 0.5;
}

/* Category expand/collapse toggle */
.category-toggle {
    width: 24px;
    height: 24px;
    background: transparent;
    border: none;
    color: #6c757d;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 4px;
    margin-right: 5px;
    transition: all 0.3s ease;
    flex-shrink: 0;
}

.category-toggle:hover {
    background: rgba(0, 0, 0, 0.05);
    color: #3182ce;
}

.category-toggle i {
    font-size: 0.8rem;
    transition: transform 0.3s ease;
}

.category-tree-item.collapsed > .category-item .category-toggle i {
    transform: rotate(-90deg);
}

.category-item .category-icon i.fa-folder {
    transition: all 0.3s ease;
}

.category-tree-item.collapsed > .category-item .category-icon i.fa-folder:before {
    content: "\f07b"; /* fa-folder icon code */
}

.category-toggle-spacer {
    width: 24px;
    height: 24px;
    margin-right: 5px;
    flex-shrink: 0;
}

.category-checkbox {
    margin-right: 10px;
    width: 18px;
    height: 18px;
    cursor: pointer;
    flex-shrink: 0;
}

.category-icon {
    width: 35px;
    height: 35px;
    background: linear-gradient(135deg, #4299e1 0%, #3182ce 100%);
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 1rem;
    margin-right: 12px;
    flex-shrink: 0;
}


/* Different icon styles for hierarchy levels */
.category-tree-item.level-0 > .category-item .category-icon {
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    width: 38px;
    height: 38px;
}

.category-tree-item.level-0 > .category-item .category-icon i {
    font-size: 1.1rem;
}

.category-tree-item.level-1 > .category-item .category-icon {
    background: linear-gradient(135deg, #60a5fa 0%, #3b82f6 100%);
}

.category-tree-item.level-2 > .category-item .category-icon {
    background: linear-gradient(135deg, #93c5fd 0%, #60a5fa 100%);
    width: 32px;
    height: 32px;
    font-size: 0.9rem;
}

.category-tree-item.level-3 > .category-item .category-icon {
    background: linear-gradient(135deg, #bfdbfe 0%, #93c5fd 100%);
    width: 30px;
    height: 30px;
    font-size: 0.85rem;
}

.category-info {
    flex: 1;
    min-width: 0;
}

.category-name {
    font-weight: 600;
    font-size: 0.95rem;
    margin: 0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.category-meta {
    font-size: 0.8rem;
    opacity: 0.8;
    margin-top: 3px;
}

.category-actions {
    display: flex;
    gap: 5px;
    opacity: 0;
    transition: opacity 0.3s ease;
}

.category-item:hover .category-actions {
    opacity: 1;
}

.category-action-btn {
    width: 28px;
    height: 28px;
    border: none;
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s ease;
    font-size: 0.75rem;
}

.btn-edit {
    background: #63b3ed;
    color: white;
}

.btn-edit:hover {
    background: #4299e1;
}

.btn-visibility {
    background: #718096;
    color: white;
}

.btn-visibility:hover {
    background: #4a5568;
}

.btn-delete {
    background: #fc8181;
    color: white;
}

.btn-delete:hover {
    background: #f56565;
}

/* Subcategory list */
.subcategory-list {
    list-style: none;
    padding-left: 15px;
    margin: 8px 0 0 5px;
    transition: all 0.3s ease;
    overflow: hidden;
    border-left: 2px solid #e0f2fe;
}

.category-tree {
    list-style: none;
    padding: 0;
    margin: 0;
}

.category-tree-item.level-0 > ul {
    border-left: none;
    padding-left: 0;
    margin-left: 10px;
}

.category-tree-item.collapsed > ul {
    display: none !important;
}

/* Parent categories (level 0) - Bold and prominent */
.category-tree-item.level-0 {
    margin-bottom: 16px;
}

.category-tree-item.level-0 > .category-item {
    background: transparent;
    border: 2px solid #e0f2fe;
    border-radius: 8px;
    font-weight: 600;
    margin-bottom: 4px;
    box-shadow: none;
}

.category-tree-item.level-0 > .category-item:hover {
    background: transparent;
    border-color: #bae6fd;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
}

.category-tree-item.level-0 > .category-item.selected {
    background: linear-gradient(135deg, #e0f2fe 0%, #bae6fd 100%);
    border: 2px solid #3b82f6 !important;
    box-shadow: 0 4px 8px rgba(59, 130, 246, 0.2);
}

.category-tree-item.level-0 > .category-item .category-name {
    font-size: 1.05rem;
    color: #1e3a8a;
}

/* Level 1 subcategories - Light blue background with left border */
.category-tree-item.level-1 > .category-item {
    margin-left: 20px;
    background: transparent;
    border-left: 3px solid #bae6fd;
    padding-left: 12px;
    margin-bottom: 4px;
}

.category-tree-item.level-1 > .category-item:hover {
    background: transparent;
    border-left-color: #7dd3fc;
}

.category-tree-item.level-1 > .category-item.selected {
    background: #dbeafe;
    border: 2px solid #3b82f6 !important;
    border-left: 3px solid #3b82f6 !important;
    box-shadow: 0 3px 6px rgba(59, 130, 246, 0.15);
}

/* Level 2 subcategories - Lighter blue with thinner border */
.category-tree-item.level-2 > .category-item {
    margin-left: 40px;
    background: transparent;
    border-left: 2px solid #cbd5e1;
    padding-left: 10px;
    margin-bottom: 3px;
}

.category-tree-item.level-2 > .category-item:hover {
    background: transparent;
    border-left-color: #94a3b8;
}

.category-tree-item.level-2 > .category-item.selected {
    background: #e2e8f0;
    border: 2px solid #64748b !important;
    border-left: 3px solid #64748b !important;
    box-shadow: 0 2px 4px rgba(100, 116, 139, 0.15);
}

/* Level 3+ subcategories - Subtle gray */
.category-tree-item.level-3 > .category-item {
    margin-left: 60px;
    background: transparent;
    border-left: 1px solid #e5e7eb;
    padding-left: 8px;
    margin-bottom: 2px;
}

.category-tree-item.level-3 > .category-item.selected {
    background: #f1f5f9;
    border: 2px solid #94a3b8 !important;
    border-left: 2px solid #94a3b8 !important;
    box-shadow: 0 1px 3px rgba(148, 163, 184, 0.15);
}

/* Course List */
.course-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.course-item {
    display: flex;
    align-items: center;
    padding: 15px;
    background: transparent;
    border-radius: 10px;
    border: 2px solid transparent;
    cursor: pointer;
    transition: all 0.3s ease;
}

.course-item:hover {
    background: transparent;
    border-color: #3182ce;
    transform: translateY(-2px);
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
}

.course-item.selected {
    background: linear-gradient(135deg, #e0f2fe 0%, #bae6fd 100%);
    color: #1e3a8a;
    border: 2px solid #3b82f6 !important;
    box-shadow: 0 4px 8px rgba(59, 130, 246, 0.2);
}

.course-item.hidden {
    opacity: 0.5;
}

.course-checkbox {
    margin-right: 10px;
    width: 18px;
    height: 18px;
    cursor: pointer;
}

.course-icon {
    width: 45px;
    height: 45px;
    background: linear-gradient(135deg, #63b3ed 0%, #4299e1 100%);
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 1.2rem;
    margin-right: 15px;
    flex-shrink: 0;
}


.course-info {
    flex: 1;
    min-width: 0;
}

.course-name {
    font-weight: 600;
    font-size: 1rem;
    margin: 0 0 5px 0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.course-shortname {
    font-size: 0.85rem;
    color: #4299e1;
    margin: 0 0 5px 0;
    font-weight: 500;
}

.course-item.selected .course-shortname {
    color: #2c5282;
}

.course-meta {
    display: flex;
    gap: 15px;
    font-size: 0.8rem;
    opacity: 0.8;
}

.course-actions {
    display: flex;
    gap: 5px;
    opacity: 0;
    transition: opacity 0.3s ease;
}

.course-item:hover .course-actions {
    opacity: 1;
}

.course-action-btn {
    width: 32px;
    height: 32px;
    border: none;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s ease;
    font-size: 0.85rem;
}

/* Course Detail Panel */
.course-detail {
    padding: 20px;
}

.detail-section {
    margin-bottom: 25px;
}

.detail-section h3 {
    font-size: 1.1rem;
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 15px;
    padding-bottom: 10px;
    border-bottom: 2px solid #f0f0f0;
}

.detail-item {
    margin-bottom: 15px;
}

.detail-label {
    font-size: 0.85rem;
    font-weight: 600;
    color: #6c757d;
    margin-bottom: 5px;
}

.detail-value {
    font-size: 0.95rem;
    color: #2c3e50;
}

.detail-actions {
    display: flex;
    flex-direction: row;
    gap: 8px;
    flex-wrap: wrap;
}

.detail-action-btn {
    padding: 8px 12px;
    border: none;
    border-radius: 6px;
    font-size: 0.8rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    flex: 1;
    min-width: 120px;
}

.detail-action-btn i {
    font-size: 0.75rem;
}

.btn-primary {
    background: linear-gradient(135deg, #3182ce 0%, #2c5282 100%);
    color: white;
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(49, 130, 206, 0.4);
}

.btn-secondary {
    background: #f8f9fa;
    color: #6c757d;
    border: 2px solid #e9ecef;
}

.btn-secondary:hover {
    background: #e9ecef;
}

.btn-danger {
    background: #fc8181;
    color: white;
}

.btn-danger:hover {
    background: #f56565;
}

/* Responsive for action buttons */
@media (max-width: 768px) {
    .detail-action-btn {
        flex: 1 1 calc(50% - 4px);
        min-width: 100px;
        font-size: 0.75rem;
        padding: 7px 10px;
    }
}

/* Bulk Actions Bar */
.bulk-actions-bar {
    position: sticky;
    bottom: 0;
    left: 0;
    right: 0;
    background: linear-gradient(135deg, #3182ce 0%, #2c5282 100%);
    padding: 15px 20px;
    border-radius: 10px;
    margin-top: 15px;
    display: none;
    align-items: center;
    gap: 15px;
    box-shadow: 0 -5px 20px rgba(0, 0, 0, 0.1);
}

.bulk-actions-bar.active {
    display: flex;
}

.bulk-selection-info {
    color: white;
    font-weight: 600;
    flex: 1;
}

.bulk-action-select {
    padding: 8px 12px;
    border: 2px solid rgba(255, 255, 255, 0.3);
    border-radius: 8px;
    background: rgba(255, 255, 255, 0.9);
    font-size: 0.9rem;
    cursor: pointer;
}

.bulk-action-btn {
    padding: 8px 20px;
    background: white;
    color: #b8d4f0;
    border: none;
    border-radius: 8px;
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
}

.bulk-action-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
}

/* Pagination */
.pagination {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 15px 20px;
    background: #f8f9fa;
    border-radius: 10px;
    margin-top: 15px;
}

.pagination-info {
    font-size: 0.9rem;
    color: #6c757d;
}

.pagination-controls {
    display: flex;
    gap: 5px;
}

.page-btn {
    padding: 8px 12px;
    background: white;
    color: #6c757d;
    border: 2px solid #e9ecef;
    border-radius: 6px;
    font-size: 0.85rem;
    cursor: pointer;
    transition: all 0.3s ease;
}

.page-btn:hover:not(:disabled) {
    background: #3182ce;
    color: white;
    border-color: #3182ce;
}

.page-btn.active {
    background: linear-gradient(135deg, #3182ce 0%, #2c5282 100%);
    color: white;
    border-color: #2c5282;
}

.page-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

/* Empty State */
.empty-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 60px 20px;
    text-align: center;
    color: #6c757d;
}

.empty-icon {
    font-size: 4rem;
    margin-bottom: 20px;
    opacity: 0.5;
}

.empty-title {
    font-size: 1.3rem;
    font-weight: 600;
    margin-bottom: 10px;
    color: #495057;
}

.empty-description {
    font-size: 0.95rem;
    opacity: 0.8;
    max-width: 400px;
}

/* Modal Styles */
.modal {
    display: none;
    position: fixed;
    z-index: 2000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.6);
    backdrop-filter: blur(5px);
}

.modal.active {
    display: flex;
    align-items: center;
    justify-content: center;
}

.modal-content {
    background: white;
    border-radius: 20px;
    width: 90%;
    max-width: 700px;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
    animation: modalSlideIn 0.3s ease-out;
    padding-top: 20px;
}

@keyframes modalSlideIn {
    from {
        opacity: 0;
        transform: scale(0.9);
    }
    to {
        opacity: 1;
        transform: scale(1);
    }
}

.modal-header {
    background: linear-gradient(135deg, #2c5282 0%, #2b6cb0 100%);
    color: white;
    padding: 25px 30px;
    border-radius: 20px 20px 0 0;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-header h3 {
    margin: 0;
    font-size: 1.5rem;
    font-weight: 600;
}

.modal-close {
    color: white;
    font-size: 28px;
    font-weight: bold;
    cursor: pointer;
    background: none;
    border: none;
    padding: 0;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    transition: background 0.3s ease;
}

.modal-close:hover {
    background: rgba(255, 255, 255, 0.2);
}

.modal-body {
    padding: 30px;
}

.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
    color: #333;
    font-size: 0.95rem;
}

.form-group input,
.form-group select,
.form-group textarea {
    width: 100%;
    padding: 12px 15px;
    border: 2px solid #e9ecef;
    border-radius: 10px;
    font-size: 1rem;
    transition: border-color 0.3s ease;
}

.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus {
    outline: none;
    border-color: #3182ce;
    box-shadow: 0 0 0 3px rgba(49, 130, 206, 0.1);
}

.form-group textarea {
    resize: vertical;
    min-height: 100px;
}

.form-control {
    width: 100%;
    padding: 12px 15px;
    border: 2px solid #e9ecef;
    border-radius: 10px;
    font-size: 1rem;
    transition: border-color 0.3s ease;
}

.form-control:focus {
    outline: none;
    border-color: #3182ce;
    box-shadow: 0 0 0 3px rgba(49, 130, 206, 0.1);
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}

/* Form Sections */
.form-section {
    background: #f8f9fa;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 20px;
}

.section-title {
    font-size: 1.1rem;
    font-weight: 600;
    color: #2c3e50;
    margin: 0 0 20px 0;
    padding-bottom: 10px;
    border-bottom: 2px solid #e0f2fe;
    display: flex;
    align-items: center;
    gap: 10px;
}

.section-title i {
    color: #3b82f6;
    font-size: 1rem;
}

.form-group {
    margin-bottom: 18px;
}

.form-group label {
    display: block;
    font-weight: 600;
    color: #495057;
    margin-bottom: 8px;
    font-size: 0.9rem;
}

.form-group input[type="text"],
.form-group input[type="date"],
.form-group input[type="number"],
.form-group select,
.form-group textarea {
    width: 100%;
    padding: 10px 12px;
    border: 2px solid #e0e0e0;
    border-radius: 6px;
    font-size: 0.9rem;
    transition: all 0.3s ease;
    font-family: inherit;
}

.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.form-group small {
    display: block;
    margin-top: 6px;
    color: #6c757d;
    font-size: 0.8rem;
    line-height: 1.4;
}

.form-group textarea {
    resize: vertical;
    min-height: 80px;
}

.modal-footer {
    padding: 20px 30px;
    border-top: 1px solid #eee;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}

.btn {
    padding: 12px 25px;
    border: none;
    border-radius: 10px;
    font-size: 1rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

/* Message Notification */
.notification {
    padding: 15px 20px;
    border-radius: 10px;
    margin-bottom: 20px;
    font-weight: 500;
    animation: slideInDown 0.5s ease-out;
}

.notification-success {
    background: #c6f6d5;
    color: #22543d;
    border: 2px solid #9ae6b4;
}

.notification-error {
    background: #fed7d7;
    color: #742a2a;
    border: 2px solid #fc8181;
}

@keyframes slideInDown {
    from {
        opacity: 0;
        transform: translateY(-20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Responsive Design */
@media (max-width: 1200px) {
    .management-grid.view-combined {
        grid-template-columns: 350px 1fr;
    }
}

@media (max-width: 968px) {
    .management-grid.view-combined {
        grid-template-columns: 1fr;
        grid-template-areas: 
            "categories"
            "courses";
    }
}

@media (max-width: 768px) {
    .sidebar-toggle {
        display: flex;
    }
    
    .admin-sidebar {
        left: -280px;
        transition: left 0.3s ease;
    }
    
    .admin-sidebar.sidebar-open {
        left: 0;
    }
    
    .admin-main-content {
        left: 0;
        width: 100vw;
    }
    
    .management-container {
        padding: 15px;
    }
    
    .management-header {
        padding: 15px;
    }
    
    .header-title h1 {
        font-size: 1.5rem;
    }
    
    .header-icon {
        width: 40px;
        height: 40px;
        font-size: 1.2rem;
    }
    
    .header-actions {
        flex-wrap: wrap;
    }
    
    .management-grid.view-combined,
    .management-grid.view-courses,
    .management-grid.view-categories {
        grid-template-columns: 1fr !important;
    }
    
    .management-grid.view-combined {
        grid-template-areas: 
            "categories"
            "courses" !important;
    }
    
    .management-grid.view-courses {
        grid-template-areas: "courses" !important;
    }
    
    .management-grid.view-categories {
        grid-template-areas: "categories" !important;
    }
    
    .panel {
        min-height: 400px;
    }
}

/* Scrollbar Styling */
.panel-content::-webkit-scrollbar,
.modal-content::-webkit-scrollbar {
    width: 8px;
}

.panel-content::-webkit-scrollbar-track,
.modal-content::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 10px;
}

.panel-content::-webkit-scrollbar-thumb,
.modal-content::-webkit-scrollbar-thumb {
    background: #c1c1c1;
    border-radius: 10px;
}

.panel-content::-webkit-scrollbar-thumb:hover,
.modal-content::-webkit-scrollbar-thumb:hover {
    background: #a8a8a8;
}
</style>

<!-- Sidebar Toggle Button for Mobile -->
<button class="sidebar-toggle" onclick="toggleSidebar()" aria-label="Toggle sidebar">
    <i class="fa fa-bars"></i>
</button>

<div class="management-container">
    <!-- Header Section -->
    <div class="management-header">
        <div class="header-top">
            <div class="header-title">
                <div class="header-icon">
                    <i class="fa fa-sitemap"></i>
                </div>
                <h1>Course Management</h1>
            </div>
        <div class="header-actions">
                <button class="header-btn" onclick="openModal('createCategoryModal')">
                    <i class="fa fa-folder-plus"></i>
                    New Category
                </button>
                <button class="header-btn" onclick="createNewCourse()">
                    <i class="fa fa-plus"></i>
                    New Course
                </button>
                <button class="header-btn" onclick="location.reload()">
                    <i class="fa fa-sync"></i>
                Refresh
            </button>
        </div>
        </div>
        

        <!-- Search Bar -->
        <form method="GET" action="" class="search-bar">
            <input type="hidden" name="view" value="<?php echo $viewmode; ?>">
            <?php if ($categoryid): ?>
            <input type="hidden" name="categoryid" value="<?php echo $categoryid; ?>">
            <?php endif; ?>
            <input type="text" name="search" class="search-input" 
                   placeholder="Search courses by name, shortname, or ID..." 
                   value="<?php echo htmlspecialchars($search); ?>">
            <button type="submit" class="search-btn">
                <i class="fa fa-search"></i>
                Search
            </button>
            <?php if ($search): ?>
            <a href="?view=<?php echo $viewmode; ?><?php echo $categoryid ? '&categoryid='.$categoryid : ''; ?>" 
               class="search-btn">
                <i class="fa fa-times"></i>
                Clear
            </a>
            <?php endif; ?>
        </form>
    </div>

    <?php if ($message): ?>
    <div class="notification notification-<?php echo $messagetype; ?>">
        <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <!-- Management Grid -->
    <div class="management-grid view-<?php echo $viewmode; ?>">
        
        <?php if ($viewmode === 'combined' || $viewmode === 'categories'): ?>
        <!-- Categories Panel -->
        <div class="panel categories-panel">
            <div class="panel-header">
                <div class="panel-header-top">
                <div>
                    <h3 class="panel-title">
                            <i class="fa fa-folder-tree"></i>
                            Categories
                    </h3>
                        <div class="panel-count">
                            <?php
                            $allcats = $DB->get_records('course_categories', ['visible' => 1]);
                            echo count($allcats) . ' total';
                            ?>
                </div>
            </div>
                    <div style="display: flex; gap: 8px;">
                        <button class="panel-action panel-action-sm" onclick="expandAllCategories()" title="Expand All">
                            <i class="fa fa-chevron-down"></i>
                                </button>
                        <button class="panel-action panel-action-sm" onclick="collapseAllCategories()" title="Collapse All">
                            <i class="fa fa-chevron-right"></i>
                                </button>
                        <button class="panel-action" onclick="openModal('createCategoryModal')">
                            <i class="fa fa-plus"></i>
                            Add
                                </button>
                            </div>
                        </div>
                </div>
            <div class="panel-content">
                <?php
                // Get category tree
                function render_category_tree($parentid, $level, $selectedid, $DB) {
                    $categories = $DB->get_records('course_categories', 
                        ['parent' => $parentid], 
                        'sortorder ASC');
                    
                    if (empty($categories)) {
                        return '';
                    }
                    
                    $html = '<ul class="category-tree' . ($level > 0 ? ' subcategory-list' : '') . '" ' . ($level > 0 ? 'style="display: block;"' : '') . '>';
                    foreach ($categories as $cat) {
                        $coursecount = $DB->count_records('course', ['category' => $cat->id, 'visible' => 1]);
                        $subcatcount = $DB->count_records('course_categories', ['parent' => $cat->id]);
                        $haschildren = $subcatcount > 0;
                        $isselected = ($cat->id == $selectedid);
                        $visibilityclass = $cat->visible ? '' : 'hidden';
                        
                        $html .= '<li class="category-tree-item level-' . $level . ($haschildren ? ' has-children' : '') . '" data-category-id="' . $cat->id . '">';
                        $html .= '<div class="category-item ' . ($isselected ? 'selected' : '') . ' ' . $visibilityclass . '" 
                                       data-category-id="' . $cat->id . '" 
                                       data-has-children="' . ($haschildren ? 'true' : 'false') . '"
                                       onclick="selectCategory(' . $cat->id . ')">';
                        
                        // Add expand/collapse toggle if has children
                        if ($haschildren) {
                            $html .= '<button class="category-toggle" onclick="event.stopPropagation(); toggleCategoryExpand(' . $cat->id . ')" title="Expand/Collapse">';
                            $html .= '<i class="fa fa-chevron-down"></i>';
                            $html .= '</button>';
                        } else {
                            $html .= '<span class="category-toggle-spacer"></span>';
                        }
                        
                        $html .= '<input type="checkbox" class="category-checkbox" onclick="event.stopPropagation()" 
                                        data-category-id="' . $cat->id . '">';
                        
                        // Different icons based on level
                        $iconClass = 'fa-folder';
                        if ($level == 0) {
                            $iconClass = $haschildren ? 'fa-folder-open' : 'fa-folder';
                        } elseif ($level == 1) {
                            $iconClass = 'fa-folder';
                        } else {
                            $iconClass = 'fa-folder-o';
                        }
                        
                        $html .= '<div class="category-icon"><i class="fa ' . $iconClass . '"></i></div>';
                        $html .= '<div class="category-info">';
                        $html .= '<h4 class="category-name">' . htmlspecialchars($cat->name) . '</h4>';
                        $html .= '<p class="category-meta">' . $coursecount . ' courses';
                        if ($haschildren) {
                            $html .= ', ' . $subcatcount . ' sub';
                        }
                        $html .= '</p>';
                        $html .= '</div>';
                        $html .= '<div class="category-actions">';
                        $html .= '<button class="category-action-btn btn-visibility" 
                                         onclick="event.stopPropagation(); toggleCategoryVisibility(' . $cat->id . ')" 
                                         title="Toggle Visibility">';
                        $html .= '<i class="fa fa-eye' . ($cat->visible ? '' : '-slash') . '"></i>';
                        $html .= '</button>';
                        $html .= '<button class="category-action-btn btn-delete" 
                                         onclick="event.stopPropagation(); deleteCategory(' . $cat->id . ')" 
                                         title="Delete">';
                        $html .= '<i class="fa fa-trash"></i>';
                        $html .= '</button>';
                        $html .= '</div>';
                        $html .= '</div>';
                        
                        // Render children
                        if ($haschildren) {
                            $html .= render_category_tree($cat->id, $level + 1, $selectedid, $DB);
                        }
                        
                        $html .= '</li>';
                    }
                    $html .= '</ul>';
                    
                    return $html;
                }
                
                $categoryTree = render_category_tree(0, 0, $categoryid, $DB);
                if ($categoryTree) {
                    echo $categoryTree;
                } else {
                    echo '<div class="empty-state">';
                    echo '<div class="empty-icon"><i class="fa fa-folder-open"></i></div>';
                    echo '<h3 class="empty-title">No Categories</h3>';
                    echo '<p class="empty-description">Create your first category to organize courses.</p>';
                    echo '</div>';
                }
                ?>
                
                <div class="bulk-actions-bar" id="categoryBulkActions">
                    <div class="bulk-selection-info">
                        <span id="categorySelectionCount">0</span> selected
            </div>
                    <select class="bulk-action-select" id="categoryBulkActionSelect">
                        <option value="">Choose action...</option>
                        <option value="move">Move to...</option>
                        <option value="delete">Delete</option>
                    </select>
                    <button class="bulk-action-btn" onclick="executeCategoryBulkAction()">
                        Apply
                                </button>
        </div>
                        </div>
                </div>
        <?php endif; ?>
        
        <?php if ($viewmode === 'combined' || $viewmode === 'courses'): ?>
        <!-- Courses Panel -->
        <div class="panel courses-panel">
            <div class="panel-header">
                <div class="panel-header-top">
                <div>
                        <h3 class="panel-title">
                            <i class="fa fa-book"></i>
                            Courses
                            <?php if ($category): ?>
                            in "<?php echo htmlspecialchars($category->name); ?>"
                            <?php endif; ?>
                    </h3>
                        <div class="panel-count">
                            <?php
                            if ($issearching) {
                                // Search courses
                                $searchlike = '%' . $DB->sql_like_escape($search) . '%';
                                $sql = "SELECT * FROM {course} 
                                        WHERE id > 1 AND visible = 1 
                                        AND (fullname LIKE ? OR shortname LIKE ? OR idnumber LIKE ?)
                                        ORDER BY fullname ASC";
                                $courses = $DB->get_records_sql($sql, [$searchlike, $searchlike, $searchlike]);
                                echo count($courses) . ' found';
                            } else if ($category) {
                                $coursecount = $DB->count_records('course', ['category' => $category->id, 'visible' => 1]);
                                echo $coursecount . ' courses';
                            } else {
                                echo 'Select a category';
                            }
                            ?>
                </div>
                    </div>
                    <?php if ($category): ?>
                    <button class="panel-action" onclick="openCourseModal(<?php echo $category->id; ?>)">
                    <i class="fa fa-plus"></i>
                        Add
                </button>
                    <?php endif; ?>
                </div>
            </div>
            <div class="panel-content">
                <?php
                if ($issearching) {
                    // Display search results
                    if (!empty($courses)) {
                        echo '<div class="course-list">';
                        foreach ($courses as $c) {
                            $cat = $DB->get_record('course_categories', ['id' => $c->category]);
                            $isselected = ($c->id == $courseid);
                            $visibilityclass = $c->visible ? '' : 'hidden';
                            
                            echo '<div class="course-item ' . ($isselected ? 'selected' : '') . ' ' . $visibilityclass . '" 
                                       data-course-id="' . $c->id . '" 
                                       onclick="selectCourse(' . $c->id . ')">';
                            echo '<input type="checkbox" class="course-checkbox" onclick="event.stopPropagation()" 
                                        data-course-id="' . $c->id . '">';
                            echo '<div class="course-icon"><i class="fa fa-book"></i></div>';
                            echo '<div class="course-info">';
                            echo '<h4 class="course-name">' . htmlspecialchars($c->fullname) . '</h4>';
                            echo '<p class="course-shortname">' . htmlspecialchars($c->shortname) . '</p>';
                            echo '<div class="course-meta">';
                            if ($cat) {
                                echo '<span><i class="fa fa-folder"></i> ' . htmlspecialchars($cat->name) . '</span>';
                            }
                            echo '<span><i class="fa fa-calendar"></i> ' . userdate($c->timecreated, '%d %B %Y') . '</span>';
                            echo '</div>';
                            echo '</div>';
                            echo '<div class="course-actions">';
                            echo '<button class="course-action-btn btn-edit" 
                                         onclick="event.stopPropagation(); editCourse(' . $c->id . ')" 
                                         title="Edit">';
                            echo '<i class="fa fa-edit"></i>';
                            echo '</button>';
                            echo '<button class="course-action-btn btn-visibility" 
                                         onclick="event.stopPropagation(); toggleCourseVisibility(' . $c->id . ')" 
                                         title="Toggle Visibility">';
                            echo '<i class="fa fa-eye' . ($c->visible ? '' : '-slash') . '"></i>';
                            echo '</button>';
                            echo '<button class="course-action-btn btn-delete" 
                                         onclick="event.stopPropagation(); deleteCourse(' . $c->id . ')" 
                                         title="Delete">';
                            echo '<i class="fa fa-trash"></i>';
                            echo '</button>';
                            echo '</div>';
                            echo '</div>';
                        }
                        echo '</div>';
                    } else {
                        echo '<div class="empty-state">';
                        echo '<div class="empty-icon"><i class="fa fa-search"></i></div>';
                        echo '<h3 class="empty-title">No Results</h3>';
                        echo '<p class="empty-description">No courses found matching "' . htmlspecialchars($search) . '"</p>';
                        echo '</div>';
                    }
                } else if ($category) {
                    // Display category courses with pagination
                    $offset = $page * $perpage;
                    $allcourses = $DB->get_records('course', 
                        ['category' => $category->id, 'visible' => 1], 
                        'sortorder ASC, fullname ASC');
                    $totalcourses = count($allcourses);
                    $courses = array_slice($allcourses, $offset, $perpage, true);
                    
                    if (!empty($courses)) {
                        echo '<div class="course-list">';
                        foreach ($courses as $c) {
                            $isselected = ($c->id == $courseid);
                            $visibilityclass = $c->visible ? '' : 'hidden';
                            
                            echo '<div class="course-item ' . ($isselected ? 'selected' : '') . ' ' . $visibilityclass . '" 
                                       data-course-id="' . $c->id . '" 
                                       onclick="selectCourse(' . $c->id . ')">';
                            echo '<input type="checkbox" class="course-checkbox" onclick="event.stopPropagation()" 
                                        data-course-id="' . $c->id . '">';
                            echo '<div class="course-icon"><i class="fa fa-book"></i></div>';
                            echo '<div class="course-info">';
                            echo '<h4 class="course-name">' . htmlspecialchars($c->fullname) . '</h4>';
                            echo '<p class="course-shortname">' . htmlspecialchars($c->shortname) . '</p>';
                            echo '<div class="course-meta">';
                            echo '<span><i class="fa fa-calendar"></i> ' . userdate($c->timecreated, '%d %B %Y') . '</span>';
                            $enrollcount = $DB->count_records('user_enrolments', ['enrolid' => $c->id]);
                            echo '<span><i class="fa fa-users"></i> ' . $enrollcount . ' enrolled</span>';
                            echo '</div>';
                            echo '</div>';
                            echo '<div class="course-actions">';
                            echo '<button class="course-action-btn btn-edit" 
                                         onclick="event.stopPropagation(); editCourse(' . $c->id . ')" 
                                         title="Edit">';
                            echo '<i class="fa fa-edit"></i>';
                            echo '</button>';
                            echo '<button class="course-action-btn btn-visibility" 
                                         onclick="event.stopPropagation(); toggleCourseVisibility(' . $c->id . ')" 
                                         title="Toggle Visibility">';
                            echo '<i class="fa fa-eye' . ($c->visible ? '' : '-slash') . '"></i>';
                            echo '</button>';
                            echo '<button class="course-action-btn btn-delete" 
                                         onclick="event.stopPropagation(); deleteCourse(' . $c->id . ')" 
                                         title="Delete">';
                            echo '<i class="fa fa-trash"></i>';
                            echo '</button>';
                            echo '</div>';
                            echo '</div>';
                        }
                        echo '</div>';
                        
                        // Pagination
                        if ($totalcourses > $perpage) {
                            $totalpages = ceil($totalcourses / $perpage);
                            echo '<div class="pagination">';
                            echo '<div class="pagination-info">';
                            echo 'Showing ' . ($offset + 1) . '-' . min($offset + $perpage, $totalcourses) . ' of ' . $totalcourses;
                            echo '</div>';
                            echo '<div class="pagination-controls">';
                            
                            $disabled = $page == 0 ? 'disabled' : '';
                            echo '<button class="page-btn" onclick="changePage(' . max(0, $page - 1) . ')" ' . $disabled . '>';
                            echo '<i class="fa fa-chevron-left"></i>';
                            echo '</button>';
                            
                            for ($i = 0; $i < $totalpages; $i++) {
                                if ($i < 3 || $i >= $totalpages - 3 || abs($i - $page) <= 1) {
                                    $active = $i == $page ? 'active' : '';
                                    echo '<button class="page-btn ' . $active . '" onclick="changePage(' . $i . ')">';
                                    echo ($i + 1);
                                    echo '</button>';
                                } else if ($i == 3 || $i == $totalpages - 4) {
                                    echo '<span class="page-ellipsis">...</span>';
                                }
                            }
                            
                            $disabled = $page >= $totalpages - 1 ? 'disabled' : '';
                            echo '<button class="page-btn" onclick="changePage(' . min($totalpages - 1, $page + 1) . ')" ' . $disabled . '>';
                            echo '<i class="fa fa-chevron-right"></i>';
                            echo '</button>';
                            
                            echo '</div>';
                            echo '</div>';
                        }
                    } else {
                        echo '<div class="empty-state">';
                        echo '<div class="empty-icon"><i class="fa fa-book"></i></div>';
                        echo '<h3 class="empty-title">No Courses</h3>';
                        echo '<p class="empty-description">This category doesn\'t have any courses yet.</p>';
                        echo '</div>';
                    }
                } else {
                    echo '<div class="empty-state">';
                    echo '<div class="empty-icon"><i class="fa fa-folder-open"></i></div>';
                    echo '<h3 class="empty-title">Select a Category</h3>';
                    echo '<p class="empty-description">Choose a category from the left to view its courses.</p>';
                    echo '</div>';
                }
                ?>
                
                <div class="bulk-actions-bar" id="courseBulkActions">
                    <div class="bulk-selection-info">
                        <span id="courseSelectionCount">0</span> selected
                        </div>
                    <select class="bulk-action-select" id="courseBulkActionSelect">
                        <option value="">Choose action...</option>
                        <option value="move">Move to category...</option>
                        <option value="visibility">Toggle visibility</option>
                        <option value="delete">Delete</option>
                    </select>
                    <button class="bulk-action-btn" onclick="executeCourseBulkAction()">
                        Apply
                    </button>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        
        
    </div>
</div>

<!-- Create Course Modal -->
<!-- Create Category Modal -->
<div id="createCategoryModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fa fa-folder-plus"></i> Create New Category</h3>
            <button class="modal-close" onclick="closeModal('createCategoryModal')">&times;</button>
                </div>
        <form method="POST" action="">
            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                <input type="hidden" name="action" value="create_category">
                
            <div class="modal-body">
                <div class="form-group">
                    <label for="category_name">Category Name *</label>
                    <input type="text" id="category_name" name="name" required>
                </div>
                
                <div class="form-group">
                    <label for="parent_category">Parent Category</label>
                    <select id="parent_category" name="parent_category">
                        <option value="0">Top Level Category</option>
                        <?php
                        $allcats = $DB->get_records('course_categories', ['visible' => 1], 'name ASC');
                        foreach ($allcats as $cat) {
                            $selected = ($cat->id == $categoryid) ? 'selected' : '';
                            echo '<option value="' . $cat->id . '" ' . $selected . '>' . 
                                 htmlspecialchars($cat->name) . '</option>';
                        }
                        ?>
                    </select>
            </div>
                
                <div class="form-group">
                    <label for="category_description">Description (Optional)</label>
                    <textarea id="category_description" name="description" rows="4"></textarea>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('createCategoryModal')">
                    Cancel
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="fa fa-folder-plus"></i>
                    Create Category
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Move Categories Modal -->
<div id="moveCategoriesModal" class="modal">
    <div class="modal-content" style="max-width: 600px;">
        <div class="modal-header">
            <h3><i class="fa fa-arrows-alt"></i> Move Categories</h3>
            <button class="modal-close" onclick="closeModal('moveCategoriesModal')">&times;</button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
            <input type="hidden" name="action" value="bulk_move_categories">
            <input type="hidden" id="selected_categories_input" name="selected_categories_input" value="">
            
            <div class="modal-body">
                <div class="form-group">
                    <label for="move_categories_to">Move <span id="selectedCategoriesCount">0</span> categor<span id="categoriesPlural">ies</span> to:</label>
                    <select id="move_categories_to" name="move_to_category" class="form-control" required>
                        <option value="0">Top Level (No Parent)</option>
                        <?php
                        $allcats = $DB->get_records('course_categories', ['visible' => 1], 'name ASC');
                        foreach ($allcats as $cat) {
                            echo '<option value="' . $cat->id . '">' . htmlspecialchars($cat->name) . '</option>';
                        }
                        ?>
                    </select>
                </div>
                <div id="moveCategoriesSelectedList"></div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('moveCategoriesModal')">
                    Cancel
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="fa fa-arrows-alt"></i>
                    Move Categories
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Move Courses Modal -->
<div id="moveCoursesModal" class="modal">
    <div class="modal-content" style="max-width: 600px;">
        <div class="modal-header">
            <h3><i class="fa fa-arrows-alt"></i> Move Courses</h3>
            <button class="modal-close" onclick="closeModal('moveCoursesModal')">&times;</button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
            <input type="hidden" name="action" value="bulk_move_courses">
            <input type="hidden" id="selected_courses_input" name="selected_courses_input" value="">
            
            <div class="modal-body">
                    <div class="form-group">
                    <label for="move_courses_to">Move <span id="selectedCoursesCount">0</span> course<span id="coursesPlural">s</span> to category:</label>
                    <select id="move_courses_to" name="move_to_category" class="form-control" required>
                        <?php
                        $allcats = $DB->get_records('course_categories', ['visible' => 1], 'name ASC');
                        foreach ($allcats as $cat) {
                            echo '<option value="' . $cat->id . '"';
                            if ($cat->id == $categoryid) {
                                echo ' selected';
                            }
                            echo '>' . htmlspecialchars($cat->name) . '</option>';
                        }
                        ?>
                    </select>
                </div>
                <div id="moveCoursesSelectedList"></div>
                    </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('moveCoursesModal')">
                    Cancel
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="fa fa-arrows-alt"></i>
                    Move Courses
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Create/Edit Course Modal -->
<div id="createCourseModal" class="modal">
    <div class="modal-content" style="max-width: 900px; max-height: 90vh; overflow-y: auto;">
        <div class="modal-header">
            <h3><i class="fa fa-plus-circle"></i> <span id="courseModalTitle">Create New Course</span></h3>
            <button class="modal-close" onclick="closeModal('createCourseModal')">&times;</button>
        </div>
        <form method="POST" action="" id="courseForm">
            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
            <input type="hidden" name="action" value="create_course" id="course_action">
            <input type="hidden" name="courseid" value="" id="course_id">
            
            <div class="modal-body">
                <!-- General Section -->
                <div class="form-section">
                    <h4 class="section-title"><i class="fa fa-info-circle"></i> General</h4>
                    
                    <div class="form-group">
                        <label for="course_fullname">Course Full Name *</label>
                        <input type="text" id="course_fullname" name="fullname" required 
                               placeholder="Introduction to Computer Science" maxlength="254">
                        <small>The full name of the course will be shown at the top of the screen and on the course list.</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="course_shortname">Course Short Name *</label>
                        <input type="text" id="course_shortname" name="shortname" required 
                               placeholder="CS101" maxlength="100">
                        <small>The course short name is used in the navigation and email subjects.</small>
                </div>
                
                <div class="form-group">
                        <label for="course_category">Course Category *</label>
                        <select id="course_category" name="category" required>
                            <?php
                            $allcats = $DB->get_records('course_categories', ['visible' => 1], 'name ASC');
                            foreach ($allcats as $cat) {
                                $selected = ($cat->id == $categoryid) ? 'selected' : '';
                                echo '<option value="' . $cat->id . '" ' . $selected . '>' . 
                                     htmlspecialchars($cat->name) . '</option>';
                            }
                            ?>
                        </select>
                        <small>Select the category for this course.</small>
                </div>
                
                <div class="form-group">
                        <label for="course_visible">Course Visibility *</label>
                        <select id="course_visible" name="visible">
                            <option value="1" selected>Show</option>
                            <option value="0">Hide</option>
                    </select>
                        <small>This determines whether the course appears in the course list.</small>
                </div>
                
                    <div class="form-group">
                        <label for="course_startdate">Start Date</label>
                        <input type="date" id="course_startdate" name="startdate" 
                               value="<?php echo date('Y-m-d', strtotime('+1 day')); ?>">
                        <small>The course start date.</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="course_enddate">End Date (Optional)</label>
                        <input type="date" id="course_enddate" name="enddate">
                        <small>The course end date (optional).</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="course_idnumber">Course ID Number</label>
                        <input type="text" id="course_idnumber" name="idnumber" maxlength="100"
                               placeholder="CS101-2024">
                        <small>The ID number is used for matching this course against external systems.</small>
                    </div>
                </div>
                
                <!-- Description Section -->
                <div class="form-section">
                    <h4 class="section-title"><i class="fa fa-align-left"></i> Description</h4>
                
                <div class="form-group">
                        <label for="course_summary">Course Summary</label>
                        <textarea id="course_summary" name="summary" rows="6" 
                                  placeholder="Enter course description..."></textarea>
                        <small>The course summary is displayed in the course listing.</small>
                </div>
            </div>
                
                <!-- Course Format Section -->
                <div class="form-section">
                    <h4 class="section-title"><i class="fa fa-list"></i> Course Format</h4>
                    
                    <div class="form-group">
                        <label for="course_format">Format *</label>
                        <select id="course_format" name="format">
                            <option value="topics" selected>Topics format</option>
                            <option value="weeks">Weekly format</option>
                            <option value="social">Social format</option>
                            <option value="singleactivity">Single activity format</option>
                        </select>
                        <small>The course format determines the layout of the course page.</small>
            </div>
                    
                    <div class="form-group">
                        <label for="course_numsections">Number of Sections</label>
                        <input type="number" id="course_numsections" name="numsections" value="10" min="0" max="52">
                        <small>Number of sections/weeks in the course.</small>
    </div>
</div>

                <!-- Appearance Section -->
                <div class="form-section">
                    <h4 class="section-title"><i class="fa fa-paint-brush"></i> Appearance</h4>
                    
                    <div class="form-group">
                        <label for="course_lang">Force Language</label>
                        <select id="course_lang" name="lang">
                            <option value="">No forcing</option>
                            <option value="en">English</option>
                            <!-- Add more languages as needed -->
                        </select>
                        <small>Force a language for this course (optional).</small>
                </div>
                    
                    <div class="form-group">
                        <label for="course_newsitems">Announcements</label>
                        <select id="course_newsitems" name="newsitems">
                            <?php for ($i = 0; $i <= 10; $i++): ?>
                            <option value="<?php echo $i; ?>" <?php echo ($i == 5) ? 'selected' : ''; ?>><?php echo $i; ?></option>
                            <?php endfor; ?>
                        </select>
                        <small>Number of recent announcements to show.</small>
                </div>
                    
                    <div class="form-group">
                        <label for="course_showgrades">Show Gradebook to Students</label>
                        <select id="course_showgrades" name="showgrades">
                            <option value="1" selected>Yes</option>
                            <option value="0">No</option>
                        </select>
                        <small>Display or hide the gradebook to students.</small>
            </div>
                    
                    <div class="form-group">
                        <label for="course_showreports">Show Activity Reports</label>
                        <select id="course_showreports" name="showreports">
                            <option value="1" selected>Yes</option>
                            <option value="0">No</option>
                        </select>
                        <small>Show activity reports to students.</small>
        </div>
                </div>
                
                <!-- Files and Uploads Section -->
                <div class="form-section">
                    <h4 class="section-title"><i class="fa fa-file-upload"></i> Files and Uploads</h4>
                
                <div class="form-group">
                        <label for="course_maxbytes">Maximum Upload Size</label>
                        <select id="course_maxbytes" name="maxbytes">
                            <option value="0">Site maximum</option>
                            <option value="1048576">1MB</option>
                            <option value="2097152">2MB</option>
                            <option value="5242880">5MB</option>
                            <option value="10485760" selected>10MB</option>
                            <option value="20971520">20MB</option>
                            <option value="52428800">50MB</option>
                            <option value="104857600">100MB</option>
                        </select>
                        <small>Maximum file upload size for this course.</small>
                    </div>
                </div>
                
                <!-- Completion Tracking Section -->
                <div class="form-section">
                    <h4 class="section-title"><i class="fa fa-check-circle"></i> Completion Tracking</h4>
                
                <div class="form-group">
                        <label for="course_enablecompletion">Enable Completion Tracking</label>
                        <select id="course_enablecompletion" name="enablecompletion">
                            <option value="0">No</option>
                            <option value="1" selected>Yes</option>
                    </select>
                        <small>When enabled, activity and course completion conditions can be set.</small>
                    </div>
                </div>
                
                <!-- Groups Section -->
                <div class="form-section">
                    <h4 class="section-title"><i class="fa fa-users"></i> Groups</h4>
                
                <div class="form-group">
                        <label for="course_groupmode">Group Mode</label>
                        <select id="course_groupmode" name="groupmode">
                            <option value="0" selected>No groups</option>
                            <option value="1">Separate groups</option>
                            <option value="2">Visible groups</option>
                        </select>
                        <small>No groups - all students are part of one community.<br>
                        Separate groups - students can only see their own group.<br>
                        Visible groups - students work in their own group, but can see other groups.</small>
                </div>
                    
                    <div class="form-group">
                        <label for="course_groupmodeforce">Force Group Mode</label>
                        <select id="course_groupmodeforce" name="groupmodeforce">
                            <option value="0" selected>No</option>
                            <option value="1">Yes</option>
                        </select>
                        <small>If forced, the group mode cannot be changed at activity level.</small>
            </div>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('createCourseModal')">
                    Cancel
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="fa fa-save"></i>
                    <span id="courseSaveBtn">Create Course</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Course Details Modal -->
<div id="courseDetailsModal" class="modal">
    <div class="modal-content" style="max-width: 800px; padding-top: 20px;">
        <div class="modal-header">
            <h3><i class="fa fa-info-circle"></i> <span id="courseDetailsTitle">Course Details</span></h3>
            <button class="modal-close" onclick="closeModal('courseDetailsModal')">&times;</button>
        </div>
        <div class="modal-body" id="courseDetailsBody">
            <div class="course-detail">
                <div class="detail-section">
                    <h3>Information</h3>
                    <div class="detail-item">
                        <div class="detail-label">Full Name</div>
                        <div class="detail-value" id="detail_fullname">-</div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Short Name</div>
                        <div class="detail-value" id="detail_shortname">-</div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Category</div>
                        <div class="detail-value" id="detail_category">-</div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Visibility</div>
                        <div class="detail-value" id="detail_visibility">-</div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Created</div>
                        <div class="detail-value" id="detail_created">-</div>
                    </div>
                </div>
                
                <div class="detail-section">
                    <h3>Actions</h3>
                    <div class="detail-actions">
                        <a href="#" id="detail_view_btn" class="detail-action-btn btn-primary">
                            <i class="fa fa-external-link-alt"></i>
                            View
                        </a>
                        <button id="detail_edit_btn" class="detail-action-btn btn-secondary">
                            <i class="fa fa-edit"></i>
                            Edit
                        </button>
                        <button class="detail-action-btn btn-secondary" id="detail_visibility_btn" onclick="">
                            <i class="fa fa-eye" id="detail_visibility_icon"></i>
                            <span id="detail_visibility_text">Hide</span>
                        </button>
                        <button class="detail-action-btn btn-danger" id="detail_delete_btn" onclick="">
                            <i class="fa fa-trash"></i>
                            Delete
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Global state
let selectedCategoryId = <?php echo $categoryid ? $categoryid : 'null'; ?>;
let selectedCourseId = <?php echo $courseid ? $courseid : 'null'; ?>;
let currentCourseData = null;

// View mode change
function changeViewMode(mode) {
    const url = new URL(window.location);
    url.searchParams.set('view', mode);
    if (selectedCategoryId) {
        url.searchParams.set('categoryid', selectedCategoryId);
    }
    window.location.href = url.toString();
}

// Category selection
function selectCategory(categoryId) {
    const url = new URL(window.location);
    url.searchParams.set('categoryid', categoryId);
    url.searchParams.delete('courseid');
    url.searchParams.delete('page');
    window.location.href = url.toString();
}

// Course selection - Open modal instead
function selectCourse(courseId) {
    selectedCourseId = courseId;
    
    // Highlight selected course
    document.querySelectorAll('.course-item').forEach(item => {
        item.classList.remove('selected');
    });
    const selectedItem = document.querySelector(`.course-item[data-course-id="${courseId}"]`);
    if (selectedItem) {
        selectedItem.classList.add('selected');
    }
    
    // Fetch course details via AJAX
    fetch('?ajax=1&action=get_course_details&courseid=' + courseId)
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                showCourseDetails(data.course);
            } else {
                alert('Error loading course details: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred while loading course details');
        });
}

// Show course details in modal
function showCourseDetails(course) {
    currentCourseData = course;
    
    // Update modal title
    document.getElementById('courseDetailsTitle').textContent = course.fullname;
    
    // Update information fields
    document.getElementById('detail_fullname').textContent = course.fullname;
    document.getElementById('detail_shortname').textContent = course.shortname;
    document.getElementById('detail_category').textContent = course.category_name || 'Unknown';
    document.getElementById('detail_visibility').textContent = course.visible == 1 ? 'Visible' : 'Hidden';
    document.getElementById('detail_created').textContent = course.created_date || '-';
    
    // Update action buttons
    document.getElementById('detail_view_btn').href = '<?php echo $CFG->wwwroot; ?>/course/view.php?id=' + course.id;
    document.getElementById('detail_edit_btn').onclick = function() {
        closeModal('courseDetailsModal');
        editCourse(course.id);
    };
    
    // Update visibility button
    const visibilityIcon = document.getElementById('detail_visibility_icon');
    const visibilityText = document.getElementById('detail_visibility_text');
    if (course.visible == 1) {
        visibilityIcon.className = 'fa fa-eye-slash';
        visibilityText.textContent = 'Hide';
    } else {
        visibilityIcon.className = 'fa fa-eye';
        visibilityText.textContent = 'Show';
    }
    document.getElementById('detail_visibility_btn').onclick = function() {
        toggleCourseVisibility(course.id);
        closeModal('courseDetailsModal');
    };
    
    document.getElementById('detail_delete_btn').onclick = function() {
        deleteCourse(course.id);
        closeModal('courseDetailsModal');
    };
    
    // Open modal
    openModal('courseDetailsModal');
}

// Page navigation
function changePage(pageNum) {
    const url = new URL(window.location);
    url.searchParams.set('page', pageNum);
    window.location.href = url.toString();
}

// Modal functions
function openModal(modalId) {
    document.getElementById(modalId).classList.add('active');
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('active');
}

function openCourseModal(categoryId) {
    // Set the selected category
    selectedCategoryId = categoryId;
    // Open the create course modal
    createNewCourse();
}

// Create new course
function createNewCourse() {
    // Reset form
    document.getElementById('courseForm').reset();
    document.getElementById('courseModalTitle').textContent = 'Create New Course';
    document.getElementById('courseSaveBtn').textContent = 'Create Course';
    document.getElementById('course_action').value = 'create_course';
    document.getElementById('course_id').value = '';
    
    // Set category if one is selected
    if (selectedCategoryId) {
        document.getElementById('course_category').value = selectedCategoryId;
    }
    
    // Open modal
    openModal('createCourseModal');
}

function editCourse(courseId) {
    // This would be called to edit an existing course
    // You can implement this to load course data and populate the form
    fetch('?ajax=1&action=get_course_details&courseid=' + courseId)
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                const course = data.course;
                document.getElementById('courseModalTitle').textContent = 'Edit Course';
                document.getElementById('courseSaveBtn').textContent = 'Update Course';
                document.getElementById('course_action').value = 'update_course';
                document.getElementById('course_id').value = course.id;
                document.getElementById('course_fullname').value = course.fullname;
                document.getElementById('course_shortname').value = course.shortname;
                document.getElementById('course_category').value = course.category;
                document.getElementById('course_visible').value = course.visible;
                document.getElementById('course_idnumber').value = course.idnumber || '';
                document.getElementById('course_summary').value = course.summary || '';
                document.getElementById('course_format').value = course.format || 'topics';
                
                openModal('createCourseModal');
            }
        });
}

// Close modal on outside click
window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.classList.remove('active');
    }
}

// Toggle course visibility
function toggleCourseVisibility(courseId) {
    if (!confirm('Toggle course visibility?')) return;
    
    fetch('?ajax=1&action=toggle_course_visibility&courseid=' + courseId)
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                location.reload();
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred');
        });
}

// Toggle category visibility
function toggleCategoryVisibility(categoryId) {
    if (!confirm('Toggle category visibility?')) return;
    
    fetch('?ajax=1&action=toggle_category_visibility&categoryid=' + categoryId)
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                location.reload();
    } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred');
        });
}

// Delete course
function deleteCourse(courseId) {
    if (!confirm('Are you sure you want to delete this course? This action will hide the course.')) return;
    
    fetch('?ajax=1&action=delete_course&courseid=' + courseId)
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                location.reload();
                } else {
                alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
            alert('An error occurred');
        });
}

// Delete category
function deleteCategory(categoryId) {
    if (!confirm('Are you sure you want to delete this category? All courses in this category will need to be moved or the category must be empty.')) return;
    
    fetch('?ajax=1&action=delete_category&categoryid=' + categoryId)
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                location.reload();
                } else {
                alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
            alert('An error occurred while deleting the category');
        });
}

// Checkbox selection tracking
document.addEventListener('DOMContentLoaded', function() {
    // Course checkboxes
    const courseCheckboxes = document.querySelectorAll('.course-checkbox');
    const courseBulkBar = document.getElementById('courseBulkActions');
    const courseCountSpan = document.getElementById('courseSelectionCount');
    
    courseCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            const selectedCount = document.querySelectorAll('.course-checkbox:checked').length;
            courseCountSpan.textContent = selectedCount;
            if (selectedCount > 0) {
                courseBulkBar.classList.add('active');
            } else {
                courseBulkBar.classList.remove('active');
            }
        });
    });
    
    // Category checkboxes
    const categoryCheckboxes = document.querySelectorAll('.category-checkbox');
    const categoryBulkBar = document.getElementById('categoryBulkActions');
    const categoryCountSpan = document.getElementById('categorySelectionCount');
    
    categoryCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            const selectedCount = document.querySelectorAll('.category-checkbox:checked').length;
            categoryCountSpan.textContent = selectedCount;
            if (selectedCount > 0) {
                categoryBulkBar.classList.add('active');
            } else {
                categoryBulkBar.classList.remove('active');
            }
        });
    });
    
    // Auto-open course details if courseid is in URL
    if (selectedCourseId) {
        selectCourse(selectedCourseId);
    }
});

// Execute bulk actions
function executeCourseBulkAction() {
    const action = document.getElementById('courseBulkActionSelect').value;
    const selected = Array.from(document.querySelectorAll('.course-checkbox:checked'))
        .map(cb => cb.getAttribute('data-course-id'));
    
    if (!action || selected.length === 0) {
        alert('Please select courses and an action');
        return;
    }
    
    if (action === 'move') {
        // Open move courses modal
        document.getElementById('selectedCoursesCount').textContent = selected.length;
        document.getElementById('coursesPlural').textContent = selected.length == 1 ? '' : 's';
        
        // Store selected course IDs in hidden input
        const form = document.querySelector('#moveCoursesModal form');
        // Remove existing hidden inputs
        form.querySelectorAll('input[name="selected_courses[]"]').forEach(input => input.remove());
        // Add new hidden inputs
        selected.forEach(id => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'selected_courses[]';
            input.value = id;
            form.appendChild(input);
        });
        
        // Show list of selected courses
        const listContainer = document.getElementById('moveCoursesSelectedList');
        listContainer.innerHTML = '<div style="margin-top: 15px; padding: 12px; background: #f7fafc; border-radius: 8px; max-height: 200px; overflow-y: auto;"><strong>Selected courses:</strong><ul style="margin: 10px 0 0 20px; padding: 0;">';
        selected.forEach(id => {
            const courseItem = document.querySelector(`.course-item[data-course-id="${id}"]`);
            const courseName = courseItem ? courseItem.querySelector('.course-name').textContent : 'Course #' + id;
            listContainer.innerHTML += '<li style="margin: 5px 0;">' + courseName + '</li>';
        });
        listContainer.innerHTML += '</ul></div>';
        
        openModal('moveCoursesModal');
    } else if (action === 'visibility') {
        alert('Bulk visibility toggle not yet implemented');
    } else if (action === 'delete') {
        if (!confirm('Are you sure you want to delete ' + selected.length + ' course' + (selected.length == 1 ? '' : 's') + '? This will hide the courses.')) {
            return;
        }
        
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
            <input type="hidden" name="action" value="bulk_delete_courses">
            ${selected.map(id => `<input type="hidden" name="selected_courses[]" value="${id}">`).join('')}
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

function executeCategoryBulkAction() {
    const action = document.getElementById('categoryBulkActionSelect').value;
    const selected = Array.from(document.querySelectorAll('.category-checkbox:checked'))
        .map(cb => cb.getAttribute('data-category-id'));
    
    if (!action || selected.length === 0) {
        alert('Please select categories and an action');
        return;
    }
    
    if (action === 'move') {
        // Open move categories modal
        document.getElementById('selectedCategoriesCount').textContent = selected.length;
        document.getElementById('categoriesPlural').textContent = selected.length == 1 ? 'y' : 'ies';
        
        // Store selected category IDs in hidden input
        const form = document.querySelector('#moveCategoriesModal form');
        // Remove existing hidden inputs
        form.querySelectorAll('input[name="selected_categories[]"]').forEach(input => input.remove());
        // Add new hidden inputs
        selected.forEach(id => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'selected_categories[]';
            input.value = id;
            form.appendChild(input);
        });
        
        // Show list of selected categories
        const listContainer = document.getElementById('moveCategoriesSelectedList');
        listContainer.innerHTML = '<div style="margin-top: 15px; padding: 12px; background: #f7fafc; border-radius: 8px; max-height: 200px; overflow-y: auto;"><strong>Selected categories:</strong><ul style="margin: 10px 0 0 20px; padding: 0;">';
        selected.forEach(id => {
            const categoryItem = document.querySelector(`.category-item[data-category-id="${id}"]`);
            const categoryName = categoryItem ? categoryItem.querySelector('.category-name').textContent : 'Category #' + id;
            listContainer.innerHTML += '<li style="margin: 5px 0;">' + categoryName + '</li>';
        });
        listContainer.innerHTML += '</ul></div>';
        
        openModal('moveCategoriesModal');
    } else if (action === 'delete') {
        if (!confirm('Are you sure you want to delete ' + selected.length + ' categor' + (selected.length == 1 ? 'y' : 'ies') + '? Only empty categories will be deleted.')) {
            return;
        }
        
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
            <input type="hidden" name="action" value="bulk_delete_categories">
            ${selected.map(id => `<input type="hidden" name="selected_categories[]" value="${id}">`).join('')}
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// Toggle category expand/collapse
function toggleCategoryExpand(categoryId) {
    const categoryTreeItem = document.querySelector(`.category-tree-item[data-category-id="${categoryId}"]`);
    if (categoryTreeItem) {
        categoryTreeItem.classList.toggle('collapsed');
    }
}

// Expand all categories
function expandAllCategories() {
    const allItems = document.querySelectorAll('.category-tree-item.has-children');
    allItems.forEach(item => {
        item.classList.remove('collapsed');
    });
}

// Collapse all categories
function collapseAllCategories() {
    const allItems = document.querySelectorAll('.category-tree-item.has-children');
    allItems.forEach(item => {
        item.classList.add('collapsed');
    });
}

// Sidebar toggle for mobile
function toggleSidebar() {
    document.querySelector('.admin-sidebar').classList.toggle('sidebar-open');
}
</script>

<?php
echo "</div>"; // Close admin-main-content
echo $OUTPUT->footer();
?>
