<?php
/**
 * Course Management Page for School Managers
 * Shows all courses available for the specific school in card format
 */

require_once('../../config.php');
require_once(__DIR__ . '/lang_init.php'); // Apply user's selected language
require_login();

global $USER, $DB, $CFG, $OUTPUT;

// Check if user is a company manager
$company_info = $DB->get_record_sql(
    "SELECT c.* 
     FROM {company} c 
     JOIN {company_users} cu ON c.id = cu.companyid 
     WHERE cu.userid = ? AND cu.managertype = 1",
    [$USER->id]
);

if (!$company_info) {
    // Try alternative query to find company info
    $company_info = $DB->get_record_sql(
        "SELECT c.* 
         FROM {company} c 
         JOIN {company_users} cu ON c.id = cu.companyid 
         WHERE cu.userid = ?",
        [$USER->id]
    );
}

if (!$company_info) {
    throw new moodle_exception('nocompany', 'local_iomad');
}

// Get courses available for this company - ONLY courses explicitly assigned to this company
// Enrollment count excludes School Managers (companymanager role)
$courses = $DB->get_records_sql(
    "SELECT DISTINCT c.*, cc.name as category_name,
            (SELECT COUNT(DISTINCT ue.userid) 
             FROM {user_enrolments} ue 
             JOIN {enrol} e ON ue.enrolid = e.id 
             JOIN {company_users} cu ON ue.userid = cu.userid
             JOIN {user} u ON u.id = ue.userid AND u.deleted = 0 AND u.suspended = 0
             WHERE e.courseid = c.id 
             AND cu.companyid = ?
             AND ue.status = 0
             AND NOT EXISTS (
                 SELECT 1 
                 FROM {role_assignments} ra2
                 JOIN {role} r2 ON r2.id = ra2.roleid
                 WHERE ra2.userid = u.id 
                 AND r2.shortname = 'companymanager'
             )) as enrollment_count
     FROM {course} c
     LEFT JOIN {course_categories} cc ON c.category = cc.id
     INNER JOIN {company_course} comp_c ON c.id = comp_c.courseid
     WHERE c.visible = 1 
     AND c.id > 1 
     AND comp_c.companyid = ?
     ORDER BY c.fullname ASC",
    [$company_info->id, $company_info->id]
);

// Get course statistics - ONLY for courses explicitly assigned to this company
// Total enrollments excludes School Managers (companymanager role)
$total_courses = count($courses);
$total_enrollments = $DB->count_records_sql(
    "SELECT COUNT(DISTINCT ue.userid) 
     FROM {user_enrolments} ue 
     JOIN {enrol} e ON ue.enrolid = e.id 
     JOIN {course} c ON e.courseid = c.id 
     INNER JOIN {company_course} comp_c ON c.id = comp_c.courseid
     JOIN {company_users} cu ON ue.userid = cu.userid
     JOIN {user} u ON u.id = ue.userid AND u.deleted = 0 AND u.suspended = 0
     WHERE c.visible = 1 
     AND c.id > 1 
     AND comp_c.companyid = ?
     AND cu.companyid = ?
     AND ue.status = 0
     AND NOT EXISTS (
         SELECT 1 
         FROM {role_assignments} ra2
         JOIN {role} r2 ON r2.id = ra2.roleid
         WHERE ra2.userid = u.id 
         AND r2.shortname = 'companymanager'
     )",
    [$company_info->id, $company_info->id]
);

// Debug logging for enrollment counts
error_log("========================================");
error_log("Course Management - Company ID: {$company_info->id}, Company Name: {$company_info->name}");
error_log("Course Management - Total Courses: {$total_courses}");
error_log("Course Management - Total Enrollments (excluding school managers): {$total_enrollments}");
error_log("Course Enrollment Counts (excluding school managers):");
foreach ($courses as $course) {
    error_log("  - {$course->fullname}: {$course->enrollment_count} enrollments");
}
error_log("========================================");

$active_courses = $DB->count_records_sql(
    "SELECT COUNT(DISTINCT c.id)
     FROM {course} c
     INNER JOIN {company_course} comp_c ON c.id = comp_c.courseid
     WHERE c.visible = 1 
     AND c.id > 1 
     AND comp_c.companyid = ?
     AND c.startdate <= ?",
    [$company_info->id, time()]
);

$completed_courses = $DB->count_records_sql(
    "SELECT COUNT(DISTINCT c.id)
     FROM {course} c
     INNER JOIN {company_course} comp_c ON c.id = comp_c.courseid
     WHERE c.visible = 1 
     AND c.id > 1 
     AND comp_c.companyid = ?
     AND c.enddate > 0 
     AND c.enddate < ?",
    [$company_info->id, time()]
);

// -----------------------------
// Build filter metadata
// -----------------------------
$parent_filter_map = [];
$subcategory_filter_map = [];
$course_filter_map = [];
$book_type_filter_map = [];

// Course cover defaults and setup (same as view_all_courses.php)
$coursecoverdefaults = [
    'student_book' => $CFG->wwwroot . '/theme/remui_kids/CradsImg/Gemini_Generated_Image_96dybo96dybo96dy.png',
    'student_course' => $CFG->wwwroot . '/theme/remui_kids/CradsImg/Gemini_Generated_Image_hcwxdbhcwxdbhcwx.png',
    'teacher_resource' => $CFG->wwwroot . '/theme/remui_kids/CradsImg/Gemini_Generated_Image_7xb0pl7xb0pl7xb0.png',
    'worksheet_pack' => $CFG->wwwroot . '/theme/remui_kids/CradsImg/Gemini_Generated_Image_ciywx0ciywx0ciyw.png',
    'teacher_guide' => $CFG->wwwroot . '/theme/remui_kids/CradsImg/Gemini_Generated_Image_k3ktqnk3ktqnk3kt.png',
    'practice_book' => $CFG->wwwroot . '/theme/remui_kids/CradsImg/Gemini_Generated_Image_hz61skhz61skhz61.png',
    'teacher_book' => $CFG->wwwroot . '/theme/remui_kids/CradsImg/Gemini_Generated_Image_kmjtndkmjtndkmjt.png',
    'assessment_book' => $CFG->wwwroot . '/theme/remui_kids/CradsImg/Gemini_Generated_Image_86ksa986ksa986ks.png',
    'workbook' => $CFG->wwwroot . '/theme/remui_kids/CradsImg/Gemini_Generated_Image_86ksa986ksa986ks.png'
];
$coursecovercycle = array_values($coursecoverdefaults);
$coursecovercycle = array_unique(array_filter($coursecovercycle));
$course_cover_fallback_index = 0;
$coursecoverfallback = $CFG->wwwroot . '/theme/remui_kids/CradsImg/Gemini_Generated_Image_86ksa986ksa986ks.png';

if (!function_exists('theme_remui_kids_slugify')) {
    function theme_remui_kids_slugify(string $text): string {
        $text = strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);
        return trim($text, '-');
    }
}

if (!function_exists('theme_remui_kids_get_booktype_cover_overrides')) {
    function theme_remui_kids_get_booktype_cover_overrides(): array {
        static $overrides = null;
        if ($overrides !== null) {
            return $overrides;
        }
        global $CFG;
        $jsonpath = $CFG->dirroot . '/theme/remui_kids/CradsImg/booktype_covers.json';
        if (file_exists($jsonpath)) {
            $decoded = json_decode(file_get_contents($jsonpath), true);
            if (is_array($decoded)) {
                $overrides = $decoded;
                return $overrides;
            }
        }
        $overrides = [];
        return $overrides;
    }
}

