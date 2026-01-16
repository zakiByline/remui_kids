<?php
/**
 * Elementary My Course Page
 * 
 * This file handles the elementary my course page for Grades 1-3 students
 * with Moodle navigation bar integration and enhanced course data.
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lang_init.php'); // Apply user's selected language
require_once($CFG->libdir.'/adminlib.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/lib/cohort_sidebar_helper.php');
require_once(__DIR__ . '/lib/cohort_sidebar_helper.php');
require_login();

global $USER, $PAGE, $OUTPUT, $DB;

// Set page context
$PAGE->set_context(context_system::instance());
$PAGE->set_url('/theme/remui_kids/elementary_my_course.php');
$PAGE->set_title('My Courses - Elementary Dashboard');
$PAGE->set_heading('My Amazing Courses');
$PAGE->set_pagelayout('mydashboard');

// Check if user is elementary student (Grades 1-3) - simplified check
try {
    $user_cohorts = $DB->get_records_sql(
        "SELECT c.id, c.name, c.idnumber 
         FROM {cohort} c
         JOIN {cohort_members} cm ON c.id = cm.cohortid
         WHERE cm.userid = ? AND (c.idnumber LIKE ? OR c.idnumber LIKE ? OR c.idnumber LIKE ? OR c.idnumber LIKE ?)",
        [$USER->id, 'grade1%', 'grade2%', 'grade3%', 'elementary%']
    );
} catch (Exception $e) {
    // If cohort check fails, just set empty array and continue
    $user_cohorts = [];
}

// For now, allow all logged-in users to access this page
// You can uncomment the redirect below if you want to restrict access
/*
if (empty($user_cohorts)) {
    // Redirect to regular dashboard if not elementary student
    redirect(new moodle_url('/my/'));
}
*/

$coursecoverdefaults = [];
$coursecovercycle = [];
$covercycleindex = 0;

