<?php
/**
 * Custom Grader Report with Advanced Filtering
 * 
 * Features:
 * - School-wise filtering
 * - Grade/Class-wise filtering  
 * - Course-wise filtering
 * - Dynamic data loading
 * - Export functionality (CSV, PDF, Print)
 * - Visual charts and analytics
 */

require_once('../../../config.php');
require_once($CFG->libdir.'/gradelib.php');
require_once($CFG->libdir.'/grade/grade_category.php');
require_once($CFG->libdir.'/grade/grade_item.php');

// Set up the page
$PAGE->set_url('/theme/remui_kids/admin/custom_grader_report.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Custom Grader Report');
$PAGE->set_heading('Custom Grader Report');
$PAGE->set_pagelayout('admin');

// Check if user has admin capabilities
require_capability('moodle/grade:viewall', context_system::instance());

// Get filter parameters
$school_id = optional_param('school_id', 0, PARAM_INT);
$grade_id = optional_param('grade_id', 0, PARAM_INT);
$course_id = optional_param('course_id', 0, PARAM_INT);
$export = optional_param('export', '', PARAM_ALPHA);

// Get all schools from company table (following admin dashboard pattern)
$schools = $DB->get_records('company', array(), 'name ASC');

// Get all grades/classes from cohort table
$grades = $DB->get_records('cohort', array(), 'name ASC');

// Get all courses
$courses = $DB->get_records('course', array('visible' => 1), 'fullname ASC');

// Get filtered data based on selections
$filtered_data = array();
if ($school_id || $grade_id || $course_id) {
    $filtered_data = get_filtered_grader_data($school_id, $grade_id, $course_id);
}

// Handle export
if ($export) {
    handle_export($filtered_data, $export);
    exit;
}

// Start output
echo $OUTPUT->header();

