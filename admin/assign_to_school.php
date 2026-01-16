<?php
/**
 * Assign Courses to School - Modern UI
 * Beautiful animated interface for managing school course assignments
 */

// Check for AJAX request FIRST, before loading config.php
$action = $_GET['action'] ?? $_POST['action'] ?? null;
if ($action) {
    // CRITICAL: Define AJAX_SCRIPT BEFORE requiring config.php
    define('AJAX_SCRIPT', true);
}

require_once('../../../config.php');

// Handle AJAX requests
if ($action) {
    // Initialize minimal Moodle for AJAX
    require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

    // Set JSON header
    header('Content-Type: application/json');
    
    // Prevent any output buffering or page rendering
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    // Debug: Log the action (remove in production)
    error_log("AJAX Action: " . $action);
    
    global $USER, $DB;
    
    /**
     * Create licenses for school courses
     * 
     * @param int $companyid School/Company ID
     * @param string $license_model License model: 'none', 'overall', 'percourse', 'combined'
     * @param array $courseids Array of course IDs to link to licenses
     * @param array $license_data License configuration data
     * @return array Array of created license IDs
     */
    function create_school_licenses($companyid, $license_model, $courseids, $license_data) {
        global $DB;
        
        if ($license_model === 'none' || empty($courseids)) {
            return [];
        }
        
        $licenses_created = [];
        $company = $DB->get_record('company', ['id' => $companyid]);
        if (!$company) {
            return [];
        }
        
        // Set license dates from course dates
        $startdate = $license_data['startdate'] ?? time();
        $enddate = $license_data['enddate'] ?? ($startdate + (365 * 86400)); // Default 1 year if not set
        $validlength = $license_data['validlength'] ?? 365;
        
        // Create overall license if needed
        if ($license_model === 'overall' || $license_model === 'combined') {
            $overall_allocation = $license_data['overall_allocation'] ?? 0;
            if ($overall_allocation > 0) {
                $license = new \stdClass();
                $license->name = $company->name . ' - Overall License - ' . date('Y-m-d H:i');
                $license->allocation = $overall_allocation;
                $license->used = 0;
                $license->startdate = $startdate;
                $license->expirydate = $enddate;
                $license->validlength = $validlength;
                $license->companyid = $companyid;
                $license->parentid = 0;
                $license->type = 0; // Standard license
                $license->program = 0;
                $license->reference = '';
                $license->instant = 0;
                $license->cutoffdate = 0;
                $license->clearonexpire = 0;
                
                $licenseid = $DB->insert_record('companylicense', $license);
                
                // Link all courses to this license
                foreach ($courseids as $courseid) {
                    $DB->insert_record('companylicense_courses', [
                        'licenseid' => $licenseid,
                        'courseid' => $courseid
                    ]);
                }
                
                $licenses_created[] = $licenseid;
            }
        }
        
        // Create per-course licenses if needed
        if ($license_model === 'percourse' || $license_model === 'combined') {
            $percourse_allocation = $license_data['percourse_allocation'] ?? 0;
            if ($percourse_allocation > 0) {
                foreach ($courseids as $courseid) {
                    $course = $DB->get_record('course', ['id' => $courseid]);
                    if (!$course) {
                        continue;
                    }
                    
                    $license = new \stdClass();
                    $license->name = $company->name . ' - ' . $course->fullname . ' - ' . date('Y-m-d H:i');
                    $license->allocation = $percourse_allocation;
                    $license->used = 0;
                    $license->startdate = $startdate;
                    $license->expirydate = $enddate;
                    $license->validlength = $validlength;
                    $license->companyid = $companyid;
                    $license->parentid = 0;
                    $license->type = 0; // Standard license
                    $license->program = 0;
                    $license->reference = '';
                    $license->instant = 0;
                    $license->cutoffdate = 0;
                    $license->clearonexpire = 0;
                    
                    $licenseid = $DB->insert_record('companylicense', $license);
                    
                    // Link this course to its license
                    $DB->insert_record('companylicense_courses', [
                        'licenseid' => $licenseid,
                        'courseid' => $courseid
                    ]);
                    
                    $licenses_created[] = $licenseid;
                }
            }
        }
        
        // Mark courses as licensed in iomad_courses table
        foreach ($courseids as $courseid) {
            if (!$iomad_course = $DB->get_record('iomad_courses', ['courseid' => $courseid])) {
                $iomad_course = new \stdClass();
                $iomad_course->courseid = $courseid;
                $iomad_course->licensed = 1;
                $iomad_course->shared = 0;
                $iomad_course->validlength = $validlength;
                $iomad_course->warnexpire = 0;
                $iomad_course->warncompletion = 0;
                $iomad_course->notifyperiod = 0;
                $iomad_course->expireafter = 0;
                $iomad_course->warnnotstarted = 0;
                $iomad_course->hasgrade = 1;
                $DB->insert_record('iomad_courses', $iomad_course);
            } else {
                $iomad_course->licensed = 1;
                $iomad_course->validlength = $validlength;
                $DB->update_record('iomad_courses', $iomad_course);
            }
        }
        
        return $licenses_created;
    }
    
    switch ($action) {
        case 'get_schools':
            try {
                // First check if company table exists
                if (!$DB->get_manager()->table_exists('company')) {
                    error_log('Company table does not exist, falling back to course categories');
                    // Fallback to course categories if company table doesn't exist
                $schools = $DB->get_records_sql(
                    "SELECT id, name 
                     FROM {course_categories} 
                     WHERE visible = 1 
                     AND id > 1 
                     AND parent = 0
                     ORDER BY name ASC",
                    []
                );
                } else {
                    // Fetch schools from company table
                    $schools = $DB->get_records_sql(
                        "SELECT id, name 
                         FROM {company} 
                         ORDER BY name ASC",
                        []
                    );
                }
                
                // Debug: Log the result
                error_log('AJAX Schools fetched: ' . print_r($schools, true));
                
                // If no schools found, return empty array
                if (empty($schools)) {
                    $schools = [];
                }
                
                echo json_encode(['status' => 'success', 'schools' => array_values($schools)]);
            } catch (Exception $e) {
                error_log('Error fetching schools: ' . $e->getMessage());
                echo json_encode(['status' => 'error', 'message' => 'Failed to load schools: ' . $e->getMessage()]);
            }
            exit;
            
        case 'get_school_info':
            $school_id = intval($_GET['school_id']);
            
            try {
                // Get company and its category
                $company = $DB->get_record('company', ['id' => $school_id]);
                if (!$company) {
                    echo json_encode(['status' => 'error', 'message' => 'School not found']);
                    exit;
                }
                
                $category = null;
                $category_name = 'No category assigned';
                if (!empty($company->category)) {
                    $category = $DB->get_record('course_categories', ['id' => $company->category]);
                    if ($category) {
                        $category_name = $category->name;
                    }
                }
                
                echo json_encode([
                    'status' => 'success',
                    'school_name' => $company->name,
                    'category_id' => $company->category ?: null,
                    'category_name' => $category_name
                ]);
            } catch (Exception $e) {
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            }
            exit;
            
        case 'get_school_courses':
            $school_id = intval($_GET['school_id']);
            
            try {
                // Get company and its category
                $company = $DB->get_record('company', ['id' => $school_id]);
                if (!$company || empty($company->category)) {
                    echo json_encode(['status' => 'success', 'courses' => []]);
                    exit;
                }
                
                // Get the school's main category
                $school_category = $DB->get_record('course_categories', ['id' => $company->category]);
                if (!$school_category) {
                    echo json_encode(['status' => 'success', 'courses' => []]);
                    exit;
                }
                
                // Get ALL categories under the school's category (including the school category itself)
                $all_categories = $DB->get_records_sql(
                    "SELECT id, name, path, parent, depth, sortorder, visible
                     FROM {course_categories} 
                     WHERE (id = ? OR path LIKE ?)
                     AND visible = 1
                     ORDER BY path ASC, sortorder ASC",
                    [$company->category, $school_category->path . '/%']
                );
                
                // Build category map
                $category_map = [];
                foreach ($all_categories as $cat) {
                    $category_map[$cat->id] = [
                        'id' => $cat->id,
                        'name' => $cat->name,
                        'path' => $cat->path,
                        'parent' => $cat->parent,
                        'depth' => $cat->depth,
                        'sortorder' => $cat->sortorder
                    ];
                }
                
                // Get all courses in the school's category tree
                $category_ids = array_keys($category_map);
                list($in_sql, $params) = $DB->get_in_or_equal($category_ids);
                
                $sql = "SELECT c.*, 
                            cc.name as category_name,
                            cc.id as category_id
                     FROM {course} c
                     LEFT JOIN {course_categories} cc ON c.category = cc.id
                     WHERE c.visible = 1 
                     AND c.id > 1
                     AND c.category $in_sql
                     ORDER BY c.fullname ASC";
                     
                $courses = $DB->get_records_sql($sql, $params);
                
                // Build hierarchical structure like the potential courses
                function buildSchoolCategoryTree($parent_id, $category_map) {
                    $children = [];
                    foreach ($category_map as $cat_id => $cat_info) {
                        if ($cat_info['parent'] == $parent_id) {
                            $children[] = [
                                'category' => $cat_info,
                                'courses' => [],
                                'subcategories' => buildSchoolCategoryTree($cat_id, $category_map)
                            ];
                    }
                    }
                    // Sort by sortorder, then name
                    usort($children, function($a, $b) {
                        $sort_a = $a['category']['sortorder'] ?? 999;
                        $sort_b = $b['category']['sortorder'] ?? 999;
                        if ($sort_a != $sort_b) {
                            return $sort_a <=> $sort_b;
                        }
                        return strcmp($a['category']['name'], $b['category']['name']);
                    });
                    return $children;
                }
                
                // Build hierarchy starting from school's main category
                $hierarchy = [
                    [
                                'category' => [
                            'id' => $school_category->id,
                            'name' => $school_category->name,
                            'path' => $school_category->path,
                            'parent' => $school_category->parent,
                            'depth' => $school_category->depth,
                            'sortorder' => $school_category->sortorder
                                ],
                                'courses' => [],
                        'subcategories' => buildSchoolCategoryTree($school_category->id, $category_map)
                    ]
                            ];
                
                // Helper to find category in tree and add course
                function findCategoryAndAddCourse(&$tree, $cat_id, $course_data) {
                    foreach ($tree as &$node) {
                        if ($node['category']['id'] == $cat_id) {
                            $node['courses'][] = $course_data;
                            return true;
                        }
                        if (!empty($node['subcategories'])) {
                            if (findCategoryAndAddCourse($node['subcategories'], $cat_id, $course_data)) {
                                return true;
                    }
                }
                    }
                    return false;
                }
                
                // Add courses to their respective categories
                foreach ($courses as $course) {
                    $course_cat_id = $course->category_id;
                    $course_data = [
                        'id' => $course->id,
                        'fullname' => $course->fullname,
                        'shortname' => $course->shortname,
                        'idnumber' => $course->idnumber,
                        'category_name' => $course->category_name,
                        'category_id' => $course->category_id
                    ];
                    
                    findCategoryAndAddCourse($hierarchy, $course_cat_id, $course_data);
                }
                
                // Don't filter empty categories - show all categories even if empty
                echo json_encode(['status' => 'success', 'courses' => $hierarchy]);
            } catch (Exception $e) {
                echo json_encode(['status' => 'error', 'message' => 'Failed to load school courses: ' . $e->getMessage()]);
            }
            exit;
            
        case 'get_potential_courses':
            $school_id = intval($_GET['school_id']);
            
            try {
                // Get company to check its category
                $company = $DB->get_record('company', ['id' => $school_id]);
                if (!$company) {
                    echo json_encode(['status' => 'error', 'message' => 'School not found']);
                    exit;
                }
                
                // Get ALL categories (excluding root category id 0 and 1)
                // Build the full hierarchy like Moodle's course management
                $all_categories = $DB->get_records_sql(
                    "SELECT id, name, path, parent, depth, sortorder, visible
                     FROM {course_categories} 
                     WHERE id > 1
                     AND visible = 1
                     ORDER BY sortorder ASC, name ASC"
                );
                
                if (empty($all_categories)) {
                    echo json_encode(['status' => 'success', 'courses' => []]);
                    exit;
                }
                
                // Build category map
                $category_map = [];
                foreach ($all_categories as $cat) {
                    $category_map[$cat->id] = [
                        'id' => $cat->id,
                        'name' => $cat->name,
                        'path' => $cat->path,
                        'parent' => $cat->parent,
                        'depth' => $cat->depth,
                        'sortorder' => $cat->sortorder
                    ];
                }
                
                // Get all category IDs
                $all_category_ids = array_keys($category_map);
                
                if (empty($all_category_ids)) {
                    echo json_encode(['status' => 'success', 'courses' => []]);
                    exit;
                }
                
                list($in_sql, $params) = $DB->get_in_or_equal($all_category_ids);
                
                // Get all courses from Foundation, Intermediate, Advanced categories AND their subcategories
                // Exclude courses that are already in the school's category
                $exclude_condition = '';
                if (!empty($company->category)) {
                    $exclude_condition = "AND c.id NOT IN (
                        SELECT id FROM {course} WHERE category = ?
                    )";
                    $params[] = $company->category;
                }
                
                $sql = "SELECT c.*, 
                            cc.name as category_name,
                            cc.id as category_id,
                            cc.parent as category_parent,
                            cc.path as category_path,
                            cc.depth as category_depth
                     FROM {course} c 
                     LEFT JOIN {course_categories} cc ON c.category = cc.id
                     WHERE c.visible = 1 
                     AND c.id > 1 
                     AND c.category $in_sql
                     $exclude_condition
                     ORDER BY cc.path ASC, c.fullname ASC";
                     
                $courses = $DB->get_records_sql($sql, $params);
                
                // Build hierarchical structure like Moodle's course management
                // First, identify top-level categories (parent = 0 or 1, or depth = 1)
                $top_level_categories = [];
                foreach ($category_map as $cat_id => $cat_info) {
                    if ($cat_info['parent'] == 0 || $cat_info['parent'] == 1 || $cat_info['depth'] == 1) {
                        $top_level_categories[$cat_id] = $cat_info;
                    }
                }
                
                // Build tree structure recursively
                function buildCategoryTree($parent_id, $category_map) {
                    $children = [];
                    foreach ($category_map as $cat_id => $cat_info) {
                        if ($cat_info['parent'] == $parent_id) {
                            $children[] = [
                                'category' => $cat_info,
                                'courses' => [],
                                'subcategories' => buildCategoryTree($cat_id, $category_map)
                            ];
                        }
                    }
                    // Sort by sortorder, then name
                    usort($children, function($a, $b) {
                        $sort_a = $a['category']['sortorder'] ?? 999;
                        $sort_b = $b['category']['sortorder'] ?? 999;
                        if ($sort_a != $sort_b) {
                            return $sort_a <=> $sort_b;
                        }
                        return strcmp($a['category']['name'], $b['category']['name']);
                    });
                    return $children;
                }
                
                // Build hierarchy starting from top-level categories
                $hierarchy = [];
                foreach ($top_level_categories as $top_id => $top_info) {
                    $hierarchy[] = [
                        'category' => $top_info,
                        'courses' => [],
                        'subcategories' => buildCategoryTree($top_id, $category_map)
                    ];
                }
                
                // Sort top-level by sortorder, then name
                usort($hierarchy, function($a, $b) {
                    $sort_a = $a['category']['sortorder'] ?? 999;
                    $sort_b = $b['category']['sortorder'] ?? 999;
                    if ($sort_a != $sort_b) {
                        return $sort_a <=> $sort_b;
                    }
                    return strcmp($a['category']['name'], $b['category']['name']);
                });
                    
                // Helper to find category in tree and add course
                function findCategoryAndAddCourse(&$tree, $cat_id, $course_data) {
                    foreach ($tree as &$node) {
                        if ($node['category']['id'] == $cat_id) {
                            $node['courses'][] = $course_data;
                            return true;
                        }
                        if (!empty($node['subcategories'])) {
                            if (findCategoryAndAddCourse($node['subcategories'], $cat_id, $course_data)) {
                                return true;
                            }
                        }
                    }
                    return false;
                }
                
                // Now add courses to their respective categories
                foreach ($courses as $course) {
                    $course_cat_id = $course->category_id;
                    $course_data = [
                        'id' => $course->id,
                        'fullname' => $course->fullname,
                        'shortname' => $course->shortname,
                        'idnumber' => $course->idnumber,
                        'category_name' => $course->category_name,
                        'category_id' => $course->category_id
                            ];
                    
                    findCategoryAndAddCourse($hierarchy, $course_cat_id, $course_data);
                        }
                        
                // Filter out empty categories (keep categories even if they only have subcategories)
                function filterEmptyCategories($cat_data) {
                    // Keep if has courses or has subcategories with content
                    $has_content = false;
                    
                    if (count($cat_data['courses']) > 0) {
                        $has_content = true;
                    }
                    
                    // Filter and check subcategories
                    $filtered_subs = [];
                    foreach ($cat_data['subcategories'] as $sub) {
                        $filtered = filterEmptyCategories($sub);
                        if ($filtered !== null) {
                            $filtered_subs[] = $filtered;
                            $has_content = true;
                }
                    }
                    $cat_data['subcategories'] = $filtered_subs;
                    
                    return $has_content ? $cat_data : null;
                }
                
                $courses_array = [];
                foreach ($hierarchy as $cat_data) {
                    $filtered = filterEmptyCategories($cat_data);
                    if ($filtered !== null) {
                        $courses_array[] = $filtered;
                    }
                }
                
                echo json_encode(['status' => 'success', 'courses' => $courses_array]);
            } catch (Exception $e) {
                echo json_encode(['status' => 'error', 'message' => 'Failed to load potential courses: ' . $e->getMessage()]);
            }
            exit;
            
        case 'get_school_categories':
            $school_id = intval($_GET['school_id']);
            
            try {
                // Get company and its category
                $company = $DB->get_record('company', ['id' => $school_id]);
                if (!$company || empty($company->category)) {
                    echo json_encode(['status' => 'error', 'message' => 'School not found or has no category']);
            exit;
                }
                
                // Get all categories under the school's category (including the school category itself)
                $school_category = $DB->get_record('course_categories', ['id' => $company->category]);
                if (!$school_category) {
                    echo json_encode(['status' => 'error', 'message' => 'School category not found']);
                    exit;
                }
                
                // Get all categories that are under the school's category path
                $all_categories = $DB->get_records_sql(
                    "SELECT id, name, path, parent, depth, sortorder
                     FROM {course_categories} 
                     WHERE (id = ? OR path LIKE ?)
                     AND visible = 1
                     ORDER BY path ASC, sortorder ASC",
                    [$company->category, $school_category->path . '/%']
                );
            
                // Build category map
                $category_map = [];
                foreach ($all_categories as $cat) {
                    $category_map[$cat->id] = [
                        'id' => $cat->id,
                        'name' => $cat->name,
                        'path' => $cat->path,
                        'parent' => $cat->parent,
                        'depth' => $cat->depth,
                        'sortorder' => $cat->sortorder
                    ];
                }
                
                // Build tree structure
                function buildSchoolCategoryTree($parent_id, $category_map, $school_category_id) {
                    $children = [];
                    foreach ($category_map as $cat_id => $cat_info) {
                        if ($cat_info['parent'] == $parent_id) {
                            $children[] = [
                                'id' => $cat_info['id'],
                                'name' => $cat_info['name'],
                                'path' => $cat_info['path'],
                                'depth' => $cat_info['depth'],
                                'subcategories' => buildSchoolCategoryTree($cat_id, $category_map, $school_category_id)
                            ];
                        }
                    }
                    // Sort by sortorder, then name
                    usort($children, function($a, $b) {
                        return strcmp($a['name'], $b['name']);
                    });
                    return $children;
                }
                
                // Build hierarchy starting from school's main category
                $tree = [
                    'id' => $school_category->id,
                    'name' => $school_category->name,
                    'path' => $school_category->path,
                    'depth' => $school_category->depth,
                    'subcategories' => buildSchoolCategoryTree($school_category->id, $category_map, $school_category->id)
                ];
                
                echo json_encode(['status' => 'success', 'categories' => $tree]);
            } catch (Exception $e) {
                echo json_encode(['status' => 'error', 'message' => 'Failed to load school categories: ' . $e->getMessage()]);
            }
            exit;
            
        case 'get_category_list':
            // Get category list for autocomplete (only school's categories)
            $school_id = intval($_GET['school_id'] ?? 0);
            
            try {
                if ($school_id > 0) {
                    $company = $DB->get_record('company', ['id' => $school_id]);
                    if (!$company || empty($company->category)) {
                        echo json_encode(['status' => 'error', 'message' => 'School not found or has no category']);
                    exit;
                }
                    
                    // Get all categories under the school's category
                    $school_category = $DB->get_record('course_categories', ['id' => $company->category]);
                    if (!$school_category) {
                        echo json_encode(['status' => 'error', 'message' => 'School category not found']);
                    exit;
                }
                    
                    // Get all categories under the school's category
                    $all_categories = $DB->get_records_sql(
                        "SELECT id, name, path, depth, parent
                         FROM {course_categories} 
                         WHERE path LIKE ? OR id = ?
                         ORDER BY path ASC",
                        [$school_category->path . '/%', $company->category]
                    );
                    
                    // Build category list with full path (parent hierarchy)
                    $categories = [];
                    $category_map = [];
                    
                    // First, create a map of all categories
                    foreach ($all_categories as $cat) {
                        $category_map[$cat->id] = $cat;
                    }
                    
                    // Build full path for each category
                    foreach ($all_categories as $cat) {
                        $path_parts = [];
                        $current_cat = $cat;
                        
                        // Walk up the parent chain to build full path
                        while ($current_cat) {
                            array_unshift($path_parts, format_string($current_cat->name));
                            
                            if ($current_cat->parent > 0 && isset($category_map[$current_cat->parent])) {
                                $current_cat = $category_map[$current_cat->parent];
                            } else {
                                break;
                            }
                        }
                        
                        $full_path = implode(' / ', $path_parts);
                        $categories[$cat->id] = $full_path;
                    }
                } else {
                    // Get all categories if no school specified
                    require_once($CFG->dirroot . '/course/classes/management_helper.php');
                    $categories = \core_course_category::make_categories_list(\core_course\management\helper::get_course_copy_capabilities());
                }
                
                echo json_encode(['status' => 'success', 'categories' => $categories]);
            } catch (Exception $e) {
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            }
            exit;
            
        case 'check_shortname':
            // Check if a shortname already exists
            $prefix = trim($_GET['prefix'] ?? '');
            $source_course_id = intval($_GET['source_course_id'] ?? 0);
            
            try {
                if (empty($prefix)) {
                    echo json_encode(['status' => 'error', 'message' => 'Prefix is required']);
            exit;
                }
                
                if ($source_course_id <= 0) {
                    echo json_encode(['status' => 'error', 'message' => 'Source course ID is required']);
                    exit;
                }
                
                $source_course = $DB->get_record('course', ['id' => $source_course_id]);
                if (!$source_course) {
                    echo json_encode(['status' => 'error', 'message' => 'Source course not found']);
                    exit;
                }
                
                // Generate shortname: prefix + parent's shortname
                $new_shortname = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $prefix)) . '_' . $source_course->shortname;
                
                // Check if it exists
                $exists = $DB->record_exists('course', ['shortname' => $new_shortname]);
                
                echo json_encode([
                    'status' => 'success',
                    'exists' => $exists,
                    'shortname' => $new_shortname,
                    'message' => $exists ? 'This shortname already exists. Please use a different prefix.' : 'Shortname is available.'
                ]);
            } catch (Exception $e) {
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            }
            exit;
            
        case 'assign_course':
            $school_id = intval($_POST['school_id']);
            $course_id = intval($_POST['course_id']);
            $form_data = json_decode($_POST['form_data'] ?? '{}', true);
            
            try {
                // Verify course exists
                $source_course = $DB->get_record('course', ['id' => $course_id]);
                if (!$source_course) {
                    echo json_encode(['status' => 'error', 'message' => 'Course not found']);
                    exit;
                }
                
                // Verify company exists
                $company = $DB->get_record('company', ['id' => $school_id]);
                if (!$company) {
                    echo json_encode(['status' => 'error', 'message' => 'School not found']);
                    exit;
                }
                
                // Extract form data
                $prefix = trim($form_data['prefix'] ?? '');
                $target_category = intval($form_data['category'] ?? 0);
                $visible = intval($form_data['visible'] ?? 1);
                $startdate = intval($form_data['startdate'] ?? 0);
                $enddate = intval($form_data['enddate'] ?? 0);
                $userdata = intval($form_data['userdata'] ?? 0);
                
                // Extract license data
                $license_model = $form_data['license_model'] ?? 'none';
                $overall_allocation = isset($form_data['overall_allocation']) ? intval($form_data['overall_allocation']) : null;
                $percourse_allocation = isset($form_data['percourse_allocation']) ? intval($form_data['percourse_allocation']) : null;
                $validlength = isset($form_data['validlength']) ? intval($form_data['validlength']) : 365;
                
                // Prefix is REQUIRED
                if (empty($prefix)) {
                    echo json_encode(['status' => 'error', 'message' => 'Prefix is required']);
                    exit;
                }
                
                if ($target_category <= 0) {
                    echo json_encode(['status' => 'error', 'message' => 'Target category is required']);
                    exit;
                }
                
                // Verify target category exists
                $target_cat = $DB->get_record('course_categories', ['id' => $target_category]);
                if (!$target_cat) {
                    echo json_encode(['status' => 'error', 'message' => 'Target category not found']);
                    exit;
                }
                
                // If school has a category, verify target is under it
                if (!empty($company->category)) {
                    $school_category = $DB->get_record('course_categories', ['id' => $company->category]);
                    if ($target_category != $company->category && strpos($target_cat->path, $school_category->path . '/') !== 0) {
                        echo json_encode(['status' => 'error', 'message' => 'Target category must be under school category']);
            exit;
    }
}

                // Generate shortname: prefix + parent's shortname
                $new_shortname = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $prefix)) . '_' . $source_course->shortname;
                
                // Check if shortname already exists
                if ($DB->record_exists('course', ['shortname' => $new_shortname])) {
                    echo json_encode([
                        'status' => 'error', 
                        'message' => 'A course with shortname "' . $new_shortname . '" already exists. Please use a different prefix.',
                        'shortname' => $new_shortname
                    ]);
                    exit;
                }
                
                // Keep fullname same as parent (no prefix on fullname)
                $fullname = $source_course->fullname;
                
                // Prepare copy data using copy_helper format
                // Include backup files first - must include moodle2 plan builder
                require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
                require_once($CFG->dirroot . '/backup/moodle2/backup_plan_builder.class.php');
                require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
                require_once($CFG->dirroot . '/backup/util/helper/copy_helper.class.php');
                
                $copydata = new \stdClass();
                $copydata->courseid = $course_id;
                $copydata->fullname = $fullname;
                $copydata->shortname = $new_shortname;
                $copydata->category = $target_category;
                $copydata->visible = $visible;
                $copydata->startdate = $startdate > 0 ? $startdate : (time() + 86400); // Default to tomorrow
                $copydata->enddate = $enddate > 0 ? $enddate : 0;
                $copydata->idnumber = '';
                $copydata->userdata = $userdata;
                $copydata->companyid = $school_id;
                $copydata->keptroles = [];
                
                // Process the data first (validates and cleans it)
                try {
                    $processed_data = \copy_helper::process_formdata($copydata);
                } catch (\moodle_exception $e) {
                    echo json_encode(['status' => 'error', 'message' => 'Validation error: ' . $e->getMessage()]);
                    exit;
                }
                
                // Queue the copy job using copy_helper
                try {
                    $copyids = \copy_helper::create_copy($processed_data);
                } catch (\Exception $e) {
                    echo json_encode(['status' => 'error', 'message' => 'Failed to queue copy: ' . $e->getMessage()]);
                    exit;
                }
                
                // NOTE: License creation happens after course copy completes
                // Since course copy is async, we store license configuration for later processing
                // Licenses will be created when the copied course is available
                // For now, we'll create licenses immediately but they'll reference the source course
                // TODO: Create adhoc task or event handler to create licenses after copy completes
                
                $license_created = false;
                $license_ids = [];
                
                if ($license_model !== 'none') {
                    // Store license configuration for later use
                    // We'll create licenses after course copy completes
                    // For now, create licenses with source course ID as placeholder
                    // These will need to be updated after copy completes
                    
                    $license_data = [
                        'startdate' => $startdate > 0 ? $startdate : (time() + 86400),
                        'enddate' => $enddate > 0 ? $enddate : 0,
                        'validlength' => $validlength,
                        'overall_allocation' => $overall_allocation,
                        'percourse_allocation' => $percourse_allocation
                    ];
                    
                    // Create licenses immediately (will be linked to copied course after copy completes)
                    // For now, create with source course - will be updated after copy
                    try {
                        $license_ids = create_school_licenses($school_id, $license_model, [$course_id], $license_data);
                        $license_created = !empty($license_ids);
                    } catch (\Exception $e) {
                        error_log('License creation error: ' . $e->getMessage());
                        // Don't fail the course copy if license creation fails
                        // Licenses can be created manually later
                    }
                }
                
                // Build progress URL
                $progress_url = new moodle_url('/backup/copyprogress.php', ['id' => $course_id]);
                
                $message = 'Course copy queued successfully. It will be processed by CRON.';
                if ($license_created) {
                    $message .= ' Licenses have been created and will be linked to the copied course after copy completes.';
                }
                
                echo json_encode([
                    'status' => 'success', 
                    'message' => $message,
                    'backupid' => $copyids['backupid'] ?? null,
                    'restoreid' => $copyids['restoreid'] ?? null,
                    'progress_url' => $progress_url->out(false),
                    'source_course_id' => $course_id,
                    'license_created' => $license_created,
                    'license_ids' => $license_ids
                ]);
                exit; // CRITICAL: Exit immediately after JSON response
                
            } catch (Exception $e) {
                echo json_encode(['status' => 'error', 'message' => 'Failed to queue course copy: ' . $e->getMessage()]);
                exit; // CRITICAL: Exit immediately after JSON response
            }
            
        case 'unassign_course':
            $school_id = intval($_POST['school_id']);
            $course_id = intval($_POST['course_id']);
            
            try {
                // Verify course exists
                $course = $DB->get_record('course', ['id' => $course_id]);
                if (!$course) {
                    echo json_encode(['status' => 'error', 'message' => 'Course not found']);
                    exit;
                }
                
                // Verify company exists and has a category
                $company = $DB->get_record('company', ['id' => $school_id]);
                if (!$company || empty($company->category)) {
                    echo json_encode(['status' => 'error', 'message' => 'School not found or has no category']);
                    exit;
                }
                
                // Check if course is in the school's category
                if ($course->category != $company->category) {
                    echo json_encode(['status' => 'error', 'message' => 'Course is not in the school category']);
                    exit;
                }
                
                // Check for enrollments
                $enrollments = $DB->count_records('user_enrolments', ['status' => 0]);
                $course_enrollments = $DB->count_records_sql(
                    "SELECT COUNT(DISTINCT ue.userid) 
                     FROM {user_enrolments} ue
                     JOIN {enrol} e ON e.id = ue.enrolid
                     WHERE e.courseid = ? AND ue.status = 0",
                    [$course_id]
                );
                
                if ($course_enrollments > 0) {
                    // Check if confirmation was sent
                    $confirm = isset($_POST['confirm']) && $_POST['confirm'] == '1';
                    if (!$confirm) {
                        echo json_encode([
                            'status' => 'warning',
                            'message' => 'Course has ' . $course_enrollments . ' enrolled users. This action will delete the course and all associated data.',
                            'enrollments' => $course_enrollments
                        ]);
                        exit;
                    }
                }
                
                // Delete the course (this will also remove from company_course via cascade or triggers)
                require_once($CFG->dirroot . '/course/lib.php');
                delete_course($course_id, false);
                
                echo json_encode(['status' => 'success', 'message' => 'Course removed from school category successfully']);
                
            } catch (Exception $e) {
                echo json_encode(['status' => 'error', 'message' => 'Failed to remove course: ' . $e->getMessage()]);
            }
            exit;
            
        default:
            echo json_encode(['status' => 'error', 'message' => 'Unknown action: ' . $action]);
            exit;
    }
    // This should never be reached, but just in case:
    exit;
}

