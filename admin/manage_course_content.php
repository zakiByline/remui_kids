<?php
/**
 * Manage Course Content Page
 * Accordion-style interface for managing course content by categories
 */

require_once('../../../config.php');
require_login();

// Check admin capabilities
$context = context_system::instance();
require_capability('moodle/site:config', $context);

// Get current user
global $USER, $DB, $OUTPUT, $PAGE;

// Set page context and URL
$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/admin/manage_course_content.php');
$PAGE->set_title('Manage Course Content');
$PAGE->set_heading('Manage Course Content');


// Get hierarchical categories structure: Parent → Sub → Courses
try {
    // Get parent categories (categories with parent = 0)
    $parent_categories = $DB->get_records('course_categories', ['visible' => 1, 'parent' => 0], 'name ASC');
    
    // Build hierarchical structure
    $hierarchical_categories = [];
    foreach ($parent_categories as $parent) {
        // Get courses directly under this parent category (no subcategory)
        $direct_courses = $DB->get_records('course', ['category' => $parent->id, 'visible' => 1], 'fullname ASC');
        $direct_course_count = count($direct_courses);
        
        // Get subcategories for this parent
        $subcategories = $DB->get_records('course_categories', ['visible' => 1, 'parent' => $parent->id], 'name ASC');
        
        $parent_data = [
            'id' => $parent->id,
            'name' => $parent->name,
            'description' => $parent->description,
            'direct_courses' => $direct_courses,
            'direct_course_count' => $direct_course_count,
            'subcategories' => []
        ];
        
        $total_courses = $direct_course_count; // Start with direct courses
        foreach ($subcategories as $subcategory) {
            // Get courses for this subcategory
            $courses = $DB->get_records('course', ['category' => $subcategory->id, 'visible' => 1], 'fullname ASC');
            $course_count = count($courses);
            $total_courses += $course_count;
            
            $parent_data['subcategories'][] = [
                'id' => $subcategory->id,
                'name' => $subcategory->name,
                'description' => $subcategory->description,
                'course_count' => $course_count,
                'courses' => $courses
            ];
        }
        
        $parent_data['total_courses'] = $total_courses;
        
        // Only include parent categories that have courses (either direct or in subcategories)
        if ($total_courses > 0) {
            $hierarchical_categories[] = $parent_data;
        }
    }
} catch (Exception $e) {
    // Handle database errors
    echo "Database error: " . $e->getMessage();
    exit;
}

echo $OUTPUT->header();

// Debug: Check if user is logged in
if (!isloggedin()) {
    echo "<div style='color: red; padding: 20px;'>User not logged in. Please log in first.</div>";
    echo $OUTPUT->footer();
    exit;
}

// Add session status indicator
echo "<div id='session-status' style='position: fixed; top: 10px; right: 10px; background: #28a745; color: white; padding: 5px 10px; border-radius: 4px; font-size: 12px; z-index: 9999;'>Session Active</div>";
?>

<style>
/* Sidebar Styles */
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

