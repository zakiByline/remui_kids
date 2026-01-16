<?php
/**
 * Course Index Custom Functions
 * Handles the hierarchical course structure for the course index page
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Get all courses organized by category and grade
 * 
 * @return array Array of categories with courses organized by grade
 */
function theme_remui_kids_get_course_index_data() {
    global $DB, $USER, $CFG;
    
    try {
        error_log("=== FETCHING REAL IOMAD/MOODLE COURSE INDEX DATA ===");
        error_log("User ID: " . $USER->id . " | User: " . $USER->firstname . " " . $USER->lastname);
        
        // Get all course categories from IOMAD/Moodle database
        $categories = $DB->get_records('course_categories', null, 'sortorder ASC', 'id, name, description, sortorder, parent');
        
        if (empty($categories)) {
            error_log("❌ NO COURSE CATEGORIES FOUND in IOMAD/Moodle database");
            return [];
        }
        
        error_log("✅ Found " . count($categories) . " REAL course categories from IOMAD/Moodle database:");
        foreach ($categories as $cat) {
            error_log("  - Category ID: {$cat->id} | Name: '{$cat->name}' | Parent: {$cat->parent}");
        }
        
        $categories_data = [];
        
        foreach ($categories as $category) {
            error_log("--- Processing Category: '{$category->name}' (ID: {$category->id}) ---");
            
            // Get ALL courses in this category from IOMAD/Moodle database
            $courses = $DB->get_records('course', [
                'category' => $category->id,
                'visible' => 1
            ], 'fullname ASC', 'id, fullname, shortname, startdate, enddate, summary, timecreated, timemodified');
            
            if (empty($courses)) {
                error_log("  ⚠️  No courses found in category '{$category->name}'");
                continue;
            }
            
            error_log("  ✅ Category '{$category->name}' has " . count($courses) . " REAL courses from IOMAD/Moodle database:");
            foreach ($courses as $course) {
                error_log("    - Course ID: {$course->id} | Name: '{$course->fullname}' | Short: '{$course->shortname}'");
            }
            
            // Organize courses by grade with REAL IOMAD/MOODLE data
            $grades_data = [];
            $total_students = 0;
            $total_activities = 0;
            $total_completions = 0;
            
            error_log("  📚 Processing " . count($courses) . " courses to organize by grade...");
            
            foreach ($courses as $course) {
                error_log("    📖 Processing Course: '{$course->fullname}' (ID: {$course->id})");
                
                // Extract grade from course name or category with enhanced detection
                $grade = theme_remui_kids_extract_grade_from_course($course, $category);
                
                // Get REAL course statistics from IOMAD/Moodle database
                $course_stats = theme_remui_kids_get_course_statistics($course->id);
                
                $course_data = [
                    'id' => $course->id,
                    'fullname' => $course->fullname,
                    'shortname' => $course->shortname,
                    'summary' => $course->summary,
                    'startdate' => $course->startdate ? date('M d, Y', $course->startdate) : 'N/A',
                    'enddate' => $course->enddate ? date('M d, Y', $course->enddate) : 'Ongoing',
                    'student_count' => $course_stats['student_count'],
                    'activity_count' => $course_stats['activity_count'],
                    'completion_rate' => $course_stats['completion_rate'],
                    'recent_activity' => $course_stats['recent_activity'],
                    'url' => (new moodle_url('/course/view.php', ['id' => $course->id]))->out()
                ];
                
                error_log("    ✅ Course data prepared: {$course_stats['student_count']} students, {$course_stats['activity_count']} activities, {$course_stats['completion_rate']}% completion");
                
                // Initialize grade if not exists
                if (!isset($grades_data[$grade])) {
                    $grades_data[$grade] = [
                        'grade_name' => $grade,
                        'courses' => [],
                        'total_students' => 0,
                        'total_activities' => 0,
                        'total_completions' => 0
                    ];
                    error_log("    🆕 Created new grade group: {$grade}");
                }
                
                // Add course to grade
                $grades_data[$grade]['courses'][] = $course_data;
                $grades_data[$grade]['total_students'] += $course_stats['student_count'];
                $grades_data[$grade]['total_activities'] += $course_stats['activity_count'];
                $grades_data[$grade]['total_completions'] += $course_stats['completed_count'];
                
                $total_students += $course_stats['student_count'];
                $total_activities += $course_stats['activity_count'];
                $total_completions += $course_stats['completed_count'];
                
                error_log("    📊 Grade '{$grade}' now has " . count($grades_data[$grade]['courses']) . " courses, {$grades_data[$grade]['total_students']} total students");
            }
            
            // Calculate category completion rate
            $completion_rate = $total_students > 0 ? round(($total_completions / $total_students) * 100, 1) : 0;
            
            error_log("  📊 Category '{$category->name}' Summary:");
            error_log("    - Total Courses: " . count($courses));
            error_log("    - Total Students: {$total_students}");
            error_log("    - Total Activities: {$total_activities}");
            error_log("    - Completion Rate: {$completion_rate}%");
            error_log("    - Grades Found: " . count($grades_data));
            
            // Determine if we should flatten courses (do not show redundant grade header)
            $flat_courses = [];
            if (count($grades_data) === 1) {
                $only = reset($grades_data);
                $onlygradename = is_array($only) && isset($only['grade_name']) ? $only['grade_name'] : '';
                $isredundant = false;
                if ($onlygradename) {
                    // Redundant when grade equals category name or is one of Foundation/Intermediate/Advanced/General
                    $isredundant = (
                        mb_strtolower(trim($onlygradename)) === mb_strtolower(trim($category->name))
                    ) || preg_match('/^(foundation|intermediate|advanced|general)$/i', $onlygradename);
                    // Not redundant if it's a numbered grade like "Grade 5"
                    if (preg_match('/^grade\s*\d+$/i', $onlygradename)) {
                        $isredundant = false;
                    }
                }
                if ($isredundant && isset($only['courses']) && is_array($only['courses'])) {
                    $flat_courses = $only['courses'];
                }
            }

            // Convert grades to indexed array for template
            $grades_array = [];
            foreach ($grades_data as $grade) {
                $grades_array[] = $grade;
                error_log("    - Grade '{$grade['grade_name']}': {$grade['total_students']} students, " . count($grade['courses']) . " courses");
            }
            
            $categories_data[] = [
                'id' => $category->id,
                'name' => $category->name,
                'description' => $category->description,
                'total_courses' => count($courses),
                'total_students' => $total_students,
                'total_activities' => $total_activities,
                'completion_rate' => $completion_rate,
                'grades' => $grades_array,
                'flat_courses' => $flat_courses
            ];
        }
        
        error_log("🎉 FINAL RESULT: Organized into " . count($categories_data) . " categories with REAL IOMAD/MOODLE data");
        error_log("📋 Summary:");
        foreach ($categories_data as $cat) {
            error_log("  - Category: '{$cat['name']}' | Courses: {$cat['total_courses']} | Students: {$cat['total_students']} | Grades: " . count($cat['grades']));
        }
        
        return $categories_data;
        
    } catch (Exception $e) {
        error_log("Error in theme_remui_kids_get_course_index_data: " . $e->getMessage());
        return [];
    }
}