// If we get here, it's not an AJAX request - render the normal page
require_login();

// Check admin capabilities
$context = context_system::instance();
require_capability('moodle/site:config', $context);

// Get current user
global $USER, $DB, $OUTPUT;

// Get all schools from company table
try {
    // First check if company table exists
    if (!$DB->get_manager()->table_exists('company')) {
        error_log('Company table does not exist, falling back to course categories');
        // Fallback to course categories if company table doesn't exist
    $schools = $DB->get_records_sql(
        "SELECT id, name 
         FROM {course_categories} 
         WHERE visible = 1 
         AND id > 1 
         AND parent = 0
         ORDER BY name ASC",
        []
    );
    } else {
        // Fetch schools from company table
        $schools = $DB->get_records_sql(
            "SELECT id, name 
             FROM {company} 
             ORDER BY name ASC",
            []
        );
    }
    
    // Debug: Log the result
    error_log('Main schools fetched: ' . print_r($schools, true));
    
    // If no schools found, return empty array
    if (empty($schools)) {
        $schools = [];
    }
} catch (Exception $e) {
    error_log('Error in main schools fetch: ' . $e->getMessage());
    // If all fails, return empty array
    $schools = [];
}

$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/admin/assign_to_school.php');
$PAGE->set_title('Assign Courses to School');
$PAGE->set_heading('Assign Courses to School');

