<?php
/**
 * Courses & Programs Management Page
 * Displays all courses in card format with full management functionality
 */

require_once('../../../config.php');
require_login();

// Check admin capabilities
$context = context_system::instance();
require_capability('moodle/site:config', $context);

// Get current user
global $USER, $DB, $OUTPUT;

// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    switch ($_GET['action']) {
            
    }
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'create_course':
                $fullname = trim($_POST['fullname']);
                $shortname = trim($_POST['shortname']);
                $summary = trim($_POST['summary']);
                $category_id = intval($_POST['category_id']);
                
                if ($fullname && $shortname && $category_id) {
                    $course = new stdClass();
                    $course->fullname = $fullname;
                    $course->shortname = $shortname;
                    $course->summary = $summary;
                    $course->category = $category_id;
                    $course->format = 'topics';
                    $course->numsections = 1;
                    $course->startdate = time();
                    $course->timecreated = time();
                    $course->timemodified = time();
                    $course->visible = 1;
                    
                    if ($DB->insert_record('course', $course)) {
                        $success_message = "Course created successfully!";
                        $message_type = "success";
                    } else {
                        $success_message = "Failed to create course. Please try again.";
                        $message_type = "error";
                    }
                } else {
                    $success_message = "Please fill in all required fields.";
                    $message_type = "error";
                }
                break;
                
            case 'create_category':
                $name = trim($_POST['name']);
                $description = trim($_POST['description']);
                
                if ($name) {
                    $category = new stdClass();
                    $category->name = $name;
                    $category->description = $description;
                    $category->parent = 0;
                    $category->sortorder = 0;
                    $category->timecreated = time();
                    $category->timemodified = time();
                    $category->visible = 1;
                    
                    if ($DB->insert_record('course_categories', $category)) {
                        $success_message = "Category created successfully!";
                        $message_type = "success";
                    } else {
                        $success_message = "Failed to create category. Please try again.";
                        $message_type = "error";
                    }
                } else {
                    $success_message = "Please enter category name.";
                    $message_type = "error";
                }
                break;
        }
    }
}


// Get all categories
$categories = $DB->get_records('course_categories', ['visible' => 1], 'name ASC');

// Get statistics
$total_courses = $DB->count_records_select('course', 'id > 1 AND visible = 1');
$total_categories = count($categories);

// Get total enrollments
$total_enrollments = $DB->count_records_sql(
    "SELECT COUNT(*) FROM {user_enrolments} ue 
     JOIN {enrol} e ON ue.enrolid = e.id 
     JOIN {course} c ON e.courseid = c.id 
     WHERE c.visible = 1 AND c.id > 1"
);

// Get learning paths (mock data for now - you can implement this based on your system)
$learning_paths = 30; // This would be calculated based on your learning path system

$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/admin/courses.php');
$PAGE->set_title('Courses & Programs Management');
$PAGE->set_heading('Courses & Programs Management');

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
        background: #f8f9fa;
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

/* Courses Page Styles */
.courses-container {
    max-width: 100%;
    margin: 0;
    padding: 2rem 2.5rem;
    background: #ffffff;
    min-height: 100vh;
}

.courses-header {
    text-align: center;
    margin-bottom: 50px;
    color: #2c3e50;
    position: relative;
    background: #ffffff;
    padding: 35px 30px;
    border-radius: 15px;
    box-shadow: 0 18px 45px rgba(15, 23, 42, 0.08);
    border: 1px solid rgba(99, 102, 241, 0.18);
}

.courses-header h1 {
    font-size: 2.5rem;
    font-weight: 700;
    margin-bottom: 15px;
    color: #0f172a;
    text-shadow: none;
}

.courses-header p {
    font-size: 1.1rem;
    color: #5c6ac4;
    margin-bottom: 30px;
    font-weight: 500;
}

.header-actions {
    position: absolute;
    top: 20px;
    right: 20px;
    display: flex;
    gap: 15px;
}

