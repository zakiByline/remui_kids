<?php
/**
 * School Hierarchy Page - Display schools with their students, cohorts, and courses
 */

require_once('../../../config.php');
global $DB, $CFG, $OUTPUT, $PAGE, $USER;

// Set up the page
$PAGE->set_url('/theme/remui_kids/admin/school_hierarchy.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_title('School Hierarchy');
$PAGE->set_heading('School Hierarchy');
// Don't set pagelayout - use default

// Check if user has admin capabilities
require_login();
require_capability('moodle/site:config', context_system::instance());

echo $OUTPUT->header();

// Add custom CSS
echo "<style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
    
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }
    
    body {
        font-family: 'Inter', sans-serif;
        background: #ffffff;
        min-height: 100vh;
        overflow-x: hidden;
    }
    
    /* Override Moodle's default layout */
    #page {
        margin: 0 !important;
        padding: 0 !important;
    }
    
    #page-wrapper {
        margin: 0 !important;
        padding: 0 !important;
    }
    
    /* IMPORTANT: Keep region-main-box visible, just style it */
    #region-main-box {
        display: block !important;
        margin: 0 !important;
        padding: 0 !important;
    }
    
    #region-main {
        display: block !important;
        margin: 0 !important;
        padding: 0 !important;
    }
    
    #page-content {
        padding: 0 !important;
        margin: 0 !important;
    }
    
    .container-fluid {
        padding: 0 !important;
        margin: 0 !important;
    }
    
    /* Make sure our content is visible */
    .admin-main-content, .hierarchy-container {
        display: block !important;
        visibility: visible !important;
        opacity: 1 !important;
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
        background: #ffffff;
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
            background: #ffffff;
        }
    }
    
    .hierarchy-container {
        max-width: 100%;
        margin: 0;
        padding: 2rem;
    }
    
    .page-header {
        background: rgba(255, 255, 255, 0.95);
        border-radius: 20px;
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
        backdrop-filter: blur(10px);
        overflow: hidden;
        margin-bottom: 30px;
        position: relative;
        padding: 2rem;
    }
    
    .page-title {
        font-size: 2rem;
        font-weight: 800;
        color: #0369a1;
        margin-bottom: 8px;
    }
    
    .page-subtitle {
        font-size: 1.3rem;
        color: #0369a1;
        margin: 0;
        font-weight: 500;
        opacity: 0.9;
    }
    
    /* Search and Filters */
    .filters-section {
        background: rgba(255, 255, 255, 0.95);
        border-radius: 20px;
        padding: 30px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        backdrop-filter: blur(10px);
        margin-bottom: 30px;
        display: flex;
        gap: 1rem;
        align-items: center;
        flex-wrap: wrap;
    }
    
    .filter-input, .filter-select {
        padding: 12px 16px;
        border: 2px solid #e2e8f0;
        border-radius: 12px;
        font-size: 1rem;
        transition: all 0.3s ease;
        background: white;
        flex: 1;
        min-width: 200px;
    }
    
    .filter-input:focus, .filter-select:focus {
        outline: none;
        border-color: #0369a1;
        box-shadow: 0 0 0 3px rgba(3, 105, 161, 0.1);
    }
    
    /* Expand Icon Animation */
    .expand-icon {
        transition: transform 0.3s;
    }
    
    .expanded .expand-icon {
        transform: rotate(180deg);
    }
    
    
    
    /* Data Tables */
    .data-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 1rem;
    }
    
    .data-table thead {
        background: #f8fafc;
    }
    
    .data-table th {
        padding: 1rem;
        text-align: left;
        font-weight: 600;
        color: #475569;
        font-size: 0.875rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        border-bottom: 2px solid #e2e8f0;
    }
    
    .data-table td {
        padding: 1rem;
        border-bottom: 1px solid #f1f5f9;
        color: #334155;
    }
    
    .students-pagination {
        display: none;
        flex-direction: column;
        gap: 10px;
        margin-top: 16px;
        padding: 12px 0;
        align-items: center;
        text-align: center;
    }
    
    .students-pagination.active {
        display: flex;
    }
    
    .students-pagination-info {
        font-size: 0.9rem;
        color: #475569;
        font-weight: 500;
    }
    
    .students-pagination-buttons {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        align-items: center;
        justify-content: center;
    }
    
    .students-pagination-buttons .pagination-button {
        padding: 8px 14px;
        border-radius: 10px;
        border: 1px solid #e2e8f0;
        background: #fff;
        color: #0369a1;
        font-weight: 600;
        text-decoration: none;
        transition: all 0.2s ease;
        min-width: 38px;
        text-align: center;
        cursor: pointer;
    }
    
    .students-pagination-buttons .pagination-button:hover:not(.disabled):not(.active) {
        background: #e0f2fe;
        border-color: #bae6fd;
    }
    
    .students-pagination-buttons .pagination-button.active {
        background: #0369a1;
        color: #fff;
        border-color: #0369a1;
        box-shadow: 0 6px 14px rgba(3, 105, 161, 0.25);
    }
    
    .students-pagination-buttons .pagination-button.disabled {
        color: #94a3b8;
        background: #f8fafc;
        pointer-events: none;
    }
    
    .data-table tbody tr:hover {
        background: #f8fafc;
    }
    
    .user-info {
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }
    
    .user-avatar {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: linear-gradient(135deg, #0ea5e9 0%, #0369a1 100%);
        color: white;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 1rem;
    }
    
    .user-name {
        font-weight: 600;
        color: #1e293b;
    }
    
    .user-email {
        font-size: 0.875rem;
        color: #64748b;
    }
    
    .status-badge {
        padding: 0.4rem 0.8rem;
        border-radius: 20px;
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
    }
    
    .status-active {
        background: #d1fae5;
        color: #065f46;
    }
    
    .status-suspended {
        background: #fee2e2;
        color: #991b1b;
    }
    
    .cohort-badge {
        display: inline-block;
        padding: 0.4rem 0.8rem;
        background: #dbeafe;
        color: #1e40af;
        border-radius: 6px;
        font-size: 0.875rem;
        margin: 0.25rem;
    }
    
    .course-badge {
        display: inline-block;
        padding: 0.4rem 0.8rem;
        background: #fef3c7;
        color: #92400e;
        border-radius: 6px;
        font-size: 0.875rem;
        margin: 0.25rem;
    }
    
    .view-courses-btn {
        background: #fef3c7;
        color: #92400e;
        border: 1px solid #fbbf24;
        border-radius: 20px;
        padding: 0.5rem 1rem;
        font-size: 0.85rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s ease;
    }
    
    .view-courses-btn:hover {
        background: #fde68a;
        transform: translateY(-1px);
    }
    
    .courses-modal {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, 0.5);
        z-index: 100000;
        justify-content: center;
        align-items: center;
        padding: 20px;
    }
    
    .courses-modal.active {
        display: flex;
    }
    
    .courses-modal-content {
        background: #ffffff;
        border-radius: 18px;
        max-width: 520px;
        width: 100%;
        padding: 24px 28px;
        box-shadow: 0 25px 60px rgba(15, 23, 42, 0.25);
    }
    
    .courses-modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 16px;
    }
    
    .courses-modal-title {
        font-size: 1.2rem;
        font-weight: 700;
        color: #0f172a;
        margin: 0;
    }
    
    .courses-modal-close {
        background: none;
        border: none;
        font-size: 1.2rem;
        cursor: pointer;
        color: #94a3b8;
    }
    
    .courses-modal-list {
        display: flex;
        flex-direction: column;
        gap: 12px;
        max-height: 60vh;
        overflow-y: auto;
    }
    
    .courses-modal-card {
        border: 1px solid #fbbf24;
        background: #fef9c3;
        border-radius: 10px;
        padding: 12px 14px;
        text-decoration: none;
        display: block;
    }
    
    .courses-modal-card h4 {
        margin: 0;
        font-size: 0.95rem;
        color: #92400e;
    }
    
    .courses-modal-card span {
        font-size: 0.8rem;
        color: #a16207;
    }
    
    .empty-state {
        text-align: center;
        padding: 3rem;
        color: #94a3b8;
    }
    
    .empty-state i {
        font-size: 3rem;
        margin-bottom: 1rem;
        opacity: 0.5;
    }
    
    /* Summary Stats */
    .summary-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 30px;
        margin-bottom: 40px;
    }
    
    .summary-card {
        background: rgba(255, 255, 255, 0.95);
        border-radius: 16px;
        padding: 20px 18px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        backdrop-filter: blur(10px);
        text-align: center;
        position: relative;
        overflow: hidden;
        transition: transform 0.2s ease;
    }
    
    .summary-card:hover {
        transform: translateY(-5px);
    }
    
    .summary-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: linear-gradient(135deg, #e0f2fe 0%, #bae6fd 100%);
    }
    
    .summary-icon {
        width: 48px;
        height: 48px;
        border-radius: 50%;
        background: #e0f2fe;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #0369a1;
        font-size: 1.2rem;
        margin: 0 auto 12px;
    }
    
    .summary-number {
        font-size: 2rem;
        font-weight: 800;
        color: #2d3748;
        margin-bottom: 4px;
    }
    
    .summary-label {
        font-size: 1rem;
        color: #6b7280;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    /* Pastel color variations for different stat types */
    .summary-card:nth-child(1) .summary-icon {
        background: #e0f2fe;
        color: #0369a1;
    }
    
    .summary-card:nth-child(2) .summary-icon {
        background: #dcfce7;
        color: #166534;
    }
    
    .summary-card:nth-child(3) .summary-icon {
        background: #f3e8ff;
        color: #7c3aed;
    }
    
    .summary-card:nth-child(4) .summary-icon {
        background: #fed7aa;
        color: #ea580c;
    }
    
    /* Floating elements */
    .floating-elements {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        pointer-events: none;
        z-index: -1;
    }
    
    .floating-circle {
        position: absolute;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.1);
        animation: float 6s ease-in-out infinite;
    }
    
    .floating-circle:nth-child(1) {
        width: 100px;
        height: 100px;
        top: 10%;
        left: 10%;
        animation-delay: 0s;
    }
    
    .floating-circle:nth-child(2) {
        width: 80px;
        height: 80px;
        top: 60%;
        right: 10%;
        animation-delay: 2s;
    }
    
    .floating-circle:nth-child(3) {
        width: 60px;
        height: 60px;
        bottom: 20%;
        left: 20%;
        animation-delay: 4s;
    }
    
    .floating-circle:nth-child(4) {
        width: 120px;
        height: 120px;
        top: 30%;
        right: 30%;
        animation-delay: 1s;
    }
    
    @keyframes float {
        0%, 100% { transform: translateY(0px) rotate(0deg); }
        50% { transform: translateY(-20px) rotate(180deg); }
    }
