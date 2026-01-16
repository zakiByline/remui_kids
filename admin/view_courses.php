<?php
require_once('../../../config.php');

// Check if user is logged in and has proper permissions
require_login();
$context = context_system::instance();
require_capability('moodle/course:view', $context);

// Get company information for the logged-in user
$user_id = $USER->id;
$company_info = $DB->get_record_sql(
    "SELECT c.* FROM {company} c 
     JOIN {company_users} cu ON c.id = cu.companyid 
     WHERE cu.userid = ? AND cu.managertype = 1",
    [$user_id]
);

if (!$company_info) {
    print_error('Company not found', 'error');
}

// Get courses associated with the company
$courses = $DB->get_records_sql(
    "SELECT c.*, cc.id as company_course_id
     FROM {course} c 
     LEFT JOIN {company_course} cc ON c.id = cc.courseid AND cc.companyid = ?
     WHERE c.visible = 1 AND c.id > 1
     ORDER BY c.fullname ASC",
    [$company_info->id]
);

// Get course statistics
$course_stats = [];
foreach ($courses as $course) {
    // Count enrolled students
    $enrolled_count = $DB->count_records_sql(
        "SELECT COUNT(DISTINCT ue.userid) 
         FROM {user_enrolments} ue 
         JOIN {enrol} e ON ue.enrolid = e.id 
         JOIN {user} u ON ue.userid = u.id
         JOIN {company_users} cu ON u.id = cu.userid
         WHERE e.courseid = ? AND cu.companyid = ? AND ue.status = 0 AND e.status = 0 AND u.deleted = 0",
        [$course->id, $company_info->id]
    );
    
    // Count teachers assigned to course
    $teacher_count = $DB->count_records_sql(
        "SELECT COUNT(DISTINCT ra.userid) 
         FROM {role_assignments} ra 
         JOIN {role} r ON ra.roleid = r.id 
         JOIN {user} u ON ra.userid = u.id
         JOIN {company_users} cu ON u.id = cu.userid
         WHERE ra.contextid = ? AND r.shortname IN ('teacher', 'editingteacher', 'coursecreator') AND cu.companyid = ? AND u.deleted = 0",
        [context_course::instance($course->id)->id, $company_info->id]
    );
    
    $course_stats[$course->id] = [
        'enrolled_students' => $enrolled_count,
        'assigned_teachers' => $teacher_count
    ];
}

$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/admin/view_courses.php');
$PAGE->set_title('View Courses');
$PAGE->set_heading('View Courses');

echo $OUTPUT->header();
?>