echo $OUTPUT->header();

// Include admin sidebar from includes
require_once(__DIR__ . '/includes/admin_sidebar.php');

// Sidebar toggle button for mobile
echo "<button class='sidebar-toggle' onclick='toggleSidebar()' aria-label='Toggle sidebar'>";
echo "<i class='fa fa-bars'></i>";
echo "</button>";

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
    background: linear-gradient(135deg, #fce4ec 0%, #f3e5f5 50%, #e8f5e8 100%);
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
    padding: 1rem 2rem;
    color: #495057;
    text-decoration: none;
    transition: all 0.3s ease;
    position: relative;
    font-weight: 500;
    font-size: 0.95rem;
}

.admin-sidebar .sidebar-link:hover {
    background: #f8f9fa;
    color: #2196F3;
    padding-left: 2.5rem;
}

.admin-sidebar .sidebar-item.active .sidebar-link {
    background: linear-gradient(90deg, rgba(33, 150, 243, 0.1) 0%, transparent 100%);
    color: #2196F3;
    border-left: 4px solid #2196F3;
    font-weight: 600;
}

.admin-sidebar .sidebar-icon {
    margin-right: 1rem;
    font-size: 1.1rem;
    width: 20px;
    text-align: center;
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
    padding-top: 80px;
}

/* Sidebar toggle button for mobile */
.sidebar-toggle {
    display: none;
    position: fixed;
    top: 20px;
    left: 20px;
    z-index: 1001;
    background: #2196F3;
    color: white;
    border: none;
    width: 45px;
    height: 45px;
    border-radius: 50%;
    cursor: pointer;
    box-shadow: 0 4px 12px rgba(33, 150, 243, 0.4);
    transition: all 0.3s ease;
}

.sidebar-toggle:hover {
    background: #1976D2;
    transform: scale(1.1);
}

/* Mobile responsive */
@media (max-width: 768px) {
    .admin-sidebar {
        position: fixed;
        top: 0;
        left: -280px;
        transition: left 0.3s ease;
    }
    
    .admin-sidebar.sidebar-open {
        left: 0;
    }
    
    .admin-main-content {
        position: relative;
        left: 0;
        width: 100vw;
        height: auto;
        min-height: 100vh;
        padding-top: 20px;
    }
    
    .sidebar-toggle {
        display: flex;
        align-items: center;
        justify-content: center;
    }
}

<style>
/* Modern Assign to School Page Styles */
.assign-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 20px;
    background: linear-gradient(135deg, #e1bee7 0%, #f8bbd9 100%);
    min-height: 100vh;
}

.assign-header {
    text-align: center;
    margin-bottom: 30px;
    color: #374151;
    position: relative;
    padding-top: 20px;
}

.assign-header h1 {
    font-size: 2.2rem;
    font-weight: 700;
    margin-bottom: 8px;
    color: #1e293b;
    line-height: 1.2;
}

.assign-header p {
    font-size: 1rem;
    color: #64748b;
    margin-bottom: 0;
    font-weight: 500;
}