</style>";

// Floating background elements
echo "<div class='floating-elements'>";
echo "<div class='floating-circle'></div>";
echo "<div class='floating-circle'></div>";
echo "<div class='floating-circle'></div>";
echo "<div class='floating-circle'></div>";
echo "</div>";

// Include admin sidebar from includes
require_once(__DIR__ . '/includes/admin_sidebar.php');

// Floating background elements
echo "<div class='floating-elements'>";
echo "<div class='floating-circle'></div>";
echo "<div class='floating-circle'></div>";
echo "<div class='floating-circle'></div>";
echo "<div class='floating-circle'></div>";
echo "</div>";

// Main content area
echo "<div class='admin-main-content'>";
echo "<div class='hierarchy-container'>";

try {
    error_log("School Hierarchy: Starting to load schools");
    
    // Get all schools with their hierarchical data
    $schools = [];
    $allCohorts = []; // For filter dropdown
    
    if ($DB->get_manager()->table_exists('company')) {
        $schoolRecords = $DB->get_records_sql(
            "SELECT c.id, c.name, c.shortname
             FROM {company} c
             ORDER BY c.name"
        );
        
        foreach ($schoolRecords as $school) {
            $schoolData = [
                'id' => $school->id,
                'name' => $school->name,
                'shortname' => $school->shortname,
                'students' => []
            ];
            
            // Get all students in this school
            $students = $DB->get_records_sql(
                "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email, u.username, u.suspended, u.lastaccess
                 FROM {user} u
                 JOIN {company_users} cu ON cu.userid = u.id
                 JOIN {role_assignments} ra ON ra.userid = u.id
                 JOIN {role} r ON r.id = ra.roleid
                 WHERE cu.companyid = :companyid
                 AND r.shortname = 'student'
                 AND u.deleted = 0
                 ORDER BY u.firstname, u.lastname",
                ['companyid' => $school->id]
            );
            
            foreach ($students as $student) {
                $studentData = [
                    'id' => $student->id,
                    'firstname' => $student->firstname,
                    'lastname' => $student->lastname,
                    'email' => $student->email,
                    'username' => $student->username,
                    'suspended' => $student->suspended,
                    'lastaccess' => $student->lastaccess,
                    'cohorts' => []
                ];
                
                // Get all cohorts this student belongs to
                $studentCohorts = $DB->get_records_sql(
                    "SELECT DISTINCT co.id, co.name, co.idnumber
                     FROM {cohort} co
                     JOIN {cohort_members} cm ON cm.cohortid = co.id
                     WHERE cm.userid = :userid
                     ORDER BY co.name",
                    ['userid' => $student->id]
                );
                
                foreach ($studentCohorts as $cohort) {
                    // Track all grades for filter dropdown (use integer key)
                    $cohortIdInt = (int)$cohort->id;
                    if (!isset($allCohorts[$cohortIdInt])) {
                        $allCohorts[$cohortIdInt] = $cohort->name;
                    }
                    
                    $cohortData = [
                        'id' => $cohortIdInt,  // Store as integer
                        'name' => $cohort->name,
                        'idnumber' => $cohort->idnumber
                    ];
                    
                    $studentData['cohorts'][] = $cohortData;
                }
                
                // Get ALL courses the student is actually enrolled in (not just through cohorts)
                $studentCourses = $DB->get_records_sql(
                    "SELECT DISTINCT c.id, c.fullname, c.shortname
                     FROM {course} c
                     JOIN {enrol} e ON e.courseid = c.id
                     JOIN {user_enrolments} ue ON ue.enrolid = e.id
                     WHERE ue.userid = :userid
                     AND ue.status = 0
                     AND c.id > 1
                     ORDER BY c.fullname",
                    ['userid' => $student->id]
                );
                
                $studentData['courses'] = [];
                foreach ($studentCourses as $course) {
                    $studentData['courses'][] = [
                        'id' => (int)$course->id,
                        'fullname' => $course->fullname,
                        'shortname' => $course->shortname
                    ];
                }
                
                $schoolData['students'][] = $studentData;
            }
            
            $schools[] = $schoolData;
        }
    }
    
    // Calculate summary statistics
    $totalSchools = count($schools);
    $totalStudents = 0;
    $totalCohorts = count($allCohorts);
    $totalCourses = 0;
    $uniqueCourses = [];
    
    foreach ($schools as $school) {
        $totalStudents += count($school['students']);
        foreach ($school['students'] as $student) {
            foreach ($student['courses'] as $course) {
                $uniqueCourses[$course['id']] = true;
            }
        }
    }
    $totalCourses = count($uniqueCourses);
    
    // Page Header
    echo "<div class='page-header'>";
    echo "<h1 class='page-title'>School Hierarchy</h1>";
    echo "<p class='page-subtitle'>View organizational structure with schools, students, grades, and courses</p>";
    echo "</div>";
    
    // Summary Statistics
    echo "<div class='summary-stats'>";
    
    echo "<div class='summary-card'>";
    echo "<div class='summary-icon'><i class='fa fa-school'></i></div>";
    echo "<div class='summary-number'>$totalSchools</div>";
    echo "<div class='summary-label'>Total Schools</div>";
    echo "</div>";
    
    echo "<div class='summary-card'>";
    echo "<div class='summary-icon'><i class='fa fa-user-graduate'></i></div>";
    echo "<div class='summary-number'>$totalStudents</div>";
    echo "<div class='summary-label'>Total Students</div>";
    echo "</div>";
    
    echo "<div class='summary-card'>";
    echo "<div class='summary-icon'><i class='fa fa-users'></i></div>";
    echo "<div class='summary-number'>$totalCohorts</div>";
    echo "<div class='summary-label'>Total Grades</div>";
    echo "</div>";
    
    echo "<div class='summary-card'>";
    echo "<div class='summary-icon'><i class='fa fa-book'></i></div>";
    echo "<div class='summary-number'>$totalCourses</div>";
    echo "<div class='summary-label'>Total Courses</div>";
    echo "</div>";
    
    echo "</div>";
    
    // School Selection Dropdown (Primary Filter)
    echo "<div class='school-selector-section' style='background: #ffffff; border-radius: 15px; padding: 2rem; margin-bottom: 2rem; box-shadow: 0 12px 30px rgba(15, 23, 42, 0.08); border: 1px solid #e2e8f0;'>";
    echo "<div style='display: flex; gap: 1.5rem; align-items: center; flex-wrap: wrap;'>";
    
    echo "<div style='flex: 1; min-width: 300px;'>";
    echo "<label style='display: block; font-weight: 600; color: #0f172a; margin-bottom: 0.5rem; font-size: 1.05rem;'>";
    echo "<i class='fa fa-school' style='color:#2563eb;'></i> Select a School";
    echo "</label>";
    echo "<select class='filter-select' id='filter-school' style='width: 100%; padding: 1rem; border: 1px solid #cbd5e1; border-radius: 10px; font-size: 1rem; background: #f8fafc; color:#0f172a;'>";
    echo "<option value=''>-- Choose a School --</option>";
    foreach ($schools as $school) {
        $studentCount = count($school['students']);
        echo "<option value='{$school['id']}'>" . format_string($school['name']) . " ({$studentCount} students)</option>";
    }
    echo "</select>";
    echo "</div>";
    
    echo "<div style='flex: 1; min-width: 250px;'>";
    echo "<label style='display: block; font-weight: 600; color: #0f172a; margin-bottom: 0.5rem; font-size: 1.05rem;'>";
    echo "<i class='fa fa-users' style='color:#8b5cf6;'></i> Filter by Grade (Optional)";
    echo "</label>";
    echo "<select class='filter-select' id='filter-cohort' style='width: 100%; padding: 1rem; border: 1px solid #cbd5e1; border-radius: 10px; font-size: 1rem; background: #f8fafc; color:#0f172a;'>";
    echo "<option value='all'>All Grades</option>";
    foreach ($allCohorts as $cohortId => $cohortName) {
        echo "<option value='{$cohortId}'>" . format_string($cohortName) . "</option>";
    }
    echo "</select>";
    echo "</div>";
    
    echo "<div style='flex: 1; min-width: 250px;'>";
    echo "<label style='display: block; font-weight: 600; color: #0f172a; margin-bottom: 0.5rem; font-size: 1.05rem;'>";
    echo "<i class='fa fa-search' style='color:#16a34a;'></i> Search";
    echo "</label>";
    echo "<input type='text' class='filter-input' id='search-hierarchy' placeholder='Search students, grades, or courses...' style='width: 100%; padding: 1rem; border: 1px solid #cbd5e1; border-radius: 10px; font-size: 1rem; background:#f8fafc; color:#0f172a;'>";
    echo "</div>";
    
    echo "</div>";
    echo "</div>";
    
    // Empty state message (shown when no school selected)
    echo "<div id='no-school-selected' style='background: white; padding: 4rem; border-radius: 15px; text-align: center; box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);'>";
    echo "<i class='fa fa-school' style='font-size: 4rem; color: #bdbdbd; margin-bottom: 1rem;'></i>";
    echo "<h3 style='color: #424242; font-size: 1.5rem; margin-bottom: 0.5rem;'>Please Select a School</h3>";
    echo "<p style='color: #757575; font-size: 1rem;'>Choose a school from the dropdown above to view its students, grades, and courses.</p>";
    echo "</div>";
    
    // Students Container (hidden initially, shown when school is selected)
    echo "<div id='students-container' style='display: none;'>";
    
    // School Data Cards (one per school, hidden by default)
    if (!empty($schools)) {
        foreach ($schools as $school) {
            $studentCount = count($school['students']);
            
            echo "<div class='school-data' data-school-id='{$school['id']}' style='display: none;'>";
            
            // School Info Header
            echo "<div style='background: linear-gradient(135deg, #e0f7ff 0%, #f3fff8 100%); color: #0f172a; padding: 1.5rem 2rem; border-radius: 18px; margin-bottom: 1.5rem; box-shadow: 0 10px 30px rgba(15, 23, 42, 0.12); border: 1px solid rgba(209, 213, 219, 0.6);'>";
            echo "<div style='display: flex; justify-content: space-between; align-items: center;'>";
            echo "<div>";
            echo "<h2 style='margin: 0; font-size: 1.8rem; font-weight: 700; color: #0f172a;'>" . format_string($school['name']) . "</h2>";
            echo "<p style='margin: 0.5rem 0 0 0; font-size: 1rem; color: #475569;'>{$studentCount} students enrolled</p>";
            echo "</div>";
            echo "</div>";
            echo "</div>";
            
            // Students Display - Table Format
            if (!empty($school['students'])) {
                echo "<div style='background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);'>";
                echo "<table class='data-table' style='width: 100%; border-collapse: collapse;'>";
                echo "<thead>";
                echo "<tr style='background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);'>";
                echo "<th style='padding: 1.2rem 1rem; text-align: left; font-weight: 700; color: #1e293b; font-size: 0.95rem; border-bottom: 2px solid #cbd5e1;'>Student</th>";
                echo "<th style='padding: 1.2rem 1rem; text-align: left; font-weight: 700; color: #1e293b; font-size: 0.95rem; border-bottom: 2px solid #cbd5e1;'>Email</th>";
                echo "<th style='padding: 1.2rem 1rem; text-align: left; font-weight: 700; color: #1e293b; font-size: 0.95rem; border-bottom: 2px solid #cbd5e1;'>Status</th>";
                echo "<th style='padding: 1.2rem 1rem; text-align: left; font-weight: 700; color: #1e293b; font-size: 0.95rem; border-bottom: 2px solid #cbd5e1;'>Grades</th>";
                echo "<th style='padding: 1.2rem 1rem; text-align: left; font-weight: 700; color: #1e293b; font-size: 0.95rem; border-bottom: 2px solid #cbd5e1;'>Courses</th>";
                echo "<th style='padding: 1.2rem 1rem; text-align: left; font-weight: 700; color: #1e293b; font-size: 0.95rem; border-bottom: 2px solid #cbd5e1;'>Last Access</th>";
                echo "</tr>";
                echo "</thead>";
                echo "<tbody>";
                
                foreach ($school['students'] as $student) {
                    $avatar = strtoupper(substr($student['firstname'], 0, 1));
                    $status_class = $student['suspended'] ? 'status-suspended' : 'status-active';
                    $status_text = $student['suspended'] ? 'Suspended' : 'Active';
                    $last_access = $student['lastaccess'] ? date('M j, Y', $student['lastaccess']) : 'Never';
                    $coursetextchunks = [];
                    if (!empty($student['courses'])) {
                        foreach ($student['courses'] as $courseinfo) {
                            if (!empty($courseinfo['fullname'])) {
                                $coursetextchunks[] = format_string($courseinfo['fullname']);
                            }
                            if (!empty($courseinfo['shortname'])) {
                                $coursetextchunks[] = format_string($courseinfo['shortname']);
                            }
                        }
                    }
                    $courses_search_attr = s(implode(' ', $coursetextchunks));
                    
                    echo "<tr class='student-row' data-student-id='{$student['id']}' data-course-text='{$courses_search_attr}' data-cohorts='" . json_encode(array_column($student['cohorts'], 'id')) . "' style='border-bottom: 1px solid #f1f5f9; transition: background 0.2s;' onmouseover='this.style.background=\"#f8fafc\"' onmouseout='this.style.background=\"white\"'>";
                    
                    // Student Column
                    echo "<td style='padding: 1rem; vertical-align: top;'>";
                    echo "<div style='display: flex; align-items: center; gap: 0.75rem;'>";
                    echo "<div class='user-avatar' style='width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, #0ea5e9 0%, #0369a1 100%); color: white; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 1rem;'>$avatar</div>";
                    echo "<div>";
                    echo "<div style='font-weight: 600; color: #1e293b; font-size: 0.95rem;'>{$student['firstname']} {$student['lastname']}</div>";
                    echo "<div style='font-size: 0.8rem; color: #64748b;'>{$student['username']}</div>";
                    echo "</div>";
                    echo "</div>";
                    echo "</td>";
                    
                    // Email Column
                    echo "<td style='padding: 1rem; vertical-align: top;'>";
                    echo "<span style='color: #475569; font-size: 0.9rem;'>{$student['email']}</span>";
                    echo "</td>";
                    
                    // Status Column
                    echo "<td style='padding: 1rem; vertical-align: top;'>";
                    echo "<span class='status-badge $status_class'>$status_text</span>";
                    echo "</td>";
                    
                    // Grades Column
                    echo "<td style='padding: 1rem; vertical-align: top;'>";
                    if (!empty($student['cohorts'])) {
                        foreach ($student['cohorts'] as $cohort) {
                            echo "<div class='cohort-item' data-cohort-id='{$cohort['id']}' style='background: #dbeafe; border-left: 3px solid #0ea5e9; padding: 0.5rem 0.75rem; margin-bottom: 0.5rem; border-radius: 4px;'>";
                            echo "<div style='font-weight: 600; color: #1e40af; font-size: 0.9rem;'>" . format_string($cohort['name']) . "</div>";
                            echo "<div style='font-size: 0.75rem; color: #64748b; margin-top: 2px;'>ID: " . s($cohort['idnumber']) . "</div>";
                            echo "</div>";
                        }
                    } else {
                        echo "<span style='color: #94a3b8; font-style: italic; font-size: 0.85rem;'>No grades</span>";
                    }
                    echo "</td>";
                    
                    // Courses Column - Display student's enrolled courses
                echo "<td style='padding: 1rem; vertical-align: top;'>";
                if (!empty($student['courses'])) {
                    $coursesjson = htmlspecialchars(json_encode($student['courses']), ENT_QUOTES);
                    $coursecount = count($student['courses']);
                    $coursetext = $coursecount . "_view course" . ($coursecount > 1 ? 's' : '');
                    echo "<button type='button' class='view-courses-btn' data-courses=\"{$coursesjson}\">{$coursetext}</button>";
                } else {
                    echo "<span style='color: #94a3b8; font-style: italic; font-size: 0.85rem;'>No courses</span>";
                }
                echo "</td>";
                    
                    // Last Access Column
                    echo "<td style='padding: 1rem; vertical-align: top;'>";
                    echo "<span style='color: #64748b; font-size: 0.85rem;'>$last_access</span>";
                    echo "</td>";
                    
                    echo "</tr>";
                }
                
                echo "</tbody>";
                echo "</table>";
                echo "</div>";
                echo "<div class='students-pagination' data-school-id='{$school['id']}'>";
                echo "<div class='students-pagination-info'>Showing 0 students</div>";
                echo "<div class='students-pagination-buttons'></div>";
                echo "</div>";
            } else {
                echo "<div class='empty-state'>";
                echo "<i class='fa fa-user-graduate'></i>";
                echo "<p>No students enrolled in this school</p>";
                echo "</div>";
            }
            
            echo "</div>"; // End school-data
        }
        
    echo "</div>"; // End students-container
        
    } else {
        echo "<div class='empty-state' style='background: white; padding: 4rem; border-radius: 12px;'>";
        echo "<i class='fa fa-school'></i>";
        echo "<h3>No Schools Found</h3>";
        echo "<p>The company/school management system is not enabled or no schools exist.</p>";
        echo "</div>";
    }
    
} catch (Exception $e) {
    echo "<div class='alert alert-danger' style='background: #fee2e2; color: #991b1b; padding: 1.5rem; border-radius: 8px;'>";
    echo "<h4>❌ Error</h4>";
    echo "<p>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "</div>";
}

