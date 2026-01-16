<?php
// Simple Teacher Courses Page - Bypass Moodle redirects
require_once('../../../config.php');
require_once(__DIR__ . '/../lang_init.php'); // Apply user's selected language

global $CURRENT_LANG;

// Simple login check
if (!isloggedin()) {
    redirect(get_login_url());
}

// Check if user is teacher
$isteacher = false;
$teacherroles = $DB->get_records_select('role', "shortname IN ('editingteacher','teacher','manager')");
$roleids = array_keys($teacherroles);

if (!empty($roleids)) {
    list($insql, $params) = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED, 'r');
    $params['userid'] = $USER->id;
    $params['ctxlevel'] = CONTEXT_COURSE;
    
    $teacher_courses = $DB->get_records_sql(
        "SELECT DISTINCT ctx.instanceid as courseid
         FROM {role_assignments} ra
         JOIN {context} ctx ON ra.contextid = ctx.id
         WHERE ra.userid = :userid AND ctx.contextlevel = :ctxlevel AND ra.roleid {$insql}
         LIMIT 1",
        $params
    );
    
    if (!empty($teacher_courses)) {
        $isteacher = true;
    }
}

if (is_siteadmin()) {
    $isteacher = true;
}

if (!$isteacher) {
    echo "<h1>Access Denied</h1>";
    echo "<p>You must be a teacher to access this page.</p>";
    echo "<p><a href='" . $CFG->wwwroot . "'>Go Back</a></p>";
    exit;
}

// Get courses for current user
$teacher_courses = [];
if (!empty($roleids)) {
    list($insql, $params) = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED, 'r');
    $params['userid'] = $USER->id;
    $params['ctxlevel'] = CONTEXT_COURSE;
    
    $courses = $DB->get_records_sql(
        "SELECT DISTINCT c.*, cat.name as category_name, cat.id as category_id
         FROM {course} c
         JOIN {context} ctx ON c.id = ctx.instanceid AND ctx.contextlevel = :ctxlevel
         JOIN {role_assignments} ra ON ctx.id = ra.contextid AND ra.userid = :userid AND ra.roleid {$insql}
         LEFT JOIN {course_categories} cat ON c.category = cat.id
         WHERE c.visible = 1 AND c.id > 1
         ORDER BY cat.sortorder ASC, c.sortorder ASC",
        $params
    );
    
    // Organize courses by category
    foreach ($courses as $course) {
        $category_id = $course->category_id ?: 0;
        $category_name = $course->category_name ?: 'Uncategorized';
        
        if (!isset($teacher_courses[$category_id])) {
            $teacher_courses[$category_id] = [
                'name' => $category_name,
                'courses' => []
            ];
        }
        
        // Get course statistics
        $enrollment_count = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT ue.userid)
             FROM {enrol} e
             JOIN {user_enrolments} ue ON e.id = ue.enrolid
             WHERE e.courseid = ?",
            [$course->id]
        );
        
        $activity_count = $DB->count_records_sql(
            "SELECT COUNT(*)
             FROM {course_modules}
             WHERE course = ? AND visible = 1",
            [$course->id]
        );
        
        $teacher_courses[$category_id]['courses'][] = [
            'id' => $course->id,
            'fullname' => $course->fullname,
            'shortname' => $course->shortname,
            'enrollment_count' => $enrollment_count,
            'activity_count' => $activity_count,
            'status' => $course->visible ? 'active' : 'draft'
        ];
    }
}