/* Main content area with sidebar */
.admin-main-content {
    position: fixed;
    top: 0;
    left: 280px;
    width: calc(100vw - 280px);
    height: 100vh;
    background-color: #f8f9fa;
    overflow-y: auto;
    z-index: 99;
    will-change: transform;
    backface-visibility: hidden;
    padding-top: 80px;
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

/* Manage Course Content Page Styles */
.manage-content-container {
    max-width: 1600px;
    margin: 0 auto;
    padding: 20px;
    background: #f8f9fa;
    min-height: 100vh;
}

.page-header {
    display: flex;
    align-items: center;
    gap: 15px;
    margin-bottom: 30px;
    padding: 20px;
    background: white;
    border-radius: 15px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
}

.page-icon {
    width: 50px;
    height: 50px;
    background: linear-gradient(135deg, #a8d8ea 0%, #c2e9fb 100%);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 1.5rem;
}

.page-title {
    font-size: 2rem;
    font-weight: 700;
    color: #333;
    margin: 0;
}

.categories-container {
    background: white;
    border-radius: 15px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    overflow: hidden;
}

/* Parent Category Styles */
.parent-category-item {
    border-bottom: 2px solid #d4edda;
    transition: all 0.3s ease;
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    margin-bottom: 10px;
    border-radius: 10px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(168, 216, 234, 0.2);
}

.parent-category-item:last-child {
    border-bottom: none;
}

/* Subcategory Styles */
.subcategory-item {
    border-bottom: 1px solid #d4edda;
    transition: all 0.3s ease;
    background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
    margin: 5px 10px;
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 1px 4px rgba(168, 216, 234, 0.15);
}

.subcategory-item:last-child {
    border-bottom: none;
}

/* Legacy category-item for backward compatibility */
.category-item {
    border-bottom: 1px solid #e9ecef;
    transition: all 0.3s ease;
}

.category-item:last-child {
    border-bottom: none;
}

/* Parent Category Header */
.parent-category-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 20px 25px;
    cursor: pointer;
    background: linear-gradient(135deg, #a8d8ea 0%, #c2e9fb 100%);
    color: #2c3e50;
    transition: all 0.3s ease;
    position: relative;
}

.parent-category-header:hover {
    background: linear-gradient(135deg, #98c8da 0%, #b2d9eb 100%);
}

.parent-category-header.active {
    background: linear-gradient(135deg, #88b8ca 0%, #a2c9db 100%);
    border-left: 4px solid #ffd8a8;
}

/* Subcategory Header */
.subcategory-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 15px 20px;
    cursor: pointer;
    background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
    transition: all 0.3s ease;
    position: relative;
    border-left: 3px solid #d4edda;
}

.subcategory-header:hover {
    background: linear-gradient(135deg, #e8f5e8 0%, #f0f8f0 100%);
    border-left-color: #a8d8ea;
}

.subcategory-header.active {
    background: linear-gradient(135deg, #d4edda 0%, #e8f5e8 100%);
    border-left-color: #a8d8ea;
}

/* Legacy category-header for backward compatibility */
.category-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 20px 25px;
    cursor: pointer;
    background: white;
    transition: all 0.3s ease;
    position: relative;
}

.category-header:hover {
    background: #f8f9fa;
}

.category-header.active {
    background: #e3f2fd;
    border-left: 4px solid #2196f3;
}

.category-info {
    display: flex;
    align-items: center;
    gap: 15px;
}

/* Parent Category Name */
.parent-category-header .category-name {
    font-size: 1.2rem;
    font-weight: 700;
    color: #2c3e50;
    margin: 0;
}

/* Subcategory Name */
.subcategory-name {
    font-size: 1rem;
    font-weight: 600;
    color: #333;
    margin: 0;
}

/* Legacy category-name for backward compatibility */
.category-name {
    font-size: 1.1rem;
    font-weight: 600;
    color: #333;
    margin: 0;
}

/* Parent Category Count */
.parent-category-header .category-count {
    background: rgba(44, 62, 80, 0.15);
    color: #2c3e50;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 0.9rem;
    font-weight: 500;
}

/* Subcategory Count */
.subcategory-count {
    background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
    color: #155724;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 0.85rem;
    font-weight: 500;
}

/* Legacy category-count for backward compatibility */
.category-count {
    background: #e9ecef;
    color: #6c757d;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 0.9rem;
    font-weight: 500;
}

.category-arrow {
    font-size: 1.2rem;
    color: #6c757d;
    transition: transform 0.3s ease;
}

.category-header.active .category-arrow {
    transform: rotate(180deg);
    color: #2196f3;
}

/* Parent Category Content */
.parent-category-content {
    max-height: 0;
    overflow: hidden;
    transition: max-height 0.3s ease;
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    padding: 10px;
}

.parent-category-content.active {
    max-height: 2000px;
}

/* Subcategory Content */
.subcategory-content {
    max-height: 0;
    overflow: hidden;
    transition: max-height 0.3s ease;
    background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
}

.subcategory-content.active {
    max-height: 2000px;
}

/* Legacy category-content for backward compatibility */
.category-content {
    max-height: 0;
    overflow: hidden;
    transition: max-height 0.3s ease;
    background: #f8f9fa;
}

.category-content.active {
    max-height: 500px;
    padding: 20px 25px;
}

.courses-list {
    display: flex;
    flex-direction: column;
    gap: 15px;
}

.course-card {
    background: white;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    display: flex;
    align-items: center;
    gap: 20px;
    transition: all 0.3s ease;
}

.course-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(0,0,0,0.15);
}

/* Direct Courses Section Styles */
.direct-courses-section {
    margin: 15px 10px;
    background: linear-gradient(135deg, #e8f5e8 0%, #f0f8f0 100%);
    border-radius: 12px;
    border: 1px solid #c3e6cb;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(40, 167, 69, 0.1);
}

.direct-courses-header {
    background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
    color: white;
    padding: 15px 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.direct-courses-title {
    margin: 0;
    font-size: 1.1rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
}

.direct-courses-title::before {
    content: "📚";
    font-size: 1.2rem;
}

.direct-courses-count {
    background: rgba(255, 255, 255, 0.2);
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 0.9rem;
    font-weight: 500;
}

.direct-courses-list {
    padding: 20px;
    display: flex;
    flex-direction: column;
    gap: 15px;
}

.course-thumbnail {
    width: 80px;
    height: 80px;
    border-radius: 10px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 2rem;
    flex-shrink: 0;
    overflow: hidden;
    position: relative;
}

.course-thumbnail img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: 10px;
    position: absolute;
    top: 0;
    left: 0;
}

.course-thumbnail i {
    position: relative;
    z-index: 1;
}

.course-info {
    flex: 1;
}

.course-title {
    font-size: 1.2rem;
    font-weight: 700;
    color: #333;
    margin: 0 0 5px 0;
}

.course-subtitle {
    font-size: 1rem;
    color: #667eea;
    margin: 0 0 10px 0;
    font-weight: 500;
}

.course-actions {
    display: flex;
    gap: 10px;
}

.manage-content-btn {
    background: linear-gradient(135deg, #2196f3 0%, #21cbf3 100%);
    color: white;
    border: none;
    padding: 10px 20px;
    border-radius: 8px;
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
}

.manage-content-btn:hover {
    background: linear-gradient(135deg, #1976d2 0%, #1cb5e0 100%);
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(33, 150, 243, 0.3);
    color: white;
    text-decoration: none;
}

.manage-content-btn:disabled {
    opacity: 0.7;
    cursor: not-allowed;
    transform: none;
}

.manage-content-btn .fa-spinner {
    animation: spin 1s linear infinite;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

.empty-category {
    text-align: center;
    padding: 40px 20px;
    color: #6c757d;
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



/* Responsive Design */
@media (max-width: 768px) {
    .manage-content-container {
        padding: 15px;
    }
    
    .page-header {
        padding: 15px;
    }
    
    .page-title {
        font-size: 1.5rem;
    }
    
    .category-header {
        padding: 15px 20px;
    }
    
    .category-content.active {
        padding: 15px 20px;
    }
    
    .course-card {
        flex-direction: column;
        text-align: center;
        gap: 15px;
    }
    
    .course-thumbnail {
        width: 60px;
        height: 60px;
        font-size: 1.5rem;
    }
    
    
}
</style>

<?php
// Include admin sidebar from includes
require_once(__DIR__ . '/includes/admin_sidebar.php');
?>

<!-- Main content area with sidebar -->
<div class='admin-main-content'>

<div class="manage-content-container">
    <div class="page-header">
        <div class="page-icon">
            <i class="fa fa-book"></i>
        </div>
        <h1 class="page-title">Manage Course Content</h1>
    </div>

    <div class="categories-container">
        <?php foreach ($hierarchical_categories as $parent): ?>
            <div class="parent-category-item" data-category-id="<?php echo $parent['id']; ?>">
                <div class="parent-category-header" onclick="toggleParentCategory(<?php echo $parent['id']; ?>)">
                    <div class="category-info">
                        <h3 class="category-name"><?php echo htmlspecialchars($parent['name']); ?></h3>
                        <span class="category-count"><?php echo $parent['total_courses']; ?> courses</span>
                    </div>
                    <div class="category-arrow">
                        <i class="fa fa-chevron-down"></i>
                    </div>
                </div>
                <div class="parent-category-content" id="parent-content-<?php echo $parent['id']; ?>">
                    <?php if ($parent['direct_course_count'] > 0): ?>
                        <!-- Direct Courses Section -->
                        <div class="direct-courses-section">
                            <div class="direct-courses-header">
                                <h4 class="direct-courses-title">Direct Courses</h4>
                                <span class="direct-courses-count"><?php echo $parent['direct_course_count']; ?> courses</span>
                            </div>
                            <div class="direct-courses-list">
                                <?php foreach ($parent['direct_courses'] as $course): 
                                    // Get course image - try multiple methods with detailed debugging
                                    $courseimage = '';
                                    $debug_info = '';
                                    
                                    // Method 1: Try using the theme function
                                    try {
                                        $courseimage = theme_remui_kids_get_course_image($course);
                                        $debug_info .= 'Theme function returned: ' . $courseimage . ' | ';
                                    } catch (Exception $e) {
                                        $debug_info .= 'Theme function error: ' . $e->getMessage() . ' | ';
                                    }
                                    
                                    // Method 2: Direct database query to check if files exist
                                    try {
                                        $coursecontext = context_course::instance($course->id);
                                        $fs = get_file_storage();
                                        $files = $fs->get_area_files($coursecontext->id, 'course', 'overviewfiles', 0, 'timemodified DESC', false);
                                        $debug_info .= 'Files found: ' . count($files) . ' | ';
                                        
                                        if (!empty($files)) {
                                            $file = reset($files);
                                            $debug_info .= 'File: ' . $file->get_filename() . ' | ';
                                            
                                            // Try the exact method from lib.php line 698-705
                                            $direct_image = moodle_url::make_pluginfile_url(
                                                $coursecontext->id,
                                                'course',
                                                'overviewfiles',
                                                null,
                                                '/',
                                                $file->get_filename()
                                            )->out();
                                            $debug_info .= 'Direct URL: ' . $direct_image . ' | ';
                                            
                                            // If theme function returned a default image, use direct instead
                                            if (strpos($courseimage, 'freepik.com') !== false || 
                                                strpos($courseimage, 'unsplash.com') !== false) {
                                                $courseimage = $direct_image;
                                            }
                                        }
                                    } catch (Exception $e) {
                                        $debug_info .= 'Direct query error: ' . $e->getMessage() . ' | ';
                                    }
                                    
                                    // Check if it's a default online image
                                    $is_default_image = (strpos($courseimage, 'freepik.com') !== false || 
                                                         strpos($courseimage, 'unsplash.com') !== false);
                                    
                                    // Keep the default images from theme function (Freepik/Unsplash)
                                    // Don't replace with gradient - show the default images
                                    if ($is_default_image) {
                                        $debug_info .= 'Using default Freepik image | ';
                                    }
                                    
                                    // If no course image found, use a default gradient based on course ID
                                    if (empty($courseimage)) {
                                        $gradient_colors = [
                                            'linear-gradient(135deg, #667eea 0%, #764ba2 100%)',
                                            'linear-gradient(135deg, #f093fb 0%, #f5576c 100%)',
                                            'linear-gradient(135deg, #4facfe 0%, #00f2fe 100%)',
                                            'linear-gradient(135deg, #43e97b 0%, #38f9d7 100%)',
                                            'linear-gradient(135deg, #fa709a 0%, #fee140 100%)',
                                            'linear-gradient(135deg, #a8edea 0%, #fed6e3 100%)',
                                            'linear-gradient(135deg, #ff9a9e 0%, #fecfef 100%)',
                                            'linear-gradient(135deg, #ffecd2 0%, #fcb69f 100%)'
                                        ];
                                        $gradient_index = $course->id % count($gradient_colors);
                                        $default_gradient = $gradient_colors[$gradient_index];
                                    }
                                ?>
                                    <div class="course-card">
                                        <div class="course-thumbnail" <?php if (empty($courseimage)): ?>style="background: <?php echo $default_gradient; ?>"<?php endif; ?>>
                                            <?php if (!empty($courseimage)): ?>
                                                <img src="<?php echo $courseimage; ?>" alt="<?php echo htmlspecialchars($course->fullname); ?>" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                                <i class="fa fa-book" style="display: none;"></i>
                                            <?php else: ?>
                                            <i class="fa fa-book"></i>
                                            <?php endif; ?>
                                        </div>
                                        <div class="course-info">
                                            <h4 class="course-title"><?php echo htmlspecialchars($course->fullname); ?></h4>
                                            <p class="course-subtitle"><?php echo htmlspecialchars($course->shortname); ?></p>
                                            <!-- Debug Info: <?php echo htmlspecialchars($debug_info); ?> -->
                                            <!-- Final Image URL: <?php echo !empty($courseimage) ? htmlspecialchars($courseimage) : 'No image - using gradient'; ?> -->
                                        </div>
                                        <div class="course-actions">
                                        <button class="manage-content-btn" onclick="viewCourse(<?php echo $course->id; ?>)">
                                                Manage Content <i class="fa fa-chevron-right"></i>
                                            </button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <?php foreach ($parent['subcategories'] as $subcategory): ?>
                        <div class="subcategory-item" data-subcategory-id="<?php echo $subcategory['id']; ?>">
                            <div class="subcategory-header" onclick="toggleSubcategory(<?php echo $subcategory['id']; ?>)">
                                <div class="subcategory-info">
                                    <h4 class="subcategory-name"><?php echo htmlspecialchars($subcategory['name']); ?></h4>
                                    <span class="subcategory-count"><?php echo $subcategory['course_count']; ?> courses</span>
                                </div>
                                <div class="subcategory-arrow">
                                    <i class="fa fa-chevron-right"></i>
                                </div>
                            </div>
                            <div class="subcategory-content" id="subcontent-<?php echo $subcategory['id']; ?>">
                                <div class="courses-list" id="courses-<?php echo $subcategory['id']; ?>">
                        <?php 
                        if (count($subcategory['courses']) > 0): 
                        ?>
                            <?php foreach ($subcategory['courses'] as $course): 
                                // Get course image - try multiple methods with detailed debugging
                                $courseimage = '';
                                $debug_info = '';
                                
                                // Method 1: Try using the theme function
                                try {
                                    $courseimage = theme_remui_kids_get_course_image($course);
                                    $debug_info .= 'Theme function returned: ' . $courseimage . ' | ';
                                } catch (Exception $e) {
                                    $debug_info .= 'Theme function error: ' . $e->getMessage() . ' | ';
                                }
                                
                                // Method 2: Direct database query to check if files exist
                                try {
                                    $coursecontext = context_course::instance($course->id);
                                    $fs = get_file_storage();
                                    $files = $fs->get_area_files($coursecontext->id, 'course', 'overviewfiles', 0, 'timemodified DESC', false);
                                    $debug_info .= 'Files found: ' . count($files) . ' | ';
                                    
                                    if (!empty($files)) {
                                        $file = reset($files);
                                        $debug_info .= 'File: ' . $file->get_filename() . ' | ';
                                        
                                        // Try the exact method from lib.php line 698-705
                                        $direct_image = moodle_url::make_pluginfile_url(
                                            $coursecontext->id,
                                            'course',
                                            'overviewfiles',
                                            null,
                                            '/',
                                            $file->get_filename()
                                        )->out();
                                        $debug_info .= 'Direct URL: ' . $direct_image . ' | ';
                                        
                                        // If theme function returned a default image, use direct instead
                                        if (strpos($courseimage, 'freepik.com') !== false || 
                                            strpos($courseimage, 'unsplash.com') !== false) {
                                            $courseimage = $direct_image;
                                        }
                                    }
                                } catch (Exception $e) {
                                    $debug_info .= 'Direct query error: ' . $e->getMessage() . ' | ';
                                }
                                
                                // Check if it's a default online image
                                $is_default_image = (strpos($courseimage, 'freepik.com') !== false || 
                                                     strpos($courseimage, 'unsplash.com') !== false);
                                
                                // Keep the default images from theme function (Freepik/Unsplash)
                                // Don't replace with gradient - show the default images
                                if ($is_default_image) {
                                    $debug_info .= 'Using default Freepik image | ';
                                }
                                
                                // If no course image found, use a default gradient based on course ID
                                if (empty($courseimage)) {
                                    $gradient_colors = [
                                        'linear-gradient(135deg, #667eea 0%, #764ba2 100%)',
                                        'linear-gradient(135deg, #f093fb 0%, #f5576c 100%)',
                                        'linear-gradient(135deg, #4facfe 0%, #00f2fe 100%)',
                                        'linear-gradient(135deg, #43e97b 0%, #38f9d7 100%)',
                                        'linear-gradient(135deg, #fa709a 0%, #fee140 100%)',
                                        'linear-gradient(135deg, #a8edea 0%, #fed6e3 100%)',
                                        'linear-gradient(135deg, #ff9a9e 0%, #fecfef 100%)',
                                        'linear-gradient(135deg, #ffecd2 0%, #fcb69f 100%)'
                                    ];
                                    $gradient_index = $course->id % count($gradient_colors);
                                    $default_gradient = $gradient_colors[$gradient_index];
                                }
                            ?>
                                <div class="course-card">
                                    <div class="course-thumbnail" <?php if (empty($courseimage)): ?>style="background: <?php echo $default_gradient; ?>"<?php endif; ?>>
                                        <?php if (!empty($courseimage)): ?>
                                            <img src="<?php echo $courseimage; ?>" alt="<?php echo htmlspecialchars($course->fullname); ?>" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                            <i class="fa fa-book" style="display: none;"></i>
                                        <?php else: ?>
                                        <i class="fa fa-book"></i>
                                        <?php endif; ?>
                                    </div>
                                    <div class="course-info">
                                        <h4 class="course-title"><?php echo htmlspecialchars($course->fullname); ?></h4>
                                        <p class="course-subtitle"><?php echo htmlspecialchars($course->shortname); ?></p>
                                        <!-- Debug Info: <?php echo htmlspecialchars($debug_info); ?> -->
                                        <!-- Final Image URL: <?php echo !empty($courseimage) ? htmlspecialchars($courseimage) : 'No image - using gradient'; ?> -->
                                    </div>
                                    <div class="course-actions">
                                    <button class="manage-content-btn" onclick="viewCourse(<?php echo $course->id; ?>)">
                                            Manage Content <i class="fa fa-chevron-right"></i>
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-category">
                                <div class="empty-icon">
                                    <i class="fa fa-book"></i>
                                </div>
                                <p class="empty-text">No courses in this subcategory</p>
                            </div>
                        <?php endif; ?>
                    </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Floating Action Button -->


<script>
// Get base URL from PHP
const WWWROOT = '<?php echo $CFG->wwwroot; ?>';

// Ensure jQuery is available
if (typeof $ === 'undefined') {
    console.warn('jQuery not loaded, using vanilla JavaScript');
}

// Parent Category toggle functionality
function toggleParentCategory(categoryId) {
    const categoryItem = document.querySelector(`[data-category-id="${categoryId}"]`);
    const categoryHeader = categoryItem.querySelector('.parent-category-header');
    const categoryContent = document.getElementById(`parent-content-${categoryId}`);
    
    // Toggle active state
    categoryHeader.classList.toggle('active');
    categoryContent.classList.toggle('active');
}

// Subcategory toggle functionality
function toggleSubcategory(subcategoryId) {
    const subcategoryItem = document.querySelector(`[data-subcategory-id="${subcategoryId}"]`);
    const subcategoryHeader = subcategoryItem.querySelector('.subcategory-header');
    const subcategoryContent = document.getElementById(`subcontent-${subcategoryId}`);
    
    // Toggle active state
    subcategoryHeader.classList.toggle('active');
    subcategoryContent.classList.toggle('active');
}

// Legacy category toggle functionality for backward compatibility
function toggleCategory(categoryId) {
    const categoryItem = document.querySelector(`[data-category-id="${categoryId}"]`);
    const categoryHeader = categoryItem.querySelector('.category-header');
    const categoryContent = document.getElementById(`content-${categoryId}`);
    
    // Toggle active state
    categoryHeader.classList.toggle('active');
    categoryContent.classList.toggle('active');
}

// AJAX functions removed - courses are now loaded directly in PHP

// Function to handle course selection for content management

    // Show loading state
    function selectCourseForContent(courseId) {
    // Call viewCourse function when a course is selected for content management
    viewCourse(courseId);
}


// View course function


function viewCourse(courseId) {
    // Redirect to course view page
    window.location.href = `${WWWROOT}/course/view.php?id=${courseId}`;
}

// Initialize page
document.addEventListener('DOMContentLoaded', function() {
    // Auto-expand first category with courses
    const categories = document.querySelectorAll('.category-item');
    for (let category of categories) {
        const count = category.querySelector('.category-count').textContent;
        if (count !== '0') {
            const categoryId = category.dataset.categoryId;
            toggleCategory(categoryId);
            break;
        }
    }
});

// Sidebar toggle function
function toggleSidebar() {
    const sidebar = document.querySelector('.admin-sidebar');
    sidebar.classList.toggle('sidebar-open');
}

// Close sidebar when clicking outside on mobile
document.addEventListener('click', function(event) {
    const sidebar = document.querySelector('.admin-sidebar');
    const toggleBtn = document.querySelector('.sidebar-toggle');
    
    if (window.innerWidth <= 768) {
        if (!sidebar.contains(event.target) && toggleBtn && !toggleBtn.contains(event.target)) {
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

</div><!-- Close admin-main-content -->

<?php
echo $OUTPUT->footer();
?>