// Add custom CSS for the custom grader report page with admin sidebar
echo "<style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
    
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }
    
    body {
        font-family: 'Inter', sans-serif;
        background: linear-gradient(135deg, #fef7f7 0%, #f0f9ff 50%, #f0fdf4 100%);
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
    
    .custom-grader-report-container {
        max-width: 1400px;
        margin: 0 auto;
        animation: slideInUp 0.8s ease-out;
    }
    
    @keyframes slideInUp {
        from {
            opacity: 0;
            transform: translateY(50px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    .page-header {
        background: rgba(255, 255, 255, 0.95);
        border-radius: 20px;
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
        backdrop-filter: blur(10px);
        overflow: hidden;
        margin-bottom: 30px;
        position: relative;
    }
    
    .header-background {
        background: linear-gradient(135deg, #e0f2fe 0%, #bae6fd 100%);
        height: 120px;
        position: relative;
        overflow: hidden;
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        text-align: center;
        padding: 20px;
    }
    
    .header-content {
        position: relative;
        z-index: 2;
        color: #0369a1;
    }
    
    .header-background::before {
        content: '';
        position: absolute;
        top: -50%;
        left: -50%;
        width: 200%;
        height: 200%;
        background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
        animation: rotate 20s linear infinite;
    }
    
    @keyframes rotate {
        from { transform: rotate(0deg); }
        to { transform: rotate(360deg); }
    }
    
    .page-content {
        padding: 40px;
        position: relative;
    }
    
    .breadcrumb {
        background: rgba(255, 255, 255, 0.1);
        padding: 15px 30px;
        border-radius: 12px;
        margin-bottom: 20px;
        backdrop-filter: blur(10px);
    }
    
    .breadcrumb a {
        color: rgba(255, 255, 255, 0.8);
        text-decoration: none;
        transition: color 0.3s ease;
    }
    
    .breadcrumb a:hover {
        color: white;
    }
    
    .breadcrumb-item {
        color: rgba(255, 255, 255, 0.9);
    }
    
    .page-title-section {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 30px;
        flex-wrap: wrap;
        gap: 20px;
    }
    
    .title-content {
        flex: 1;
        min-width: 300px;
    }
    
    .title-actions {
        display: flex;
        gap: 15px;
        align-items: center;
    }
    
    .page-title {
        font-size: 2rem;
        font-weight: 800;
        color: #0369a1;
        margin-bottom: 8px;
        animation: fadeInUp 1s ease-out 0.3s both;
    }
    
    .page-subtitle {
        font-size: 1.3rem;
        color: #0369a1;
        margin: 0;
        font-weight: 500;
        animation: fadeInUp 1s ease-out 0.4s both;
        opacity: 0.9;
    }
    
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

// Admin Sidebar Navigation
// Include admin sidebar from includes
require_once(__DIR__ . '/includes/admin_sidebar.php');

// Main content area with sidebar
echo "<div class='admin-main-content'>";

// Page Header


// Page Content
echo "<div class='page-content'>";

// Include custom CSS
echo '<link rel="stylesheet" href="' . $CFG->wwwroot . '/theme/remui_kids/style/custom-grader-report.css">';

// Include Chart.js for visualizations
echo '<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>';

?>

<!-- Custom Grader Report Container -->
<div class="custom-grader-container">
    
    <!-- Header Section -->
    <div class="grader-header">
        <h1 class="grader-title">
            <i class="fa fa-chart-line" aria-hidden="true"></i>
            Custom Grader Report
        </h1>
        <p class="grader-subtitle">Advanced filtering and analytics for grade management</p>
    </div>

    <!-- Filter Section -->
    <div class="filter-section">
        <div class="filter-row">
            <div class="filter-group">
                <label for="school_filter">Select School:</label>
                <select id="school_filter" name="school_id" class="filter-select">
                    <option value="0">All Schools</option>
                    <?php foreach ($schools as $school): ?>
                        <option value="<?php echo $school->id; ?>" <?php echo $school_id == $school->id ? 'selected' : ''; ?>>
                            <?php echo format_string($school->name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group">
                <label for="grade_filter">Select Grade/Class:</label>
                <select id="grade_filter" name="grade_id" class="filter-select">
                    <option value="0">All Grades</option>
                    <?php foreach ($grades as $grade): ?>
                        <option value="<?php echo $grade->id; ?>" <?php echo $grade_id == $grade->id ? 'selected' : ''; ?>>
                            <?php echo format_string($grade->name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group">
                <label for="course_filter">Select Course:</label>
                <select id="course_filter" name="course_id" class="filter-select">
                    <option value="0">All Courses</option>
                    <?php foreach ($courses as $course): ?>
                        <option value="<?php echo $course->id; ?>" <?php echo $course_id == $course->id ? 'selected' : ''; ?>>
                            <?php echo format_string($course->fullname); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-actions">
                <button type="button" id="search_btn" class="btn btn-primary">
                    <i class="fa fa-search" aria-hidden="true"></i> Search
                </button>
                <button type="button" id="test_ajax_btn" class="btn btn-info">
                    <i class="fa fa-flask" aria-hidden="true"></i> Test AJAX
                </button>
                <button type="button" id="load_more_btn" class="btn btn-warning">
                    <i class="fa fa-refresh" aria-hidden="true"></i> Load More Users
                </button>
                <button type="button" id="reset_btn" class="btn btn-secondary">
                    <i class="fa fa-refresh" aria-hidden="true"></i> Reset
                </button>
            </div>
        </div>
    </div>

    <!-- Analytics Section -->
    <div class="analytics-section">
        <div class="analytics-cards">
            <div class="analytics-card">
                <div class="card-icon">
                    <i class="fa fa-users" aria-hidden="true"></i>
                </div>
                <div class="card-content">
                    <h3 id="total_students">0</h3>
                    <p>Total Students</p>
                </div>
            </div>
            
            <div class="analytics-card">
                <div class="card-icon">
                    <i class="fa fa-graduation-cap" aria-hidden="true"></i>
                </div>
                <div class="card-content">
                    <h3 id="total_courses">0</h3>
                    <p>Total Courses</p>
                </div>
            </div>
            
            <div class="analytics-card">
                <div class="card-icon">
                    <i class="fa fa-chart-bar" aria-hidden="true"></i>
                </div>
                <div class="card-content">
                    <h3 id="avg_grade">0%</h3>
                    <p>Average Grade</p>
                </div>
            </div>
            
            <div class="analytics-card">
                <div class="card-icon">
                    <i class="fa fa-trophy" aria-hidden="true"></i>
                </div>
                <div class="card-content">
                    <h3 id="completion_rate">0%</h3>
                    <p>Completion Rate</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Charts Section -->
    <div class="charts-section">
        <div class="chart-container">
            <h3>Grade Distribution</h3>
            <canvas id="gradeDistributionChart"></canvas>
        </div>
        
        <div class="chart-container">
            <h3>School Performance</h3>
            <canvas id="schoolPerformanceChart"></canvas>
        </div>
    </div>

    <!-- Results Section -->
    <div class="results-section">
        <div class="results-header">
            <h3>Grade Report Results</h3>
            <div class="export-actions">
                <button type="button" id="export_csv" class="btn btn-success">
                    <i class="fa fa-file-csv" aria-hidden="true"></i> Export CSV
                </button>
                <button type="button" id="export_pdf" class="btn btn-danger">
                    <i class="fa fa-file-pdf" aria-hidden="true"></i> Export PDF
                </button>
                <button type="button" id="print_report" class="btn btn-info">
                    <i class="fa fa-print" aria-hidden="true"></i> Print
                </button>
            </div>
        </div>

        <div class="table-container">
            <table class="grader-table" id="grader_table">
                <thead>
                    <tr>
                        <th>Student Name</th>
                        <th>Email</th>
                        <th>School</th>
                        <th>Grade/Class</th>
                        <th>Course</th>
                        <th>Activities</th>
                        <th>Average Grade</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="grader_tbody">
                    <!-- Dynamic content will be loaded here -->
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Initialize the custom grader report
    initCustomGraderReport();
});

function initCustomGraderReport() {
    // Search button functionality
    document.getElementById('search_btn').addEventListener('click', function() {
        loadGraderData();
    });

    // Test AJAX button functionality
    document.getElementById('test_ajax_btn').addEventListener('click', function() {
        testAjaxConnection();
    });

    // Load More button functionality
    document.getElementById('load_more_btn').addEventListener('click', function() {
        loadMoreUsers();
    });

    // Reset button functionality
    document.getElementById('reset_btn').addEventListener('click', function() {
        resetFilters();
    });

    // Export functionality
    document.getElementById('export_csv').addEventListener('click', function() {
        exportData('csv');
    });

    document.getElementById('export_pdf').addEventListener('click', function() {
        exportData('pdf');
    });

    document.getElementById('print_report').addEventListener('click', function() {
        printReport();
    });

    // Load initial data
    loadGraderData();
    
    // Add fallback for when AJAX fails
    setTimeout(function() {
        const tbody = document.getElementById('grader_tbody');
        if (tbody.innerHTML.includes('Loading...')) {
            console.log('AJAX timeout - loading fallback data');
            loadFallbackData();
        }
    }, 5000);
}

function loadGraderData() {
    const schoolId = document.getElementById('school_filter').value;
    const gradeId = document.getElementById('grade_filter').value;
    const courseId = document.getElementById('course_filter').value;

    console.log('Search button clicked with filters:', {
        schoolId: schoolId,
        gradeId: gradeId,
        courseId: courseId
    });

    // Show loading state
    showLoading();

    // Use the main grader data endpoint
    fetch('<?php echo $CFG->wwwroot; ?>/theme/remui_kids/admin/get_grader_data.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            school_id: schoolId,
            grade_id: gradeId,
            course_id: courseId
        })
    })
    .then(response => {
        console.log('Response status:', response.status);
        if (!response.ok) {
            throw new Error('Network response was not ok: ' + response.status);
        }
        return response.json();
    })
    .then(data => {
        console.log('Data received:', data);
        console.log('Data type:', typeof data);
        console.log('Data keys:', Object.keys(data));
        
        if (data.success) {
            console.log('Success! Updating table with', data.students ? data.students.length : 0, 'students');
            updateTable(data);
            updateAnalytics(data.analytics);
            updateCharts(data.chartData);
        } else {
            console.error('Server error:', data.error);
            showError('Error loading data: ' + (data.error || 'Unknown error'));
        }
        hideLoading();
    })
    .catch(error => {
        console.error('Fetch error:', error);
        showError('Failed to load data. Please check your connection and try again.');
        hideLoading();
    });
}

function updateTable(data) {
    console.log('updateTable called with:', data);
    console.log('Students array:', data.students);
    console.log('Students count:', data.students ? data.students.length : 'undefined');
    
    const tbody = document.getElementById('grader_tbody');
    tbody.innerHTML = '';

    if (!data.students || !Array.isArray(data.students)) {
        console.error('No students data or not an array');
        tbody.innerHTML = '<tr><td colspan="9" class="text-center">No student data available</td></tr>';
        return;
    }

    data.students.forEach(student => {
        const row = document.createElement('tr');
        row.innerHTML = `
            <td>${student.fullname}</td>
            <td>${student.email}</td>
            <td>${student.school_name}</td>
            <td>${student.grade_name}</td>
            <td>${student.course_name}</td>
            <td>${student.total_activities}</td>
            <td>${student.average_grade}%</td>
            <td><span class="status-badge ${student.status}">${student.status}</span></td>
            <td>
                <button class="btn btn-sm btn-primary" onclick="viewStudentDetails(${student.id})">
                    <i class="fa fa-eye"></i> View
                </button>
            </td>
        `;
        tbody.appendChild(row);
    });
}

function updateAnalytics(analytics) {
    document.getElementById('total_students').textContent = analytics.total_students;
    document.getElementById('total_courses').textContent = analytics.total_courses;
    document.getElementById('avg_grade').textContent = analytics.avg_grade + '%';
    document.getElementById('completion_rate').textContent = analytics.completion_rate + '%';
}

function updateCharts(chartData) {
    try {
        // Destroy existing charts first
        if (window.gradeChart) {
            window.gradeChart.destroy();
            window.gradeChart = null;
        }
        if (window.schoolChart) {
            window.schoolChart.destroy();
            window.schoolChart = null;
        }
        
        // Grade Distribution Chart
        const gradeCtx = document.getElementById('gradeDistributionChart').getContext('2d');
        window.gradeChart = new Chart(gradeCtx, {
        type: 'doughnut',
        data: {
            labels: chartData.grade_distribution.labels,
            datasets: [{
                data: chartData.grade_distribution.data,
                backgroundColor: ['#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', '#9966FF']
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false
        }
    });

    // School Performance Chart
    const schoolCtx = document.getElementById('schoolPerformanceChart').getContext('2d');
    window.schoolChart = new Chart(schoolCtx, {
        type: 'bar',
        data: {
            labels: chartData.school_performance.labels,
            datasets: [{
                label: 'Average Grade',
                data: chartData.school_performance.data,
                backgroundColor: '#36A2EB'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    max: 100
                }
            }
        }
    });
    
    } catch (error) {
        console.error('Chart creation error:', error);
        // Don't let chart errors break the table display
    }
}

function resetFilters() {
    document.getElementById('school_filter').value = '0';
    document.getElementById('grade_filter').value = '0';
    document.getElementById('course_filter').value = '0';
    loadGraderData();
}

function exportData(format) {
    const schoolId = document.getElementById('school_filter').value;
    const gradeId = document.getElementById('grade_filter').value;
    const courseId = document.getElementById('course_filter').value;
    
    window.open(`<?php echo $CFG->wwwroot; ?>/theme/remui_kids/admin/custom_grader_report.php?export=${format}&school_id=${schoolId}&grade_id=${gradeId}&course_id=${courseId}`, '_blank');
}

function printReport() {
    window.print();
}

function loadMoreUsers() {
    console.log('Loading more users...');
    showLoading();
    
    // Get current filter values
    const schoolId = document.getElementById('school_filter').value;
    const gradeId = document.getElementById('grade_filter').value;
    const courseId = document.getElementById('course_filter').value;
    
    console.log('Loading more users with filters:', { schoolId, gradeId, courseId });
    
    fetch('<?php echo $CFG->wwwroot; ?>/theme/remui_kids/admin/get_grader_data.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            school_id: parseInt(schoolId) || 0,
            grade_id: parseInt(gradeId) || 0,
            course_id: parseInt(courseId) || 0,
            load_more: true
        })
    })
    .then(response => {
        console.log('Load more response status:', response.status);
        if (!response.ok) {
            throw new Error('Network response was not ok: ' + response.status);
        }
        return response.json();
    })
    .then(data => {
        console.log('Load more data received:', data);
        if (data.success) {
            updateTable(data);
            updateAnalytics(data.analytics);
            updateCharts(data.chartData);
        } else {
            console.error('Server error:', data.error);
            showError('Error loading more data: ' + (data.error || 'Unknown error'));
        }
        hideLoading();
    })
    .catch(error => {
        console.error('Load more error:', error);
        showError('Failed to load more data. Please check your connection and try again.');
        hideLoading();
    });
}

function showLoading() {
    // Add loading spinner
    const tbody = document.getElementById('grader_tbody');
    tbody.innerHTML = '<tr><td colspan="9" class="text-center"><i class="fa fa-spinner fa-spin"></i> Loading...</td></tr>';
}

function hideLoading() {
    // Loading will be replaced by actual data
}

function showError(message) {
    const tbody = document.getElementById('grader_tbody');
    tbody.innerHTML = `<tr><td colspan="9" class="text-center text-danger"><i class="fa fa-exclamation-triangle"></i> ${message}</td></tr>`;
}

function loadFallbackData() {
    console.log('Loading fallback data...');
    const tbody = document.getElementById('grader_tbody');
    tbody.innerHTML = `
        <tr>
            <td colspan="9" class="text-center">
                <div class="alert alert-info">
                    <i class="fa fa-info-circle"></i> 
                    AJAX request failed. Please refresh the page or check your connection.
                    <br><br>
                    <button class="btn btn-primary btn-sm" onclick="location.reload()">
                        <i class="fa fa-refresh"></i> Refresh Page
                    </button>
                </div>
            </td>
        </tr>
    `;
}

function showSimpleTestResults(data) {
    console.log('Showing simple test results:', data);
    const tbody = document.getElementById('grader_tbody');
    
    let tableHtml = '';
    
    // Show sample users if available
    if (data.data.sample_users && data.data.sample_users.length > 0) {
        data.data.sample_users.forEach(user => {
            tableHtml += `
                <tr>
                    <td>${user.firstname} ${user.lastname}</td>
                    <td>${user.email}</td>
                    <td>N/A</td>
                    <td>N/A</td>
                    <td>N/A</td>
                    <td>0</td>
                    <td>0%</td>
                    <td><span class="status-badge Needs Improvement">Needs Improvement</span></td>
                    <td>
                        <button class="btn btn-sm btn-primary" onclick="viewStudentDetails(${user.id})">
                            <i class="fa fa-eye"></i> View
                        </button>
                    </td>
                </tr>
            `;
        });
    } else {
        tableHtml = `
            <tr>
                <td colspan="9" class="text-center">
                    <div class="alert alert-info">
                        <i class="fa fa-info-circle"></i> 
                        Database test successful!<br>
                        <strong>Users:</strong> ${data.data.user_count} | 
                        <strong>Courses:</strong> ${data.data.course_count} | 
                        <strong>Schools:</strong> ${data.data.company_count} | 
                        <strong>Cohorts:</strong> ${data.data.cohort_count}<br>
                        <small>No sample users found. This might be normal if you have no users in the system.</small>
                    </div>
                </td>
            </tr>
        `;
    }
    
    tbody.innerHTML = tableHtml;
    
    // Update analytics
    document.getElementById('total_students').textContent = data.data.user_count;
    document.getElementById('total_courses').textContent = data.data.course_count;
    document.getElementById('avg_grade').textContent = '0%';
    document.getElementById('completion_rate').textContent = '0%';
}

function testAjaxConnection() {
    console.log('Testing AJAX connection...');
    showLoading();
    
    fetch('<?php echo $CFG->wwwroot; ?>/theme/remui_kids/admin/test_ajax.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            test: 'connection',
            timestamp: new Date().toISOString()
        })
    })
    .then(response => {
        console.log('Test response status:', response.status);
        return response.json();
    })
    .then(data => {
        console.log('Test response data:', data);
        const tbody = document.getElementById('grader_tbody');
        tbody.innerHTML = `
            <tr>
                <td colspan="9" class="text-center">
                    <div class="alert alert-success">
                        <i class="fa fa-check-circle"></i> 
                        AJAX connection successful!<br>
                        <small>Response: ${data.message}</small><br>
                        <small>Server: PHP ${data.server_info.php_version} | Moodle ${data.server_info.moodle_version}</small>
                    </div>
                </td>
            </tr>
        `;
    })
    .catch(error => {
        console.error('Test AJAX error:', error);
        const tbody = document.getElementById('grader_tbody');
        tbody.innerHTML = `
            <tr>
                <td colspan="9" class="text-center">
                    <div class="alert alert-danger">
                        <i class="fa fa-exclamation-triangle"></i> 
                        AJAX test failed: ${error.message}
                    </div>
                </td>
            </tr>
        `;
    });
}