if (!function_exists('theme_remui_kids_course_keyword_match')) {
    function theme_remui_kids_course_keyword_match(string $haystack, array $keywords): bool {
        $normalizedHaystack = preg_replace('/[^a-z0-9]+/', '', $haystack);
        foreach ($keywords as $keyword) {
            $keyword = strtolower(trim($keyword));
            if ($keyword === '') {
                continue;
            }
            if (strpos($haystack, $keyword) !== false) {
                return true;
            }
            if (strlen($keyword) <= 3 && preg_match('/\b' . preg_quote($keyword, '/') . '\b/', $haystack)) {
                return true;
            }
            $normalizedKeyword = preg_replace('/[^a-z0-9]+/', '', $keyword);
            if ($normalizedKeyword !== '' && strpos($normalizedHaystack, $normalizedKeyword) !== false) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('theme_remui_kids_extract_label_from_fullname')) {
    function theme_remui_kids_extract_label_from_fullname(string $fullname): string {
        $fullname = trim($fullname);
        if ($fullname === '') {
            return '';
        }
        $parts = preg_split('/\s*(?:-|–|—|:|\||•)\s*/u', $fullname);
        if (!empty($parts) && trim($parts[0]) !== '') {
            return trim($parts[0]);
        }
        return $fullname;
    }
}

if (!function_exists('theme_remui_kids_detect_course_book_type')) {
    function theme_remui_kids_detect_course_book_type(array $course): string {
        $fullname = $course['fullname'] ?? '';
        $shortname = $course['shortname'] ?? '';
        $haystack = strtolower($fullname . ' ' . $shortname);

        $bookTypeKeywords = [
            'Student Course' => ['student course', 'student-course', 'studentcourse', 'sc', 'student courses'],
            'Practice Book' => ['practice book', 'practice-book', 'practicebook', 'pb'],
            'Student Book' => ['student book', 'student-book', 'studentbook', 'sb', 'learner book', 'learner\'s book'],
            'Teacher Resource' => ['teacher resource', 'resource pack', 'resource book', 'tr'],
            'Teacher Book' => ['teacher book', 'teachers book', 'tb'],
            'Teacher Guide' => ['teacher guide', 'teachers guide', 'guide book', 'guidebook', 'tg'],
            'Worksheet Pack' => ['worksheet pack', 'worksheet', 'worksheets', 'activity pack', 'wp'],
            'Workbook' => ['workbook', 'work book', 'wb'],
            'Assessment Book' => ['assessment book', 'assessment pack', 'assessment', 'ab']
        ];

        foreach ($bookTypeKeywords as $label => $keywords) {
            if (theme_remui_kids_course_keyword_match($haystack, $keywords)) {
                return $label;
            }
        }

        $derivedLabel = theme_remui_kids_extract_label_from_fullname($fullname);
        if ($derivedLabel !== '') {
            $derivedLower = strtolower($derivedLabel);
            foreach ($bookTypeKeywords as $label => $keywords) {
            foreach ($keywords as $keyword) {
                    if (strtolower($keyword) === $derivedLower || strpos($derivedLower, strtolower($keyword)) !== false) {
                    return $label;
                }
            }
        }
            if (stripos($derivedLabel, 'student') !== false && stripos($derivedLabel, 'course') !== false) {
                return 'Student Course';
            }
            if (in_array($derivedLabel, array_keys($bookTypeKeywords))) {
                return $derivedLabel;
            }
        }

        if (!empty($shortname)) {
            $shortLower = strtolower($shortname);
            foreach ($bookTypeKeywords as $label => $keywords) {
                foreach ($keywords as $keyword) {
                    if (strpos($shortLower, strtolower($keyword)) !== false) {
                        return $label;
                    }
                }
            }
        }

        return '';
    }
}

if (!function_exists('theme_remui_kids_select_course_cover')) {
    function theme_remui_kids_select_course_cover(array $course, array $defaults, array $cycle, int &$index, ?string &$type = null) {
        static $dynamiccovermap = [];
        global $CFG;
        $overrides = theme_remui_kids_get_booktype_cover_overrides();

        $labelKeyMap = [
            'Student Book' => 'student_book',
            'Student Course' => 'student_course',
            'Teacher Resource' => 'teacher_resource',
            'Worksheet Pack' => 'worksheet_pack',
            'Teacher Guide' => 'teacher_guide',
            'Practice Book' => 'practice_book',
            'Teacher Book' => 'teacher_book',
            'Workbook' => 'workbook',
            'Assessment Book' => 'assessment_book'
        ];

        if (empty($type)) {
            $type = theme_remui_kids_detect_course_book_type($course);
        }

        $hasType = !empty($type);
        $slug = '';
        if ($hasType && function_exists('theme_remui_kids_slugify')) {
            $slug = theme_remui_kids_slugify($type);
        }

        if ($hasType) {
            if (isset($labelKeyMap[$type]) && isset($defaults[$labelKeyMap[$type]])) {
                return $defaults[$labelKeyMap[$type]];
            }

            $customcoverdir = $CFG->dirroot . '/theme/remui_kids/CradsImg';
            $customcoverurl = $CFG->wwwroot . '/theme/remui_kids/CradsImg';

            if (!empty($slug)) {
                if (isset($overrides[$slug])) {
                    $overridefile = $overrides[$slug];
                    if ($overridefile && file_exists($customcoverdir . '/' . $overridefile)) {
                        $cover = $customcoverurl . '/' . $overridefile;
                        $dynamiccovermap[$slug] = $cover;
                        return $cover;
                    }
                }

                $generatedCandidates = [
                    'Gemini_Generated_Image_' . $slug . '.png',
                    'booktype-' . $slug . '.png',
                    $slug . '.png'
                ];
                foreach ($generatedCandidates as $candidate) {
                    if (file_exists($customcoverdir . '/' . $candidate)) {
                        $cover = $customcoverurl . '/' . $candidate;
                        $dynamiccovermap[$slug] = $cover;
                        return $cover;
                    }
                }

                if (isset($dynamiccovermap[$slug])) {
                    return $dynamiccovermap[$slug];
                }
            }

            return '';
        }

        if (empty($cycle)) {
            $type = 'Student Book';
            return isset($defaults['student_book']) ? $defaults['student_book'] : '';
        }

        $cycleIndex = $index % count($cycle);
        $cover = $cycle[$cycleIndex];
        $index++;

        if (!empty($slug)) {
            $dynamiccovermap[$slug] = $cover;
        }

        return $cover;
    }
}

$category_cache = [];
if (!empty($courses)) {
    $category_ids = array_unique(array_map(function($course) {
        return $course->category ?? null;
    }, $courses));
    $category_ids = array_filter($category_ids, function($id) {
        return $id !== null;
    });

    if (!empty($category_ids)) {
        list($insql, $params) = $DB->get_in_or_equal($category_ids, SQL_PARAMS_NAMED);
        $category_cache = $DB->get_records_select('course_categories', "id $insql", $params);

        // Ensure parent categories are loaded as well.
        $parent_ids = [];
        foreach ($category_cache as $cat) {
            if ($cat->parent && !isset($category_cache[$cat->parent])) {
                $parent_ids[$cat->parent] = $cat->parent;
            }
        }
        if (!empty($parent_ids)) {
            list($parent_sql, $parent_params) = $DB->get_in_or_equal(array_values($parent_ids), SQL_PARAMS_NAMED, 'pc');
            $parent_records = $DB->get_records_select('course_categories', "id $parent_sql", $parent_params);
            foreach ($parent_records as $record) {
                $category_cache[$record->id] = $record;
            }
        }
    }
}

foreach ($courses as $course) {
    $category = $course->category ? ($category_cache[$course->category] ?? null) : null;
    $parent_category = $category;
    $subcategory = null;

    if ($category && $category->parent != 0) {
        $subcategory = $category;
        $parent_category = $category_cache[$category->parent] ?? $DB->get_record('course_categories', ['id' => $category->parent]);
        if ($parent_category) {
            $category_cache[$parent_category->id] = $parent_category;
        }
    }

    if (!$parent_category) {
        $parent_category = (object)[
            'id' => 0,
            'name' => get_string('uncategorised', 'moodle')
        ];
    }

    $parent_id = (string)$parent_category->id;
    $parent_name = $parent_category->name ?? get_string('uncategorised', 'moodle');
    $subcategory_id = $subcategory ? (string)$subcategory->id : 'direct-' . $parent_id;
    $subcategory_name = $subcategory ? $subcategory->name : 'Direct Courses';

    if (!isset($parent_filter_map[$parent_id])) {
        $parent_filter_map[$parent_id] = [
            'id' => $parent_id,
            'name' => $parent_name,
            'count' => 0
        ];
    }
    $parent_filter_map[$parent_id]['count']++;

    if (!isset($subcategory_filter_map[$subcategory_id])) {
        $subcategory_filter_map[$subcategory_id] = [
            'id' => $subcategory_id,
            'name' => $subcategory_name,
            'parent_id' => $parent_id,
            'count' => 0
        ];
    }
    $subcategory_filter_map[$subcategory_id]['count']++;

    $course_filter_map[] = [
        'id' => (string)$course->id,
        'name' => $course->fullname,
        'parent_id' => $parent_id,
        'subcategory_id' => $subcategory_id
    ];

    // Detect book type for filter and UI.
    $course_array = [
        'id' => $course->id,
        'fullname' => $course->fullname ?? '',
        'shortname' => $course->shortname ?? '',
        'summary' => $course->summary ?? ''
    ];
    $book_type_label = theme_remui_kids_detect_course_book_type($course_array);
    $fallback_label = $book_type_label;
    if ($book_type_label === '') {
        $book_type_label = 'General Course';
    }
    $book_type_slug = theme_remui_kids_slugify($book_type_label ?: 'general-course');

    // Select course cover image (same logic as view_all_courses.php)
    $coursecover = theme_remui_kids_select_course_cover(
        $course_array,
        $coursecoverdefaults,
        $coursecovercycle,
        $course_cover_fallback_index,
        $fallback_label
    );
    
    // Use fallback if no cover found
    if (empty($coursecover)) {
        $coursecover = $coursecoverfallback;
    }

    if (!isset($book_type_filter_map[$book_type_slug])) {
        $book_type_filter_map[$book_type_slug] = [
            'slug' => $book_type_slug,
            'label' => $book_type_label,
            'count' => 0
        ];
    }
    $book_type_filter_map[$book_type_slug]['count']++;

    // Attach metadata to course object for rendering.
    $course->parent_category_id = $parent_id;
    $course->parent_category_name = $parent_name;
    $course->subcategory_id = $subcategory_id;
    $course->subcategory_name = $subcategory_name;
    $course->book_type_label = $book_type_label;
    $course->book_type_slug = $book_type_slug;
    $course->book_cover_url = $coursecover;
}

$parent_filter_list = array_values($parent_filter_map);
usort($parent_filter_list, function($a, $b) {
    return strcasecmp($a['name'], $b['name']);
});

$subcategory_filter_list = array_values($subcategory_filter_map);
usort($subcategory_filter_list, function($a, $b) {
    if ($a['parent_id'] === $b['parent_id']) {
        return strcasecmp($a['name'], $b['name']);
    }
    return strcasecmp($a['parent_id'], $b['parent_id']);
});

$course_filter_list = $course_filter_map;
usort($course_filter_list, function($a, $b) {
    return strcasecmp($a['name'], $b['name']);
});

$book_type_filter_list = array_values($book_type_filter_map);
usort($book_type_filter_list, function($a, $b) {
    return strcasecmp($a['label'], $b['label']);
});

// Set page context
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/theme/remui_kids/course_management.php'));
$PAGE->set_title('Course Management - ' . $company_info->name);
$PAGE->set_heading('Course Management');

// Prepare sidebar context
$sidebarcontext = [
    'config' => [
        'wwwroot' => $CFG->wwwroot,
    ],
    'company_name' => $company_info->name,
    'company_logo_url' => !empty($company_info->logo) ? $company_info->logo : '',
    'has_logo' => !empty($company_info->logo),
    'user_info' => [
        'fullname' => fullname($USER),
    ],
    'courses_active' => true,
];

echo $OUTPUT->header();

// Render sidebar
try {
    echo $OUTPUT->render_from_template('theme_remui_kids/school_manager_sidebar', $sidebarcontext);
} catch (Exception $e) {
    echo "<!-- Sidebar rendering error: " . $e->getMessage() . " -->";
}

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
        background: #f8f9fa;
        min-height: 100vh;
    }
    
    /* Main content area with sidebar */
    .school-manager-main-content {
        position: fixed;
        top: 55px;
        left: 280px;
        width: calc(100vw - 280px);
        height: calc(100vh - 55px);
        background-color: #ffffff;
        overflow-y: auto;
        z-index: 99;
        padding-top: 35px;
        transition: left 0.3s ease, width 0.3s ease;
    }
    
    .main-content {
        max-width: 1800px;
        margin: 0 auto;
        padding: 0 30px 30px 30px;
    }
    
    /* Page Header */
    .page-header {
        background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%);
        border-radius: 0.75rem;
        padding: 2rem 2.5rem;
        margin-bottom: 2rem;
        margin-top: 0;
        border: 1px solid #e2e8f0;
        box-shadow: 
            0 1px 3px rgba(0, 0, 0, 0.05),
            0 4px 12px rgba(0, 0, 0, 0.04);
        position: relative;
        overflow: visible;
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 2rem;
    }
    
    .page-header::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 4px;
        height: 100%;
        background: linear-gradient(180deg, #3b82f6 0%, #06b6d4 100%);
        border-radius: 0.75rem 0 0 0.75rem;
    }
    
    .page-header-content {
        flex: 1;
        min-width: 0;
        position: relative;
        z-index: 1;
    }
    
    .page-title {
        font-size: 1.875rem;
        font-weight: 700;
        color: #1e293b;
        margin: 0 0 0.75rem 0;
        letter-spacing: -0.5px;
        line-height: 1.2;
        font-family: 'Inter', 'Segoe UI', 'Roboto', -apple-system, BlinkMacSystemFont, sans-serif;
    }
    
    .page-subtitle {
        font-size: 0.875rem;
        color: #64748b;
        margin: 0;
        font-weight: 400;
        line-height: 1.5;
    }
    
    .back-button {
        background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
        color: #ffffff;
        border: none;
        padding: 0.625rem 1.25rem;
        border-radius: 8px;
        font-size: 0.8125rem;
        font-weight: 600;
        text-decoration: none;
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        display: flex;
        align-items: center;
        gap: 0.5rem;
        box-shadow: 
            0 2px 8px rgba(59, 130, 246, 0.2),
            0 1px 4px rgba(37, 99, 235, 0.15);
        flex-shrink: 0;
        position: relative;
        z-index: 1;
    }
    
    .back-button:hover {
        background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
        transform: translateY(-1px);
        box-shadow: 
            0 4px 12px rgba(59, 130, 246, 0.3),
            0 2px 6px rgba(37, 99, 235, 0.2);
        color: #ffffff;
        text-decoration: none;
    }
    
    /* Stats Section */
    .stats-section {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 20px;
        margin-bottom: 40px;
    }

    .course-filter-section {
        background: #ffffff;
        padding: 25px;
        border-radius: 15px;
        margin-bottom: 30px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        border: 1px solid #e9ecef;
    }

    .course-filter-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 15px;
    }

    .course-filter-group label {
        display: block;
        font-size: 0.75rem;
        font-weight: 700;
        color: #6c757d;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-bottom: 6px;
    }

    .course-filter-group select {
        width: 100%;
        border: 2px solid #e9ecef;
        border-radius: 10px;
        padding: 10px 12px;
        font-size: 0.9rem;
        background: #fff;
        color: #2c3e50;
        transition: all 0.3s ease;
    }

    .course-filter-group select:focus {
        outline: none;
        border-color: #5c6ac4;
        box-shadow: 0 0 0 3px rgba(92, 106, 196, 0.15);
    }
    
    .stat-card {
        background: #ffffff;
        padding: 1.2rem;
        border-radius: 15px;
        text-align: center;
        box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        border: 1px solid #e9ecef;
        transition: all 0.3s ease;
        min-height: 110px;
        display: flex;
        flex-direction: column;
        justify-content: center;
        position: relative;
        overflow: hidden;
    }
    
    .stat-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: linear-gradient(90deg, #007bff, #28a745);
    }
    
    .stat-card:nth-child(1) {
        background: linear-gradient(180deg, rgba(0, 123, 255, 0.08), #ffffff);
    }
    
    .stat-card:nth-child(2) {
        background: linear-gradient(180deg, rgba(40, 167, 69, 0.08), #ffffff);
    }
    
    .stat-card:nth-child(3) {
        background: linear-gradient(180deg, rgba(111, 66, 193, 0.08), #ffffff);
    }
    
    .stat-card:nth-child(4) {
        background: linear-gradient(180deg, rgba(253, 126, 20, 0.08), #ffffff);
    }
    
    .stat-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 8px 30px rgba(0,0,0,0.15);
    }
    
    .stat-icon {
        width: 50px;
        height: 50px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.5rem;
        color: #ffffff;
        margin: 0 auto 15px auto;
    }
    
    .stat-icon.courses { background: linear-gradient(135deg, #007bff, #0056b3); }
    .stat-icon.enrollments { background: linear-gradient(135deg, #28a745, #1e7e34); }
    .stat-icon.active { background: linear-gradient(135deg, #6f42c1, #5a359a); }
    .stat-icon.completed { background: linear-gradient(135deg, #fd7e14, #e55a00); }
    
    .stat-number {
        font-size: 2.2rem;
        font-weight: 700;
        color: #2c3e50;
        margin-bottom: 5px;
    }
    
    .stat-label {
        font-size: 0.85rem;
        color: #6c757d;
        font-weight: 500;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    /* Search Section */
    .search-section {
        background: transparent;
        padding: 0;
        border-radius: 0;
        margin-bottom: 30px;
        box-shadow: none;
        border: none;
        display: flex;
        justify-content: center;
        align-items: center;
        width: 100%;
    }
    
    .search-header {
        display: flex;
        justify-content: center;
        align-items: center;
        margin-bottom: 0;
        flex-wrap: wrap;
        gap: 15px;
        width: 100%;
    }
    
    .search-title {
        display: none;
    }
    
    .search-controls {
        display: flex;
        gap: 15px;
        align-items: center;
        flex-wrap: wrap;
        justify-content: center;
        width: 100%;
        max-width: 600px;
    }
    
    .search-input {
        padding: 14px 20px;
        border: 2px solid #e9ecef;
        border-radius: 12px;
        font-size: 1rem;
        width: 100%;
        max-width: 500px;
        transition: all 0.3s ease;
        background: #ffffff;
    }
    
    .search-input:focus {
        outline: none;
        border-color: #3b82f6;
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    }
    
    .search-select {
        padding: 12px 16px;
        border: 2px solid #e9ecef;
        border-radius: 10px;
        font-size: 0.9rem;
        background: #ffffff;
        cursor: pointer;
        transition: all 0.3s ease;
    }
    
    .search-select:focus {
        outline: none;
        border-color: #007bff;
        box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1);
    }
    
    /* Course Grid */
    .courses-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
        gap: 25px;
        margin-bottom: 30px;
    }
    
    .course-card {
        background: #ffffff;
        border-radius: 15px;
        overflow: hidden;
        box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        border: 1px solid #e9ecef;
        transition: all 0.3s ease;
        cursor: pointer;
        position: relative;
    }
    
    .course-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 8px 30px rgba(0,0,0,0.15);
        border-color: #dee2e6;
    }
    
    .course-image {
        width: 100%;
        height: 200px;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        position: relative;
        overflow: hidden;
    }
    
    .course-thumbnail {
        width: 100%;
        height: 100%;
        object-fit: cover;
        object-position: center;
    }
    
    .course-thumbnail img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    
    .course-icon-container {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 100%;
        height: 100%;
    }
    
    .course-image::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grid" width="10" height="10" patternUnits="userSpaceOnUse"><path d="M 10 0 L 0 0 0 10" fill="none" stroke="rgba(255,255,255,0.1)" stroke-width="0.5"/></pattern></defs><rect width="100" height="100" fill="url(%23grid)"/></svg>');
        opacity: 0.3;
    }
    
    .course-icon {
        font-size: 3rem;
        color: #ffffff;
        z-index: 1;
        position: relative;
    }
    
    .course-badge {
        position: absolute;
        top: 10px;
        left: 10px;
        z-index: 10;
    }
    
    .enrolled-count {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-size: 0.85rem;
        color: white;
        background: rgba(21, 87, 36, 0.9);
        backdrop-filter: blur(10px);
        padding: 6px 12px;
        border-radius: 20px;
        font-weight: 700;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
    }
    
    .enrolled-count i {
        color: white;
        font-size: 0.9rem;
    }
    
    .enrolled-count:hover {
        background: rgba(21, 87, 36, 1);
        transform: scale(1.05);
        transition: all 0.2s ease;
    }
    
    /* Modal Styles */
    #enrolledStudentsModal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        z-index: 9999;
    }
    
    .modal-overlay {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.5);
        backdrop-filter: blur(5px);
    }
    
    .modal-content {
        background: white;
        border-radius: 15px;
        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
        max-width: 1000px;
        width: 90%;
        max-height: 85vh;
        overflow: hidden;
        animation: modalSlideIn 0.3s ease;
        display: flex;
        flex-direction: column;
    }
    
    @keyframes modalSlideIn {
        from {
            opacity: 0;
            transform: translateY(-50px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    .modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 20px 25px;
        border-bottom: 1px solid #e9ecef;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
    }
    
    .modal-header h3 {
        margin: 0;
        font-size: 1.3rem;
        font-weight: 600;
    }
    
    .modal-close {
        background: none;
        border: none;
        color: white;
        font-size: 1.5rem;
        cursor: pointer;
        padding: 5px;
        border-radius: 50%;
        width: 30px;
        height: 30px;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: background 0.2s ease;
    }
    
    .modal-close:hover {
        background: rgba(255, 255, 255, 0.2);
    }
    
    .modal-body {
        padding: 25px;
        flex: 1;
        overflow-y: auto;
        display: flex;
        flex-direction: column;
    }
    
    /* Search and Filter Section */
    .search-filter-section {
        display: flex;
        gap: 15px;
        margin-bottom: 20px;
        flex-wrap: wrap;
        align-items: center;
    }
    
    .search-box {
        flex: 1;
        min-width: 300px;
        position: relative;
    }
    
    .search-box input {
        width: 100%;
        padding: 12px 40px 12px 15px;
        border: 2px solid #e9ecef;
        border-radius: 8px;
        font-size: 0.95rem;
        transition: all 0.3s ease;
        box-sizing: border-box;
    }
    
    .search-box input:focus {
        outline: none;
        border-color: #667eea;
        box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
    }
    
    .search-box i {
        position: absolute;
        right: 15px;
        top: 50%;
        transform: translateY(-50%);
        color: #6c757d;
        pointer-events: none;
    }
    
    .filter-box {
        position: relative;
    }
    
    .filter-box select {
        padding: 12px 40px 12px 15px;
        border: 2px solid #e9ecef;
        border-radius: 8px;
        font-size: 0.95rem;
        background: white;
        cursor: pointer;
        transition: all 0.3s ease;
        appearance: none;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%236c757d' d='M6 9L1 4h10z'/%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-position: right 12px center;
    }
    
    .filter-box select:focus {
        outline: none;
        border-color: #667eea;
        box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
    }
    
    .filter-box i {
        position: absolute;
        left: 15px;
        top: 50%;
        transform: translateY(-50%);
        color: #6c757d;
        pointer-events: none;
    }
    
    .students-count {
        font-size: 1rem;
        color: #6c757d;
        margin-bottom: 15px;
        font-weight: 500;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .count-badge {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 4px 12px;
        border-radius: 12px;
        font-size: 0.85rem;
        font-weight: 600;
    }
    
    .students-list {
        display: flex;
        flex-direction: column;
        gap: 15px;
    }
    
    .student-item {
        display: flex;
        align-items: center;
        padding: 12px 15px;
        background: #f8f9fa;
        border-radius: 10px;
        border: 1px solid #e9ecef;
    }
    
    .student-item:hover {
        background: #e9ecef;
        border-color: #dee2e6;
    }
    
    .student-avatar {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        margin-right: 15px;
        flex-shrink: 0;
    }
    
    .student-avatar i {
        color: white;
        font-size: 1.2rem;
    }
    
    .student-info {
        flex: 1;
    }
    
    .student-name {
        font-weight: 600;
        color: #2c3e50;
        margin-bottom: 2px;
        font-size: 0.95rem;
    }
    
    .student-email {
        font-size: 0.8rem;
        color: #6c757d;
        margin-bottom: 5px;
    }
    
    .student-meta {
        display: flex;
        gap: 6px;
        margin-bottom: 6px;
        flex-wrap: nowrap;
        align-items: center;
    }
    
    .student-role,
    .student-cohort {
        display: inline-flex;
        align-items: center;
        font-size: 0.7rem;
        padding: 3px 8px;
        border-radius: 10px;
        font-weight: 600;
        white-space: nowrap;
        transition: all 0.2s ease;
        line-height: 1.2;
    }
    
    .student-role {
        background: linear-gradient(135deg, rgba(52, 152, 219, 0.2), rgba(41, 128, 185, 0.15));
        color: #2980b9;
        border: 1px solid rgba(52, 152, 219, 0.4);
        box-shadow: 0 2px 4px rgba(52, 152, 219, 0.1);
    }
    
    .student-role:hover {
        background: linear-gradient(135deg, rgba(52, 152, 219, 0.25), rgba(41, 128, 185, 0.2));
        transform: translateY(-1px);
    }
    
    .student-cohort {
        background: linear-gradient(135deg, rgba(155, 89, 182, 0.2), rgba(142, 68, 173, 0.15));
        color: #8e44ad;
        border: 1px solid rgba(155, 89, 182, 0.4);
        box-shadow: 0 2px 4px rgba(155, 89, 182, 0.1);
    }
    
    .student-cohort:hover {
        background: linear-gradient(135deg, rgba(155, 89, 182, 0.25), rgba(142, 68, 173, 0.2));
        transform: translateY(-1px);
    }
    
    /* Specific styling for different roles */
    .student-role.role-student {
        background: linear-gradient(135deg, rgba(52, 152, 219, 0.2), rgba(41, 128, 185, 0.15));
        color: #2980b9;
        border: 1px solid rgba(52, 152, 219, 0.4);
    }
    
    .student-role.role-teacher {
        background: linear-gradient(135deg, rgba(230, 126, 34, 0.2), rgba(211, 84, 0, 0.15));
        color: #d35400;
        border: 1px solid rgba(230, 126, 34, 0.4);
    }
    
    /* Specific styling for different cohort states */
    .student-cohort.cohort-assigned {
        background: linear-gradient(135deg, rgba(46, 204, 113, 0.2), rgba(39, 174, 96, 0.15));
        color: #27ae60;
        border: 1px solid rgba(46, 204, 113, 0.4);
    }
    
    .student-cohort.cohort-none {
        background: linear-gradient(135deg, rgba(149, 165, 166, 0.2), rgba(127, 140, 141, 0.15));
        color: #7f8c8d;
        border: 1px solid rgba(149, 165, 166, 0.4);
    }
    
    .student-cohort.cohort-na {
        background: linear-gradient(135deg, rgba(155, 89, 182, 0.15), rgba(142, 68, 173, 0.1));
        color: #95a5a6;
        border: 1px solid rgba(155, 89, 182, 0.3);
        font-style: italic;
    }
    
    .student-enrolled-date {
        font-size: 0.7rem;
        color: #adb5bd;
    }
    
    .student-status {
        margin-left: 15px;
    }
    
    .status-badge.enrolled {
        background: rgba(40, 167, 69, 0.2);
        color: #28a745;
        border: 1px solid rgba(40, 167, 69, 0.3);
        padding: 3px 10px;
        border-radius: 12px;
        font-size: 0.7rem;
        font-weight: 600;
    }
    
    .loading, .no-students, .error {
        text-align: center;
        padding: 40px 20px;
        color: #6c757d;
    }
    
    .loading {
        color: #667eea;
    }
    
    .error {
        color: #dc3545;
    }
    
    /* Enrollment Modal Styles - Redesigned */
    .enrollment-modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        z-index: 10000;
    }
    
    .enrollment-modal-content {
        max-width: 900px;
        width: 95%;
        max-height: 95vh;
        overflow: hidden;
        background: #ffffff;
        border-radius: 12px;
        box-shadow: 0 10px 50px rgba(0, 0, 0, 0.3);
        display: flex;
        flex-direction: column;
    }
    
    /* New Modal Header - Compact */
    .modal-header-new {
        background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
        color: white;
        padding: 20px 30px;
        border-radius: 12px 12px 0 0;
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        position: relative;
        flex-shrink: 0;
    }
    
    .header-content {
        flex: 1;
    }
    
    .modal-main-title {
        font-size: 1.6rem;
        font-weight: 700;
        margin: 0 0 5px 0;
        color: white;
    }
    
    .modal-subtitle {
        font-size: 0.9rem;
        margin: 0;
        color: rgba(255, 255, 255, 0.9);
        font-weight: 400;
    }
    
    .modal-close-new {
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
        font-size: 1.3rem;
        transition: all 0.3s ease;
    }
    
    .modal-close-new:hover {
        background: rgba(255, 255, 255, 0.3);
        transform: scale(1.1);
    }
    
    /* Modal Body - Compact */
    .modal-body-new {
        padding: 25px 30px 20px 30px;
        overflow-y: auto;
        overflow-x: hidden;
    }
    
    .enrollment-form-container {
        max-width: 100%;
    }
    
    .form-section-title {
        font-size: 1.2rem;
        font-weight: 600;
        color: #2c3e50;
        margin: 0 0 20px 0;
        padding-bottom: 10px;
        border-bottom: 2px solid #e9ecef;
    }
    
    /* Form Fields - Compact */
    .enrollment-form-new {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 18px;
        column-gap: 25px;
    }
    
    .form-field {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }
    
    /* Make course field and section title span full width */
    .form-field.full-width {
        grid-column: 1 / -1;
    }
    
    .field-label {
        font-size: 0.7rem;
        font-weight: 700;
        color: #6c757d;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin: 0;
    }
    
    .input-wrapper {
        position: relative;
        display: flex;
        align-items: center;
    }
    
    .form-control-new {
        width: 100%;
        padding: 10px 40px 10px 14px;
        border: 2px solid #e9ecef;
        border-radius: 8px;
        font-size: 0.9rem;
        color: #2c3e50;
        background: white;
        transition: all 0.3s ease;
        font-family: 'Inter', sans-serif;
    }
    
    /* Hide native select arrow to avoid duplicate arrows */
    .form-control-new[type="text"],
    .form-control-new:not(select) {
        -webkit-appearance: none;
        -moz-appearance: none;
        appearance: none;
    }
    
    select.form-control-new {
        -webkit-appearance: none;
        -moz-appearance: none;
        appearance: none;
        background-image: none;
    }
    
    .form-control-new:focus {
        outline: none;
        border-color: #007bff;
        box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1);
    }
    
    .form-control-new:disabled {
        background: #f8f9fa;
        color: #6c757d;
        cursor: not-allowed;
    }
    
    .disabled-field {
        position: relative;
    }
    
    .disabled-field .form-control-new {
        background: #f8f9fa;
        border-color: #dee2e6;
        color: #6c757d;
        font-weight: 600;
    }
    
    .field-icon {
        position: absolute;
        right: 16px;
        color: #6c757d;
        font-size: 0.9rem;
        pointer-events: none;
    }
    
    .disabled-field .field-icon {
        color: #adb5bd;
    }
    
    .field-hint {
        font-size: 0.7rem;
        color: #6c757d;
        font-style: italic;
        margin: 0;
        line-height: 1.2;
    }
    
    /* Search Results - Compact */
    .search-results {
        margin-top: 6px;
        max-height: 250px;
        overflow-y: auto;
        border: 2px solid #e9ecef;
        border-radius: 8px;
        background: white;
        display: none;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    }
    
    /* Specific styling for user dropdown to show exactly 3 users */
    #userSearchResults {
        max-height: 195px;
    }
    
    /* Specific styling for cohort dropdown to show exactly 3 cohorts */
    #cohortSearchResults {
        max-height: 195px;
    }
    
    /* Ensure cohort results are visible when they have content */
    #cohortSearchResults.cohort-dropdown-visible {
        display: block !important;
    }
    
    .search-results:not(:empty) {
        display: block;
    }
    
    /* Compact scrollbar for search results */
    .search-results::-webkit-scrollbar {
        width: 6px;
    }
    
    .search-results::-webkit-scrollbar-track {
        background: #f1f1f1;
    }
    
    .search-results::-webkit-scrollbar-thumb {
        background: #c1c1c1;
        border-radius: 3px;
    }
    
    /* Selected Users - Compact */
    .selected-users-new {
        display: flex;
        flex-direction: column;
        gap: 8px;
        padding: 12px;
        background: #f8f9fa;
        border: 2px solid #e9ecef;
        border-radius: 8px;
        min-height: 50px;
        max-height: 120px;
        overflow-y: auto;
    }
    
    .selected-users-new::-webkit-scrollbar {
        width: 6px;
    }
    
    .selected-users-new::-webkit-scrollbar-track {
        background: #f1f1f1;
    }
    
    .selected-users-new::-webkit-scrollbar-thumb {
        background: #c1c1c1;
        border-radius: 3px;
    }
    
    /* Action Buttons - Compact */
    .form-actions-new {
        display: flex;
        gap: 12px;
        justify-content: flex-end;
        margin-top: 20px;
        margin-bottom: 0;
        padding-top: 20px;
        padding-bottom: 0;
        border-top: 2px solid #e9ecef;
        grid-column: 1 / -1;
    }
    
    .btn-cancel-new,
    .btn-enroll-new {
        padding: 10px 24px;
        border-radius: 8px;
        font-size: 0.95rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        border: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }
    
    .btn-cancel-new {
        background: #6c757d;
        color: white;
    }
    
    .btn-cancel-new:hover {
        background: #5a6268;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(108, 117, 125, 0.3);
    }
    
    .btn-enroll-new {
        background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
        color: white;
    }
    
    .btn-enroll-new:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(0, 123, 255, 0.4);
    }
    
    /* User List in Search Results - Compact */
    .user-list {
        display: flex;
        flex-direction: column;
    }
    
    .user-item {
        display: flex;
        align-items: center;
        padding: 10px 12px;
        border-bottom: 1px solid #f1f3f5;
        cursor: pointer;
        transition: all 0.2s ease;
    }
    
    .user-item:last-child {
        border-bottom: none;
    }
    
    .user-item:hover {
        background: #f8f9fa;
    }
    
    .user-item.selected {
        background: #e3f2fd;
        border-left: 3px solid #007bff;
    }
    
    .user-item .user-avatar {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        margin-right: 10px;
        flex-shrink: 0;
    }
    
    .user-item .user-avatar i {
        color: white;
        font-size: 0.9rem;
    }
    
    .user-item .user-info {
        flex: 1;
    }
    
    .user-item .user-name {
        font-weight: 600;
        color: #2c3e50;
        margin-bottom: 2px;
        font-size: 0.85rem;
    }
    
    .user-item .user-email {
        font-size: 0.75rem;
        color: #6c757d;
    }
    
    .user-item .user-action {
        color: #007bff;
        font-size: 1.2rem;
    }
    
    /* Selected Users Display - Compact */
    .selected-user-badge {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 8px 10px;
        background: white;
        border: 2px solid #e9ecef;
        border-radius: 6px;
        transition: all 0.2s ease;
    }
    
    .selected-user-badge:hover {
        border-color: #007bff;
    }
    
    .selected-user-badge .user-avatar {
        width: 28px;
        height: 28px;
        border-radius: 50%;
        background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }
    
    .selected-user-badge .user-avatar i {
        color: white;
        font-size: 0.85rem;
    }
    
    .selected-user-badge .user-info {
        flex: 1;
        min-width: 0;
    }
    
    .selected-user-badge .user-name {
        font-weight: 600;
        color: #2c3e50;
        font-size: 0.8rem;
        margin-bottom: 2px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    
    .selected-user-badge .user-email {
        font-size: 0.7rem;
        color: #6c757d;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    
    .remove-user-btn {
        background: #dc3545;
        color: white;
        border: none;
        border-radius: 50%;
        width: 24px;
        height: 24px;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        font-size: 0.8rem;
        transition: all 0.2s ease;
        flex-shrink: 0;
    }
    
    .remove-user-btn:hover {
        background: #c82333;
        transform: scale(1.1);
    }
    
    .no-results {
        text-align: center;
        padding: 25px;
        color: #6c757d;
        font-style: italic;
    }
    
    .no-selection-message {
        text-align: center;
        padding: 15px;
        color: #adb5bd;
        font-style: italic;
        font-size: 0.8rem;
    }
    
    /* Responsive: Tablet */
    @media (max-width: 1024px) {
        .enrollment-modal-content {
            max-width: 800px;
            width: 90%;
        }
        
        .enrollment-form-new {
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            column-gap: 20px;
        }
    }
    
    /* Responsive: Small Tablet/Large Mobile */
    @media (max-width: 768px) {
        .enrollment-modal-content {
            max-width: 95%;
            width: 95%;
        }
        
        .modal-header-new {
            padding: 18px 20px;
        }
        
        .modal-main-title {
            font-size: 1.4rem;
        }
        
        .modal-subtitle {
            font-size: 0.85rem;
        }
        
        .modal-body-new {
            padding: 20px;
        }
        
        .enrollment-form-new {
            grid-template-columns: 1fr;
            gap: 16px;
        }
        
        .form-field.full-width {
            grid-column: 1;
        }
        
        .form-actions-new {
            margin-top: 18px;
            padding-top: 18px;
        }
    }
    
    /* Responsive: Mobile */
    @media (max-width: 480px) {
        .enrollment-modal-content {
            width: 98%;
            max-height: 95vh;
        }
        
        .modal-header-new {
            padding: 15px 18px;
        }
        
        .modal-main-title {
            font-size: 1.3rem;
        }
        
        .modal-subtitle {
            font-size: 0.8rem;
        }
        
        .modal-body-new {
            padding: 18px;
        }
        
        .enrollment-form-new {
            gap: 14px;
        }
        
        .form-control-new {
            padding: 9px 38px 9px 12px;
            font-size: 0.85rem;
        }
        
        .field-label {
            font-size: 0.65rem;
        }
        
        .btn-cancel-new,
        .btn-enroll-new {
            padding: 9px 18px;
            font-size: 0.9rem;
        }
    }
    
    .course-content {
        padding: 20px;
    }
    
    .course-title {
        font-size: 1.2rem;
        font-weight: 600;
        color: #2c3e50;
        margin-bottom: 8px;
        line-height: 1.3;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }
    
    .course-category {
        font-size: 0.85rem;
        color: #6c757d;
        margin-bottom: 12px;
        font-weight: 500;
    }
    
    .course-description {
        font-size: 0.9rem;
        color: #6c757d;
        line-height: 1.4;
        margin-bottom: 15px;
        display: -webkit-box;
        -webkit-line-clamp: 3;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    .course-tags {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        margin-bottom: 12px;
    }

    .course-tag {
        font-size: 0.75rem;
        font-weight: 600;
        padding: 4px 10px;
        border-radius: 999px;
        background: #eef2ff;
        color: #4c1d95;
    }

    .course-tag.book-type {
        background: #dcfce7;
        color: #065f46;
    }

    .course-tag.category-tag {
        background: #fdf2f8;
        color: #9d174d;
    }
    
    .course-meta {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 15px;
    }
    
    .enrollment-count {
        display: flex;
        align-items: center;
        gap: 5px;
        font-size: 0.85rem;
        color: #6c757d;
    }
    
    .course-status {
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 500;
    }
    
    .status-available {
        background: rgba(40, 167, 69, 0.2);
        color: #28a745;
        border: 1px solid rgba(40, 167, 69, 0.3);
    }
    
    .status-inactive {
        background: rgba(108, 117, 125, 0.2);
        color: #6c757d;
        border: 1px solid rgba(108, 117, 125, 0.3);
    }
    
    .course-actions {
        display: flex;
        gap: 10px;
    }
    
    .btn {
        padding: 8px 16px;
        border: none;
        border-radius: 8px;
        font-size: 0.85rem;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.3s ease;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 5px;
        flex: 1;
        justify-content: center;
    }
    
    .btn-primary {
        background: transparent;
        color: #007bff;
        border: 2px solid #007bff;
    }
    
    .btn-primary:hover {
        background: #007bff;
        color: white;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0, 123, 255, 0.3);
        text-decoration: none;
    }
    
    .btn-secondary {
        background: transparent;
        color: #28a745;
        border: 2px solid #28a745;
    }
    
    .btn-secondary:hover {
        background: #28a745;
        color: white;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(40, 167, 69, 0.3);
        text-decoration: none;
    }
    
    .btn-secondary i {
        color: #ffffff;
    }
    
    .btn-secondary:hover i {
        color: #ffffff;
    }
    
    /* No courses message */
    .no-courses {
        text-align: center;
        padding: 60px 20px;
        color: #6c757d;
    }
    
    .no-courses-icon {
        font-size: 4rem;
        color: #dee2e6;
        margin-bottom: 20px;
    }
    
    .no-courses h3 {
        font-size: 1.5rem;
        margin-bottom: 10px;
        color: #6c757d;
    }
    
    .no-courses p {
        font-size: 1rem;
        margin-bottom: 20px;
    }
    
    /* Tablet Responsive (768px - 1024px) */
    @media (max-width: 1024px) and (min-width: 769px) {
        .school-manager-main-content {
            left: 240px;
            width: calc(100vw - 240px);
        }
        
        .main-content {
            padding: 0 20px 30px 20px;
        }
        
        .page-title {
            font-size: 1.8rem;
        }
        
        .stats-section {
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
        }
        
        .courses-grid {
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
        }
    }
    
    /* Mobile Responsive (max-width: 768px) */
    @media (max-width: 768px) {
        .school-manager-main-content {
            left: 0 !important;
            width: 100vw !important;
            top: 55px;
            height: calc(100vh - 55px);
            padding-top: 80px;
        }
        
        .main-content {
            padding: 0 15px 30px 15px;
        }
        
        .page-header {
            flex-direction: column;
            align-items: flex-start;
            gap: 1rem;
            padding: 1.5rem 1.5rem;
            margin-top: 0;
        }
        
        .page-title {
            font-size: 1.5rem;
        }
        
        .page-subtitle {
            font-size: 0.875rem;
        }
        
        .back-button {
            width: 100%;
            justify-content: center;
        }
        
        .stats-section {
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
        }
        
        .stat-card {
            padding: 1rem;
            min-height: 100px;
        }
        
        .stat-icon {
            width: 40px;
            height: 40px;
            font-size: 1.2rem;
            margin-bottom: 10px;
        }
        
        .stat-number {
            font-size: 1.8rem;
        }
        
        .stat-label {
            font-size: 0.75rem;
        }
        
        .search-section {
            padding: 0;
        }
        
        .search-header {
            flex-direction: column;
            align-items: center;
            gap: 12px;
        }
        
        .search-title {
            display: none;
        }
        
        .search-controls {
            flex-direction: column;
            gap: 10px;
            width: 100%;
            max-width: 100%;
        }
        
        .search-input {
            width: 100%;
            max-width: 100%;
        }
        
        .courses-grid {
            grid-template-columns: 1fr;
            gap: 15px;
        }
        
        .course-card {
            margin: 0 auto;
            max-width: 100%;
        }
        
        .course-image {
            height: 180px;
        }
        
        .course-content {
            padding: 15px;
        }
        
        .course-title {
            font-size: 1.1rem;
        }
        
        .course-actions {
            flex-direction: column;
            gap: 8px;
        }
        
        .btn {
            width: 100%;
            padding: 10px 16px;
        }
        
        /* Modal adjustments for mobile */
        .modal-content {
            width: 95%;
            max-height: 85vh;
        }
        
        .enrollment-modal-content {
            width: 95%;
            max-height: 85vh;
        }
        
        .modal-header h3 {
            font-size: 1.1rem;
        }
        
        .form-group {
            gap: 6px;
        }
        
        .form-group label {
            font-size: 0.85rem;
        }
        
        .form-select,
        .form-input {
            padding: 10px 12px;
            font-size: 0.85rem;
        }
    }
    
    /* Small Mobile Responsive (max-width: 480px) */
    @media (max-width: 480px) {
        .school-manager-main-content {
            padding-top: 70px;
        }
        
        .page-header {
            padding: 1.5rem 1.5rem;
            margin-bottom: 1.5rem;
        }
        
        .page-title {
            font-size: 1.5rem;
        }
        
        .page-subtitle {
            font-size: 0.875rem;
        }
        
        .stats-section {
            grid-template-columns: 1fr;
            gap: 10px;
        }
        
        .stat-card {
            min-height: 90px;
        }
        
        .stat-number {
            font-size: 1.6rem;
        }
        
        .search-section {
            padding: 0;
            margin-bottom: 20px;
        }
        
        .search-title {
            display: none;
        }
        
        .search-controls {
            width: 100%;
            max-width: 100%;
        }
        
        .search-input {
            width: 100%;
            max-width: 100%;
        }
        
        .course-image {
            height: 160px;
        }
        
        .course-title {
            font-size: 1rem;
        }
        
        .course-category,
        .course-description {
            font-size: 0.8rem;
        }
        
        .modal-content {
            width: 98%;
        }
        
        .enrollment-modal-content {
            width: 98%;
        }
        
        .modal-header {
            padding: 15px 20px;
        }
        
        .modal-header h3 {
            font-size: 1rem;
        }
        
        .modal-body {
            padding: 20px 15px;
        }
    }
</style>

<!-- Main content area -->
<div class='school-manager-main-content'>
    <div class='main-content'>
        
        <!-- Page Header -->
        <div class='page-header'>
            <div class='page-header-content'>
                <h1 class='page-title'>Course Management</h1>
                <p class='page-subtitle'>Manage and view all courses available for <?php echo htmlspecialchars($company_info->name); ?></p>
            </div>
            <a href="<?php echo $CFG->wwwroot; ?>/my/" class='back-button'>
                <i class="fa fa-arrow-left"></i>
                Back to Dashboard
            </a>
        </div>
        
        <!-- Statistics Section -->
        <div class='stats-section'>
            <div class='stat-card'>
                <div class='stat-icon courses'>
                    <i class="fa fa-book"></i>
                </div>
                <div class='stat-number'><?php echo $total_courses; ?></div>
                <div class='stat-label'>Total Courses</div>
            </div>
            
            <div class='stat-card'>
                <div class='stat-icon enrollments'>
                    <i class="fa fa-users"></i>
                </div>
                <div class='stat-number'><?php echo number_format($total_enrollments); ?></div>
                <div class='stat-label'>Total Enrollments</div>
            </div>
            
            <div class='stat-card'>
                <div class='stat-icon active'>
                    <i class="fa fa-play-circle"></i>
                </div>
                <div class='stat-number'><?php echo $active_courses; ?></div>
                <div class='stat-label'>Active Courses</div>
            </div>
            
            <div class='stat-card'>
                <div class='stat-icon completed'>
                    <i class="fa fa-check-circle"></i>
                </div>
                <div class='stat-number'><?php echo $completed_courses; ?></div>
                <div class='stat-label'>Completed Courses</div>
            </div>
        </div>
        
        <!-- Filter Section -->
        <div class='course-filter-section'>
            <div class='course-filter-grid'>
                <div class='course-filter-group'>
                    <label for='parentCategoryFilter'>Parent Category</label>
                    <select id='parentCategoryFilter'>
                        <option value='all'>All Parent Categories</option>
                        <?php foreach ($parent_filter_list as $parent_option): ?>
                            <option value="<?php echo $parent_option['id']; ?>">
                                <?php echo htmlspecialchars($parent_option['name']); ?> (<?php echo $parent_option['count']; ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class='course-filter-group'>
                    <label for='subcategoryFilter'>Subcategory</label>
                    <select id='subcategoryFilter' disabled>
                        <option value='all'>All Subcategories</option>
                        <?php foreach ($subcategory_filter_list as $subcategory_option): ?>
                            <option value="<?php echo $subcategory_option['id']; ?>"
                                    data-parent-id="<?php echo $subcategory_option['parent_id']; ?>"
                                    hidden>
                                <?php echo htmlspecialchars($subcategory_option['name']); ?> (<?php echo $subcategory_option['count']; ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class='course-filter-group'>
                    <label for='courseFilter'>Course</label>
                    <select id='courseFilter' disabled>
                        <option value='all'>All Courses</option>
                        <?php foreach ($course_filter_list as $course_option): ?>
                            <option value="<?php echo $course_option['id']; ?>"
                                    data-parent-id="<?php echo $course_option['parent_id']; ?>"
                                    data-subcategory-id="<?php echo $course_option['subcategory_id']; ?>"
                                    hidden>
                                <?php echo htmlspecialchars($course_option['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class='course-filter-group'>
                    <label for='bookTypeFilter'>Book Type</label>
                    <select id='bookTypeFilter'>
                        <option value='all'>All Book Types</option>
                        <?php foreach ($book_type_filter_list as $book_option): ?>
                            <option value="<?php echo $book_option['slug']; ?>">
                                <?php echo htmlspecialchars($book_option['label']); ?> (<?php echo $book_option['count']; ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
        
        <!-- Search Section -->
        <div class='search-section'>
            <div class='search-header'>
                <h2 class='search-title'>Available Courses</h2>
                <div class='search-controls'>
                    <input type='text' id='search-input' class='search-input' placeholder='Search courses...'>
                    <select id='search-field' class='search-select' style='display: none;'>
                        <option value='all'>All Fields</option>
                        <option value='name'>Course Name</option>
                        <option value='category'>Category</option>
                        <option value='description'>Description</option>
                    </select>
                </div>
            </div>
        </div>
        
        <!-- Courses Grid -->
        <div class='courses-grid' id='courses-grid'>
            <?php if (empty($courses)): ?>
                <div class='no-courses'>
                    <div class='no-courses-icon'>
                        <i class="fa fa-book-open"></i>
                    </div>
                    <h3>No Courses Available</h3>
                    <p>There are currently no courses available for your school.</p>
                    <a href="<?php echo $CFG->wwwroot; ?>/course/management.php" class='btn btn-primary'>
                        <i class="fa fa-plus"></i>
                        Create New Course
                    </a>
                </div>
            <?php else: ?>
                <?php foreach ($courses as $course): ?>
                    <div class='course-card' 
                         data-name="<?php echo htmlspecialchars($course->fullname); ?>"
                         data-category="<?php echo htmlspecialchars($course->category_name ?? 'Uncategorized'); ?>"
                         data-description="<?php echo htmlspecialchars(strip_tags($course->summary ?? '')); ?>"
                         data-parent-id="<?php echo htmlspecialchars($course->parent_category_id); ?>"
                         data-subcategory-id="<?php echo htmlspecialchars($course->subcategory_id); ?>"
                         data-course-id="<?php echo $course->id; ?>"
                         data-booktype="<?php echo htmlspecialchars($course->book_type_slug ?? 'general-course'); ?>"
                         onclick="viewCourse(<?php echo $course->id; ?>)">
                        
                        <div class='course-image'>
                            <?php 
                            // Get course cover image (same logic as view_all_courses.php)
                            $courseimage = $course->book_cover_url ?? $coursecoverfallback;
                            if (empty($courseimage)) {
                                $courseimage = $coursecoverfallback;
                            }
                            ?>
                            
                            <img src="<?php echo htmlspecialchars($courseimage); ?>" alt="<?php echo htmlspecialchars($course->fullname); ?>" class="course-thumbnail" loading="lazy">
                            
                            <!-- Enrollment Badge -->
                            <div class="course-badge">
                                <span class="enrolled-count" onclick="event.stopPropagation(); showEnrolledStudents(<?php echo $course->id; ?>, <?php echo $course->enrollment_count; ?>);" style="cursor: pointer;">
                                    <i class="fa fa-users"></i>
                                    <?php echo $course->enrollment_count; ?>
                                </span>
                            </div>
                        </div>
                        
                        <div class='course-content'>
                            <h3 class='course-title'><?php echo htmlspecialchars($course->fullname); ?></h3>
                            <div class='course-category'><?php echo htmlspecialchars($course->category_name ?? 'Uncategorized'); ?></div>
                            
                            <div class='course-tags'>
                                <span class='course-tag category-tag'><?php echo htmlspecialchars($course->parent_category_name ?? 'Category'); ?></span>
                                <?php if (!empty($course->book_type_label)): ?>
                                    <span class='course-tag book-type'><?php echo htmlspecialchars($course->book_type_label); ?></span>
                                <?php endif; ?>
                            </div>
                            
                            <?php if (!empty($course->summary)): ?>
                                <div class='course-description'><?php echo htmlspecialchars(strip_tags($course->summary)); ?></div>
                            <?php endif; ?>
                            
                            <div class='course-meta'>
                                <div class='course-status status-available'>Available</div>
                            </div>
                            
                            <div class='course-actions'>
                                <a href="<?php echo $CFG->wwwroot; ?>/course/view.php?id=<?php echo $course->id; ?>" 
                                   class='btn btn-primary' onclick="event.stopPropagation();">
                                    <i class="fa fa-eye"></i>
                                    View Course
                                </a>
                                <button onclick="event.stopPropagation(); openEnrollmentModal(<?php echo $course->id; ?>, '<?php echo htmlspecialchars($course->fullname); ?>');" 
                                        class='btn btn-secondary'>
                                    <i class="fa fa-plus"></i>
                                    Enroll
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
    </div>
</div>

<!-- Enrollment Modal -->
<div id="enrollmentModal" class="enrollment-modal" style="display: none;">
    <div class="modal-overlay" onclick="closeEnrollmentModal()">
        <div class="modal-content enrollment-modal-content" onclick="event.stopPropagation()">
            <div class="modal-header-new">
                <div class="header-content">
                    <h2 class="modal-main-title">Enroll Users</h2>
                    <p class="modal-subtitle">Enroll students and teachers in courses</p>
                </div>
                <button class="modal-close-new" onclick="closeEnrollmentModal()" title="Close">
                    <i class="fa fa-times"></i>
                </button>
            </div>
            <div class="modal-body-new">
                <div class="enrollment-form-container">
                    <form id="enrollmentForm" class="enrollment-form-new">
                        <input type="hidden" id="selectedCourseId" name="course_id" value="">
                        
                        <!-- Course Selection (Disabled) - Full Width -->
                        <div class="form-field full-width">
                            <label class="field-label">SELECT COURSE</label>
                            <div class="input-wrapper disabled-field">
                                <input type="text" id="selectedCourseName" class="form-control-new" readonly disabled>
                                <span class="field-icon">
                                    <i class="fa fa-lock"></i>
                                </span>
                            </div>
                            <small class="field-hint">Pre-selected and cannot be changed</small>
                        </div>
                        
                        <!-- Role Selection -->
                        <div class="form-field">
                            <label class="field-label">SELECT ROLE</label>
                            <div class="input-wrapper">
                                <select id="enrollmentRole" name="enrollment_role" class="form-control-new" required>
                                    <option value="">Choose a role...</option>
                                    <option value="student">Student</option>
                                    <option value="teacher">Teacher</option>
                                    <option value="editingteacher">Editing Teacher</option>
                                </select>
                                <span class="field-icon">
                                    <i class="fa fa-chevron-down"></i>
                                </span>
                            </div>
                        </div>
                        
                        <!-- Start Date -->
                        <div class="form-field">
                            <label class="field-label">START DATE</label>
                            <div class="input-wrapper">
                                <input type="date" id="enrollmentStartDate" name="start_date" class="form-control-new" required>
                                <span class="field-icon">
                                    <i class="fa fa-calendar"></i>
                                </span>
                            </div>
                        </div>
                        
                        <!-- User Search -->
                        <div class="form-field">
                            <label class="field-label">SELECT USERS (OPTIONAL)</label>
                            <div class="input-wrapper">
                                <input type="text" id="userSearch" class="form-control-new" placeholder="Search users...">
                                <span class="field-icon">
                                    <i class="fa fa-search"></i>
                                </span>
                            </div>
                            <div id="userSearchResults" class="search-results"></div>
                        </div>
                        
                        <!-- Cohort Selection -->
                        <div class="form-field">
                            <label class="field-label">SELECT COHORTS (OPTIONAL)</label>
                            <div class="input-wrapper">
                                <input type="text" id="cohortSearch" class="form-control-new" placeholder="Search cohorts...">
                                <span class="field-icon">
                                    <i class="fa fa-search"></i>
                                </span>
                            </div>
                            <div id="cohortSearchResults" class="search-results"></div>
                        </div>
                        
                        <!-- Selected Users Display -->
                        <div class="form-field" id="selectedUsersContainer" style="display: none;">
                            <label class="field-label">SELECTED USERS</label>
                            <div id="selectedUsers" class="selected-users-new"></div>
                        </div>
                        
                        <!-- Selected Cohorts Display -->
                        <div class="form-field" id="selectedCohortsContainer" style="display: none;">
                            <label class="field-label">SELECTED COHORTS</label>
                            <div id="selectedCohorts" class="selected-users-new"></div>
                        </div>
                        
                        <!-- Enrollment End Date -->
                        <div class="form-field">
                            <label class="field-label">END DATE (OPTIONAL)</label>
                            <div class="input-wrapper">
                                <input type="date" id="enrollmentEndDate" name="end_date" class="form-control-new">
                                <span class="field-icon">
                                    <i class="fa fa-calendar"></i>
                                </span>
                            </div>
                        </div>
                        
                        <!-- Action Buttons -->
                        <div class="form-actions-new">
                            <button type="button" class="btn-cancel-new" onclick="closeEnrollmentModal()">
                                <i class="fa fa-times"></i>
                                Cancel
                            </button>
                            <button type="submit" class="btn-enroll-new">
                                <i class="fa fa-user-plus"></i>
                                Enroll Users
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('search-input');
    const searchField = document.getElementById('search-field');
    const coursesGrid = document.getElementById('courses-grid');
    const courseCards = document.querySelectorAll('.course-card');
    const parentCategoryFilter = document.getElementById('parentCategoryFilter');
    const subcategoryFilter = document.getElementById('subcategoryFilter');
    const courseFilterDropdown = document.getElementById('courseFilter');
    const bookTypeFilter = document.getElementById('bookTypeFilter');
    
    function filterCourses() {
        const searchTerm = searchInput.value.toLowerCase().trim();
        const searchFieldValue = searchField.value;
        const parentValue = parentCategoryFilter ? parentCategoryFilter.value : 'all';
        const subcategoryValue = subcategoryFilter ? subcategoryFilter.value : 'all';
        const courseValue = courseFilterDropdown ? courseFilterDropdown.value : 'all';
        const bookTypeValue = bookTypeFilter ? bookTypeFilter.value : 'all';
        
        courseCards.forEach(card => {
            const cardParent = card.dataset.parentId || 'none';
            const cardSubcategory = card.dataset.subcategoryId || 'none';
            const cardCourseId = card.dataset.courseId || '';
            const cardBookType = card.dataset.booktype || 'general-course';
            
            const matchesParent = parentValue === 'all' || cardParent === parentValue;
            let matchesSubcategory = true;
            if (parentValue !== 'all' && subcategoryValue !== 'all') {
                matchesSubcategory = cardSubcategory === subcategoryValue;
            }
            const matchesCourse = courseValue === 'all' || cardCourseId === courseValue;
            const matchesBookType = bookTypeValue === 'all' || cardBookType === bookTypeValue;
            const matchesFilters = matchesParent && matchesSubcategory && matchesCourse && matchesBookType;
            
            let matchesSearch = true;
            if (searchTerm) {
                const nameMatch = card.dataset.name.toLowerCase().includes(searchTerm);
                const categoryMatch = card.dataset.category.toLowerCase().includes(searchTerm);
                const descriptionMatch = card.dataset.description.toLowerCase().includes(searchTerm);
                
                switch (searchFieldValue) {
                    case 'name':
                        matchesSearch = nameMatch;
                        break;
                    case 'category':
                        matchesSearch = categoryMatch;
                        break;
                    case 'description':
                        matchesSearch = descriptionMatch;
                        break;
                    case 'all':
                    default:
                        matchesSearch = nameMatch || categoryMatch || descriptionMatch;
                        break;
                }
            }
            
            const shouldShow = matchesFilters && matchesSearch;
            card.style.display = shouldShow ? 'block' : 'none';
        });
        
        const visibleCards = Array.from(courseCards).filter(card => card.style.display !== 'none');
        if (visibleCards.length === 0) {
            showNoResults();
        } else {
            hideNoResults();
        }
    }
    
    function showNoResults() {
        let noResults = document.getElementById('no-results');
        if (!noResults) {
            noResults = document.createElement('div');
            noResults.id = 'no-results';
            noResults.className = 'no-courses';
            noResults.innerHTML = `
                <div class='no-courses-icon'>
                    <i class="fa fa-search"></i>
                </div>
                <h3>No Courses Found</h3>
                <p>No courses match your current filters.</p>
            `;
            coursesGrid.appendChild(noResults);
        }
    }
    
    function hideNoResults() {
        const noResults = document.getElementById('no-results');
        if (noResults) {
            noResults.remove();
        }
    }
    
    function handleParentCategoryChange() {
        if (!subcategoryFilter || !courseFilterDropdown) {
            filterCourses();
            return;
        }
        const selectedParent = parentCategoryFilter.value;
        if (selectedParent === 'all') {
            subcategoryFilter.value = 'all';
            subcategoryFilter.disabled = true;
            Array.from(subcategoryFilter.options).forEach(option => {
                option.hidden = option.value !== 'all';
            });
            courseFilterDropdown.value = 'all';
            courseFilterDropdown.disabled = true;
            Array.from(courseFilterDropdown.options).forEach(option => {
                option.hidden = option.value !== 'all';
            });
        } else {
            subcategoryFilter.disabled = false;
            let hasVisibleSub = false;
            Array.from(subcategoryFilter.options).forEach(option => {
                if (option.value === 'all') {
                    option.hidden = false;
                    return;
                }
                const matchesParent = option.dataset.parentId === selectedParent;
                option.hidden = !matchesParent;
                if (matchesParent) {
                    hasVisibleSub = true;
                }
            });
            if (!hasVisibleSub) {
                subcategoryFilter.value = 'all';
                subcategoryFilter.disabled = true;
            } else {
                subcategoryFilter.value = 'all';
            }
            updateCourseFilterOptions();
        }
        filterCourses();
    }
    
    function handleSubcategoryChange() {
        updateCourseFilterOptions();
        filterCourses();
    }
    
    function updateCourseFilterOptions() {
        if (!courseFilterDropdown || !parentCategoryFilter) {
            return;
        }
        const selectedParent = parentCategoryFilter.value;
        const selectedSubcategory = subcategoryFilter ? subcategoryFilter.value : 'all';
        
        if (selectedParent === 'all') {
            courseFilterDropdown.value = 'all';
            courseFilterDropdown.disabled = true;
            Array.from(courseFilterDropdown.options).forEach(option => {
                option.hidden = option.value !== 'all';
            });
            return;
        }
        
        courseFilterDropdown.disabled = false;
        let visibleCount = 0;
        Array.from(courseFilterDropdown.options).forEach(option => {
            if (option.value === 'all') {
                option.hidden = false;
                return;
            }
            const matchesParent = option.dataset.parentId === selectedParent;
            const matchesSub = selectedSubcategory === 'all' || option.dataset.subcategoryId === selectedSubcategory;
            option.hidden = !(matchesParent && matchesSub);
            if (!option.hidden) {
                visibleCount++;
            }
        });
        
        courseFilterDropdown.value = 'all';
        if (visibleCount === 0) {
            courseFilterDropdown.disabled = true;
        }
    }
    
    // Add event listeners
    searchInput.addEventListener('input', filterCourses);
    searchField.addEventListener('change', filterCourses);
    if (parentCategoryFilter) {
        parentCategoryFilter.addEventListener('change', handleParentCategoryChange);
    }
    if (subcategoryFilter) {
        subcategoryFilter.addEventListener('change', handleSubcategoryChange);
    }
    if (courseFilterDropdown) {
        courseFilterDropdown.addEventListener('change', filterCourses);
    }
    if (bookTypeFilter) {
        bookTypeFilter.addEventListener('change', filterCourses);
    }
    
    if (parentCategoryFilter) {
        handleParentCategoryChange();
    } else {
        filterCourses();
    }
});

function viewCourse(courseId) {
    window.location.href = '<?php echo $CFG->wwwroot; ?>/course/view.php?id=' + courseId;
}

function showEnrolledStudents(courseId, totalCount) {
    // Create modal if it doesn't exist
    let modal = document.getElementById('enrolledStudentsModal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'enrolledStudentsModal';
        modal.innerHTML = `
            <div class="modal-overlay" onclick="closeEnrolledModal()">
                <div class="modal-content" onclick="event.stopPropagation()">
                    <div class="modal-header">
                        <h3>Enrolled Students</h3>
                        <button class="modal-close" onclick="closeEnrolledModal()">&times;</button>
                    </div>
                    <div class="modal-body">
                        <div class="search-filter-section">
                            <div class="search-box">
                                <input 
                                    type="text" 
                                    id="studentSearchInput" 
                                    placeholder="Search by name, ID, email, role, or grade..."
                                    onkeyup="filterEnrolledStudents()"
                                />
                                <i class="fa fa-search"></i>
                            </div>
                            <div class="filter-box">
                                <select id="roleFilterSelect" onchange="filterEnrolledStudents()">
                                    <option value="all">All Roles</option>
                                    <option value="student">Student</option>
                                    <option value="editingteacher">Editing Teacher</option>
                                    <option value="teacher">Teacher</option>
                                </select>
                            </div>
                        </div>
                        <div id="enrolledStudentsList">
                            <div class="loading">Loading students...</div>
                        </div>
                    </div>
                </div>
            </div>
        `;
        document.body.appendChild(modal);
    }
    
    // SET GLOBAL FLAG - MODAL IS OPEN (prevents sidebar watchdog from showing it)
    window.isModalOpen = true;
    console.log('🔴 Modal opened - sidebar should be hidden');
    
    // Hide sidebar and adjust main content using setProperty to override !important
    const sidebar = document.querySelector('.school-manager-sidebar');
    if (sidebar) {
        sidebar.style.setProperty('display', 'none', 'important');
        sidebar.style.setProperty('visibility', 'hidden', 'important');
        sidebar.style.setProperty('opacity', '0', 'important');
        sidebar.style.setProperty('transform', 'translateX(-100%)', 'important');
    }
    
    // Adjust main content area to full width
    const mainContent = document.querySelector('.school-manager-main-content');
    if (mainContent) {
        mainContent.style.setProperty('left', '0', 'important');
        mainContent.style.setProperty('width', '100vw', 'important');
    }
    
    // Show modal
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    
    // Load enrolled students
    loadEnrolledStudents(courseId, totalCount);
}

function loadEnrolledStudents(courseId, totalCount) {
    const listContainer = document.getElementById('enrolledStudentsList');
    
    // Show loading state
    listContainer.innerHTML = '<div class="loading">Loading students...</div>';
    
    // Make AJAX request to get enrolled students
    fetch('<?php echo $CFG->wwwroot; ?>/theme/remui_kids/get_enrolled_students.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `course_id=${courseId}&company_id=<?php echo $company_info->id; ?>`
    })
    .then(response => response.json())
    .then(data => {
        console.log('Enrolled Students Data:', data);
        if (data.success) {
            if (data.students.length > 0) {
                // Log first student to verify data structure
                console.log('First Student Data:', data.students[0]);
                
                // Store all students for filtering
                window.allEnrolledStudents = data.students;
                
                let html = `<div class="students-count">
                    <span>Showing: <span class="count-badge" id="displayedCount">${data.students.length}</span> / <span class="count-badge">${data.students.length}</span></span>
                </div>`;
                html += '<div class="students-list" id="studentsListContainer">';
                data.students.forEach(student => {
                    console.log('Student:', student.fullname, 'Role:', student.role, 'Cohort:', student.cohort);
                    
                    // Determine role key for filtering
                    let roleKey = 'student';
                    if (student.role) {
                        if (student.role.toLowerCase().includes('editing')) {
                            roleKey = 'editingteacher';
                        } else if (student.role.toLowerCase().includes('teacher')) {
                            roleKey = 'teacher';
                        }
                    }
                    
                    html += `
                        <div class="student-item" 
                             data-name="${student.fullname.toLowerCase()}"
                             data-id="${student.id}"
                             data-email="${student.email.toLowerCase()}"
                             data-role="${roleKey}"
                             data-role-display="${(student.role || '').toLowerCase()}"
                             data-cohort="${(student.cohort || '').toLowerCase()}">
                            <div class="student-avatar">
                                <i class="fa fa-user"></i>
                            </div>
                            <div class="student-info">
                                <div class="student-name">${student.fullname}</div>
                                <div class="student-email">${student.email}</div>
                                <div class="student-meta">
                                    <span class="student-role ${student.role_class || ''}">${student.role || 'No role'}</span>
                                    <span class="student-cohort ${student.cohort_class || ''}">${student.cohort || 'No cohort'}</span>
                                </div>
                                <div class="student-enrolled-date">Enrolled: ${student.enrolled_date}</div>
                            </div>
                            <div class="student-status">
                                <span class="status-badge enrolled">Enrolled</span>
                            </div>
                        </div>
                    `;
                });
                html += '</div>';
                listContainer.innerHTML = html;
            } else {
                listContainer.innerHTML = '<div class="no-students">No students enrolled in this course.</div>';
            }
        } else {
            listContainer.innerHTML = '<div class="error">Error loading students: ' + data.message + '</div>';
        }
    })
    .catch(error => {
        console.error('Error:', error);
        listContainer.innerHTML = '<div class="error">Error loading students. Please try again.</div>';
    });
}

function closeEnrolledModal() {
    const modal = document.getElementById('enrolledStudentsModal');
    const sidebar = document.querySelector('.school-manager-sidebar');
    
    if (modal) {
        modal.style.display = 'none';
        document.body.style.overflow = 'auto';
        
        // CLEAR GLOBAL FLAG - MODAL IS CLOSED (allows sidebar to show)
        window.isModalOpen = false;
        console.log('🟢 Modal closed - sidebar can be shown');
        
        // Show sidebar again using setProperty to override !important
        if (sidebar) {
            sidebar.style.setProperty('display', 'flex', 'important');
            sidebar.style.setProperty('visibility', 'visible', 'important');
            sidebar.style.setProperty('opacity', '1', 'important');
            sidebar.style.setProperty('transform', 'translateX(0)', 'important');
        }
        
        // Restore main content area position
        const mainContent = document.querySelector('.school-manager-main-content');
        if (mainContent) {
            mainContent.style.setProperty('left', '280px', 'important');
            mainContent.style.setProperty('width', 'calc(100vw - 280px)', 'important');
        }
    }
}

// Filter Enrolled Students Function
function filterEnrolledStudents() {
    const searchInput = document.getElementById('studentSearchInput');
    const roleFilter = document.getElementById('roleFilterSelect');
    
    if (!searchInput || !roleFilter) return;
    
    const searchTerm = searchInput.value.toLowerCase().trim();
    const selectedRole = roleFilter.value.toLowerCase();
    
    const studentItems = document.querySelectorAll('.student-item');
    const totalStudents = studentItems.length;
    let visibleCount = 0;
    
    studentItems.forEach(item => {
        const name = item.getAttribute('data-name') || '';
        const id = item.getAttribute('data-id') || '';
        const email = item.getAttribute('data-email') || '';
        const role = item.getAttribute('data-role') || '';
        const roleDisplay = item.getAttribute('data-role-display') || '';
        const cohort = item.getAttribute('data-cohort') || '';
        
        // Check search term match (searches all fields)
        const matchesSearch = searchTerm === '' || 
            name.includes(searchTerm) ||
            id.includes(searchTerm) ||
            email.includes(searchTerm) ||
            roleDisplay.includes(searchTerm) ||
            cohort.includes(searchTerm);
        
        // Check role filter match
        const matchesRole = selectedRole === 'all' || role === selectedRole;
        
        // Show item if it matches both search and filter
        if (matchesSearch && matchesRole) {
            item.style.display = 'flex';
            visibleCount++;
        } else {
            item.style.display = 'none';
        }
    });
    
    // Update displayed count
    const displayedCountElement = document.getElementById('displayedCount');
    if (displayedCountElement) {
        displayedCountElement.textContent = visibleCount;
    }
    
    // Show "no results" message if no students match
    const listContainer = document.getElementById('studentsListContainer');
    if (listContainer) {
        let noResultsDiv = listContainer.querySelector('.no-results-message');
        
        if (visibleCount === 0) {
            if (!noResultsDiv) {
                noResultsDiv = document.createElement('div');
                noResultsDiv.className = 'no-results-message';
                noResultsDiv.innerHTML = `
                    <div style="text-align: center; padding: 40px; color: #6c757d;">
                        <i class="fa fa-search" style="font-size: 3rem; margin-bottom: 15px; opacity: 0.3;"></i>
                        <h4 style="margin: 10px 0; color: #495057;">No Results Found</h4>
                        <p style="margin: 0; font-size: 0.9rem;">Try adjusting your search or filter criteria</p>
                    </div>
                `;
                listContainer.appendChild(noResultsDiv);
            }
        } else {
            if (noResultsDiv) {
                noResultsDiv.remove();
            }
        }
    }
}

// Enrollment Modal Functions
function openEnrollmentModal(courseId, courseName) {
    const modal = document.getElementById('enrollmentModal');
    const courseIdInput = document.getElementById('selectedCourseId');
    const courseNameInput = document.getElementById('selectedCourseName');
    const startDateInput = document.getElementById('enrollmentStartDate');
    const sidebar = document.querySelector('.school-manager-sidebar');
    
    // Set course ID and name
    courseIdInput.value = courseId;
    courseNameInput.value = courseName;
    
    // Set today's date as default start date
    const today = new Date().toISOString().split('T')[0];
    startDateInput.value = today;
    
    // Reset form fields
    document.getElementById('enrollmentRole').value = '';
    document.getElementById('userSearch').value = '';
    document.getElementById('cohortSearch').value = '';
    document.getElementById('enrollmentEndDate').value = '';
    document.getElementById('selectedUsers').innerHTML = '';
    document.getElementById('selectedUsersContainer').style.display = 'none';
    document.getElementById('userSearchResults').innerHTML = '';
    document.getElementById('userSearchResults').style.display = 'none';
    document.getElementById('selectedCohorts').innerHTML = '';
    document.getElementById('selectedCohortsContainer').style.display = 'none';
    const cohortResults = document.getElementById('cohortSearchResults');
    cohortResults.innerHTML = '';
    cohortResults.style.display = 'none';
    cohortResults.classList.remove('cohort-dropdown-visible');
    
    // Clear selected arrays and enrollment groups
    selectedUsers = [];
    selectedCohorts = [];
    enrollmentGroups = [];
    currentRole = '';
    
    // Clear multi-role display if it exists
    const summaryContainer = document.getElementById('multi-role-summary');
    if (summaryContainer) {
        summaryContainer.innerHTML = '';
        summaryContainer.style.display = 'none';
    }
    
    // DON'T auto-load cohorts - only load when user clicks the field
    console.log('🎯 Modal opened - cohorts will load when field is clicked');
    
    // SET GLOBAL FLAG - MODAL IS OPEN (prevents sidebar watchdog from showing it)
    window.isModalOpen = true;
    console.log('🔴 Enrollment Modal opened - sidebar should be hidden');
    
    // Hide sidebar using setProperty to override !important
    if (sidebar) {
        sidebar.style.setProperty('display', 'none', 'important');
        sidebar.style.setProperty('visibility', 'hidden', 'important');
        sidebar.style.setProperty('opacity', '0', 'important');
        sidebar.style.setProperty('transform', 'translateX(-100%)', 'important');
    }
    
    // Adjust main content area to full width
    const mainContent = document.querySelector('.school-manager-main-content');
    if (mainContent) {
        mainContent.style.setProperty('left', '0', 'important');
        mainContent.style.setProperty('width', '100vw', 'important');
    }
    
    // Show modal
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeEnrollmentModal() {
    const modal = document.getElementById('enrollmentModal');
    const sidebar = document.querySelector('.school-manager-sidebar');
    
    if (modal) {
        modal.style.display = 'none';
        document.body.style.overflow = 'auto';
        
        // CLEAR GLOBAL FLAG - MODAL IS CLOSED (allows sidebar to show)
        window.isModalOpen = false;
        console.log('🟢 Enrollment Modal closed - sidebar can be shown');
        
        // Show sidebar again using setProperty to override !important
        if (sidebar) {
            sidebar.style.setProperty('display', 'flex', 'important');
            sidebar.style.setProperty('visibility', 'visible', 'important');
            sidebar.style.setProperty('opacity', '1', 'important');
            sidebar.style.setProperty('transform', 'translateX(0)', 'important');
        }
        
        // Restore main content area position
        const mainContent = document.querySelector('.school-manager-main-content');
        if (mainContent) {
            mainContent.style.setProperty('left', '280px', 'important');
            mainContent.style.setProperty('width', 'calc(100vw - 280px)', 'important');
        }
        
        // Reset form
        document.getElementById('enrollmentForm').reset();
        document.getElementById('selectedUsers').innerHTML = '';
        document.getElementById('userSearchResults').innerHTML = '';
        document.getElementById('userSearchResults').style.display = 'none';
        document.getElementById('selectedUsersContainer').style.display = 'none';
        document.getElementById('selectedCohorts').innerHTML = '';
        document.getElementById('cohortSearchResults').innerHTML = '';
        document.getElementById('cohortSearchResults').style.display = 'none';
        document.getElementById('selectedCohortsContainer').style.display = 'none';
        
        // Clear selected arrays and enrollment groups
        selectedUsers = [];
        selectedCohorts = [];
        enrollmentGroups = [];
        currentRole = '';
        
        // Clear multi-role display if it exists
        const summaryContainer = document.getElementById('multi-role-summary');
        if (summaryContainer) {
            summaryContainer.innerHTML = '';
            summaryContainer.style.display = 'none';
        }
    }
}

// User and Cohort search functionality - Multi-role support
let enrollmentGroups = [];  // Array of {role, users[], cohorts[]}
let selectedUsers = [];  // Temporary for current role
let selectedCohorts = [];  // Temporary for current role
let currentRole = '';  // Track current selected role
let searchTimeout;
let cohortSearchTimeout;

document.addEventListener('DOMContentLoaded', function() {
    const userSearch = document.getElementById('userSearch');
    const userSearchResults = document.getElementById('userSearchResults');
    const cohortSearch = document.getElementById('cohortSearch');
    const cohortSearchResults = document.getElementById('cohortSearchResults');
    const roleSelect = document.getElementById('enrollmentRole');
    
    // Role change handler - Save current selections before switching roles
    if (roleSelect) {
        roleSelect.addEventListener('change', function() {
            const newRole = this.value;
            
            console.log('Role changed from', currentRole, 'to', newRole);
            
            // Save current selections before switching
            if (currentRole && (selectedUsers.length > 0 || selectedCohorts.length > 0)) {
                saveCurrentRoleSelections(currentRole);
            }
            
            // Update current role
            currentRole = newRole;
            
            // Load existing selections for this role (if switching back)
            loadRoleSelections(newRole);
            
            // Clear search
            userSearchResults.innerHTML = '';
            userSearchResults.style.display = 'none';
            userSearch.value = '';
            
            // Show hint based on selected role
            if (newRole) {
                const roleNames = {
                    'student': 'students',
                    'teacher': 'teachers',
                    'editingteacher': 'editing teachers'
                };
                userSearch.placeholder = `Search ${roleNames[newRole] || 'users'}...`;
                
                // AUTO-LOAD all users of this role immediately
                loadAllUsersByRole(newRole);
            } else {
                userSearch.placeholder = 'Search users...';
                userSearchResults.innerHTML = '';
                userSearchResults.style.display = 'none';
            }
            
            // Update display to show all enrollment groups
            updateMultiRoleDisplay();
        });
    }
    
    // User search handler with role filtering
    if (userSearch) {
        // Search as user types
        userSearch.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            const query = this.value.trim();
            
            // Get selected role for filtering
            const selectedRole = roleSelect ? roleSelect.value : '';
            
            // If query is less than 2 characters, reload full list for the selected role
            if (query.length < 2) {
                if (selectedRole) {
                    // Reload full list of users for this role
                    loadAllUsersByRole(selectedRole);
                } else {
                    // No role selected, clear results
                    userSearchResults.innerHTML = '';
                    userSearchResults.style.display = 'none';
                }
                return;
            }
            
            // Query is 2+ characters, perform search
            searchTimeout = setTimeout(() => {
                searchUsers(query, selectedRole);
            }, 300);
        });
        
        // Hide results when field loses focus (unless clicking on results)
        userSearch.addEventListener('blur', function() {
            setTimeout(() => {
                const userSearchResults = document.getElementById('userSearchResults');
                if (userSearchResults && !userSearchResults.matches(':hover')) {
                    userSearchResults.style.display = 'none';
                }
            }, 200);
        });
        
        // Show results when field is focused
        userSearch.addEventListener('focus', function() {
            const userSearchResults = document.getElementById('userSearchResults');
            const roleSelect = document.getElementById('enrollmentRole');
            const selectedRole = roleSelect ? roleSelect.value : '';
            
            // If results already exist, just show them
            if (userSearchResults && userSearchResults.innerHTML.trim() !== '') {
                userSearchResults.style.display = 'block';
            } else if (selectedRole) {
                // If role is selected but no results loaded yet, auto-load all users
                loadAllUsersByRole(selectedRole);
            }
        });
    }
    
    // Cohort search handler - Load all cohorts on focus, filter on input
    if (cohortSearch) {
        // Load all cohorts when field is clicked or focused - always show dropdown
        cohortSearch.addEventListener('click', function() {
            const cohortSearchResults = document.getElementById('cohortSearchResults');
            
            // Always reload to show fresh cohort list
            searchCohorts(this.value.trim());
        });
        
        cohortSearch.addEventListener('focus', function() {
            const cohortSearchResults = document.getElementById('cohortSearchResults');
            
            // If results already loaded, just show them
            if (cohortSearchResults && cohortSearchResults.innerHTML.trim() !== '') {
                cohortSearchResults.style.display = 'block';
            } else {
                // Load all cohorts
                searchCohorts('');
            }
        });
        
        // Search cohorts as user types
        cohortSearch.addEventListener('input', function() {
            clearTimeout(cohortSearchTimeout);
            const query = this.value.trim();
            
            // If empty, show all cohorts, otherwise filter
            cohortSearchTimeout = setTimeout(() => {
                searchCohorts(query);
            }, 300);
        });
        
        // Auto-close dropdown when field loses focus
        cohortSearch.addEventListener('blur', function() {
            setTimeout(() => {
                const cohortSearchResults = document.getElementById('cohortSearchResults');
                if (cohortSearchResults && !cohortSearchResults.matches(':hover')) {
                    // Close dropdown automatically
                    cohortSearchResults.style.display = 'none';
                    cohortSearchResults.classList.remove('cohort-dropdown-visible');
                    console.log('✅ Cohort dropdown auto-closed (no selection)');
                }
            }, 200);
        });
    }
    
    // Handle form submission
    const enrollmentForm = document.getElementById('enrollmentForm');
    if (enrollmentForm) {
        enrollmentForm.addEventListener('submit', function(e) {
            e.preventDefault();
            submitEnrollment();
        });
    }
    
    // Global click handler to close cohort dropdown when clicking outside
    document.addEventListener('click', function(event) {
        const cohortSearch = document.getElementById('cohortSearch');
        const cohortSearchResults = document.getElementById('cohortSearchResults');
        
        if (cohortSearch && cohortSearchResults) {
            // Check if click is outside both the search field and results
            const isClickInsideCohortArea = cohortSearch.contains(event.target) || 
                                           cohortSearchResults.contains(event.target);
            
            if (!isClickInsideCohortArea && cohortSearchResults.style.display === 'block') {
                // Click is outside - close the dropdown
                cohortSearchResults.style.display = 'none';
                cohortSearchResults.classList.remove('cohort-dropdown-visible');
                console.log('✅ Cohort dropdown closed (clicked outside)');
            }
        }
    });
});

// Load all users by role (when role is selected)
function loadAllUsersByRole(role) {
    const userSearchResults = document.getElementById('userSearchResults');
    
    if (!role) {
        userSearchResults.innerHTML = '';
        userSearchResults.style.display = 'none';
        return;
    }
    
    // Show loading state with role-specific message
    const roleNames = {
        'student': 'students',
        'teacher': 'teachers',
        'editingteacher': 'editing teachers'
    };
    userSearchResults.innerHTML = `<div class="loading">Loading ${roleNames[role] || 'users'}...</div>`;
    userSearchResults.style.display = 'block';
    
    // Make AJAX request to load all users of this role
    fetch('<?php echo $CFG->wwwroot; ?>/theme/remui_kids/search_users.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `query=&company_id=<?php echo $company_info->id; ?>&role=${encodeURIComponent(role)}&load_all=1`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            displayUserSearchResults(data.users, role);
        } else {
            userSearchResults.innerHTML = '<div class="error">Error loading users: ' + data.message + '</div>';
            userSearchResults.style.display = 'block';
        }
    })
    .catch(error => {
        console.error('Error loading users:', error);
        userSearchResults.innerHTML = '<div class="error">Error loading users. Please try again.</div>';
        userSearchResults.style.display = 'block';
    });
}

function searchUsers(query, role = '') {
    const userSearchResults = document.getElementById('userSearchResults');
    const roleSelect = document.getElementById('enrollmentRole');
    
    // Check if role is selected
    if (!role && roleSelect) {
        role = roleSelect.value;
    }
    
    // Require role selection before searching users
    if (!role) {
        userSearchResults.innerHTML = '<div class="no-results">Please select a role first</div>';
        userSearchResults.style.display = 'block';
        return;
    }
    
    // Show loading state with role-specific message
    const roleNames = {
        'student': 'students',
        'teacher': 'teachers',
        'editingteacher': 'editing teachers'
    };
    userSearchResults.innerHTML = `<div class="loading">Searching ${roleNames[role] || 'users'}...</div>`;
    userSearchResults.style.display = 'block';
    
    // Make AJAX request to search users with role filter
    fetch('<?php echo $CFG->wwwroot; ?>/theme/remui_kids/search_users.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `query=${encodeURIComponent(query)}&company_id=<?php echo $company_info->id; ?>&role=${encodeURIComponent(role)}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            displayUserSearchResults(data.users, role);
        } else {
            userSearchResults.innerHTML = '<div class="error">Error searching users: ' + data.message + '</div>';
            userSearchResults.style.display = 'block';
        }
    })
    .catch(error => {
        console.error('Error:', error);
        userSearchResults.innerHTML = '<div class="error">Error searching users. Please try again.</div>';
        userSearchResults.style.display = 'block';
    });
}

function displayUserSearchResults(users, role = '') {
    const userSearchResults = document.getElementById('userSearchResults');
    
    // Show role-specific message if no users found
    if (users.length === 0) {
        const roleNames = {
            'student': 'students',
            'teacher': 'teachers',
            'editingteacher': 'editing teachers'
        };
        const roleName = roleNames[role] || 'users';
        userSearchResults.innerHTML = `
            <div class="no-results">
                <i class="fa fa-info-circle"></i>
                No ${roleName} found in your school.
                <br><small>Make sure users are assigned to your school and have the correct role.</small>
            </div>`;
        userSearchResults.style.display = 'block';
        return;
    }
    
    let html = '<div class="user-list">';
    users.forEach(user => {
        const isSelected = selectedUsers.some(u => u.id === user.id);
        html += `
            <div class="user-item ${isSelected ? 'selected' : ''}" onclick="toggleUser(${user.id}, '${user.fullname.replace(/'/g, "\\'")}', '${user.email.replace(/'/g, "\\'")}')">
                <div class="user-avatar">
                    <i class="fa fa-user"></i>
                </div>
                <div class="user-info">
                    <div class="user-name">${user.fullname}</div>
                    <div class="user-email">${user.email}</div>
                </div>
                <div class="user-action">
                    <i class="fa fa-${isSelected ? 'check' : 'plus'}"></i>
                </div>
            </div>
        `;
    });
    html += '</div>';
    
    userSearchResults.innerHTML = html;
    userSearchResults.style.display = 'block';
}

function toggleUser(userId, userName, userEmail) {
    const existingUser = selectedUsers.find(u => u.id === userId);
    
    if (existingUser) {
        // Remove user from selection
        selectedUsers = selectedUsers.filter(u => u.id !== userId);
    } else {
        // Add user to selection
        selectedUsers.push({
            id: userId,
            name: userName,
            email: userEmail
        });
    }
    
    // Update displays
    updateSelectedUsers();
    updateUserSearchResults();
}

function updateSelectedUsers() {
    const selectedUsersContainer = document.getElementById('selectedUsers');
    const selectedUsersSection = document.getElementById('selectedUsersContainer');
    
    if (selectedUsers.length === 0) {
        selectedUsersContainer.innerHTML = '<div class="no-selection-message">No users selected</div>';
        selectedUsersSection.style.display = 'none';
        return;
    }
    
    selectedUsersSection.style.display = 'block';
    
    let html = '';
    selectedUsers.forEach(user => {
        html += `
            <div class="selected-user-badge">
                <div class="user-avatar">
                    <i class="fa fa-user"></i>
                </div>
                <div class="user-info">
                    <div class="user-name">${user.name}</div>
                    <div class="user-email">${user.email}</div>
                </div>
                <button type="button" onclick="removeUser(${user.id})" class="remove-user-btn" title="Remove user">
                    <i class="fa fa-times"></i>
                </button>
            </div>
        `;
    });
    
    selectedUsersContainer.innerHTML = html;
}

function removeUser(userId) {
    selectedUsers = selectedUsers.filter(u => u.id !== userId);
    updateSelectedUsers();
    updateUserSearchResults();
    updateMultiRoleDisplay();  // Update multi-role display
}

// Multi-role enrollment functions
function saveCurrentRoleSelections(role) {
    if (!role) return;
    
    console.log('💾 Saving selections for role:', role, 'Users:', selectedUsers.length, 'Cohorts:', selectedCohorts.length);
    
    // Find existing group for this role
    const existingIndex = enrollmentGroups.findIndex(g => g.role === role);
    
    const group = {
        role: role,
        users: [...selectedUsers],
        cohorts: [...selectedCohorts]
    };
    
    if (existingIndex >= 0) {
        // Update existing group
        enrollmentGroups[existingIndex] = group;
    } else {
        // Add new group
        enrollmentGroups.push(group);
    }
    
    console.log('✅ Saved! Total groups:', enrollmentGroups.length);
}

function loadRoleSelections(role) {
    if (!role) {
        selectedUsers = [];
        selectedCohorts = [];
        updateSelectedUsers();
        updateSelectedCohorts();
        return;
    }
    
    console.log('📂 Loading selections for role:', role);
    
    // Find existing group for this role
    const group = enrollmentGroups.find(g => g.role === role);
    
    if (group) {
        selectedUsers = [...group.users];
        selectedCohorts = [...group.cohorts];
        console.log('✅ Loaded', selectedUsers.length, 'users and', selectedCohorts.length, 'cohorts');
    } else {
        selectedUsers = [];
        selectedCohorts = [];
        console.log('ℹ️ No previous selections for this role');
    }
    
    updateSelectedUsers();
    updateSelectedCohorts();
}

function updateMultiRoleDisplay() {
    // Save current role selections first
    if (currentRole && (selectedUsers.length > 0 || selectedCohorts.length > 0)) {
        saveCurrentRoleSelections(currentRole);
    }
    
    console.log('🎨 Updating multi-role display. Total groups:', enrollmentGroups.length);
    
    // Create or update a summary display
    const roleSelect = document.getElementById('enrollmentRole');
    let summaryContainer = document.getElementById('multi-role-summary');
    
    if (!summaryContainer) {
        // Create summary container after role select
        summaryContainer = document.createElement('div');
        summaryContainer.id = 'multi-role-summary';
        summaryContainer.style.cssText = 'margin: 1rem 0; padding: 1rem; background: #f0f9ff; border: 2px solid #3b82f6; border-radius: 8px;';
        roleSelect.parentElement.parentElement.insertAdjacentElement('afterend', summaryContainer);
    }
    
    if (enrollmentGroups.length === 0) {
        summaryContainer.innerHTML = '';
        summaryContainer.style.display = 'none';
        return;
    }
    
    summaryContainer.style.display = 'block';
    
    const roleNames = {
        'student': 'Student',
        'teacher': 'Teacher',
        'editingteacher': 'Editing Teacher'
    };
    
    let html = '<div style="font-weight: 600; color: #1e40af; margin-bottom: 0.5rem; font-size: 0.9rem;">✅ Selected for Enrollment:</div>';
    
    enrollmentGroups.forEach((group, index) => {
        const totalItems = group.users.length + group.cohorts.length;
        html += `
            <div style="background: white; padding: 0.75rem; margin-bottom: 0.5rem; border-radius: 6px; border: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <span style="background: #3b82f6; color: white; padding: 3px 10px; border-radius: 12px; font-size: 0.8rem; font-weight: 600; margin-right: 0.5rem;">${roleNames[group.role] || group.role}</span>
                    <span style="color: #6b7280; font-size: 0.85rem;">${totalItems} item(s) selected</span>
                </div>
                <button type="button" onclick="removeEnrollmentGroup(${index})" style="background: #dc2626; color: white; border: none; padding: 4px 10px; border-radius: 4px; font-size: 0.75rem; cursor: pointer;">
                    <i class="fa fa-trash"></i> Remove
                </button>
            </div>
        `;
    });
    
    summaryContainer.innerHTML = html;
    console.log('✅ Display updated');
}

function removeEnrollmentGroup(index) {
    console.log('🗑️ Removing enrollment group at index:', index);
    enrollmentGroups.splice(index, 1);
    updateMultiRoleDisplay();
}

function updateUserSearchResults() {
    const userItems = document.querySelectorAll('#userSearchResults .user-item');
    userItems.forEach(item => {
        const userId = parseInt(item.onclick.toString().match(/toggleUser\((\d+)/)[1]);
        const isSelected = selectedUsers.some(u => u.id === userId);
        
        // Update selected state
        item.classList.toggle('selected', isSelected);
        
        // Update icon
        const icon = item.querySelector('.user-action i');
        if (icon) {
            icon.className = `fa fa-${isSelected ? 'check' : 'plus'}`;
        }
    });
}

// Cohort search functionality - SAME LOGIC AS USER SEARCH
function searchCohorts(query) {
    const cohortSearchResults = document.getElementById('cohortSearchResults');
    
    console.log('🔍 Searching cohorts with query:', query);
    
    // Show loading state and ensure visibility
    cohortSearchResults.innerHTML = '<div class="loading">Loading cohorts...</div>';
    cohortSearchResults.style.display = 'block';
    cohortSearchResults.classList.add('cohort-dropdown-visible');
    
    // Make AJAX request to search cohorts (same pattern as user search)
    fetch('<?php echo $CFG->wwwroot; ?>/theme/remui_kids/search_cohorts.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `query=${encodeURIComponent(query)}&company_id=<?php echo $company_info->id; ?>`
    })
    .then(response => response.json())
    .then(data => {
        console.log('✅ Cohorts loaded:', data);
        if (data.success) {
            if (data.cohorts && data.cohorts.length > 0) {
                console.log(`📊 Found ${data.cohorts.length} cohorts`);
                displayCohortSearchResults(data.cohorts);
            } else {
                console.warn('⚠️ No cohorts found');
                cohortSearchResults.innerHTML = '<div class="no-results"><i class="fa fa-info-circle"></i> No cohorts available in your school.</div>';
                cohortSearchResults.style.display = 'block';
            }
        } else {
            console.error('❌ Cohort search failed:', data.message);
            cohortSearchResults.innerHTML = '<div class="error">Error: ' + data.message + '</div>';
            cohortSearchResults.style.display = 'block';
        }
    })
    .catch(error => {
        console.error('❌ Network error loading cohorts:', error);
        cohortSearchResults.innerHTML = '<div class="error">Error loading cohorts. Please try again.</div>';
        cohortSearchResults.style.display = 'block';
    });
}

function displayCohortSearchResults(cohorts) {
    const cohortSearchResults = document.getElementById('cohortSearchResults');
    
    console.log('📋 Displaying cohorts:', cohorts);
    
    // Show message if no cohorts found
    if (!cohorts || cohorts.length === 0) {
        cohortSearchResults.innerHTML = '<div class="no-results"><i class="fa fa-info-circle"></i> No cohorts available in your school.</div>';
        cohortSearchResults.style.display = 'block';
        return;
    }
    
    // Build cohort list without "None" option
    let html = '<div class="user-list">';
    
    cohorts.forEach(cohort => {
        const isSelected = selectedCohorts.some(c => c.id === cohort.id);
        const memberText = cohort.member_count === 1 ? '1 member' : `${cohort.member_count} members`;
        html += `
            <div class="user-item ${isSelected ? 'selected' : ''}" onclick="toggleCohort(${cohort.id}, '${cohort.name.replace(/'/g, "\\'")}', ${cohort.member_count})">
                <div class="user-avatar">
                    <i class="fa fa-users"></i>
                </div>
                <div class="user-info">
                    <div class="user-name">${cohort.name}</div>
                    <div class="user-email">${memberText}</div>
                </div>
                <div class="user-action">
                    <i class="fa fa-${isSelected ? 'check' : 'plus'}"></i>
                </div>
            </div>
        `;
    });
    html += '</div>';
    
    cohortSearchResults.innerHTML = html;
    cohortSearchResults.style.display = 'block';
    cohortSearchResults.classList.add('cohort-dropdown-visible');
    console.log('✅ Cohort dropdown displayed with', cohorts.length, 'items');
}

function toggleCohort(cohortId, cohortName, memberCount) {
    const existingCohort = selectedCohorts.find(c => c.id === cohortId);
    
    if (existingCohort) {
        // Remove cohort from selection
        selectedCohorts = selectedCohorts.filter(c => c.id !== cohortId);
    } else {
        // Add cohort to selection
        selectedCohorts.push({
            id: cohortId,
            name: cohortName,
            member_count: memberCount
        });
    }
    
    // Update displays
    updateSelectedCohorts();
    updateCohortSearchResults();
}

function updateSelectedCohorts() {
    const selectedCohortsContainer = document.getElementById('selectedCohorts');
    const selectedCohortsSection = document.getElementById('selectedCohortsContainer');
    
    // Same pattern as updateSelectedUsers
    if (selectedCohorts.length === 0) {
        selectedCohortsContainer.innerHTML = '<div class="no-selection-message">No cohorts selected</div>';
        selectedCohortsSection.style.display = 'none';
        return;
    }
    
    // Show the container
    selectedCohortsSection.style.display = 'block';
    
    // Build HTML for selected cohorts (same structure as users)
    let html = '';
    selectedCohorts.forEach(cohort => {
        html += `
            <div class="selected-user-badge">
                <div class="user-avatar">
                    <i class="fa fa-users"></i>
                </div>
                <div class="user-info">
                    <div class="user-name">${cohort.name}</div>
                    <div class="user-email">${cohort.member_count} members</div>
                </div>
                <button type="button" onclick="removeCohort(${cohort.id})" class="remove-user-btn" title="Remove cohort">
                    <i class="fa fa-times"></i>
                </button>
            </div>
        `;
    });
    
    selectedCohortsContainer.innerHTML = html;
}

function removeCohort(cohortId) {
    // Same pattern as removeUser
    selectedCohorts = selectedCohorts.filter(c => c.id !== cohortId);
    updateSelectedCohorts();
    updateCohortSearchResults();
    updateMultiRoleDisplay();  // Update multi-role display
}

function updateCohortSearchResults() {
    const cohortItems = document.querySelectorAll('#cohortSearchResults .user-item');
    cohortItems.forEach(item => {
        const cohortId = parseInt(item.onclick.toString().match(/toggleCohort\((\d+)/)[1]);
        const isSelected = selectedCohorts.some(c => c.id === cohortId);
        
        // Update selected state
        item.classList.toggle('selected', isSelected);
        
        // Update icon
        const icon = item.querySelector('.user-action i');
        if (icon) {
            icon.className = `fa fa-${isSelected ? 'check' : 'plus'}`;
        }
    });
}

function submitEnrollment() {
    // Save current role selections if any
    if (currentRole && (selectedUsers.length > 0 || selectedCohorts.length > 0)) {
        saveCurrentRoleSelections(currentRole);
    }
    
    // Validate that at least one role group has users or cohorts
    if (enrollmentGroups.length === 0) {
        alert('Please select at least one role with users or cohorts to enroll.');
        return;
    }
    
    const formData = new FormData(document.getElementById('enrollmentForm'));
    const courseId = formData.get('course_id');
    const endDate = formData.get('end_date');
    const startDate = formData.get('start_date');
    
    // Show loading
    const submitBtn = document.querySelector('#enrollmentForm button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Enrolling...';
    submitBtn.disabled = true;
    
    console.log('📤 Submitting enrollment for', enrollmentGroups.length, 'role group(s)');
    
    // Prepare enrollment data with ALL role groups
    const enrollmentData = {
        course_id: courseId,
        start_date: startDate,
        end_date: endDate,
        enrollment_groups: enrollmentGroups,  // Send all role groups
        company_id: <?php echo $company_info->id; ?>
    };
    
    console.log('Enrollment data:', enrollmentData);
    
    // Make AJAX request to enroll users
    fetch('<?php echo $CFG->wwwroot; ?>/theme/remui_kids/enroll_users.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(enrollmentData)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            let message = 'Successfully enrolled:\n';
            if (data.enrolled_users > 0) {
                message += `- ${data.enrolled_users} individual users\n`;
            }
            if (data.enrolled_cohort_members > 0) {
                message += `- ${data.enrolled_cohort_members} users from cohorts\n`;
            }
            message += `\nTotal: ${data.total_enrolled} enrollments`;
            
            alert(message);
            closeEnrollmentModal();
            // Refresh the page to update enrollment counts
            location.reload();
        } else {
            alert('Error enrolling users: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error enrolling users. Please try again.');
    })
    .finally(() => {
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    });
}
</script>

<?php
echo $OUTPUT->footer();
?>