<div class="school-manager-main-content">
    <div class="view-courses-container">
        <div class="compact-header">
            <h1 class="page-title">View Courses</h1>
            <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/school_manager_dashboard.php" class="back-btn">
                <i class="fa fa-arrow-left"></i> Back to Dashboard
            </a>
        </div>
        
        <div class="courses-stats">
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fa fa-book"></i>
                </div>
                <div class="stat-content">
                    <h3 class="stat-number"><?php echo count($courses); ?></h3>
                    <p class="stat-label">Total Courses</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fa fa-users"></i>
                </div>
                <div class="stat-content">
                    <h3 class="stat-number"><?php echo array_sum(array_column($course_stats, 'enrolled_students')); ?></h3>
                    <p class="stat-label">Total Enrollments</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fa fa-chalkboard-teacher"></i>
                </div>
                <div class="stat-content">
                    <h3 class="stat-number"><?php echo array_sum(array_column($course_stats, 'assigned_teachers')); ?></h3>
                    <p class="stat-label">Teacher Assignments</p>
                </div>
            </div>
        </div>
        
        <div class="courses-list-container">
            <div class="courses-header">
                <h2>Available Courses</h2>
                <div class="search-filter">
                    <input type="text" id="course-search" placeholder="Search courses..." class="search-input">
                    <select id="course-filter" class="filter-select">
                        <option value="">All Courses</option>
                        <option value="assigned">Assigned to School</option>
                        <option value="unassigned">Not Assigned</option>
                    </select>
                </div>
            </div>
            
            <div class="courses-grid" id="courses-grid">
                <?php if (empty($courses)): ?>
                    <div class="no-courses">
                        <div class="no-courses-icon">
                            <i class="fa fa-book-open"></i>
                        </div>
                        <h3>No Courses Available</h3>
                        <p>There are no courses available for your school at the moment.</p>
                        <a href="<?php echo $CFG->wwwroot; ?>/course/index.php" class="btn btn-primary">
                            <i class="fa fa-plus"></i> Browse All Courses
                        </a>
                    </div>
                <?php else: ?>
                    <?php foreach ($courses as $course): ?>
                        <div class="course-card" data-course-id="<?php echo $course->id; ?>" 
                             data-assigned="<?php echo $course->company_course_id ? 'assigned' : 'unassigned'; ?>">
                            <div class="course-header">
                                <div class="course-icon">
                                    <i class="fa fa-book"></i>
                                </div>
                                <div class="course-status">
                                    <?php if ($course->company_course_id): ?>
                                        <span class="status-badge assigned">Assigned</span>
                                    <?php else: ?>
                                        <span class="status-badge unassigned">Available</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="course-content">
                                <h3 class="course-title"><?php echo format_string($course->fullname); ?></h3>
                                <p class="course-summary"><?php echo format_string($course->summary); ?></p>
                                
                                <div class="course-meta">
                                    <div class="meta-item">
                                        <i class="fa fa-calendar"></i>
                                        <span>Created: <?php echo date('M d, Y', $course->timecreated); ?></span>
                                    </div>
                                    <div class="meta-item">
                                        <i class="fa fa-id-badge"></i>
                                        <span>ID: <?php echo $course->idnumber ?: 'N/A'; ?></span>
                                    </div>
                                </div>
                                
                                <div class="course-stats">
                                    <div class="stat-item">
                                        <i class="fa fa-users"></i>
                                        <span><?php echo $course_stats[$course->id]['enrolled_students']; ?> Students</span>
                                    </div>
                                    <div class="stat-item">
                                        <i class="fa fa-chalkboard-teacher"></i>
                                        <span><?php echo $course_stats[$course->id]['assigned_teachers']; ?> Teachers</span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="course-actions">
                                <?php if ($course->company_course_id): ?>
                                    <a href="<?php echo $CFG->wwwroot; ?>/course/view.php?id=<?php echo $course->id; ?>" 
                                       class="btn btn-primary btn-sm">
                                        <i class="fa fa-eye"></i> View Course
                                    </a>
                                    <button class="btn btn-secondary btn-sm" onclick="viewCourseDetails(<?php echo $course->id; ?>)">
                                        <i class="fa fa-info-circle"></i> Details
                                    </button>
                                <?php else: ?>
                                    <button class="btn btn-success btn-sm" onclick="assignCourse(<?php echo $course->id; ?>)">
                                        <i class="fa fa-plus"></i> Assign to School
                                    </button>
                                    <a href="<?php echo $CFG->wwwroot; ?>/course/view.php?id=<?php echo $course->id; ?>" 
                                       class="btn btn-outline btn-sm">
                                        <i class="fa fa-eye"></i> Preview
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<style>
.school-manager-main-content {
    padding: 0;
    margin: 0;
    min-height: 100vh;
    background-color: #f8f9fa;
}

.view-courses-container {
    padding: 1rem;
    max-width: 1400px;
    margin: 0 auto;
}

.compact-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.5rem;
    padding: 1rem;
    background: white;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.compact-header h1.page-title {
    font-size: 1.8rem;
    font-weight: 600;
    color: #2c3e50;
    margin: 0;
}

.back-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 1rem;
    background: #6c757d;
    color: white;
    text-decoration: none;
    border-radius: 4px;
    font-size: 0.9rem;
    transition: background-color 0.2s;
}

.back-btn:hover {
    background: #5a6268;
    color: white;
    text-decoration: none;
}

.courses-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-bottom: 2rem;
}

.stat-card {
    background: white;
    padding: 1.5rem;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    display: flex;
    align-items: center;
    gap: 1rem;
}

.stat-icon {
    width: 50px;
    height: 50px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 1.2rem;
}

.stat-content h3.stat-number {
    font-size: 1.8rem;
    font-weight: 700;
    color: #2c3e50;
    margin: 0;
}

.stat-content p.stat-label {
    color: #6c757d;
    margin: 0;
    font-size: 0.9rem;
}

.courses-list-container {
    background: white;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    overflow: hidden;
}

.courses-header {
    padding: 1.5rem;
    border-bottom: 1px solid #dee2e6;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1rem;
}

.courses-header h2 {
    margin: 0;
    color: #2c3e50;
}

.search-filter {
    display: flex;
    gap: 1rem;
    align-items: center;
}

.search-input, .filter-select {
    padding: 0.5rem;
    border: 1px solid #ced4da;
    border-radius: 4px;
    font-size: 0.9rem;
}

.search-input {
    width: 200px;
}

.filter-select {
    width: 150px;
}

.courses-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
    gap: 1.5rem;
    padding: 1.5rem;
}

.course-card {
    border: 1px solid #dee2e6;
    border-radius: 8px;
    overflow: hidden;
    transition: all 0.3s ease;
    background: white;
}