.header-btn {
    background: #ffffff;
    color: #6c757d;
    border: 2px solid #e9ecef;
    padding: 12px 24px;
    border-radius: 12px;
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    gap: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.header-btn:hover {
    background: #f8f9fa;
    border-color: #dee2e6;
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(0,0,0,0.15);
}

.header-btn.primary {
    background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
    color: white;
    border: none;
}

.header-btn.primary:hover {
    background: linear-gradient(135deg, #0056b3 0%, #004085 100%);
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(0,123,255,0.3);
}


.stats-row {
    display: flex;
    justify-content: space-between;
    gap: 20px;
    margin-bottom: 40px;
    flex-wrap: wrap;
}

.stat-card {
    background: #ffffff;
    padding: 30px;
    border-radius: 18px;
    text-align: center;
    box-shadow: 0 12px 32px rgba(15, 23, 42, 0.08);
    border: 1px solid rgba(148, 163, 184, 0.35);
    transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
    animation: fadeInUp 0.6s ease-out;
    display: flex;
    align-items: center;
    gap: 18px;
    flex: 1;
    min-width: 280px;
    position: relative;
}

.stat-card:hover {
    transform: translateY(-6px);
    box-shadow: 0 18px 40px rgba(15, 23, 42, 0.15);
    border-color: rgba(59, 130, 246, 0.25);
}

.stat-icon {
    width: 58px;
    height: 58px;
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.8rem;
    font-weight: 600;
    flex-shrink: 0;
}

.stat-content {
    text-align: left;
}

.stat-content .number {
    font-size: 2.6rem;
    font-weight: 800;
    margin-bottom: 6px;
    line-height: 1;
    color: #0f172a;
}

.stat-content .label {
    font-size: 1rem;
    font-weight: 600;
    letter-spacing: 0.02em;
}

.stat-card.stat-courses .stat-icon {
    background: rgba(59, 130, 246, 0.15);
    color: #1d4ed8;
}

.stat-card.stat-courses .number,
.stat-card.stat-courses .label {
    color: #1d4ed8;
}
.stat-card.stat-courses:hover {
    border-color: rgba(37, 99, 235, 0.35);
}

.stat-card.stat-categories .stat-icon {
    background: rgba(34, 197, 94, 0.15);
    color: #15803d;
}

.stat-card.stat-categories .number,
.stat-card.stat-categories .label {
    color: #15803d;
}
.stat-card.stat-categories:hover {
    border-color: rgba(21, 128, 61, 0.35);
}

.stat-card.stat-enrollments .stat-icon {
    background: rgba(139, 92, 246, 0.15);
    color: #6d28d9;
}

.stat-card.stat-enrollments .number,
.stat-card.stat-enrollments .label {
    color: #6d28d9;
}
.stat-card.stat-enrollments:hover {
    border-color: rgba(109, 40, 217, 0.35);
}

.stat-card.stat-paths .stat-icon {
    background: rgba(249, 115, 22, 0.15);
    color: #c2410c;
}

.stat-card.stat-paths .number,
.stat-card.stat-paths .label {
    color: #c2410c;
}
.stat-card.stat-paths:hover {
    border-color: rgba(194, 65, 12, 0.35);
}

.actions-row {
    display: flex;
    justify-content: center;
    gap: 20px;
    margin-bottom: 40px;
    flex-wrap: wrap;
}

/* Management Section */
.management-section {
    margin-bottom: 50px;
}

.section-title {
    text-align: left;
    color: #1f2937;
    font-size: 1.6rem;
    font-weight: 700;
    margin: 0 0 25px 5px;
    text-shadow: none;
}

/* Management Cards Grid */
.management-grid-container {
    max-width: 100%;
    margin: 0 auto 40px auto;
    padding: 20px;
}

.management-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 20px;
    justify-items: center;
}

.management-card {
    width: 100%;
    max-width: 320px;
    background:#ffffff;
    border-radius: 15px;
    padding: 25px;
    color: #2c3e50;
    cursor: pointer;
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
    box-shadow: 0 4px 20px rgba(0,0,0,0.08);
    border: 1px solid #e9ecef;
    display: flex;
    flex-direction: column;
    gap: 15px;
}

.management-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 30px rgba(0,0,0,0.15);
    border-color: #dee2e6;
}

.card-icon {
    width: 50px;
    height: 50px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.3rem;
    flex-shrink: 0;
    align-self: flex-start;
    color: #ffffff;
}