if (!function_exists('theme_remui_kids_slugify')) {
    function theme_remui_kids_slugify(string $text): string {
        $text = strtolower($text);
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
            // Check Student Course FIRST (before Student Book) to avoid conflicts
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

        // Check in order - Student Course must be checked before Student Book
        foreach ($bookTypeKeywords as $label => $keywords) {
            if (theme_remui_kids_course_keyword_match($haystack, $keywords)) {
                return $label;
            }
        }

        // Try extracting from fullname first part
        $derivedLabel = theme_remui_kids_extract_label_from_fullname($fullname);
        if ($derivedLabel !== '') {
            // Check if derived label matches any known type (case-insensitive)
            $derivedLower = strtolower($derivedLabel);
            foreach ($bookTypeKeywords as $label => $keywords) {
                foreach ($keywords as $keyword) {
                    if (strtolower($keyword) === $derivedLower || strpos($derivedLower, strtolower($keyword)) !== false) {
                        return $label;
                    }
                }
            }
            // If it contains "student" and "course", return Student Course
            if (stripos($derivedLabel, 'student') !== false && stripos($derivedLabel, 'course') !== false) {
                return 'Student Course';
            }
            // If it's a known type, return it
            if (in_array($derivedLabel, array_keys($bookTypeKeywords))) {
                return $derivedLabel;
            }
        }

        if (!empty($shortname)) {
            // Check shortname against keywords too
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

$coursecoverdefaults = [
    'student_book' => $CFG->wwwroot . '/theme/remui_kids/CradsImg/Gemini_Generated_Image_96dybo96dybo96dy.png',
    'student_course' => $CFG->wwwroot . '/theme/remui_kids/CradsImg/Gemini_Generated_Image_hcwxdbhcwxdbhcwx.png',
    'teacher_resource' => $CFG->wwwroot . '/theme/remui_kids/CradsImg/Gemini_Generated_Image_7xb0pl7xb0pl7xb0.png',
    'worksheet_pack' => $CFG->wwwroot . '/theme/remui_kids/CradsImg/Gemini_Generated_Image_ciywx0ciywx0ciyw.png',
    'teacher_guide' => $CFG->wwwroot . '/theme/remui_kids/CradsImg/Gemini_Generated_Image_k3ktqnk3ktqnk3kt.png',
    'practice_book' => $CFG->wwwroot . '/theme/remui_kids/CradsImg/Gemini_Generated_Image_hz61skhz61skhz61.png',
    'teacher_book' => $CFG->wwwroot . '/theme/remui_kids/CradsImg/Gemini_Generated_Image_kmjtndkmjtndkmjt.png',
    'assessment_book' => $CFG->wwwroot . '/theme/remui_kids/CradsImg/Gemini_Generated_Image_86ksa986ksa986ks.png'
];
$coursecovercycle = array_values($coursecoverdefaults);

// Get user's courses with error handling
try {
    $courses = enrol_get_users_courses($USER->id, true, ['id', 'fullname', 'shortname', 'summary', 'startdate', 'enddate', 'category', 'idnumber']);
} catch (Exception $e) {
    // If course retrieval fails, set empty array
    $courses = [];
}

$course_data = [];

foreach ($courses as $course) {
    try {
        $coursecontext = context_course::instance($course->id);
        
        // Get category information (name and idnumber)
        $category = $DB->get_record('course_categories', ['id' => $course->category]);
        $categoryname = $category ? $category->name : 'General';
        $categoryidnumber = $category ? $category->idnumber : '';
        
        // Get course image - Compatible with all Moodle versions
        $courseimage = '';
        $fs = get_file_storage();
        
        try {
            // Method 1: Get course overview files (standard location for course images)
            $files = $fs->get_area_files($coursecontext->id, 'course', 'overviewfiles', 0, 'timemodified DESC', false);
            
            if (!empty($files)) {
                foreach ($files as $file) {
                    // Skip directories
                    if ($file->is_directory()) {
                        continue;
                    }
                    
                    // Check if it's an image file
                    $mimetype = $file->get_mimetype();
                    if (strpos($mimetype, 'image/') === 0) {
                        $courseimage = moodle_url::make_pluginfile_url(
                            $file->get_contextid(),
                            $file->get_component(),
                            $file->get_filearea(),
                            null,
                            $file->get_filepath(),
                            $file->get_filename()
                        )->out();
                        
                        error_log("Course {$course->id} ({$course->fullname}) - Image found: {$courseimage}");
                        break; // Use first image found
                    }
                }
            }
            
            // Method 2: Try course summary files if no overview image
            if (empty($courseimage)) {
                $summaryfiles = $fs->get_area_files($coursecontext->id, 'course', 'summary', 0, 'timemodified DESC', false);
                
                foreach ($summaryfiles as $file) {
                    if ($file->is_directory()) {
                        continue;
                    }
                    
                    $mimetype = $file->get_mimetype();
                    if (strpos($mimetype, 'image/') === 0) {
                        $courseimage = moodle_url::make_pluginfile_url(
                            $file->get_contextid(),
                            $file->get_component(),
                            $file->get_filearea(),
                            $file->get_itemid(),
                            $file->get_filepath(),
                            $file->get_filename()
                        )->out();
                        
                        error_log("Course {$course->id} ({$course->fullname}) - Image from summary: {$courseimage}");
                        break;
                    }
                }
            }
            
            // Method 3: Check course->overviewfiles property (some Moodle versions)
            if (empty($courseimage) && isset($course->overviewfiles) && !empty($course->overviewfiles)) {
                error_log("Course {$course->id} - Trying overviewfiles property");
                $courseimage = reset($course->overviewfiles);
            }
            
            // Final check
            if (empty($courseimage)) {
                error_log("Course {$course->id} ({$course->fullname}) - NO IMAGE FOUND - Will show placeholder");
            }
            
        } catch (Exception $e) {
            error_log("Course {$course->id} - Image fetch error: " . $e->getMessage());
            $courseimage = ''; // Ensure it's empty on error
        }
    } catch (Exception $e) {
        // If course processing fails, skip this course
        continue;
    }
    
    // Determine grade level from multiple sources with improved pattern matching
    // Check in order: category idnumber, category name, course idnumber, course shortname, course fullname
    $grade_level = 'Grade 1'; // Default
    
    // Combine all searchable strings
    $search_strings = [
        strtolower($categoryidnumber ?: ''),
        strtolower($categoryname ?: ''),
        strtolower($course->idnumber ?: ''),
        strtolower($course->shortname ?: ''),
        strtolower($course->fullname ?: '')
    ];
    
    $combined_text = implode(' ', $search_strings);
    
    // Pattern matching for Grade 3 (check first to avoid conflicts with Grade 2)
    if (preg_match('/\bgrade\s*[_-]?3\b|\bgrade3\b|\bg3\b/i', $combined_text)) {
        $grade_level = 'Grade 3';
    }
    // Pattern matching for Grade 2
    elseif (preg_match('/\bgrade\s*[_-]?2\b|\bgrade2\b|\bg2\b/i', $combined_text)) {
        $grade_level = 'Grade 2';
    }
    // Pattern matching for Grade 1 (explicit match)
    elseif (preg_match('/\bgrade\s*[_-]?1\b|\bgrade1\b|\bg1\b/i', $combined_text)) {
        $grade_level = 'Grade 1';
    }
    
    // Debug logging
    error_log("Course {$course->id} ({$course->fullname}) - Grade Detection:");
    error_log("  Category IDNumber: " . ($categoryidnumber ?: 'N/A'));
    error_log("  Category Name: " . $categoryname);
    error_log("  Course IDNumber: " . ($course->idnumber ?: 'N/A'));
    error_log("  Course Shortname: " . ($course->shortname ?: 'N/A'));
    error_log("  Detected Grade: " . $grade_level);
    
    // Detect book type using the same logic as teacher courses
    $course_for_detection = [
        'fullname' => $course->fullname,
        'shortname' => $course->shortname
    ];
    $detected_book_type = theme_remui_kids_detect_course_book_type($course_for_detection);
    
    // Get course sections and activities (for both cards and tree view)
    // Calculate progress based on activity completion (same as dashboard)
    $lessons = [];
    $total_sections = 0;
    $completed_sections = 0;
    $total_activities = 0;
    $completed_activities = 0;
    
    try {
        require_once($CFG->dirroot . '/lib/completionlib.php');
        $completion = new completion_info($course);
        $modinfo = get_fast_modinfo($course);
        $sections = $modinfo->get_section_info_all();
        
        // Track processed activity IDs to prevent duplicates
        $processed_activity_ids = [];
        
        $lessonnumber = 1;
        foreach ($sections as $sectionnum => $section) {
            if ($sectionnum == 0) continue; // Skip section 0
            
            // Skip sections that are subsections/localules - they should only be accessible within their parent sections
            if (isset($section->component) && $section->component === 'mod_subsection') {
                continue;
            }
            
            // Skip sections that are not visible to user
            if (!$section->uservisible || !$section->visible) {
                continue;
            }
            
            // Skip sections that are subsections/localules - they should only be accessible within their parent sections
            if (isset($section->component) && $section->component === 'mod_subsection') {
                continue;
            }
            
            // Skip sections that are not visible to user
            if (!$section->uservisible || !$section->visible) {
                continue;
            }
            
            $sectionname = $section->name ?: "Lesson " . $lessonnumber;
            $subsections = [];
            $direct_activities = [];
            $subsections = [];
            $direct_activities = [];
            $activitycount = 0;
            $section_completed_count = 0;
            
            // Get activities and subsections in this section using modinfo sections
            if (isset($modinfo->sections[$section->section])) {
                foreach ($modinfo->sections[$section->section] as $cmid) {
                    if (empty($cmid)) continue;
                    
                    try {
                        if (!isset($modinfo->cms[$cmid])) {
                            continue;
                        }
                        
                        $cm = $modinfo->cms[$cmid];
                        if (!$cm->uservisible || $cm->deletioninprogress) continue;
                        
                        // Skip label modules when counting
                        if ($cm->modname === 'label') {
                            continue;
                        }
                        
                        // Check if this is a subsection
                        if ($cm->modname === 'subsection') {
                            // Get subsection details and its activities
                            $subsection_section = $DB->get_record('course_sections', [
                                'component' => 'mod_subsection',
                                'itemid' => $cm->instance,
                                'visible' => 1
                            ], '*', IGNORE_MISSING);
                            
                            if ($subsection_section) {
                                $subsection_activities = [];
                                $subsection_activity_count = 0;
                                $subsection_completed_count = 0;
                                
                                // Get activities inside this subsection
                                if (!empty($subsection_section->sequence)) {
                                    $subsection_modids = array_filter(array_map('intval', explode(',', $subsection_section->sequence)));
                                    foreach ($subsection_modids as $submodid) {
                                        if (empty($submodid)) continue;
                                        
                                        try {
                                            if (!isset($modinfo->cms[$submodid])) {
                                                continue;
                                            }
                                            
                                            $subcm = $modinfo->cms[$submodid];
                                            if (!$subcm->uservisible || $subcm->deletioninprogress) {
                                                continue;
                                            }
                                            
                                            // Skip labels and nested subsections
                                            if ($subcm->modname === 'label' || $subcm->modname === 'subsection') {
                                                continue;
                                            }
                                            
                                            // Skip if already processed (prevent duplicates)
                                            if (in_array($subcm->id, $processed_activity_ids)) {
                                                continue;
                                            }
                                            $processed_activity_ids[] = $subcm->id;
                                            
                                            $completiondata = $completion->get_data($subcm, false, $USER->id);
                                            $iscompleted = ($completiondata->completionstate == COMPLETION_COMPLETE || 
                                                          $completiondata->completionstate == COMPLETION_COMPLETE_PASS);
                                            
                                            if ($iscompleted) {
                                                $subsection_completed_count++;
                                                $section_completed_count++;
                                                $completed_activities++;
                                            }
                                            
                                            $subsection_activity_count++;
                                            $activitycount++;
                                            $total_activities++;
                                            
                                            $estimatedtime = theme_remui_kids_get_activity_estimated_time($subcm->modname);
                                            
                                            $subsection_activities[] = [
                                                'activity_number' => $subsection_activity_count,
                                                'id' => $subcm->id,
                                                'name' => $subcm->name,
                                                'type' => $subcm->modname,
                                                'duration' => $estimatedtime,
                                                'points' => 100,
                                                'icon' => theme_remui_kids_get_activity_icon($subcm->modname),
                                                'url' => $subcm->url ? $subcm->url->out() : '',
                                                'completed' => $iscompleted
                                            ];
                                        } catch (Exception $e) {
                                            continue;
                                        }
                                    }
                                }
                                
                                $subsection_progress = $subsection_activity_count > 0 
                                    ? round(($subsection_completed_count / $subsection_activity_count) * 100) 
                                    : 0;
                                
                                // Get subsection module URL in course view format
                                $subsection_url = (new moodle_url('/course/view.php', ['id' => $course->id, 'section' => $subsection_section->section]))->out(false);
                                
                                $subsections[] = [
                                    'id' => $subsection_section->id,
                                    'name' => $subsection_section->name ?: 'Subsection',
                                    'activity_count' => $subsection_activity_count,
                                    'progress_percentage' => $subsection_progress,
                                    'has_activities' => !empty($subsection_activities),
                                    'activities' => $subsection_activities,
                                    'url' => $subsection_url
                                ];
                            }
                            // Continue to next module (we've processed all activities inside this subsection)
                            continue;
                        }
                        
                        // Regular activity (not inside a subsection) - count it normally
                        // Skip if already processed (prevent duplicates)
                        if (in_array($cm->id, $processed_activity_ids)) {
                            continue;
                        }
                        $processed_activity_ids[] = $cm->id;
                        
                        $completiondata = $completion->get_data($cm, false, $USER->id);
                        $iscompleted = ($completiondata->completionstate == COMPLETION_COMPLETE || 
                                      $completiondata->completionstate == COMPLETION_COMPLETE_PASS);
                        
                        if ($iscompleted) {
                            $section_completed_count++;
                            $completed_activities++;
                        }
                        
                        $activitycount++;
                        $total_activities++;
                        
                        $estimatedtime = theme_remui_kids_get_activity_estimated_time($cm->modname);
                        
                        $direct_activities[] = [
                            'activity_number' => count($direct_activities) + 1,
                            'id' => $cm->id,
                            'name' => $cm->name,
                            'type' => $cm->modname,
                            'duration' => $estimatedtime,
                            'points' => 100,
                            'icon' => theme_remui_kids_get_activity_icon($cm->modname),
                            'url' => $cm->url ? $cm->url->out() : '',
                            'completed' => $iscompleted
                        ];
                    } catch (Exception $e) {
                        continue;
                }
            }
            }
            
            // Calculate total activity count including subsections
            $total_activity_count = $activitycount;
            $has_subsections = !empty($subsections);
            $has_direct_activities = !empty($direct_activities);
            
            // Always count visible sections as lessons (even if they have no activities)
            $sectionprogress = $total_activity_count > 0 
                ? round(($section_completed_count / $total_activity_count) * 100) 
                : 0;
            
                $total_sections++;
                
            if ($section_completed_count == $total_activity_count && $total_activity_count > 0) {
                    $completed_sections++;
                }
                
                $lessons[] = [
                    'id' => $sectionnum,
                    'name' => $sectionname,
                'activity_count' => $total_activity_count,
                    'progress_percentage' => $sectionprogress,
                'has_subsections' => $has_subsections,
                'subsections' => $subsections,
                'has_direct_activities' => $has_direct_activities,
                'direct_activities' => $direct_activities,
                    'url' => (new moodle_url('/course/view.php', ['id' => $course->id, 'section' => $sectionnum]))->out()
                ];
            
            $lessonnumber++;
        }
        
        // Calculate progress percentage based on activity completion (same as dashboard)
        $progress = 0;
        if ($total_activities > 0) {
            $progress = ($completed_activities / $total_activities) * 100;
        }
        $progress_percentage = round($progress);
    } catch (Exception $e) {
        $lessons = [];
        $progress = 0;
        $progress_percentage = 0;
    }
    
    // Determine course status (same logic as dashboard)
    $completed = $progress >= 100;
    $in_progress = $progress > 0 && $progress < 100;
    $not_started = $progress == 0;

    // If no course image found, use book type detection to select appropriate cover
    if (empty($courseimage)) {
        $course_for_cover = [
            'fullname' => $course->fullname,
            'shortname' => $course->shortname
        ];
        // Pass the detected book type to avoid re-detection
        $courseimage = theme_remui_kids_select_course_cover($course_for_cover, $coursecoverdefaults, $coursecovercycle, $covercycleindex, $detected_book_type);
    }
    
    // Debug: Log final course image value before adding to array
    error_log("ADDING TO ARRAY - Course {$course->id} ({$course->fullname}) - Image: " . ($courseimage ?: 'EMPTY/NULL'));
    
    $course_data[] = [
        'id' => $course->id,
        'fullname' => $course->fullname,
        'shortname' => $course->shortname,
        'summary' => $course->summary,
        'courseimage' => $courseimage,
        'categoryname' => $categoryname,
        'grade_level' => $grade_level,
        'book_type' => $detected_book_type,
        'progress_percentage' => $progress_percentage,
        'completed_sections' => $completed_sections,
        'total_sections' => $total_sections,
        'completed_activities' => $completed_activities,
        'total_activities' => $total_activities,
        'completed' => $completed,
        'in_progress' => $in_progress,
        'not_started' => $not_started,
        'courseurl' => new moodle_url('/course/view.php', ['id' => $course->id]),
        // Tree view data
        'has_lessons' => !empty($lessons),
        'lessons' => $lessons
    ];
}

// Get search and filter parameters
$search_query = optional_param('search', '', PARAM_TEXT);
$filter_course_param = optional_param('filter_course', 'all', PARAM_ALPHANUMEXT);
$filter_course = ($filter_course_param == 'all') ? 'all' : (int)$filter_course_param;

// Extract unique courses for filter dropdown (from ORIGINAL courses before any filtering)
// This ensures the dropdown always shows all available courses
$unique_courses_for_filter = [];
$seen_course_ids = [];
foreach ($courses as $course) {
    if (!in_array($course->id, $seen_course_ids)) {
        $unique_courses_for_filter[] = [
            'id' => $course->id,
            'fullname' => $course->fullname,
            'shortname' => $course->shortname
        ];
        $seen_course_ids[] = $course->id;
    }
}
usort($unique_courses_for_filter, function($a, $b) {
    return strcmp($a['fullname'], $b['fullname']);
});

foreach ($unique_courses_for_filter as &$course) {
    $course['filter_course_selected'] = ($filter_course != 'all' && $course['id'] == $filter_course);
}
unset($course);
$filter_course_all = ($filter_course == 'all');

// Get current page BEFORE filtering
$current_page = optional_param('page', 1, PARAM_INT);
if ($current_page < 1) {
    $current_page = 1;
}

// Check if filters are active
$has_active_filters = !empty($search_query) || $filter_course != 'all';

// Filter courses based on search and filter_course
if ($has_active_filters) {
    $filtered_course_data = [];
    foreach ($course_data as $course) {
        // Apply search filter
        if (!empty($search_query)) {
            $search_lower = strtolower($search_query);
            $course_name_lower = strtolower($course['fullname']);
            $course_shortname_lower = strtolower($course['shortname']);
            $category_lower = strtolower($course['categoryname'] ?? '');
            
            if (strpos($course_name_lower, $search_lower) === false &&
                strpos($course_shortname_lower, $search_lower) === false &&
                strpos($category_lower, $search_lower) === false) {
                continue; // Skip if doesn't match search
            }
        }
        
        // Apply course filter
        if ($filter_course != 'all' && $course['id'] != $filter_course) {
            continue; // Skip if doesn't match filter
        }
        
        $filtered_course_data[] = $course;
    }
    $course_data = $filtered_course_data;
}


// Calculate overall statistics (from filtered data) - BEFORE pagination
$total_courses = count($course_data);
$completed_courses = count(array_filter($course_data, function($course) { return $course['completed']; }));
$in_progress_courses = count(array_filter($course_data, function($course) { return $course['in_progress']; }));
$overall_progress = $total_courses > 0 ? round(array_sum(array_column($course_data, 'progress_percentage')) / $total_courses) : 0;

// Pagination - Show 8 courses per page
$per_page = 8;
$total_pages = ceil($total_courses / $per_page);

// If filters are active and we're on a page beyond the filtered results, reset to page 1
if ($has_active_filters && $current_page > $total_pages && $total_pages > 0) {
    $current_page = 1;
    // Redirect to page 1 with filters preserved
    $redirect_url = new moodle_url('/theme/remui_kids/elementary_my_course.php', ['page' => 1]);
    if (!empty($search_query)) {
        $redirect_url->param('search', $search_query);
    }
    if ($filter_course != 'all') {
        $redirect_url->param('filter_course', $filter_course);
    }
    redirect($redirect_url);
}

// Ensure current page is valid
if ($current_page > $total_pages && $total_pages > 0) {
    $current_page = $total_pages;
} elseif ($total_pages == 0) {
    $current_page = 1;
}

// Calculate offset and slice courses for current page
$offset = ($current_page - 1) * $per_page;
$paginated_courses = array_slice($course_data, $offset, $per_page);

// Build pagination URLs
$base_url = new moodle_url('/theme/remui_kids/elementary_my_course.php');
if (!empty($search_query)) {
    $base_url->param('search', $search_query);
}
if ($filter_course != 'all') {
    $base_url->param('filter_course', $filter_course);
}

$pagination = [
    'current_page' => $current_page,
    'total_pages' => $total_pages,
    'total_items' => $total_courses,
    'per_page' => $per_page,
    'has_previous' => $current_page > 1,
    'has_next' => $current_page < $total_pages,
    'previous_url' => $current_page > 1 ? $base_url->out(false, ['page' => $current_page - 1]) : '',
    'next_url' => $current_page < $total_pages ? $base_url->out(false, ['page' => $current_page + 1]) : '',
    'page_urls' => []
];

// Generate page URLs for pagination controls
for ($i = 1; $i <= $total_pages; $i++) {
    $pagination['page_urls'][] = [
        'page' => $i,
        'url' => $base_url->out(false, ['page' => $i]),
        'is_current' => $i == $current_page
    ];
}

// Debug: Log all courses and their images
error_log("====== TEMPLATE CONTEXT DEBUG ======");
error_log("Total courses: " . $total_courses);
foreach ($course_data as $idx => $cdata) {
    error_log("Course #{$idx}: {$cdata['fullname']} - Image: " . ($cdata['courseimage'] ?: 'NO IMAGE'));
}
error_log("====================================");
// Check for support videos in 'courses' category
require_once(__DIR__ . '/lib/support_helper.php');
$video_check = theme_remui_kids_check_support_videos('courses');
$has_help_videos = $video_check['has_videos'];
$help_videos_count = $video_check['count'];

// Prepare template context
$templatecontext = [
    'custom_elementary_courses' => true,
    'student_name' => $USER->firstname ?: $USER->username,
    'total_courses' => $total_courses,
    'completed_courses' => $completed_courses,
    'in_progress_courses' => $in_progress_courses,
    'overall_progress' => $overall_progress,
    'has_courses' => !empty($paginated_courses),
    'courses' => $paginated_courses,
    'show_view_all_button' => $total_courses > 6,
    
    // Pagination data
    'pagination' => $pagination,
    'show_pagination' => $total_pages > 1,
    
    // Search and filter data
    'search_query' => $search_query,
    'filter_course' => $filter_course,
    'filter_course_all' => $filter_course_all,
    'filter_courses' => $unique_courses_for_filter,
    'has_filter_courses' => !empty($unique_courses_for_filter),
    
    // Page identification flags for sidebar
    'is_lessons_page' => false,
    'is_mycourses_page' => true,
    'is_activities_page' => false,
    
    // Navigation URLs
    'dashboardurl' => (new moodle_url('/my/'))->out(),
    'mycoursesurl' => (new moodle_url('/theme/remui_kids/elementary_my_course.php'))->out(),
    'elementary_mycoursesurl' => (new moodle_url('/theme/remui_kids/elementary_my_course.php'))->out(),
    'lessonsurl' => (new moodle_url('/theme/remui_kids/elementary_lessons.php'))->out(),
    'currentactivityurl' => (new moodle_url('/theme/remui_kids/elementary_current_activity.php'))->out(),
    'activitiesurl' => (new moodle_url('/theme/remui_kids/elementary_activities.php'))->out(),
    'achievementsurl' => (new moodle_url('/theme/remui_kids/elementary_achievements.php'))->out(),
    'competenciesurl' => (new moodle_url('/theme/remui_kids/elementary_competencies.php'))->out(),
    'scheduleurl' => (new moodle_url('/theme/remui_kids/elementary_calendar.php'))->out(),
    'myreportsurl' => (new moodle_url('/theme/remui_kids/elementary_myreports.php'))->out(),
    'treeviewurl' => (new moodle_url('/theme/remui_kids/elementary_treeview.php'))->out(),
    'allcoursesurl' => (new moodle_url('/course/index.php'))->out(),
    'profileurl' => (new moodle_url('/theme/remui_kids/elementary_profile.php'))->out(),
    'settingsurl' => (new moodle_url('/user/preferences.php'))->out(),
    'scratcheditorurl' => (new moodle_url('/theme/remui_kids/scratch_simple.php'))->out(),
    'codeeditorurl' => (new moodle_url('/theme/remui_kids/code_editor_simple.php'))->out(),
    'logouturl' => (new moodle_url('/login/logout.php', ['sesskey' => sesskey()]))->out(),
    
    // Sidebar access permissions (based on user's cohort)
    'has_scratch_editor_access' => theme_remui_kids_user_has_scratch_editor_access($USER->id),
    'has_code_editor_access' => theme_remui_kids_user_has_code_editor_access($USER->id),
    
    // Help button visibility - Only show if videos exist for this category
    'show_help_button' => $has_help_videos,
    'help_videos_count' => $help_videos_count,
];

// Render the template
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('theme_remui_kids/elementary_my_course_clean', $templatecontext);

echo $OUTPUT->footer();