.course-card:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    transform: translateY(-2px);
}

.course-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem;
    background: #f8f9fa;
    border-bottom: 1px solid #dee2e6;
}

.course-icon {
    width: 40px;
    height: 40px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
}

.status-badge {
    padding: 0.25rem 0.75rem;
    border-radius: 12px;
    font-size: 0.8rem;
    font-weight: 500;
}

.status-badge.assigned {
    background: #d4edda;
    color: #155724;
}

.status-badge.unassigned {
    background: #fff3cd;
    color: #856404;
}

.course-content {
    padding: 1rem;
}

.course-title {
    font-size: 1.1rem;
    font-weight: 600;
    color: #2c3e50;
    margin: 0 0 0.5rem 0;
}

.course-summary {
    color: #6c757d;
    font-size: 0.9rem;
    margin: 0 0 1rem 0;
    line-height: 1.4;
}

.course-meta {
    margin-bottom: 1rem;
}

.meta-item {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 0.25rem;
    font-size: 0.8rem;
    color: #6c757d;
}

.course-stats {
    display: flex;
    gap: 1rem;
    margin-bottom: 1rem;
}

.stat-item {
    display: flex;
    align-items: center;
    gap: 0.25rem;
    font-size: 0.8rem;
    color: #495057;
}

.course-actions {
    padding: 1rem;
    background: #f8f9fa;
    border-top: 1px solid #dee2e6;
    display: flex;
    gap: 0.5rem;
}

.btn {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    padding: 0.5rem 1rem;
    border: none;
    border-radius: 4px;
    font-size: 0.8rem;
    font-weight: 500;
    text-decoration: none;
    cursor: pointer;
    transition: all 0.2s;
}

.btn-sm {
    padding: 0.375rem 0.75rem;
    font-size: 0.75rem;
}

.btn-primary {
    background: #007bff;
    color: white;
}

.btn-primary:hover {
    background: #0056b3;
    color: white;
}

.btn-secondary {
    background: #6c757d;
    color: white;
}

.btn-secondary:hover {
    background: #5a6268;
    color: white;
}

.btn-success {
    background: #28a745;
    color: white;
}

.btn-success:hover {
    background: #1e7e34;
    color: white;
}

.btn-outline {
    background: transparent;
    color: #007bff;
    border: 1px solid #007bff;
}

.btn-outline:hover {
    background: #007bff;
    color: white;
}

.no-courses {
    grid-column: 1 / -1;
    text-align: center;
    padding: 3rem;
}

.no-courses-icon {
    font-size: 4rem;
    color: #dee2e6;
    margin-bottom: 1rem;
}

.no-courses h3 {
    color: #6c757d;
    margin-bottom: 0.5rem;
}

.no-courses p {
    color: #6c757d;
    margin-bottom: 1.5rem;
}

@media (max-width: 768px) {
    .courses-grid {
        grid-template-columns: 1fr;
    }
    
    .courses-header {
        flex-direction: column;
        align-items: stretch;
    }
    
    .search-filter {
        flex-direction: column;
    }
    
    .search-input, .filter-select {
        width: 100%;
    }
}
</style>

<script>
// Course search and filter functionality
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('course-search');
    const filterSelect = document.getElementById('course-filter');
    const coursesGrid = document.getElementById('courses-grid');
    const courseCards = document.querySelectorAll('.course-card');
    
    function filterCourses() {
        const searchTerm = searchInput.value.toLowerCase();
        const filterValue = filterSelect.value;
        
        courseCards.forEach(card => {
            const courseTitle = card.querySelector('.course-title').textContent.toLowerCase();
            const courseSummary = card.querySelector('.course-summary').textContent.toLowerCase();
            const assignedStatus = card.getAttribute('data-assigned');
            
            const matchesSearch = courseTitle.includes(searchTerm) || courseSummary.includes(searchTerm);
            const matchesFilter = !filterValue || assignedStatus === filterValue;
            
            if (matchesSearch && matchesFilter) {
                card.style.display = 'block';
            } else {
                card.style.display = 'none';
            }
        });
    }
    
    searchInput.addEventListener('input', filterCourses);
    filterSelect.addEventListener('change', filterCourses);
});

function assignCourse(courseId) {
    if (confirm('Are you sure you want to assign this course to your school?')) {
        // Here you would implement the course assignment logic
        alert('Course assignment functionality will be implemented here.');
    }
}

function viewCourseDetails(courseId) {
    // Here you would implement the course details modal
    alert('Course details for ID: ' + courseId);
}
</script>

<?php
echo $OUTPUT->footer();
?>