echo "</div>"; // End hierarchy-container
echo "</div>"; // End admin-main-content

// Courses modal
echo "<div id='coursesModal' class='courses-modal'>";
echo "<div class='courses-modal-content'>";
echo "<div class='courses-modal-header'>";
echo "<h3 class='courses-modal-title'>Courses</h3>";
echo "<button type='button' class='courses-modal-close' aria-label='Close'>&times;</button>";
echo "</div>";
echo "<div class='courses-modal-list' id='coursesModalList'></div>";
echo "</div>";
echo "</div>";

// JavaScript for interactivity
echo "<script>
const studentsPaginationState = {};
const studentsPerPage = 10;

function resetCohortItemStyles(item) {
    if (!item) {
        return;
    }
    item.style.display = 'block';
    item.style.opacity = '1';
    item.style.border = 'none';
    item.style.borderLeft = '3px solid #0ea5e9';
    item.style.background = '#dbeafe';
}

function renderStudentsPaginationControls(schoolId, currentPage, totalPages, total, start, end) {
    const container = document.querySelector('.students-pagination[data-school-id=\"' + schoolId + '\"]');
    if (!container) {
        return;
    }
    const info = container.querySelector('.students-pagination-info');
    const buttonsWrap = container.querySelector('.students-pagination-buttons');
    container.classList.add('active');
    if (!total) {
        if (info) {
            info.textContent = 'No students match the current filters.';
        }
        if (buttonsWrap) {
            buttonsWrap.innerHTML = '';
        }
        return;
    }
    if (info) {
        info.textContent = 'Showing ' + start + '-' + end + ' of ' + total + ' students';
    }
    if (!buttonsWrap) {
        return;
    }
    if (totalPages <= 1) {
        buttonsWrap.innerHTML = '';
        return;
    }
    let html = '';
    const prevDisabled = currentPage <= 1 ? 'disabled' : '';
    html += '<button type=\"button\" class=\"pagination-button ' + prevDisabled + '\" data-page=\"' + (currentPage - 1) + '\">Prev</button>';
    const pagesToShow = new Map();
    const initialBlock = Math.min(5, totalPages);
    for (let i = 1; i <= initialBlock; i++) {
        pagesToShow.set(i, true);
    }
    if (totalPages > initialBlock && currentPage > initialBlock) {
        const windowEnd = Math.min(currentPage + 3, totalPages);
        for (let i = currentPage; i <= windowEnd; i++) {
            pagesToShow.set(i, true);
        }
    }
    pagesToShow.set(totalPages, true);
    const sortedPages = Array.from(pagesToShow.keys()).sort((a, b) => a - b);
    let previousPage = null;
    sortedPages.forEach(page => {
        if (previousPage !== null && page - previousPage > 1) {
            html += '<span class=\"pagination-button disabled\">...</span>';
        }
        const activeClass = page === currentPage ? 'active' : '';
        html += '<button type=\"button\" class=\"pagination-button ' + activeClass + '\" data-page=\"' + page + '\">' + page + '</button>';
        previousPage = page;
    });
    const nextDisabled = currentPage >= totalPages ? 'disabled' : '';
    html += '<button type=\"button\" class=\"pagination-button ' + nextDisabled + '\" data-page=\"' + (currentPage + 1) + '\">Next</button>';
    buttonsWrap.innerHTML = html;
}