?>
<!DOCTYPE html>
<html <?php echo lang_attr(); ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Courses - Teacher Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f8fafc;
            color: #333;
        }
        
        .header {
            background: white;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        
        .header h1 {
            color: #1f2937;
            font-size: 28px;
            margin-bottom: 8px;
        }
        
        .header p {
            color: #6b7280;
            font-size: 16px;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }
        
        .category-section {
            margin-bottom: 40px;
        }
        
        .category-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e5e7eb;
        }
        
        .category-title {
            font-size: 24px;
            font-weight: 600;
            color: #1f2937;
        }
        
        .category-count {
            background: #10b981;
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
        }
        
        .courses-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
        }
        
        .course-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
            border: 1px solid #e5e7eb;
        }
        
        .course-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -3px rgba(0, 0, 0, 0.1);
        }
        
        .course-header {
            display: flex;
            align-items: flex-start;
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .course-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            font-weight: 600;
            color: white;
            flex-shrink: 0;
        }
        
        .course-info {
            flex: 1;
        }
        
        .course-name {
            font-size: 16px;
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 4px;
            line-height: 1.3;
        }
        
        .course-code {
            font-size: 12px;
            color: #6b7280;
            margin-bottom: 6px;
        }
        
        .course-status {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .status-active {
            background: #d1fae5;
            color: #059669;
        }
        
        .status-draft {
            background: #fef3c7;
            color: #d97706;
        }
        
        .course-stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 15px;
        }
        
        .stat-number {
            font-size: 16px;
            font-weight: 700;
            color: #1f2937;
            margin: 0;
        }
        
        .stat-label {
            font-size: 10px;
            color: #6b7280;
            margin: 2px 0 0 0;
        }
        
        .course-actions {
            display: flex;
            gap: 8px;
        }
        
        .btn {
            flex: 1;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
            text-decoration: none;
            text-align: center;
            transition: all 0.2s ease;
            border: none;
            cursor: pointer;
        }
        
        .btn-primary {
            background: #10b981;
            color: white;
        }
        
        .btn-primary:hover {
            background: #059669;
            color: white;
        }
        
        .btn-secondary {
            background: #f3f4f6;
            color: #374151;
            border: 1px solid #d1d5db;
        }
        
        .btn-secondary:hover {
            background: #e5e7eb;
            color: #1f2937;
        }
        
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #6b7280;
        }
        
        .empty-icon {
            font-size: 48px;
            color: #d1d5db;
            margin-bottom: 16px;
        }
        
        .empty-title {
            font-size: 18px;
            font-weight: 600;
            color: #374151;
            margin: 0 0 8px 0;
        }
        
        .empty-text {
            font-size: 14px;
            margin: 0 0 20px 0;
        }
        
        .btn-create {
            background: #10b981;
            color: white;
            padding: 10px 20px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            display: inline-block;
        }
        
        .btn-create:hover {
            background: #059669;
            color: white;
        }
        
        .back-link {
            display: inline-block;
            margin-bottom: 20px;
            color: #10b981;
            text-decoration: none;
            font-weight: 500;
        }
        
        .back-link:hover {
            color: #059669;
        }
        
        @media (max-width: 768px) {
            .courses-grid {
                grid-template-columns: 1fr;
            }
            
            .course-stats {
                grid-template-columns: 1fr;
            }
            
            .container {
                padding: 0 15px;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="container">
            <a href="<?php echo $CFG->wwwroot; ?>" class="back-link">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
            <h1>My Courses</h1>
            <p>Manage and view all your courses organized by categories</p>
        </div>
    </div>
    
    <div class="container">
        <?php if (!empty($teacher_courses)): ?>
            <?php foreach ($teacher_courses as $category_id => $category_data): ?>
                <?php if (empty($category_data['courses'])) continue; ?>
                
                <div class="category-section">
                    <div class="category-header">
                        <h2 class="category-title"><?php echo htmlspecialchars($category_data['name']); ?></h2>
                        <span class="category-count"><?php echo count($category_data['courses']); ?> courses</span>
                    </div>
                    
                    <div class="courses-grid">
                        <?php foreach ($category_data['courses'] as $course): ?>
                            <?php
                            // Determine course icon and color
                            $icon = strtoupper(substr($course['shortname'], 0, 1));
                            $color = '#10b981'; // Default green
                            if (stripos($course['shortname'], 'PHYS') !== false) {
                                $color = '#f97316'; // Orange for Physics
                            } elseif (stripos($course['shortname'], 'MATH') !== false || stripos($course['shortname'], 'H.') !== false) {
                                $color = '#3b82f6'; // Blue for Math
                            } elseif (stripos($course['shortname'], 'CHEM') !== false) {
                                $color = '#8b5cf6'; // Purple for Chemistry
                            }
                            ?>
                            
                            <div class="course-card">
                                <div class="course-header">
                                    <div class="course-icon" style="background: <?php echo $color; ?>;">
                                        <?php echo $icon; ?>
                                    </div>
                                    <div class="course-info">
                                        <h3 class="course-name"><?php echo htmlspecialchars($course['fullname']); ?></h3>
                                        <p class="course-code"><?php echo htmlspecialchars($course['shortname']); ?></p>
                                        <span class="course-status status-<?php echo $course['status']; ?>">
                                            <?php echo ucfirst($course['status']); ?>
                                        </span>
                                    </div>
                                </div>
                                
                                <div class="course-stats">
                                    <div class="stat-item">
                                        <p class="stat-number"><?php echo $course['enrollment_count']; ?></p>
                                        <p class="stat-label">Students</p>
                                    </div>
                                    <div class="stat-item">
                                        <p class="stat-number"><?php echo $course['activity_count']; ?></p>
                                        <p class="stat-label">Activities</p>
                                    </div>
                                </div>
                                
                                <div class="course-actions">
                                    <a href="<?php echo $CFG->wwwroot; ?>/course/view.php?id=<?php echo $course['id']; ?>" class="btn btn-primary">Enter Course</a>
                                    <a href="<?php echo $CFG->wwwroot; ?>/course/edit.php?id=<?php echo $course['id']; ?>" class="btn btn-secondary">Edit</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="empty-state">
                <div class="empty-icon"><i class="fas fa-book-open"></i></div>
                <h3 class="empty-title">No courses found</h3>
                <p class="empty-text">You are not assigned as a teacher in any courses yet.</p>
                <a href="<?php echo $CFG->wwwroot; ?>/course/edit.php" class="btn-create">Create Your First Course</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>