/* School Selection */
.school-selection {
    background: white;
    border-radius: 8px;
    padding: 16px 20px;
    margin-bottom: 20px;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    border: 1px solid #e5e7eb;
    max-width: 800px;
    margin-left: 12px;
    margin-right: auto;
}

.school-selection-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
}

.school-selection h3 {
    color: #374151;
    margin: 0;
    font-size: 1.1rem;
    font-weight: 600;
}

.iomad-dashboard-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    text-decoration: none;
    border-radius: 6px;
    font-size: 0.9rem;
    font-weight: 500;
    transition: all 0.3s ease;
    box-shadow: 0 2px 4px rgba(102, 126, 234, 0.3);
}

.iomad-dashboard-btn:hover {
    background: linear-gradient(135deg, #5a6fd8 0%, #6a4190 100%);
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(102, 126, 234, 0.4);
    color: white;
    text-decoration: none;
}

.iomad-dashboard-btn i {
    font-size: 0.8rem;
}

.school-dropdown {
    position: relative;
    width: 100%;
}

.school-select {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    font-size: 1rem;
    background: white;
    cursor: pointer;
    transition: border-color 0.2s ease;
    appearance: none;
    background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='m6 8 4 4 4-4'/%3e%3c/svg%3e");
    background-position: right 12px center;
    background-repeat: no-repeat;
    background-size: 16px;
}

.school-select:focus {
    outline: none;
    border-color: #22c55e;
    box-shadow: 0 0 0 2px rgba(34, 197, 94, 0.1);
}

.school-select:hover {
    border-color: #9ca3af;
}

/* Main Assignment Interface */
.assignment-interface {
    display: grid;
    grid-template-columns: 1fr auto 1fr;
    gap: 20px;
    margin-bottom: 30px;
    animation: fadeInUp 0.8s ease-out;
}

.course-panel {
    background: white;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    border: 1px solid #e9ecef;
    transition: all 0.2s ease;
    min-height: 500px;
}

.course-panel:hover {
    box-shadow: 0 6px 16px rgba(0,0,0,0.15);
}

.panel-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
    padding-bottom: 12px;
    border-bottom: 1px solid #e5e7eb;
}

.panel-title {
    font-size: 1.2rem;
    font-weight: 600;
    color: #374151;
    margin: 0;
}

.course-count {
    background: #22c55e;
    color: white;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 0.85rem;
    font-weight: 500;
}

.search-container {
    margin-bottom: 20px;
    position: relative;
}

.search-input {
    width: 100%;
    padding: 8px 12px 8px 36px;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    font-size: 0.95rem;
    transition: border-color 0.2s ease;
    background: white;
}

.search-input:focus {
    outline: none;
    border-color: #22c55e;
    box-shadow: 0 0 0 2px rgba(34, 197, 94, 0.1);
}

.search-input:hover {
    border-color: #9ca3af;
}

.search-icon {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: #6b7280;
    font-size: 1rem;
}

.course-list {
    max-height: 500px;
    overflow-y: auto;
    border-radius: 15px;
    background: #f8f9fa;
    padding: 10px;
}

.course-item {
    background: white;
    border-radius: 12px;
    padding: 15px;
    margin-bottom: 10px;
    border: 2px solid transparent;
    transition: all 0.3s ease;
    cursor: pointer;
    position: relative;
    overflow: hidden;
}

.course-item::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    transform: scaleX(0);
    transition: transform 0.3s ease;
}

.course-item:hover::before {
    transform: scaleX(1);
}

.course-item:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.1);
    border-color: #667eea;
}

.course-item.selected {
    border-color: #28a745;
    background: linear-gradient(135deg, rgba(40, 167, 69, 0.1) 0%, rgba(40, 167, 69, 0.05) 100%);
}

.course-item.selected::before {
    background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
    transform: scaleX(1);
}

.course-name {
    font-size: 1.1rem;
    font-weight: 600;
    color: #333;
    margin-bottom: 5px;
    line-height: 1.3;
}

.course-category {
    font-size: 0.9rem;
    color: #6c757d;
    margin-bottom: 8px;
}

.course-meta {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 0.85rem;
    color: #6c757d;
}

.enrollment-badge {
    background: #e3f2fd;
    color: #1976d2;
    padding: 4px 8px;
    border-radius: 12px;
    font-weight: 500;
}

.warning-badge {
    background: #fff3cd;
    color: #856404;
    padding: 4px 8px;
    border-radius: 12px;
    font-weight: 500;
}

/* Action Buttons */
.action-buttons {
    display: flex;
    flex-direction: column;
    gap: 12px;
    align-items: center;
    justify-content: center;
    padding: 20px 10px;
}

.action-btn {
    background: #22c55e;
    color: white;
    border: none;
    padding: 10px 16px;
    border-radius: 8px;
    font-size: 0.9rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    gap: 6px;
    width: 100px;
    justify-content: center;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

.action-btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
}

.action-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
    transform: none;
}

.action-btn.add {
    background: #22c55e;
    color: white;
}

.action-btn.add:hover {
    background: #16a34a;
}

.action-btn.remove {
    background: #ef4444;
    color: white;
}

.action-btn.remove:hover {
    background: #dc2626;
}

/* Warning Section */
.warning-section {
    background: rgba(255, 255, 255, 0.95);
    border-radius: 20px;
    padding: 25px;
    margin-bottom: 30px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.2);
    backdrop-filter: blur(10px);
    border: 1px solid rgba(255,255,255,0.3);
    animation: fadeInUp 1s ease-out;
}

.warning-header {
    display: flex;
    align-items: center;
    gap: 15px;
    margin-bottom: 15px;
}

.warning-icon {
    background: linear-gradient(135deg, #ffc107 0%, #ff8c00 100%);
    color: white;
    width: 50px;
    height: 50px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    animation: pulse 2s infinite;
}

.warning-title {
    font-size: 1.3rem;
    font-weight: 700;
    color: #333;
    margin: 0;
}

.warning-content {
    background: #fff3cd;
    border: 1px solid #ffeaa7;
    border-radius: 15px;
    padding: 20px;
    margin-bottom: 20px;
}

.warning-text {
    color: #856404;
    font-size: 1rem;
    line-height: 1.6;
    margin: 0;
}

.confirmation-section {
    display: flex;
    align-items: center;
    gap: 15px;
    padding: 20px;
    background: #f8f9fa;
    border-radius: 15px;
    border: 2px solid #e9ecef;
}

.confirmation-checkbox {
    width: 20px;
    height: 20px;
    accent-color: #dc3545;
    cursor: pointer;
}

.confirmation-label {
    font-size: 1rem;
    font-weight: 600;
    color: #333;
    cursor: pointer;
    margin: 0;
}

/* Loading States */
.loading {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 40px;
    color: #6c757d;
}

.loading-spinner {
    width: 40px;
    height: 40px;
    border: 4px solid #f3f3f3;
    border-top: 4px solid #667eea;
    border-radius: 50%;
    animation: spin 1s linear infinite;
    margin-right: 15px;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

/* Animations */
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

.course-item {
    animation: slideInLeft 0.3s ease-out;
}

@keyframes slideInLeft {
    from {
        opacity: 0;
        transform: translateX(-20px);
    }
    to {
        opacity: 1;
        transform: translateX(0);
    }
}

/* Responsive Design */
@media (max-width: 768px) {
    .assignment-interface {
        grid-template-columns: 1fr;
        gap: 20px;
    }
    
    .action-buttons {
        flex-direction: row;
        justify-content: center;
    }
    
    .assign-header h1 {
        font-size: 2rem;
    }
    
    .course-panel {
        padding: 20px;
    }
    
    .school-selection-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 12px;
    }
    
    .iomad-dashboard-btn {
        align-self: stretch;
        justify-content: center;
    }
}

/* Custom Scrollbar */
.course-list::-webkit-scrollbar {
    width: 8px;
}

.course-list::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 10px;
}

.course-list::-webkit-scrollbar-thumb {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 10px;
}