function updateSchoolPagination(schoolId, targetPage = 1) {
    const schoolData = document.querySelector('.school-data[data-school-id=\"' + schoolId + '\"]');
    if (!schoolData) {
        return;
    }
    const rows = Array.from(schoolData.querySelectorAll('.student-row'));
    if (!rows.length) {
        const container = document.querySelector('.students-pagination[data-school-id=\"' + schoolId + '\"]');
        if (container) {
            container.classList.remove('active');
        }
        return;
    }
    const visibleRows = rows.filter(row => row.dataset.visible !== 'false');
    const totalVisible = visibleRows.length;
    const totalPages = Math.max(1, Math.ceil(Math.max(totalVisible, 1) / studentsPerPage));
    targetPage = Math.max(1, Math.min(targetPage, totalPages));
    rows.forEach(row => {
        row.style.display = 'none';
    });
    const startIndex = totalVisible ? (targetPage - 1) * studentsPerPage : 0;
    const endIndex = totalVisible ? Math.min(startIndex + studentsPerPage, totalVisible) : 0;
    visibleRows.forEach((row, index) => {
        if (index >= startIndex && index < endIndex) {
            row.style.display = '';
        }
    });
    studentsPaginationState[schoolId] = {
        currentPage: targetPage,
        totalPages,
        total: totalVisible
    };
    const startDisplay = totalVisible ? startIndex + 1 : 0;
    const endDisplay = totalVisible ? endIndex : 0;
    renderStudentsPaginationControls(schoolId, targetPage, totalPages, totalVisible, startDisplay, endDisplay);
}