.create-icon { background: linear-gradient(135deg, #28a745, #20c997); }
.enrollment-icon { background: linear-gradient(135deg, #6f42c1, #e83e8c); }
.content-icon { background: linear-gradient(135deg, #6f42c1, #e83e8c); }
.settings-icon { background: linear-gradient(135deg, #fd7e14, #dc3545); }
.view-courses-icon { background: linear-gradient(135deg, #17a2b8, #138496); }

.card-content {
    width: 100%;
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.card-content h3 {
    margin: 0;
    font-size: 1.3rem;
    font-weight: 600;
    color: #2c3e50;
    line-height: 1.3;
}

.card-subtitle {
    margin: 0;
    font-size: 0.85rem;
    color: #6c757d;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.card-description {
    margin: 0;
    font-size: 0.9rem;
    color: #6c757d;
    line-height: 1.4;
}

.card-status {
    margin-top: auto;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.status-available {
    background: rgba(40, 167, 69, 0.2);
    color: #28a745;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 500;
    border: 1px solid rgba(40, 167, 69, 0.3);
}

.card-arrow {
    font-size: 1.1rem;
    color: #6c757d;
    transition: all 0.3s ease;
    align-self: flex-end;
}

.management-card:hover .card-arrow {
    color: #007bff;
    transform: translateX(3px);
}


.action-btn {
    background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
    color: white;
    border: none;
    padding: 15px 30px;
    border-radius: 12px;
    font-size: 1rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 4px 15px rgba(0,123,255,0.2);
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 10px;
}

.action-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(0,123,255,0.3);
    color: white;
    text-decoration: none;
    background: linear-gradient(135deg, #0056b3 0%, #004085 100%);
}

.action-btn i {
    font-size: 1.1rem;
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
    background-color: rgba(0,0,0,0.5);
    backdrop-filter: blur(5px);
}

.modal-content {
    background-color: white;
    margin: 5% auto;
    padding: 0;
    border-radius: 20px;
    width: 90%;
    max-width: 600px;
    box-shadow: 0 20px 60px rgba(0,0,0,0.3);
    animation: modalSlideIn 0.3s ease-out;
}

@keyframes modalSlideIn {
    from { opacity: 0; transform: translateY(-50px); }
    to { opacity: 1; transform: translateY(0); }
}

.modal-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
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

.close {
    color: white;
    font-size: 28px;
    font-weight: bold;
    cursor: pointer;
    transition: opacity 0.2s ease;
}

.close:hover {
    opacity: 0.7;
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
    box-sizing: border-box;
}

.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus {
    outline: none;
    border-color: #667eea;
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
}

.form-group textarea {
    resize: vertical;
    min-height: 100px;
}

.modal-footer {
    padding: 20px 30px;
    border-top: 1px solid #eee;
    display: flex;
    justify-content: flex-end;
    gap: 15px;
}

.btn {
    padding: 12px 25px;
    border: none;
    border-radius: 10px;
    font-size: 1rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.btn-primary {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
}

.btn-secondary {
    background: #6c757d;
    color: white;
}

.btn-secondary:hover {
    background: #5a6268;
}

.btn-danger {
    background: #dc3545;
    color: white;
}

.btn-danger:hover {
    background: #c82333;
}

/* Message Styles */
.message {
    padding: 15px 20px;
    border-radius: 10px;
    margin-bottom: 20px;
    font-weight: 500;
    animation: slideInDown 0.5s ease-out;
}

.message-success {
    background: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
}

.message-error {
    background: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
}

@keyframes slideInDown {
    from { opacity: 0; transform: translateY(-20px); }
    to { opacity: 1; transform: translateY(0); }
}

@keyframes fadeInUp {
    from { opacity: 0; transform: translateY(30px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Responsive Design */
@media (max-width: 768px) {
    .courses-container {
        padding: 15px;
    }
    
    .courses-header h1 {
        font-size: 2rem;
    }
    
    .header-actions {
        position: static;
        justify-content: center;
        margin-bottom: 20px;
    }
    
    .stats-row {
        flex-direction: column;
        align-items: stretch;
        gap: 15px;
    }
    
    .stat-card {
        min-width: auto;
        width: 100%;
        flex: none;
    }
    
    .management-grid {
        grid-template-columns: 1fr;
        gap: 20px;
    }
    
    .management-card {
        width: 100%;
        max-width: none;
        flex-direction: column;
        text-align: center;
        gap: 15px;
    }
    
    .card-icon {
        width: 50px;
        height: 50px;
        font-size: 1.2rem;
    }
    
    .management-carousel-container {
        padding: 10px;
    }
    
    .actions-row {
        flex-direction: column;
        align-items: center;
    }
    
    
    .management-grid-container {
        padding: 10px;
    }
    
}
</style>

<div class="courses-container">
    <div class="courses-header">
        <div class="header-actions">
            <!-- <button class="header-btn" onclick="refreshCourses()">
                <i class="fa fa-refresh"></i>
                Refresh
            </button> -->
            <button class="header-btn primary"  onclick="viewAllCourses()">
                <i class="fa fa-list"></i>
                View All Courses
            </button>
        </div>
        <h1>Courses & Categories Management</h1>
        <p>Comprehensive course administration and learning management tools</p>
    </div>

    <?php if (isset($success_message)): ?>
        <div class="message message-<?php echo $message_type; ?>">
            <?php echo htmlspecialchars($success_message); ?>
        </div>
    <?php endif; ?>

    <!-- Top Statistics Row -->
    <div class="stats-row">
        <div class="stat-card stat-courses">
            <div class="stat-icon">
                <i class="fa fa-book"></i>
            </div>
            <div class="stat-content">
                <div class="number"><?php echo $total_courses; ?></div>
                <div class="label">Total Courses</div>
            </div>
        </div>
        <div class="stat-card stat-categories">
            <div class="stat-icon">
                <i class="fa fa-th-large"></i>
            </div>
            <div class="stat-content">
                <div class="number"><?php echo $total_categories; ?></div>
                <div class="label">Categories</div>
            </div>
        </div>
        <div class="stat-card stat-enrollments">
            <div class="stat-icon">
                <i class="fa fa-users"></i>
            </div>
            <div class="stat-content">
                <div class="number"><?php echo number_format($total_enrollments); ?></div>
                <div class="label">Total Enrolments</div>
            </div>
        </div>
        <div class="stat-card stat-paths">
            <div class="stat-icon">
                <i class="fa fa-sitemap"></i>
            </div>
            <div class="stat-content">
                <div class="number"><?php echo $learning_paths; ?></div>
                <div class="label">Learning Paths</div>
            </div>
        </div>
    </div>

    <!-- Management Cards Section -->
    <div class="management-section">
        <div class="management-grid-container">
                <h2 class="section-title">Course Management</h2>
                <div class="management-grid" id="managementGrid">
                    <div class="management-card" onclick="window.location.href = WWWROOT + '/theme/remui_kids/admin/course_categories.php';">
                        <div class="card-icon create-icon">
                            <i class="fa fa-plus"></i>
                        </div>
                        <div class="card-content">
                            <h3>Create Course / Category</h3>
                            <p class="card-subtitle">Course Management</p>
                            <p class="card-description">Add new courses and organize them into categories</p>
                            <div class="card-status">
                                <span class="status-available"><?php echo $total_categories; ?> Available</span>
                                <div class="card-arrow">
                                    <i class="fa fa-arrow-right"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="management-card" onclick="window.location.href='enrollments.php'">
                        <div class="card-icon enrollment-icon">
                            <i class="fa fa-user-plus"></i>
                        </div>
                        <div class="card-content">
                            <h3>User Enrollments</h3>
                            <p class="card-subtitle">Enrollment Management</p>
                            <p class="card-description">Manage student enrollments and track progress</p>
                            <div class="card-status">
                                <span class="status-available"><?php echo number_format($total_enrollments); ?> Available</span>
                                <div class="card-arrow">
                                    <i class="fa fa-arrow-right"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="management-card" onclick="manageCourseContent()">
                        <div class="card-icon content-icon">
                            <i class="fa fa-edit"></i>
                        </div>
                        <div class="card-content">
                            <h3>Manage Course Content</h3>
                            <p class="card-subtitle">Content Management</p>
                            <p class="card-description">Add Activities, Quiz, Etc...</p>
                            <div class="card-status">
                                <span class="status-available"><?php echo $total_courses; ?> Available</span>
                                <div class="card-arrow">
                                    <i class="fa fa-arrow-right"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="management-card" onclick="viewAllCourses()">
                        <div class="card-icon view-courses-icon">
                            <i class="fa fa-list"></i>
                        </div>
                        <div class="card-content">
                            <h3>View All Courses</h3>
                            <p class="card-subtitle">Course Overview</p>
                            <p class="card-description">Browse and manage all courses in the system</p>
                            <div class="card-status">
                                <span class="status-available"><?php echo $total_courses; ?> Total</span>
                                <div class="card-arrow">
                                    <i class="fa fa-arrow-right"></i>
                                </div>
                            </div>
                        </div>
                    </div>



                                    </div>
                                </div>
                            </div>
                            
</div>

<!-- Create Course Modal -->
<div id="createCourseModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Create New Course</h3>
            <span class="close" onclick="closeModal('createCourseModal')">&times;</span>
        </div>
        <form method="POST" action="">
            <div class="modal-body">
                <input type="hidden" name="action" value="create_course">
                
                <div class="form-group">
                    <label for="fullname">Course Name *</label>
                    <input type="text" id="fullname" name="fullname" required>
                </div>
                
                <div class="form-group">
                    <label for="shortname">Short Name *</label>
                    <input type="text" id="shortname" name="shortname" required>
                </div>
                
                <div class="form-group">
                    <label for="category_id">Category *</label>
                    <select id="category_id" name="category_id" required>
                        <option value="">Select Category</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?php echo $category->id; ?>"><?php echo htmlspecialchars($category->name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="summary">Description</label>
                    <textarea id="summary" name="summary" placeholder="Enter course description..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('createCourseModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Create Course</button>
            </div>
        </form>
    </div>
</div>

<!-- Create Category Modal -->
<div id="createCategoryModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Create New Category</h3>
            <span class="close" onclick="closeModal('createCategoryModal')">&times;</span>
        </div>
        <form method="POST" action="">
            <div class="modal-body">
                <input type="hidden" name="action" value="create_category">
                
                <div class="form-group">
                    <label for="name">Category Name *</label>
                    <input type="text" id="name" name="name" required>
                </div>
                
                <div class="form-group">
                    <label for="description">Description</label>
                    <textarea id="description" name="description" placeholder="Enter category description..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('createCategoryModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Create Category</button>
            </div>
        </form>
    </div>
</div>

<script>
// Get base URL from PHP
const WWWROOT = '<?php echo $CFG->wwwroot; ?>';

// Modal functions
function openModal(modalId) {
    document.getElementById(modalId).style.display = 'block';
}

function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

// Close modal when clicking outside
window.onclick = function(event) {
    const modals = document.querySelectorAll('.modal');
    modals.forEach(modal => {
        if (event.target === modal) {
            modal.style.display = 'none';
        }
    });
}

function refreshCourses() {
    location.reload();
}

// Management card functions
function manageCourseContent() {
    // Show course selection modal or redirect to course selection page
    // For now, redirect to the course content management page
    window.location.href = 'manage_course_content.php';
}

function selectCourseForContent(courseId) {
    // Call viewCourse function when a course is selected for content management
    viewCourse(courseId);
}

function viewAllCourses() {
    // Redirect to view all courses page
    window.location.href = '<?php echo $CFG->wwwroot; ?>/theme/remui_kids/admin/view_all_courses.php';
}


function viewCourse(courseId) {
    // Redirect to view all courses page
    window.location.href = WWWROOT + '/course/view.php?id=' + courseId;
}

function showMessage(message, type) {
    const messageDiv = document.createElement('div');
    messageDiv.className = `message message-${type}`;
    messageDiv.textContent = message;
    
    const container = document.querySelector('.courses-container');
    container.insertBefore(messageDiv, container.firstChild);
    
    setTimeout(() => {
        messageDiv.remove();
    }, 5000);
}

// Grid layout initialization
function initGrids() {
    // Grid layouts are handled by CSS, no JavaScript needed
    console.log('Grid layouts initialized');
}

// Initialize grids when page loads
document.addEventListener('DOMContentLoaded', function() {
    initGrids();
});

// Add fadeOut animation
const style = document.createElement('style');
style.textContent = `
    @keyframes fadeOut {
        from { opacity: 1; transform: scale(1); }
        to { opacity: 0; transform: scale(0.8); }
    }
`;
document.head.appendChild(style);
</script>

<?php
echo "</div>"; // End admin-main-content
echo $OUTPUT->footer();
?>