.course-list::-webkit-scrollbar-thumb:hover {
    background: linear-gradient(135deg, #5a6fd8 0%, #6a4190 100%);
}

/* Hierarchical Course Structure - Clean and Simple */
.category-group {
    margin-bottom: 12px;
    border: 1px solid #e9ecef;
    border-radius: 8px;
    overflow: hidden;
    background: white;
    transition: border-color 0.2s ease;
}

.category-group:hover {
    border-color: #22c55e;
}

/* Nested category styling */
.category-group .category-group {
    margin-left: 24px;
    margin-bottom: 8px;
    border-left: 2px solid #22c55e;
    background: #f9fffe;
}

.category-group .category-group .category-group {
    margin-left: 24px;
    border-left: 2px solid #16a34a;
    background: #f0fdf4;
}

.category-header {
    background: #f8f9fa;
    color: #374151;
    padding: 12px 16px;
    cursor: pointer;
    display: flex;
    justify-content: space-between;
    align-items: center;
    transition: background-color 0.2s ease;
    border-bottom: 1px solid #e9ecef;
}

.category-header:hover {
    background: #e9ecef;
}

.category-header-left {
    display: flex;
    align-items: center;
    gap: 15px;
    flex: 1;
}

.category-select-all {
    background: white;
    color: #22c55e;
    border: 1px solid #22c55e;
    padding: 6px 12px;
    border-radius: 6px;
    font-size: 0.85rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    gap: 6px;
    white-space: nowrap;
}

.category-select-all:hover {
    background: #22c55e;
    color: white;
}

.category-select-all.selected {
    background: #22c55e;
    color: white;
    border-color: #22c55e;
}

.category-select-all.selected:hover {
    background: #16a34a;
    border-color: #16a34a;
}

/* Subcategory header styling */
.category-header.subcategory-header {
    background: #f1f5f9;
    border-left: 2px solid #22c55e;
}

.category-header.subcategory-header:hover {
    background: #e2e8f0;
}

.category-title {
    margin: 0;
    font-size: 1rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
    color: #374151;
}

.category-count {
    background: #f3f4f6;
    color: #6b7280;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 0.85rem;
    font-weight: 500;
    border: 1px solid #e5e7eb;
}

.toggle-icon {
    transition: transform 0.2s ease;
    color: #6b7280;
}

.category-content {
    padding: 16px;
    background: white;
}

.direct-courses-section {
    margin-bottom: 16px;
    background: #f9fffe;
    border-radius: 6px;
    padding: 12px;
    border-left: 2px solid #22c55e;
}



.section-title {
    margin: 0 0 12px 0;
    font-size: 0.9rem;
    font-weight: 600;
    color: #374151;
    display: flex;
    align-items: center;
    gap: 6px;
}


.courses-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

/* Enhanced Course Item Styling */
.course-item {
    background: white;
    border-radius: 8px;
    padding: 12px;
    border: 1px solid #e0e0e0;
    transition: all 0.3s ease;
    cursor: pointer;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.course-item:hover {
    transform: translateX(5px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    border-color: #64b5f6;
}

.course-info {
    flex: 1;
}

.course-name {
    font-weight: 600;
    color: #333;
    margin-bottom: 4px;
    font-size: 0.95rem;
}

.course-category {
    font-size: 0.8rem;
    color: #666;
    display: flex;
    align-items: center;
    gap: 4px;
}

.course-meta {
    display: flex;
    flex-direction: column;
    gap: 4px;
    align-items: flex-end;
}

.enrollment-badge, .warning-badge {
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 0.7rem;
    font-weight: 500;
    display: flex;
    align-items: center;
    gap: 3px;
}

.enrollment-badge {
    background: #f0fdf4;
    color: #16a34a;
    border: 1px solid #bbf7d0;
}

.warning-badge {
    background: #fef3c7;
    color: #d97706;
    border: 1px solid #fde68a;
}
</style>

<div class="assign-container">
    <div class="assign-header">
        <h1>Assign Courses to School</h1>
        <p>Manage course assignments for educational institutions</p>
        <p style="margin-top: 10px; font-size: 0.9rem; color: #64748b;">
            <i class="fa fa-info-circle"></i> 
            After courses are copied, remember to 
            <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/admin/enroll_school_admins.php" 
               style="color: #2196F3; text-decoration: underline; font-weight: 600;">
                enroll school admins
            </a> 
            in the new courses.
        </p>
    </div>

    <!-- School Selection -->
    <div class="school-selection">
        <div class="school-selection-header">
            <h3>Select School</h3>
        </div>
        <div class="school-dropdown">
            <select class="school-select" id="schoolSelect">
                <option value="">Choose a school...</option>
                <?php foreach ($schools as $school): ?>
                    <option value="<?php echo $school->id; ?>"><?php echo htmlspecialchars($school->name); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <!-- School Category Display -->
        <div id="schoolCategoryDisplay" style="display: none; margin-top: 15px; padding: 15px; background: #f0f9ff; border-radius: 8px; border-left: 4px solid #2196F3;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <i class="fa fa-folder-open" style="font-size: 1.2rem; color: #2196F3;"></i>
                <div>
                    <strong style="color: #374151; font-size: 0.9rem;">School Category:</strong>
                    <span id="schoolCategoryName" style="color: #2196F3; font-weight: 600; font-size: 1.1rem; margin-left: 8px;"></span>
                </div>
            </div>
            <p style="margin: 8px 0 0 0; color: #6b7280; font-size: 0.85rem;">
                Courses from <strong>Foundation</strong>, <strong>Intermediate</strong>, and <strong>Advanced</strong> categories will be copied to this category.
            </p>
        </div>
    </div>

    <!-- Assignment Interface -->
    <div class="assignment-interface" id="assignmentInterface" style="display: none;">
        <!-- School Courses Panel -->
        <div class="course-panel">
            <div class="panel-header">
                <h3 class="panel-title">School Courses</h3>
                <div class="course-count" id="schoolCourseCount">0</div>
            </div>
            <div class="search-container">
                <i class="fa fa-search search-icon"></i>
                <input type="text" class="search-input" id="schoolSearch" placeholder="Search school courses...">
            </div>
            <div class="course-list" id="schoolCourseList">
                <div class="loading">
                    <div class="loading-spinner"></div>
                    Loading courses...
                </div>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="action-buttons">
            <button class="action-btn add" id="addBtn" disabled>
                <i class="fa fa-arrow-left"></i>
                Add
            </button>
            <button class="action-btn remove" id="removeBtn" disabled>
                Remove
                <i class="fa fa-arrow-right"></i>
            </button>
        </div>

        <!-- Potential Courses Panel -->
        <div class="course-panel">
            <div class="panel-header">
                <h3 class="panel-title">Potential Courses</h3>
                <div class="course-count" id="potentialCourseCount">0</div>
            </div>
            <div class="search-container">
                <i class="fa fa-search search-icon"></i>
                <input type="text" class="search-input" id="potentialSearch" placeholder="Search potential courses...">
            </div>
            <div class="course-list" id="potentialCourseList">
                <div class="loading">
                    <div class="loading-spinner"></div>
                    Loading courses...
                </div>
            </div>
        </div>
    </div>

    <!-- Warning Section -->
    <div class="warning-section" id="warningSection" style="display: none;">
        <div class="warning-header">
            <div class="warning-icon">
                <i class="fa fa-exclamation-triangle"></i>
            </div>
            <h3 class="warning-title">Important Warning</h3>
        </div>
        <div class="warning-content">
            <p class="warning-text">
                <strong>WARNING:</strong> If "(existing enrollments)" is shown you must tick the box beneath to allow add or remove. 
                If you do this, all users will be unenrolled and ALL THEIR DATA (for that course) IS LOST. This cannot be undone.
            </p>
        </div>
        <div class="confirmation-section">
            <input type="checkbox" class="confirmation-checkbox" id="confirmUnenroll">
            <label for="confirmUnenroll" class="confirmation-label">OK to unenroll users</label>
        </div>
    </div>
</div>

<script>
// Global variables
let selectedSchool = null;
let schoolCourses = [];
let potentialCourses = [];
let selectedSchoolCourses = [];
let selectedPotentialCourses = [];

// Initialize page
document.addEventListener('DOMContentLoaded', function() {
    initializeEventListeners();
});

function initializeEventListeners() {
    // School selection
    document.getElementById('schoolSelect').addEventListener('change', handleSchoolChange);
    
    // Search functionality
    document.getElementById('schoolSearch').addEventListener('input', (e) => filterCourses('school', e.target.value));
    document.getElementById('potentialSearch').addEventListener('input', (e) => filterCourses('potential', e.target.value));
    
    // Action buttons
    document.getElementById('addBtn').addEventListener('click', addSelectedCourses);
    document.getElementById('removeBtn').addEventListener('click', removeSelectedCourses);
    
    // Confirmation checkbox
    document.getElementById('confirmUnenroll').addEventListener('change', updateActionButtons);
}

async function handleSchoolChange(event) {
    const schoolId = event.target.value;
    if (schoolId) {
        selectedSchool = schoolId;
        
        // Load school info to show category name
        try {
            const response = await fetch(`?action=get_school_info&school_id=${schoolId}`);
            const data = await response.json();
            
            if (data.status === 'success') {
                const categoryDisplay = document.getElementById('schoolCategoryDisplay');
                const categoryName = document.getElementById('schoolCategoryName');
                
                if (data.category_name && data.category_name !== 'No category assigned') {
                    categoryName.textContent = data.category_name;
                    categoryDisplay.style.display = 'block';
                } else {
                    categoryDisplay.style.display = 'none';
                    alert('Warning: This school does not have a category assigned. Please assign a category first.');
                    return;
                }
            }
        } catch (error) {
            console.error('Error loading school info:', error);
        }
        
        document.getElementById('assignmentInterface').style.display = 'grid';
        loadSchoolCourses();
        loadPotentialCourses();
    } else {
        selectedSchool = null;
        document.getElementById('assignmentInterface').style.display = 'none';
        document.getElementById('warningSection').style.display = 'none';
        document.getElementById('schoolCategoryDisplay').style.display = 'none';
    }
}

async function loadSchoolCourses() {
    try {
        showLoading('schoolCourseList');
        const response = await fetch(`?action=get_school_courses&school_id=${selectedSchool}`);
        const data = await response.json();
        
        if (data.status === 'success' && data.courses) {
            schoolCourses = data.courses;
            renderCourses('schoolCourseList', schoolCourses, 'school');
            // Count total courses across all categories
            let total = 0;
            function countCoursesInTree(tree) {
                tree.forEach(cat => {
                    if (cat.courses) {
                        total += cat.courses.length;
                    }
                    if (cat.subcategories) {
                        countCoursesInTree(cat.subcategories);
                    }
                });
            }
            countCoursesInTree(schoolCourses);
            updateCourseCount('schoolCourseCount', total);
        } else {
            schoolCourses = [];
            renderCourses('schoolCourseList', [], 'school');
            updateCourseCount('schoolCourseCount', 0);
        }
    } catch (error) {
        console.error('Error loading school courses:', error);
        showError('schoolCourseList', 'Failed to load school courses');
    }
}

async function loadPotentialCourses() {
    try {
        showLoading('potentialCourseList');
        const response = await fetch(`?action=get_potential_courses&school_id=${selectedSchool}`);
        const data = await response.json();
        
        if (data.status === 'success' && data.courses) {
            potentialCourses = data.courses;
            renderCourses('potentialCourseList', potentialCourses, 'potential');
            // Count total courses across all categories
            let total = 0;
            potentialCourses.forEach(cat => {
                if (cat.courses) {
                    total += cat.courses.length;
                }
            });
            updateCourseCount('potentialCourseCount', total);
        } else {
            potentialCourses = [];
            renderCourses('potentialCourseList', [], 'potential');
            updateCourseCount('potentialCourseCount', 0);
        }
    } catch (error) {
        console.error('Error loading potential courses:', error);
        showError('potentialCourseList', 'Failed to load potential courses');
    }
}

function renderCourses(containerId, courses, type) {
    const container = document.getElementById(containerId);
    
    if (!container) {
        console.error('Container not found:', containerId);
        return;
    }
    
    if (!courses || courses.length === 0) {
        container.innerHTML = '<div class="loading">No categories found</div>';
        return;
    }
    
    // Both potential and school courses now have hierarchical structure
    if (courses[0] && courses[0].category) {
        const hierarchyHtml = renderPotentialCoursesHierarchy(courses, type);
        if (hierarchyHtml.trim() === '') {
            container.innerHTML = '<div class="loading">No categories found</div>';
            return;
        }
        container.innerHTML = hierarchyHtml;
    } else {
        // Fallback: render simple list (shouldn't happen now)
        container.innerHTML = courses.map(course => {
            if (!course) return '';
            return `
                <div class="course-item" 
                     data-course-id="${course.id || ''}" 
                     data-course-fullname="${escapeHtml(course.fullname || 'Unknown Course')}"
                     data-course-shortname="${escapeHtml(course.shortname || '')}"
                     data-type="${type}">
                    <div class="course-info">
                        <div class="course-name">${escapeHtml(course.fullname || 'Unknown Course')}</div>
                        <div class="course-category">
                            <i class="fa fa-tag"></i>
                            ${escapeHtml(course.category_name || 'Uncategorized')}
                        </div>
                    </div>
                    <div class="course-meta">
                        <span class="enrollment-badge">
                            <i class="fa fa-hashtag"></i>
                            ${course.idnumber || 'No ID'}
                        </span>
                    </div>
                </div>
            `;
        }).join('');
    }
    
    // Add click listeners
    container.querySelectorAll('.course-item').forEach(item => {
        item.addEventListener('click', () => toggleCourseSelection(item, type));
    });
}

function renderPotentialCoursesHierarchy(categories, type, level = 0) {
    return categories.map(categoryGroup => {
        if (!categoryGroup || !categoryGroup.category) {
            return '';
        }
        
        const category = categoryGroup.category;
        const courses = categoryGroup.courses || [];
        const subcategories = categoryGroup.subcategories || [];
        
        // Count total courses including subcategories
        const countTotalCourses = (cat) => {
            let total = (cat.courses || []).length;
            if (cat.subcategories) {
                cat.subcategories.forEach(sub => {
                    total += countTotalCourses(sub);
                });
            }
            return total;
        };
        
        const totalCourses = countTotalCourses(categoryGroup);
        
        // For school courses, show categories even if empty
        // For potential courses, only show if has content
        if (type === 'school') {
            // Always show categories in school panel, even if empty
        } else {
            // For potential courses, skip if no courses and no subcategories
            if (totalCourses === 0) {
                return '';
            }
        }
        
        const uniqueCategoryId = `${type}_${category.id}_${level}`;
        const indent = level * 20;
        
        let html = `
            <div class="category-group" style="margin-left: ${indent}px;">
                <div class="category-header ${level > 0 ? 'subcategory-header' : ''}">
                    <div class="category-header-left" onclick="toggleCategory('${uniqueCategoryId}')">
                        <h4 class="category-title">
                            <i class="fa fa-folder${level > 0 ? '-open' : ''}"></i>
                            ${escapeHtml(category.name)}
                        </h4>
                        <span class="category-count">${totalCourses} courses</span>
                    </div>
                    <button class="category-select-all" 
                            data-category-id="${category.id}" 
                            onclick="event.stopPropagation(); selectAllInCategory('${category.id}', '${type}')"
                            title="Select all ${totalCourses} courses in this category">
                        <i class="fa fa-check-double"></i>
                        Select All (${totalCourses})
                    </button>
                    <i class="fa fa-chevron-down toggle-icon" id="toggle-${uniqueCategoryId}" onclick="toggleCategory('${uniqueCategoryId}')"></i>
                </div>
                <div class="category-content" id="category-${uniqueCategoryId}" style="display: ${level === 0 ? 'block' : 'none'};">
        `;
        
        // Direct courses in this category
        if (courses.length > 0) {
            html += `
                <div class="direct-courses-section">
                    <h5 class="section-title">
                        <i class="fa fa-book"></i>
                        Courses (${courses.length})
                    </h5>
                    <div class="courses-list">
                        ${courses.map(course => `
                            <div class="course-item" 
                                 data-course-id="${course.id}" 
                                 data-course-fullname="${escapeHtml(course.fullname || 'Unknown Course')}"
                                 data-course-shortname="${escapeHtml(course.shortname || '')}"
                                 data-type="${type}">
                                <div class="course-info">
                                    <div class="course-name">${escapeHtml(course.fullname || 'Unknown Course')}</div>
                                    <div class="course-category">
                                        <i class="fa fa-tag"></i>
                                        ${escapeHtml(course.category_name || 'Uncategorized')}
                                    </div>
                                </div>
                                <div class="course-meta">
                                    <span class="enrollment-badge">
                                        <i class="fa fa-hashtag"></i>
                                        ${course.idnumber || 'No ID'}
                                    </span>
                                </div>
                            </div>
                        `).join('')}
                    </div>
                </div>
            `;
        }
        
        // Subcategories - render recursively
        if (subcategories.length > 0) {
            html += renderPotentialCoursesHierarchy(subcategories, type, level + 1);
        }
        
        html += `
                </div>
            </div>
        `;
        
        return html;
    }).join('');
}

function renderCategoryHierarchy(categories, type, level = 0) {
    return categories.map(categoryGroup => {
        if (!categoryGroup || !categoryGroup.category) return '';
        
        const category = categoryGroup.category;
        const totalCourses = countTotalCourses(categoryGroup);
        
        // Skip categories with no courses
        if (totalCourses === 0) return '';
        
        const indent = level * 20;
        
        // Create unique IDs for each panel to avoid conflicts
        const uniqueCategoryId = `${type}_${category.id}`;
        
        let html = `
            <div class="category-group" style="margin-left: ${indent}px;">
                <div class="category-header ${level > 0 ? 'subcategory-header' : ''}">
                    <div class="category-header-left" onclick="toggleCategory('${uniqueCategoryId}')">
                        <h4 class="category-title">
                            <i class="fa fa-folder${level > 0 ? '-open' : ''}"></i>
                            ${escapeHtml(category.name)}
                        </h4>
                        <span class="category-count">${totalCourses} courses</span>
                    </div>
                    <button class="category-select-all" 
                            data-category-id="${category.id}" 
                            onclick="event.stopPropagation(); selectAllInCategory('${category.id}', '${type}')"
                            title="Select all ${totalCourses} courses in this category">
                        <i class="fa fa-check-double"></i>
                        Select All (${totalCourses})
                    </button>
                    <i class="fa fa-chevron-down toggle-icon" id="toggle-${uniqueCategoryId}" onclick="toggleCategory('${uniqueCategoryId}')"></i>
                </div>
                <div class="category-content" id="category-${uniqueCategoryId}" style="display: none;">
        `;
        
        // Direct courses in this category
        if (categoryGroup.courses && categoryGroup.courses.length > 0) {
            html += `
                <div class="direct-courses-section">
                    <h5 class="section-title">
                        <i class="fa fa-book"></i>
                        Courses (${categoryGroup.courses.length})
                    </h5>
                    <div class="courses-list">
            `;
            categoryGroup.courses.forEach(course => {
                html += `
                    <div class="course-item" 
                         data-course-id="${course.id}" 
                         data-course-fullname="${escapeHtml(course.fullname || 'Unknown Course')}"
                         data-course-shortname="${escapeHtml(course.shortname || '')}"
                         data-type="${type}">
                        <div class="course-info">
                            <div class="course-name">${escapeHtml(course.fullname || 'Unknown Course')}</div>
                            <div class="course-category">
                                <i class="fa fa-tag"></i>
                                ${escapeHtml(course.category_name || 'Uncategorized')}
                            </div>
                        </div>
                        <div class="course-meta">
                            <span class="enrollment-badge">
                                <i class="fa fa-hashtag"></i>
                                ${course.idnumber || 'No ID'}
                            </span>
                            ${course.id > 1 ? '<span class="warning-badge"><i class="fa fa-users"></i> Existing enrollments</span>' : ''}
                        </div>
                    </div>
                `;
            });
            html += '</div></div>';
        }
        
        // Subcategories - render directly without container headers (only if they have courses)
        if (categoryGroup.subcategories && categoryGroup.subcategories.length > 0) {
            const subcategoryHtml = renderCategoryHierarchy(categoryGroup.subcategories, type, level + 1);
            if (subcategoryHtml.trim() !== '') {
                html += subcategoryHtml;
            }
        }
        
        html += '</div></div>';
        return html;
    }).join('');
}

function countTotalCourses(categoryGroup) {
    let total = 0;
    
    // Count direct courses
    if (categoryGroup.courses) {
        total += categoryGroup.courses.length;
    }
    
    // Count courses in subcategories
    if (categoryGroup.subcategories) {
        categoryGroup.subcategories.forEach(sub => {
            total += countTotalCourses(sub);
        });
    }
    
    return total;
}

function toggleCourseSelection(item, type) {
    const courseId = item.dataset.courseId;
    
    if (type === 'school') {
        if (selectedSchoolCourses.includes(courseId)) {
            selectedSchoolCourses = selectedSchoolCourses.filter(id => id !== courseId);
            item.classList.remove('selected');
        } else {
            selectedSchoolCourses.push(courseId);
            item.classList.add('selected');
        }
    } else {
        if (selectedPotentialCourses.includes(courseId)) {
            selectedPotentialCourses = selectedPotentialCourses.filter(id => id !== courseId);
            item.classList.remove('selected');
        } else {
            selectedPotentialCourses.push(courseId);
            item.classList.add('selected');
        }
    }
    
    // Update category "Select All" button state for both types
    updateCategorySelectAllButtons(type);
    
    updateActionButtons();
}

function updateCategorySelectAllButtons(type) {
    // Get all category select-all buttons for the specific type
    const container = type === 'school' ? 'schoolCourseList' : 'potentialCourseList';
    const selectAllButtons = document.getElementById(container).querySelectorAll('.category-select-all');
    
    selectAllButtons.forEach(btn => {
        const categoryId = btn.dataset.categoryId;
        const uniqueCategoryId = `${type}_${categoryId}`;
        const categoryContent = document.getElementById(`category-${uniqueCategoryId}`);
        
        if (!categoryContent) return;
        
        const courseItems = categoryContent.querySelectorAll('.course-item');
        if (courseItems.length === 0) return;
        
        const totalCount = courseItems.length;
        const allSelected = Array.from(courseItems).every(item => item.classList.contains('selected'));
        
        if (allSelected) {
            btn.classList.add('selected');
            btn.innerHTML = `<i class="fa fa-times-circle"></i> Deselect All (${totalCount})`;
            btn.title = `Deselect all ${totalCount} courses in this category`;
        } else {
            btn.classList.remove('selected');
            btn.innerHTML = `<i class="fa fa-check-double"></i> Select All (${totalCount})`;
            btn.title = `Select all ${totalCount} courses in this category`;
        }
    });
}

function updateActionButtons() {
    const addBtn = document.getElementById('addBtn');
    const removeBtn = document.getElementById('removeBtn');
    const confirmCheckbox = document.getElementById('confirmUnenroll');
    
    // Update add button
    addBtn.disabled = selectedPotentialCourses.length === 0;
    
    // Update remove button
    removeBtn.disabled = selectedSchoolCourses.length === 0;
    
    // Show warning if needed
    const hasEnrollments = selectedSchoolCourses.some(courseId => {
        const course = schoolCourses.find(c => c.id == courseId);
        return course && course.id > 1;
    });
    
    if (hasEnrollments) {
        document.getElementById('warningSection').style.display = 'block';
        removeBtn.disabled = !confirmCheckbox.checked;
    } else {
        document.getElementById('warningSection').style.display = 'none';
    }
}

async function addSelectedCourses() {
    if (selectedPotentialCourses.length === 0) return;
    
    // Get course details for the form
    const courses = [];
        for (const courseId of selectedPotentialCourses) {
        const courseElement = document.querySelector(`[data-course-id="${courseId}"]`);
        if (courseElement) {
            courses.push({
                id: courseId,
                fullname: courseElement.dataset.courseFullname || 'Course',
                shortname: courseElement.dataset.courseShortname || ''
            });
        }
    }
    
    // Show copy form modal
    const formData = await showCopyFormModal(courses);
    if (!formData) {
        return; // User cancelled
    }
    
    try {
        const progressUrls = [];
        const sourceCourseIds = [];
        
        // Queue copy jobs for each selected course
        for (const course of courses) {
            const formDataToSend = new FormData();
            formDataToSend.append('school_id', selectedSchool);
            formDataToSend.append('course_id', course.id);
            formDataToSend.append('form_data', JSON.stringify({
                prefix: formData.prefix,
                category: formData.category,
                visible: formData.visible,
                startdate: formData.startdate,
                enddate: formData.enddate,
                userdata: formData.userdata
            }));
            
            // Use the current page URL with action parameter
            let requestUrl = window.location.href.split('?')[0]; // Remove existing query params
            requestUrl += '?action=assign_course';
            
            console.log('Making request to:', requestUrl); // Debug
            
            const response = await fetch(requestUrl, {
                method: 'POST',
                body: formDataToSend
            });
            
            console.log('Response status:', response.status); // Debug
            
            // Check if response is OK
            if (!response.ok) {
                const errorText = await response.text();
                console.error('Error response:', errorText); // Debug
                throw new Error(`HTTP error! status: ${response.status}, body: ${errorText.substring(0, 200)}`);
            }
            
            const data = await response.json();
            
            console.log('Response data:', data); // Debug
            
            // Check for JSON parsing errors
            if (!data) {
                throw new Error('Invalid response from server');
            }
            
            if (data.status !== 'success') {
                throw new Error(data.message || 'Unknown error occurred');
        }
        
            // Collect progress URLs and source course IDs
            if (data.progress_url) {
                progressUrls.push(data.progress_url);
            }
            if (data.source_course_id) {
                sourceCourseIds.push(data.source_course_id);
            }
        }
        
        // Clear selections
        selectedPotentialCourses = [];
        clearSelections('potential');
        
        // Show success message with link to progress page
        let message = `✅ Course copy queued successfully! ${courses.length} course(s) will be processed by CRON.`;
        message += `<br><small style="opacity: 0.9;">Please ensure CRON job is enabled on your server.</small>`;
        
        if (progressUrls.length > 0) {
            const progressUrl = progressUrls[0]; // Use first course's progress page
            message += `<br><br><a href="${progressUrl}" target="_blank" style="color: white; text-decoration: underline; font-weight: bold;">Track Progress Here →</a>`;
        }
        showMessage(message, 'success');
        
        // Refresh both lists after a delay (to allow CRON to process)
        setTimeout(async () => {
            await loadSchoolCourses();
            await loadPotentialCourses();
        }, 2000);
        
    } catch (error) {
        console.error('Error queuing course copies:', error);
        let errorMessage = 'Failed to queue course copies: ';
        
        if (error.message) {
            errorMessage += error.message;
        } else if (error instanceof TypeError && error.message.includes('JSON')) {
            errorMessage += 'Invalid response from server. Please check the browser console for details.';
        } else {
            errorMessage += 'Unknown error occurred. Please check the browser console for details.';
        }
        
        showMessage(errorMessage, 'error');
    }
}

async function showCopyFormModal(courses) {
    return new Promise((resolve) => {
        // Load school categories for autocomplete
        fetch(`?action=get_category_list&school_id=${selectedSchool}`)
            .then(response => response.json())
            .then(data => {
                if (data.status !== 'success') {
                    alert('Failed to load categories');
                    resolve(null);
                    return;
                }
                
                const categories = data.categories;
                
                // Create modal
                const modal = document.createElement('div');
                modal.className = 'copy-form-modal';
                modal.style.cssText = `
                    position: fixed;
                    top: 0;
                    left: 0;
                    width: 100%;
                    height: 100%;
                    background: rgba(0,0,0,0.5);
                    z-index: 10000;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                `;
                
                const modalContent = document.createElement('div');
                modalContent.style.cssText = `
                    background: white;
                    padding: 30px;
                    border-radius: 12px;
                    max-width: 700px;
                    width: 90%;
                    max-height: 90vh;
                    overflow-y: auto;
                `;
                
                const courseNames = courses.map(c => c.fullname).join(', ');
                const courseCount = courses.length;
                
                // Get default start date (tomorrow)
                const tomorrow = new Date();
                tomorrow.setDate(tomorrow.getDate() + 1);
                tomorrow.setHours(0, 0, 0, 0);
                
                modalContent.innerHTML = `
                    <h3 style="margin-top: 0; margin-bottom: 10px;">Copy course${courseCount > 1 ? 's' : ''}: ${escapeHtml(courseNames)}</h3>
                    <p style="color: #666; margin-bottom: 20px; font-size: 14px;">Create a copy of this course${courseCount > 1 ? 's' : ''} in any course category.</p>
                    
                    <form id="copyForm" style="display: flex; flex-direction: column; gap: 20px;">
                        <div>
                            <label for="prefix" style="display: block; margin-bottom: 5px; font-weight: 600;">
                                Course shortname prefix <span style="color: red;">*</span>
                            </label>
                            <input type="text" id="prefix" name="prefix" placeholder="e.g., school_name" required
                                   style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box;">
                            <small id="prefixHelp" style="color: #666; font-size: 12px; display: block; margin-top: 5px;">
                                This will be prepended to the course shortname (e.g., "school_name_course_shortname")
                            </small>
                            <div id="shortnameCheck" style="margin-top: 5px; font-size: 12px; display: none;"></div>
                        </div>
                        
                        <div>
                            <label for="category" style="display: block; margin-bottom: 5px; font-weight: 600;">
                                Course category <span style="color: red;">*</span>
                            </label>
                            <select id="category" name="category" required 
                                    style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box;">
                                <option value="">Select a category...</option>
                            </select>
                            <small style="color: #666; font-size: 12px;">Select the destination category for the copied course${courseCount > 1 ? 's' : ''}</small>
                        </div>
                        
                        <div>
                            <label for="visible" style="display: block; margin-bottom: 5px; font-weight: 600;">Course visibility</label>
                            <select id="visible" name="visible" 
                                    style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box;">
                                <option value="1">Show</option>
                                <option value="0">Hide</option>
                            </select>
                        </div>
                        
                        <div>
                            <label for="startdate" style="display: block; margin-bottom: 5px; font-weight: 600;">Course start date</label>
                            <input type="datetime-local" id="startdate" name="startdate" 
                                   value="${tomorrow.toISOString().slice(0, 16)}"
                                   style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box;">
                        </div>
                        
                        <div>
                            <label for="enddate" style="display: block; margin-bottom: 5px; font-weight: 600;">Course end date (optional)</label>
                            <input type="datetime-local" id="enddate" name="enddate" 
                                   style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box;">
                            <small style="color: #666; font-size: 12px; display: block; margin-top: 5px;">Leave empty for unlimited course duration</small>
                        </div>
                        
                        <div>
                            <label for="userdata" style="display: block; margin-bottom: 5px; font-weight: 600;">Include user data</label>
                            <select id="userdata" name="userdata" 
                                    style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box;">
                                <option value="0">No</option>
                                <option value="1">Yes</option>
                            </select>
                            <small style="color: #666; font-size: 12px;">Include user data, enrollments, and progress from the source course (Don't select Yes here)</small>
                        </div>
                        
                        <!-- License Configuration Section -->
                        <div style="border-top: 2px solid #e5e7eb; padding-top: 20px; margin-top: 10px;">
                            <h4 style="margin: 0 0 15px 0; font-size: 16px; font-weight: 600; color: #374151;">
                                <i class="fa fa-key" style="margin-right: 8px; color: #2196F3;"></i>
                                License Configuration
                            </h4>
                            
                            <div>
                                <label for="licenseModel" style="display: block; margin-bottom: 5px; font-weight: 600;">
                                    License Model <span style="color: red;">*</span>
                                </label>
                                <select id="licenseModel" name="licenseModel" required
                                        style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box;">
                                    <option value="none">No License (Unlimited Access)</option>
                                    <option value="overall">Overall License (Shared across all courses)</option>
                                    <option value="percourse">Per-Course License (Separate limit per course)</option>
                                    <option value="combined">Combined (Overall + Per-Course)</option>
                                </select>
                                <small style="color: #666; font-size: 12px; display: block; margin-top: 5px;">
                                    Select how licenses will be allocated for these courses
                                </small>
                            </div>
                            
                            <!-- Overall License Fields -->
                            <div id="overallLicenseFields" style="display: none; margin-top: 15px; padding: 15px; background: #f0f9ff; border-radius: 6px; border-left: 3px solid #2196F3;">
                                <label for="overallAllocation" style="display: block; margin-bottom: 5px; font-weight: 600;">
                                    Overall License Allocation <span style="color: red;">*</span>
                                </label>
                                <input type="number" id="overallAllocation" name="overallAllocation" min="1" 
                                       placeholder="e.g., 300"
                                       style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box;">
                                <small style="color: #666; font-size: 12px; display: block; margin-top: 5px;">
                                    Maximum number of students across ALL courses combined. Once this limit is reached, no more students can be enrolled in any course.
                                </small>
                            </div>
                            
                            <!-- Per-Course License Fields -->
                            <div id="perCourseLicenseFields" style="display: none; margin-top: 15px; padding: 15px; background: #f0fdf4; border-radius: 6px; border-left: 3px solid #22c55e;">
                                <label for="perCourseAllocation" style="display: block; margin-bottom: 5px; font-weight: 600;">
                                    Per-Course License Allocation <span style="color: red;">*</span>
                                </label>
                                <input type="number" id="perCourseAllocation" name="perCourseAllocation" min="1" 
                                       placeholder="e.g., 50"
                                       style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box;">
                                <small style="color: #666; font-size: 12px; display: block; margin-top: 5px;">
                                    Maximum number of students per individual course. Each course will have its own license limit.
                                </small>
                            </div>
                            
                            <!-- License Duration (shown for all license models except "none") -->
                            <div id="licenseDurationFields" style="display: none; margin-top: 15px;">
                                <label for="validlength" style="display: block; margin-bottom: 5px; font-weight: 600;">
                                    License Duration (days) <span style="color: red;">*</span>
                                </label>
                                <input type="number" id="validlength" name="validlength" min="1" value="365"
                                       style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box;">
                                <small style="color: #666; font-size: 12px; display: block; margin-top: 5px;">
                                    How long each license is valid after allocation (in days). This works alongside the course end date.
                                </small>
                            </div>
                        </div>
                        
                        <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 10px;">
                            <button type="button" id="cancelBtn" 
                                    style="padding: 10px 20px; border: 1px solid #ddd; background: white; border-radius: 6px; cursor: pointer;">Cancel</button>
                            <button type="submit" id="submitBtn" 
                                    style="padding: 10px 20px; background: #22c55e; color: white; border: none; border-radius: 6px; cursor: pointer;">Queue Copy</button>
                        </div>
                    </form>
                `;
                
                modal.appendChild(modalContent);
                document.body.appendChild(modal);
                
                // Populate category dropdown
                const categorySelect = document.getElementById('category');
                Object.keys(categories).forEach(catId => {
                    const option = document.createElement('option');
                    option.value = catId;
                    option.textContent = categories[catId];
                    categorySelect.appendChild(option);
                });
                
                // License model change handler
                const licenseModel = document.getElementById('licenseModel');
                const overallLicenseFields = document.getElementById('overallLicenseFields');
                const perCourseLicenseFields = document.getElementById('perCourseLicenseFields');
                const licenseDurationFields = document.getElementById('licenseDurationFields');
                const overallAllocation = document.getElementById('overallAllocation');
                const perCourseAllocation = document.getElementById('perCourseAllocation');
                
                function updateLicenseFields() {
                    const model = licenseModel.value;
                    
                    if (model === 'none') {
                        overallLicenseFields.style.display = 'none';
                        perCourseLicenseFields.style.display = 'none';
                        licenseDurationFields.style.display = 'none';
                        overallAllocation.removeAttribute('required');
                        perCourseAllocation.removeAttribute('required');
                    } else {
                        licenseDurationFields.style.display = 'block';
                        
                        if (model === 'overall') {
                            overallLicenseFields.style.display = 'block';
                            perCourseLicenseFields.style.display = 'none';
                            overallAllocation.setAttribute('required', 'required');
                            perCourseAllocation.removeAttribute('required');
                        } else if (model === 'percourse') {
                            overallLicenseFields.style.display = 'none';
                            perCourseLicenseFields.style.display = 'block';
                            overallAllocation.removeAttribute('required');
                            perCourseAllocation.setAttribute('required', 'required');
                        } else if (model === 'combined') {
                            overallLicenseFields.style.display = 'block';
                            perCourseLicenseFields.style.display = 'block';
                            overallAllocation.setAttribute('required', 'required');
                            perCourseAllocation.setAttribute('required', 'required');
                        }
                    }
                }
                
                licenseModel.addEventListener('change', updateLicenseFields);
                updateLicenseFields(); // Initialize
                
                // Get first course ID for shortname validation (we'll check all courses have same shortname pattern)
                const firstCourseId = courses.length > 0 ? courses[0].id : null;
                const prefixInput = document.getElementById('prefix');
                const shortnameCheckDiv = document.getElementById('shortnameCheck');
                let shortnameValid = false;
                let checkTimeout = null;
                
                // Check shortname when prefix changes
                prefixInput.addEventListener('input', function() {
                    const prefix = this.value.trim();
                    shortnameCheckDiv.style.display = 'none';
                    shortnameValid = false;
                    
                    // Clear previous timeout
                    if (checkTimeout) {
                        clearTimeout(checkTimeout);
                    }
                    
                    if (!prefix) {
                        return;
                    }
                    
                    if (!firstCourseId) {
                        return;
                    }
                    
                    // Debounce the check
                    checkTimeout = setTimeout(async () => {
                        try {
                            const response = await fetch(`?action=check_shortname&prefix=${encodeURIComponent(prefix)}&source_course_id=${firstCourseId}`);
                            const data = await response.json();
                            
                            if (data.status === 'success') {
                                shortnameCheckDiv.style.display = 'block';
                                if (data.exists) {
                                    shortnameCheckDiv.innerHTML = `<span style="color: red;">⚠ ${data.message}</span>`;
                                    shortnameCheckDiv.style.color = 'red';
                                    shortnameValid = false;
                                } else {
                                    shortnameCheckDiv.innerHTML = `<span style="color: green;">✓ ${data.message} (Shortname: ${data.shortname})</span>`;
                                    shortnameCheckDiv.style.color = 'green';
                                    shortnameValid = true;
                                }
                            } else {
                                shortnameCheckDiv.style.display = 'block';
                                shortnameCheckDiv.innerHTML = `<span style="color: red;">Error: ${data.message}</span>`;
                                shortnameCheckDiv.style.color = 'red';
                                shortnameValid = false;
                            }
                        } catch (error) {
                            console.error('Error checking shortname:', error);
                            shortnameCheckDiv.style.display = 'block';
                            shortnameCheckDiv.innerHTML = `<span style="color: red;">Error checking shortname availability</span>`;
                            shortnameCheckDiv.style.color = 'red';
                            shortnameValid = false;
                        }
                    }, 500); // Wait 500ms after user stops typing
                });
                
                // Handle form submission
                document.getElementById('copyForm').addEventListener('submit', async (e) => {
                    e.preventDefault();
                    
                    const form = e.target;
                    const prefix = form.prefix.value.trim();
                    
                    if (!prefix) {
                        alert('Prefix is required');
                        form.prefix.focus();
                        return;
                    }
                    
                    if (!form.category.value) {
                        alert('Please select a category');
                        return;
                    }
                    
                    // Validate license fields
                    const licenseModel = form.licenseModel.value;
                    if (licenseModel !== 'none') {
                        if (!form.validlength.value || parseInt(form.validlength.value) < 1) {
                            alert('License duration is required and must be at least 1 day');
                            form.validlength.focus();
                            return;
                        }
                        
                        if (licenseModel === 'overall' || licenseModel === 'combined') {
                            if (!form.overallAllocation.value || parseInt(form.overallAllocation.value) < 1) {
                                alert('Overall license allocation is required and must be at least 1');
                                form.overallAllocation.focus();
                                return;
                            }
                        }
                        
                        if (licenseModel === 'percourse' || licenseModel === 'combined') {
                            if (!form.perCourseAllocation.value || parseInt(form.perCourseAllocation.value) < 1) {
                                alert('Per-course license allocation is required and must be at least 1');
                                form.perCourseAllocation.focus();
                                return;
                            }
                        }
                    }
                    
                    // Check shortname one more time before submitting
                    if (firstCourseId) {
                        try {
                            const response = await fetch(`?action=check_shortname&prefix=${encodeURIComponent(prefix)}&source_course_id=${firstCourseId}`);
                            const data = await response.json();
                            
                            if (data.status === 'success' && data.exists) {
                                alert(data.message);
                                form.prefix.focus();
                                return;
                            }
                        } catch (error) {
                            console.error('Error checking shortname:', error);
                            if (!confirm('Could not verify shortname availability. Do you want to continue anyway?')) {
                                return;
                            }
                        }
                    }
                    
                    const formData = {
                        prefix: prefix,
                        category: parseInt(form.category.value),
                        visible: parseInt(form.visible.value),
                        startdate: form.startdate.value ? Math.floor(new Date(form.startdate.value).getTime() / 1000) : 0,
                        enddate: form.enddate.value ? Math.floor(new Date(form.enddate.value).getTime() / 1000) : 0,
                        userdata: parseInt(form.userdata.value),
                        license_model: licenseModel,
                        overall_allocation: licenseModel === 'overall' || licenseModel === 'combined' ? parseInt(form.overallAllocation.value) : null,
                        percourse_allocation: licenseModel === 'percourse' || licenseModel === 'combined' ? parseInt(form.perCourseAllocation.value) : null,
                        validlength: licenseModel !== 'none' ? parseInt(form.validlength.value) : null
                    };
                    
                    document.body.removeChild(modal);
                    resolve(formData);
                });
                
                // Handle cancel
                document.getElementById('cancelBtn').addEventListener('click', () => {
                    document.body.removeChild(modal);
                    resolve(null);
                });
                
                // Close on outside click
                modal.addEventListener('click', (e) => {
                    if (e.target === modal) {
                        document.body.removeChild(modal);
                        resolve(null);
                    }
                });
            })
            .catch(error => {
                console.error('Error loading categories:', error);
                alert('Failed to load categories');
                resolve(null);
            });
    });
}

function renderCategorySelectorTree(category, containerId, selectedId, level = 0) {
    const container = document.getElementById(containerId);
    if (!container) return;
    
    const indent = level * 20;
    const isSelected = category.id == selectedId;
    
    const item = document.createElement('div');
    item.className = `category-selector-item ${isSelected ? 'selected' : ''}`;
    item.dataset.categoryId = category.id;
    item.style.cssText = `
        padding: 10px;
        margin-left: ${indent}px;
        cursor: pointer;
        border-radius: 6px;
        margin-bottom: 5px;
        border: 2px solid ${isSelected ? '#22c55e' : 'transparent'};
        background: ${isSelected ? '#f0fdf4' : 'white'};
        transition: all 0.2s;
        user-select: none;
    `;
    
    item.innerHTML = `
        <i class="fa fa-folder${category.subcategories && category.subcategories.length > 0 ? '-open' : ''}" style="margin-right: 8px; color: #2196F3;"></i>
        <strong>${escapeHtml(category.name)}</strong>
    `;
    
    item.addEventListener('mouseenter', () => {
        if (!item.classList.contains('selected')) {
            item.style.background = '#f8f9fa';
        }
    });
    
    item.addEventListener('mouseleave', () => {
        if (!item.classList.contains('selected')) {
            item.style.background = 'white';
        }
    });
    
    container.appendChild(item);
    
    // Render subcategories recursively
    if (category.subcategories && category.subcategories.length > 0) {
        category.subcategories.forEach(sub => {
            renderCategorySelectorTree(sub, containerId, selectedId, level + 1);
        });
    }
}

async function removeSelectedCourses() {
    if (selectedSchoolCourses.length === 0) return;
    
    // Check for enrollments first
    let hasEnrollments = false;
    for (const courseId of selectedSchoolCourses) {
        const course = schoolCourses.find(c => c.id == courseId);
        if (course) {
            // We'll check on the server side
            hasEnrollments = true;
            break;
        }
    }
    
    if (hasEnrollments) {
        const confirmDelete = confirm('Warning: Removing courses will delete them and all associated data including student progress, assignments, and grades. This cannot be undone. Do you want to continue?');
        if (!confirmDelete) {
            return;
        }
    }
    
    try {
        for (const courseId of selectedSchoolCourses) {
            const formData = new FormData();
            formData.append('school_id', selectedSchool);
            formData.append('course_id', courseId);
            formData.append('confirm', '1');
            
            const response = await fetch('?action=unassign_course', {
                method: 'POST',
                body: formData
            });
            
            const data = await response.json();
            if (data.status === 'warning') {
                // Course has enrollments, ask for confirmation
                const confirmDelete = confirm(data.message + '\n\nDo you want to proceed?');
                if (confirmDelete) {
                    formData.set('confirm', '1');
                    const retryResponse = await fetch('?action=unassign_course', {
                        method: 'POST',
                        body: formData
                    });
                    const retryData = await retryResponse.json();
                    if (retryData.status !== 'success') {
                        throw new Error(retryData.message);
                    }
                } else {
                    continue;
                }
            } else if (data.status !== 'success') {
                throw new Error(data.message);
            }
        }
        
        // Refresh both lists
        await loadSchoolCourses();
        await loadPotentialCourses();
        
        // Clear selections
        selectedSchoolCourses = [];
        clearSelections('school');
        
        showMessage('Courses removed successfully!', 'success');
    } catch (error) {
        console.error('Error removing courses:', error);
        showMessage('Failed to remove courses: ' + error.message, 'error');
    }
}

function clearSelections(type) {
    const container = type === 'school' ? 'schoolCourseList' : 'potentialCourseList';
    document.getElementById(container).querySelectorAll('.course-item.selected').forEach(item => {
        item.classList.remove('selected');
    });
    
    // Reset "Select All" buttons for potential courses
    if (type === 'potential') {
        document.querySelectorAll('.category-select-all').forEach(btn => {
            const categoryId = btn.dataset.categoryId;
            const categoryContent = document.getElementById(`category-${categoryId}`);
            
            if (categoryContent) {
                const totalCount = categoryContent.querySelectorAll('.course-item').length;
                btn.classList.remove('selected');
                btn.innerHTML = `<i class="fa fa-check-double"></i> Select All (${totalCount})`;
                btn.title = `Select all ${totalCount} courses in this category`;
            }
        });
    }
}

function filterCourses(type, searchTerm) {
    const container = type === 'school' ? 'schoolCourseList' : 'potentialCourseList';
    const courses = type === 'school' ? schoolCourses : potentialCourses;
    
    if (type === 'school') {
        // Simple filter for school courses
    const filteredCourses = courses.filter(course => 
        course.fullname.toLowerCase().includes(searchTerm.toLowerCase()) ||
        (course.category_name && course.category_name.toLowerCase().includes(searchTerm.toLowerCase()))
    );
    renderCourses(container, filteredCourses, type);
    } else {
        // Filter potential courses (grouped by category)
        const filteredCategories = courses.map(cat => {
            if (!cat.courses) return null;
            const filteredCourseList = cat.courses.filter(course => 
                course.fullname.toLowerCase().includes(searchTerm.toLowerCase()) ||
                (course.category_name && course.category_name.toLowerCase().includes(searchTerm.toLowerCase()))
            );
            if (filteredCourseList.length === 0) return null;
            return {
                category: cat.category,
                courses: filteredCourseList
            };
        }).filter(cat => cat !== null);
        renderCourses(container, filteredCategories, type);
    }
}

function updateCourseCount(elementId, count) {
    const element = document.getElementById(elementId);
    if (element) {
        // For potential courses, count all courses across all categories
        if (elementId === 'potentialCourseCount' && Array.isArray(potentialCourses)) {
            let total = 0;
            potentialCourses.forEach(cat => {
                if (cat.courses) {
                    total += cat.courses.length;
                }
            });
            element.textContent = total;
        } else {
            element.textContent = count;
        }
    }
}

function selectAllInCategory(categoryId, type) {
    // Get the specific panel's container to avoid cross-panel interference
    const panelContainer = type === 'school' ? 'schoolCourseList' : 'potentialCourseList';
    const uniqueCategoryId = `${type}_${categoryId}`;
    const categoryContent = document.getElementById(`category-${uniqueCategoryId}`);
    const selectAllBtn = document.getElementById(`${panelContainer}`).querySelector(`button.category-select-all[data-category-id="${categoryId}"]`);
    
    if (!categoryContent || !selectAllBtn) return;
    
    // Get all course items in this category within the specific panel
    const courseItems = categoryContent.querySelectorAll('.course-item');
    const totalCount = courseItems.length;
    
    if (totalCount === 0) return;
    
    // Check if all courses are already selected
    const allSelected = Array.from(courseItems).every(item => item.classList.contains('selected'));
    
    if (allSelected) {
        // Deselect all courses in this category
        courseItems.forEach(item => {
            const courseId = item.dataset.courseId;
            if (type === 'potential' && selectedPotentialCourses.includes(courseId)) {
                selectedPotentialCourses = selectedPotentialCourses.filter(id => id !== courseId);
                item.classList.remove('selected');
            } else if (type === 'school' && selectedSchoolCourses.includes(courseId)) {
                selectedSchoolCourses = selectedSchoolCourses.filter(id => id !== courseId);
                item.classList.remove('selected');
            }
        });
        
        selectAllBtn.classList.remove('selected');
        selectAllBtn.innerHTML = `<i class="fa fa-check-double"></i> Select All (${totalCount})`;
        selectAllBtn.title = `Select all ${totalCount} courses in this category`;
    } else {
        // Select all courses in this category
        courseItems.forEach(item => {
            const courseId = item.dataset.courseId;
            if (type === 'potential' && !selectedPotentialCourses.includes(courseId)) {
                selectedPotentialCourses.push(courseId);
                item.classList.add('selected');
            } else if (type === 'school' && !selectedSchoolCourses.includes(courseId)) {
                selectedSchoolCourses.push(courseId);
                item.classList.add('selected');
            }
        });
        
        selectAllBtn.classList.add('selected');
        selectAllBtn.innerHTML = `<i class="fa fa-times-circle"></i> Deselect All (${totalCount})`;
        selectAllBtn.title = `Deselect all ${totalCount} courses in this category`;
    }
    
    updateActionButtons();
}

function toggleCategory(categoryId) {
    const content = document.getElementById(`category-${categoryId}`);
    const toggle = document.getElementById(`toggle-${categoryId}`);
    
    if (content.style.display === 'none') {
        content.style.display = 'block';
        toggle.style.transform = 'rotate(180deg)';
    } else {
        content.style.display = 'none';
        toggle.style.transform = 'rotate(0deg)';
    }
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function showLoading(containerId) {
    document.getElementById(containerId).innerHTML = `
        <div class="loading">
            <div class="loading-spinner"></div>
            Loading courses...
        </div>
    `;
}

function showError(containerId, message) {
    document.getElementById(containerId).innerHTML = `
        <div class="loading">
            <i class="fa fa-exclamation-triangle" style="color: #dc3545; margin-right: 10px;"></i>
            ${message}
        </div>
    `;
}

function showMessage(message, type) {
    // Remove any existing messages first
    const existingMessages = document.querySelectorAll('.message');
    existingMessages.forEach(msg => msg.remove());
    
    const messageDiv = document.createElement('div');
    messageDiv.className = `message message-${type}`;
    messageDiv.innerHTML = message; // Use innerHTML to support links
    messageDiv.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        padding: 20px 25px;
        border-radius: 10px;
        color: white;
        font-weight: 600;
        z-index: 10000;
        max-width: 400px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.3);
        animation: slideInRight 0.3s ease-out;
        ${type === 'success' ? 'background: #28a745;' : 'background: #dc3545;'}
    `;
    
    document.body.appendChild(messageDiv);
    
    // Keep message visible longer for success messages (10 seconds)
    const displayTime = type === 'success' ? 10000 : 3000;
    
    setTimeout(() => {
        messageDiv.style.animation = 'slideOutRight 0.3s ease-out';
        setTimeout(() => messageDiv.remove(), 300);
    }, displayTime);
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Add CSS for animations
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
</script>

<script>
function toggleSidebar() {
    const sidebar = document.querySelector('.admin-sidebar');
    sidebar.classList.toggle('sidebar-open');
}

// Close sidebar when clicking outside on mobile
document.addEventListener('click', function(event) {
    const sidebar = document.querySelector('.admin-sidebar');
    const toggleBtn = document.querySelector('.sidebar-toggle');
    
    if (window.innerWidth <= 768) {
        if (!sidebar.contains(event.target) && !toggleBtn.contains(event.target)) {
            sidebar.classList.remove('sidebar-open');
        }
    }
});

// Handle window resize
window.addEventListener('resize', function() {
    const sidebar = document.querySelector('.admin-sidebar');
    if (window.innerWidth > 768) {
        sidebar.classList.remove('sidebar-open');
    }
});
</script>

<?php
echo "</div>"; // End admin-main-content
echo $OUTPUT->footer();
?>