/**
 * Extract grade from course name or category - ENHANCED FOR IOMAD/MOODLE
 * 
 * @param object $course Course object
 * @param object $category Category object
 * @return string Grade name
 */
function theme_remui_kids_extract_grade_from_course($course, $category) {
    error_log("    🔍 Extracting grade from Course: '{$course->fullname}' | Category: '{$category->name}'");
    
    // Try to extract grade from course name first (multiple patterns)
    $patterns = [
        '/Grade\s*(\d+)/i',
        '/(\d+)th\s*Grade/i',
        '/Class\s*(\d+)/i',
        '/Level\s*(\d+)/i',
        '/Year\s*(\d+)/i',
        '/Std\s*(\d+)/i',
        '/Standard\s*(\d+)/i'
    ];
    
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $course->fullname, $matches)) {
            $grade = 'Grade ' . $matches[1];
            error_log("    ✅ Found grade from course name: {$grade}");
            return $grade;
        }
    }
    
    // Try to extract grade from category name
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $category->name, $matches)) {
            $grade = 'Grade ' . $matches[1];
            error_log("    ✅ Found grade from category name: {$grade}");
            return $grade;
        }
    }
    
    // Check for foundation/intermediate/advanced patterns in course name
    if (preg_match('/Foundation|Elementary|Primary|Basic|Beginner/i', $course->fullname)) {
        error_log("    ✅ Found Foundation level from course name");
        return 'Foundation';
    }
    
    if (preg_match('/Intermediate|Middle|Secondary|Junior/i', $course->fullname)) {
        error_log("    ✅ Found Intermediate level from course name");
        return 'Intermediate';
    }
    
    if (preg_match('/Advanced|High|Senior|Higher/i', $course->fullname)) {
        error_log("    ✅ Found Advanced level from course name");
        return 'Advanced';
    }
    
    // Check for foundation/intermediate/advanced patterns in category name
    if (preg_match('/Foundation|Elementary|Primary|Basic|Beginner/i', $category->name)) {
        error_log("    ✅ Found Foundation level from category name");
        return 'Foundation';
    }
    
    if (preg_match('/Intermediate|Middle|Secondary|Junior/i', $category->name)) {
        error_log("    ✅ Found Intermediate level from category name");
        return 'Intermediate';
    }
    
    if (preg_match('/Advanced|High|Senior|Higher/i', $category->name)) {
        error_log("    ✅ Found Advanced level from category name");
        return 'Advanced';
    }
    
    // Try to extract numeric grade from course shortname
    if (preg_match('/(\d+)/', $course->shortname, $matches)) {
        $grade = 'Grade ' . $matches[1];
        error_log("    ✅ Found grade from course shortname: {$grade}");
        return $grade;
    }
    
    // Default to course category name or "General"
    $default_grade = $category->name ?: 'General';
    error_log("    ⚠️  Using default grade: {$default_grade}");
    return $default_grade;
}