function viewStudentDetails(studentId) {
    // Open student details modal or redirect to student profile
    window.open(`<?php echo $CFG->wwwroot; ?>/user/profile.php?id=${studentId}`, '_blank');
}
</script>

<?php
echo "</div>"; // End page-content
echo "</div>"; // End admin-main-content
echo $OUTPUT->footer();

/**
 * Get filtered grader data based on school, grade, and course filters
 */
function get_filtered_grader_data($school_id, $grade_id, $course_id) {
    global $DB;
    
    // Use the same data fetching pattern as admin dashboard
    $sql = "SELECT DISTINCT
                u.id,
                u.firstname,
                u.lastname,
                u.email,
                COALESCE(comp.name, 'N/A') as school_name,
                COALESCE(cohort.name, 'N/A') as grade_name,
                COALESCE(c.fullname, 'N/A') as course_name,
                COUNT(DISTINCT gi.id) as total_activities,
                ROUND(AVG(gg.finalgrade), 2) as average_grade,
                CASE 
                    WHEN AVG(gg.finalgrade) >= 80 THEN 'Excellent'
                    WHEN AVG(gg.finalgrade) >= 60 THEN 'Good'
                    WHEN AVG(gg.finalgrade) >= 40 THEN 'Average'
                    ELSE 'Needs Improvement'
                END as status
            FROM {user} u
            LEFT JOIN {company_users} cu ON u.id = cu.userid";
    
    $params = array();
    
    if ($school_id > 0) {
        $sql .= " AND cu.companyid = :school_id";
        $params['school_id'] = $school_id;
    }
    
    $sql .= " LEFT JOIN {company} comp ON cu.companyid = comp.id
              LEFT JOIN {cohort_members} cm ON u.id = cm.userid";
    
    if ($grade_id > 0) {
        $sql .= " AND cm.cohortid = :grade_id";
        $params['grade_id'] = $grade_id;
    }
    
    $sql .= " LEFT JOIN {cohort} cohort ON cm.cohortid = cohort.id
              LEFT JOIN {user_enrolments} ue ON u.id = ue.userid
              LEFT JOIN {enrol} e ON ue.enrolid = e.id
              LEFT JOIN {course} c ON e.courseid = c.id";
    
    if ($course_id > 0) {
        $sql .= " AND c.id = :course_id";
        $params['course_id'] = $course_id;
    }
    
    $sql .= " LEFT JOIN {grade_items} gi ON c.id = gi.courseid AND gi.itemtype = 'mod'
              LEFT JOIN {grade_grades} gg ON gi.id = gg.itemid AND u.id = gg.userid
              WHERE u.deleted = 0 AND u.suspended = 0
              GROUP BY u.id, u.firstname, u.lastname, u.email, comp.name, cohort.name, c.fullname
              ORDER BY u.firstname, u.lastname";
    
    try {
        return $DB->get_records_sql($sql, $params);
    } catch (Exception $e) {
        // Return empty array if query fails
        debugging('Database error in get_filtered_grader_data: ' . $e->getMessage());
        return array();
    }
}

/**
 * Handle export functionality
 */
function handle_export($data, $format) {
    switch ($format) {
        case 'csv':
            export_csv($data);
            break;
        case 'pdf':
            export_pdf($data);
            break;
        default:
            break;
    }
}

/**
 * Export data as CSV
 */
function export_csv($data) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="grader_report.csv"');
    
    $output = fopen('php://output', 'w');
    
    // CSV headers
    fputcsv($output, array('Student Name', 'Email', 'School', 'Grade', 'Course', 'Activities', 'Average Grade', 'Status'));
    
    // CSV data
    foreach ($data as $row) {
        fputcsv($output, array(
            $row->firstname . ' ' . $row->lastname,
            $row->email,
            $row->school_name,
            $row->grade_name,
            $row->course_name,
            $row->total_activities,
            round($row->average_grade, 2) . '%',
            $row->status
        ));
    }
    
    fclose($output);
}

/**
 * Export data as PDF (basic implementation)
 */
function export_pdf($data) {
    // This would require a PDF library like TCPDF or FPDF
    // For now, redirect to print version
    header('Location: ' . $_SERVER['REQUEST_URI'] . '&print=1');
}
?>