function initializeSchoolRows(schoolId) {
    const rows = document.querySelectorAll('.school-data[data-school-id=\"' + schoolId + '\"] .student-row');
    rows.forEach(row => {
        row.dataset.visible = 'true';
        row.style.display = '';
        const cohortItems = row.querySelectorAll('.cohort-item');
        cohortItems.forEach(item => resetCohortItemStyles(item));
    });
    updateSchoolPagination(schoolId, 1);
}

// Main functionality
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('search-hierarchy');
    const filterSchool = document.getElementById('filter-school');
    const filterCohort = document.getElementById('filter-cohort');
    const noSchoolMessage = document.getElementById('no-school-selected');
    const studentsContainer = document.getElementById('students-container');
    const allSchoolData = document.querySelectorAll('.school-data');
    const coursesModal = document.getElementById('coursesModal');
    const coursesModalList = document.getElementById('coursesModalList');
    const coursesModalClose = document.querySelector('.courses-modal-close');
    
    // Debug: Show available grades in dropdown
    console.log('=== Available Grades in Dropdown ===');
    const cohortOptions = filterCohort.querySelectorAll('option');
    cohortOptions.forEach(option => {
        if (option.value !== 'all') {
            console.log('Grade:', option.text, 'Value:', option.value, 'Type:', typeof option.value);
        }
    });
    console.log('====================================');
    
    // Handle school selection
    filterSchool.addEventListener('change', function() {
        const selectedSchool = this.value;
        
        console.log('School changed to:', selectedSchool);
        
        if (!selectedSchool) {
            // No school selected - show message
            noSchoolMessage.style.display = 'block';
            studentsContainer.style.display = 'none';
        } else {
            // School selected - show students
            noSchoolMessage.style.display = 'none';
            studentsContainer.style.display = 'block';
            
            // Hide all school data, show only selected
            allSchoolData.forEach(schoolData => {
                if (schoolData.getAttribute('data-school-id') === selectedSchool) {
                    schoolData.style.display = 'block';
                } else {
                    schoolData.style.display = 'none';
                }
            });
            
            // Reset grade filter when school changes
            filterCohort.value = 'all';
            initializeSchoolRows(selectedSchool);
            // Apply search and grade filters
            applyFilters(1);
        }
    });
    
    // Apply grade and search filters
    function applyFilters(targetPage = 1) {
        const selectedSchool = filterSchool.value;
        if (!selectedSchool) return;
        
        const searchTerm = searchInput.value.toLowerCase();
        const selectedCohort = filterCohort.value;
        
        console.log('Applying filters - School:', selectedSchool, 'Grade:', selectedCohort, 'Search:', searchTerm);
        
        const currentSchoolData = document.querySelector('.school-data[data-school-id=\"' + selectedSchool + '\"]');
        if (!currentSchoolData) return;
        
        const studentRows = currentSchoolData.querySelectorAll('.student-row');
        let visibleCount = 0;
        
        studentRows.forEach((studentRow, index) => {
            const studentText = studentRow.textContent.toLowerCase();
            const courseText = (studentRow.getAttribute('data-course-text') || '').toLowerCase();
            const cohortAttr = studentRow.getAttribute('data-cohorts') || '[]';
            const studentCohorts = JSON.parse(cohortAttr);
            
            // Debug first student only
            if (index === 0) {
                console.log('=== First Student Debug ===');
                console.log('Raw data-cohorts attribute:', cohortAttr);
                console.log('Parsed student grades:', studentCohorts);
                console.log('studentCohorts type:', typeof studentCohorts);
                console.log('selectedGrade:', selectedCohort, 'type:', typeof selectedCohort);
                console.log('selectedGrade as int:', parseInt(selectedCohort));
            }
            
            let matchesSearch = searchTerm === '' || studentText.includes(searchTerm) || courseText.includes(searchTerm);
            
            // Check grade match with multiple comparison methods for safety
            let matchesCohort = selectedCohort === 'all';
            if (!matchesCohort && selectedCohort) {
                const selectedCohortInt = parseInt(selectedCohort);
                const selectedCohortStr = String(selectedCohort);
                // Check if student has this grade (as integer or string)
                matchesCohort = studentCohorts.some(c => c === selectedCohortInt || String(c) === selectedCohortStr);
            }
            
            if (index === 0) {
                console.log('matchesGrade:', matchesCohort);
                console.log('========================');
            }
            
            if (matchesSearch && matchesCohort) {
                studentRow.dataset.visible = 'true';
                visibleCount++;
                
                // If filtering by grade, highlight only that grade
                if (selectedCohort !== 'all') {
                    const cohortItems = studentRow.querySelectorAll('.cohort-item');
                    let foundMatchingCohort = false;
                    cohortItems.forEach(cohortItem => {
                        const cohortId = cohortItem.getAttribute('data-cohort-id');
                        // Convert both to strings for comparison
                        if (String(cohortId) === String(selectedCohort)) {
                            cohortItem.style.display = 'block';
                            cohortItem.style.opacity = '1';
                            cohortItem.style.border = '3px solid #0ea5e9';
                            cohortItem.style.borderLeft = '3px solid #0ea5e9';
                            cohortItem.style.background = '#bfdbfe';
                            foundMatchingCohort = true;
                        } else {
                            cohortItem.style.display = 'block';
                            cohortItem.style.opacity = '0.3';
                            cohortItem.style.border = 'none';
                            cohortItem.style.borderLeft = '1px solid #cbd5e1';
                        }
                    });
                } else {
                    // Show all grades normally
                    const cohortItems = studentRow.querySelectorAll('.cohort-item');
                    cohortItems.forEach(resetCohortItemStyles);
                }
            } else {
                studentRow.dataset.visible = 'false';
                studentRow.style.display = 'none';
            }
        });
        
        updateSchoolPagination(selectedSchool, targetPage);
        
        console.log('Visible students:', visibleCount, 'out of', studentRows.length);
    }
    
    searchInput.addEventListener('input', function() { applyFilters(1); });
    filterCohort.addEventListener('change', function() { applyFilters(1); });
    
    document.addEventListener('click', function(event) {
        const btn = event.target.closest('.view-courses-btn');
        if (btn && coursesModal && coursesModalList) {
            const courses = JSON.parse(btn.getAttribute('data-courses') || '[]');
            coursesModalList.innerHTML = courses.map(course => {
                const name = course.fullname ? course.fullname : 'Untitled Course';
                const shortname = course.shortname ? course.shortname : '';
                const courseUrl = course.id ? '{$CFG->wwwroot}/course/view.php?id=' + course.id : '#';
                return '<a class=\"courses-modal-card\" href=\"' + courseUrl + '\"><h4>' + name + '</h4><span>' + shortname + '</span></a>';
            }).join('');
            coursesModal.classList.add('active');
        }
        
        if (coursesModal && (event.target === coursesModal || event.target === coursesModalClose)) {
            coursesModal.classList.remove('active');
        }
    });
});

document.addEventListener('click', function(event) {
    const button = event.target.closest('.students-pagination .pagination-button');
    if (!button || button.classList.contains('disabled')) {
        return;
    }
    const container = button.closest('.students-pagination');
    if (!container) {
        return;
    }
    const schoolId = container.getAttribute('data-school-id');
    const targetPage = parseInt(button.getAttribute('data-page'), 10);
    if (!schoolId || isNaN(targetPage)) {
        return;
    }
    updateSchoolPagination(schoolId, targetPage);
});
</script>";

echo $OUTPUT->footer();
?>