/**
 * Get course statistics - REAL IOMAD/MOODLE DATA
 * 
 * @param int $courseid Course ID
 * @return array Course statistics
 */
function theme_remui_kids_get_course_statistics($courseid) {
    global $DB;
    
    try {
        error_log("  📊 Fetching REAL statistics for Course ID: {$courseid}");
        
        // Get REAL student enrollment count from IOMAD/Moodle database
        $enrollment_sql = "SELECT COUNT(DISTINCT ue.userid) as count
                          FROM {user_enrolments} ue
                          JOIN {enrol} e ON ue.enrolid = e.id
                          JOIN {user} u ON ue.userid = u.id
                          WHERE e.courseid = :courseid
                          AND ue.status = 0
                          AND u.deleted = 0
                          AND u.suspended = 0";
        
        $enrollment = $DB->get_record_sql($enrollment_sql, ['courseid' => $courseid]);
        $student_count = $enrollment ? (int)$enrollment->count : 0;
        error_log("    👥 Real student count: {$student_count}");
        
        // Get REAL activity count from IOMAD/Moodle database
        $activity_count = $DB->count_records('course_modules', [
            'course' => $courseid,
            'deletioninprogress' => 0
        ]);
        error_log("    📚 Real activity count: {$activity_count}");
        
        // Get REAL completion statistics from IOMAD/Moodle database
        $completion_sql = "SELECT COUNT(*) as completed
                          FROM {course_completions}
                          WHERE course = :courseid
                          AND timecompleted IS NOT NULL";
        
        $completed = $DB->get_record_sql($completion_sql, ['courseid' => $courseid]);
        $completed_count = $completed ? (int)$completed->completed : 0;
        $completion_rate = $student_count > 0 ? round(($completed_count / $student_count) * 100, 1) : 0;
        error_log("    ✅ Real completion count: {$completed_count} | Rate: {$completion_rate}%");
        
        // Get additional REAL data from IOMAD/Moodle
        $course_info = $DB->get_record('course', ['id' => $courseid], 'fullname, shortname, startdate, enddate');
        if ($course_info) {
            error_log("    📖 Course: '{$course_info->fullname}' | Start: " . ($course_info->startdate ? date('Y-m-d', $course_info->startdate) : 'N/A'));
        }
        
        // Get REAL recent activity count
        $recent_activity_sql = "SELECT COUNT(*) as count
                              FROM {logstore_standard_log} l
                              WHERE l.courseid = :courseid
                              AND l.timecreated > :since
                              AND l.action = 'viewed'";
        
        $recent_activity = $DB->get_record_sql($recent_activity_sql, [
            'courseid' => $courseid,
            'since' => time() - (7 * 24 * 60 * 60) // Last 7 days
        ]);
        $recent_activity_count = $recent_activity ? (int)$recent_activity->count : 0;
        error_log("    🔥 Recent activity (7 days): {$recent_activity_count}");
        
        return [
            'student_count' => $student_count,
            'activity_count' => $activity_count,
            'completed_count' => $completed_count,
            'completion_rate' => $completion_rate,
            'recent_activity' => $recent_activity_count
        ];
        
    } catch (Exception $e) {
        error_log("❌ Error getting REAL course statistics for course {$courseid}: " . $e->getMessage());
        return [
            'student_count' => 0,
            'activity_count' => 0,
            'completed_count' => 0,
            'completion_rate' => 0,
            'recent_activity' => 0
        ];
    }
}

/**
 * Render course index page with custom template
 * 
 * @param int $categoryid Category ID (optional)
 * @return string Rendered HTML
 */
function theme_remui_kids_render_course_index($categoryid = 0) {
    global $PAGE, $OUTPUT, $CFG;
    
    // Get course index data
    $categories = theme_remui_kids_get_course_index_data();
    
    // Prepare template context
    $templatecontext = [
        'categories' => $categories,
        'config' => [
            'wwwroot' => $CFG->wwwroot
        ]
    ];
    
    // Render using custom template
    return $OUTPUT->render_from_template('theme_remui_kids/course_index', $templatecontext);
}
