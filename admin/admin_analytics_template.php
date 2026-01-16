<?php
/**
 * Administrative Analytics Dashboard Template
 * Inline HTML template for the administrative analytics page
 */

// Include styles first
include(__DIR__ . '/admin_analytics_styles.php');

// Main content wrapper
echo '<div class="admin-main-content">';
echo '<div class="admin-analytics-wrapper">';

// Page Header
echo '<div class="analytics-header">';
echo '<h1 class="analytics-title"><i class="fa fa-chart-bar"></i> Administrative Analytics</h1>';
echo '<p class="analytics-subtitle">Comprehensive insights into school performance, resource utilization, and system metrics</p>';
echo '</div>';

// Filters Section
echo '<div class="analytics-filters">';
echo '<div class="filter-group">';
echo '<label>School:</label>';
echo '<select id="school-filter" class="filter-select">';
echo '<option value="0" ' . ($selected_school == 0 ? 'selected' : '') . '>All Schools</option>';
foreach ($schools as $school) {
    $selected = ($selected_school == $school->id) ? 'selected' : '';
    echo "<option value='{$school->id}' {$selected}>" . format_string($school->name) . "</option>";
}
echo '</select>';
echo '</div>';

echo '<div class="filter-group">';
echo '<label>Date Range:</label>';
echo '<select id="daterange-filter" class="filter-select">';
$ranges = ['week' => 'Last Week', 'month' => 'Last Month', 'quarter' => 'Last Quarter', 'year' => 'Last Year'];
foreach ($ranges as $key => $label) {
    $selected = ($date_range == $key) ? 'selected' : '';
    echo "<option value='{$key}' {$selected}>{$label}</option>";
}
echo '</select>';
echo '</div>';

echo '<button id="refresh-btn" class="filter-btn"><i class="fa fa-sync-alt"></i> Refresh</button>';
echo '<button id="export-btn" class="filter-btn"><i class="fa fa-download"></i> Export</button>';
echo '</div>';

// Analytics Modules Tabs
echo '<div class="analytics-tabs-container">';
echo '<div class="analytics-tabs-nav">';
echo '<button class="analytics-tab-btn active" data-tab="school-performance">';
echo '<i class="fa fa-tachometer-alt"></i> School Performance Dashboard';
echo '</button>';
echo '<button class="analytics-tab-btn" data-tab="resource-utilization">';
echo '<i class="fa fa-database"></i> Resource Utilization';
echo '</button>';
echo '<button class="analytics-tab-btn" data-tab="teacher-effectiveness">';
echo '<i class="fa fa-chalkboard-teacher"></i> Teacher Effectiveness';
echo '</button>';
echo '<button class="analytics-tab-btn" data-tab="system-usage">';
echo '<i class="fa fa-server"></i> System Usage Statistics';
echo '</button>';
echo '<button class="analytics-tab-btn" data-tab="compliance-reporting">';
echo '<i class="fa fa-shield-alt"></i> Compliance Reporting';
echo '</button>';
echo '</div>';

// Tab Content Areas
echo '<div class="analytics-tabs-content">';

// Tab 1: School Performance Dashboard
echo '<div id="school-performance-tab" class="analytics-tab-content active">';
echo '<div class="tab-header">';
echo '<h2><i class="fa fa-tachometer-alt"></i> School Performance Dashboard</h2>';
echo '<p>High-level overview of school-wide academic performance and system usage statistics</p>';
echo '</div>';

echo '<div class="stats-grid">';
echo '<div class="stat-card">';
echo '<div class="stat-icon"><i class="fa fa-school"></i></div>';
echo '<div class="stat-content">';
echo '<div class="stat-value">' . $school_performance_data['total_schools'] . '</div>';
echo '<div class="stat-label">Total Schools</div>';
echo '</div>';
echo '</div>';

echo '<div class="stat-card">';
echo '<div class="stat-icon"><i class="fa fa-users"></i></div>';
echo '<div class="stat-content">';
echo '<div class="stat-value">' . number_format($school_performance_data['total_students']) . '</div>';
echo '<div class="stat-label">Total Students</div>';
echo '</div>';
echo '</div>';

echo '<div class="stat-card">';
echo '<div class="stat-icon"><i class="fa fa-chalkboard-teacher"></i></div>';
echo '<div class="stat-content">';
echo '<div class="stat-value">' . number_format($school_performance_data['total_teachers']) . '</div>';
echo '<div class="stat-label">Total Teachers</div>';
echo '</div>';
echo '</div>';

echo '<div class="stat-card">';
echo '<div class="stat-icon"><i class="fa fa-book"></i></div>';
echo '<div class="stat-content">';
echo '<div class="stat-value">' . number_format($school_performance_data['total_courses']) . '</div>';
echo '<div class="stat-label">Total Courses</div>';
echo '</div>';
echo '</div>';

echo '<div class="stat-card">';
echo '<div class="stat-icon"><i class="fa fa-check-circle"></i></div>';
echo '<div class="stat-content">';
echo '<div class="stat-value">' . $school_performance_data['average_completion_rate'] . '%</div>';
echo '<div class="stat-label">Avg Completion Rate</div>';
echo '</div>';
echo '</div>';

echo '<div class="stat-card">';
echo '<div class="stat-icon"><i class="fa fa-star"></i></div>';
echo '<div class="stat-content">';
echo '<div class="stat-value">' . $school_performance_data['average_grade'] . '%</div>';
echo '<div class="stat-label">Average Grade</div>';
echo '</div>';
echo '</div>';

echo '<div class="stat-card">';
echo '<div class="stat-icon"><i class="fa fa-user-check"></i></div>';
echo '<div class="stat-content">';
echo '<div class="stat-value">' . number_format($school_performance_data['active_users']) . '</div>';
echo '<div class="stat-label">Active Users (30 days)</div>';
echo '</div>';
echo '</div>';
echo '</div>';

// Charts Row - Enhanced with multiple charts
echo '<div class="charts-row">';
echo '<div class="chart-card">';
echo '<h4>Learning Effectiveness Trend</h4>';
echo '<div class="chart-container"><canvas id="schoolPerformanceLineChart"></canvas></div>';
echo '</div>';
echo '<div class="chart-card">';
echo '<h4>School Comparison</h4>';
echo '<div class="chart-container"><canvas id="schoolPerformanceBarChart"></canvas></div>';
echo '</div>';
echo '</div>';

// Additional Charts Row
echo '<div class="charts-row">';
echo '<div class="chart-card" style="position: relative;">';
echo '<h4>Pass/Fail Trends <small style="font-size: 0.7em; color: #6b7280;">(Click to drill down)</small></h4>';
echo '<div class="chart-container"><canvas id="passFailTrendChart"></canvas></div>';
echo '</div>';
echo '<div class="chart-card" style="position: relative;">';
echo '<h4>Average Grades by Course <small style="font-size: 0.7em; color: #6b7280;">(Click to drill down)</small></h4>';
echo '<div class="chart-container"><canvas id="gradesByCourseChart"></canvas></div>';
echo '</div>';
echo '</div>';

// Grade Categories Breakdown
if (!empty($school_performance_data['grade_categories_breakdown'])) {
    echo '<div class="analytics-section">';
    echo '<h3>Grade Categories Breakdown</h3>';
    echo '<div class="table-container">';
    echo '<table class="analytics-table">';
    echo '<thead><tr><th>Category Name</th><th>Average Grade</th><th>Student Count</th></tr></thead>';
    echo '<tbody>';
    foreach ($school_performance_data['grade_categories_breakdown'] as $category) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($category['category_name']) . '</td>';
        echo '<td><span class="badge">' . $category['avg_grade'] . '%</span></td>';
        echo '<td>' . $category['student_count'] . '</td>';
        echo '</tr>';
    }
    echo '</tbody>';
    echo '</table>';
    echo '</div>';
    echo '</div>';
}

// Attendance/Participation Section
echo '<div class="analytics-section">';
echo '<h3>Attendance & Participation</h3>';
echo '<div class="stats-grid" style="grid-template-columns: repeat(3, 1fr);">';
echo '<div class="stat-card">';
echo '<div class="stat-icon"><i class="fa fa-sign-in-alt"></i></div>';
echo '<div class="stat-content">';
echo '<div class="stat-value">' . number_format($school_performance_data['attendance_participation']['total_logins'] ?? 0) . '</div>';
echo '<div class="stat-label">Total Logins</div>';
echo '</div>';
echo '</div>';
echo '<div class="stat-card">';
echo '<div class="stat-icon"><i class="fa fa-eye"></i></div>';
echo '<div class="stat-content">';
echo '<div class="stat-value">' . number_format($school_performance_data['attendance_participation']['activity_views'] ?? 0) . '</div>';
echo '<div class="stat-label">Activity Views</div>';
echo '</div>';
echo '</div>';
echo '<div class="chart-card" style="grid-column: span 1;">';
echo '<h4>Login Trend (Daily)</h4>';
echo '<div class="chart-container"><canvas id="loginTrendChart"></canvas></div>';
echo '</div>';
echo '</div>';
echo '</div>';

// Completion Rates Section
if (!empty($school_performance_data['completion_rates'])) {
    echo '<div class="analytics-section">';
    echo '<h3>Completion Rates by Course</h3>';
    echo '<div class="table-container">';
    echo '<table class="analytics-table">';
    echo '<thead><tr><th>Course Name</th><th>Enrolled</th><th>Completed</th><th>Completion Rate</th></tr></thead>';
    echo '<tbody>';
    foreach (array_slice($school_performance_data['completion_rates'], 0, 10) as $course) {
        echo '<tr onclick="window.location.href=\'' . $CFG->wwwroot . '/theme/remui_kids/admin/admin_analytics.php?tab=completiondetail&courseid=' . $course['course_id'] . '\'" style="cursor: pointer;" onmouseover="this.style.backgroundColor=\'#f3f4f6\'" onmouseout="this.style.backgroundColor=\'\'">';
        echo '<td><strong>' . htmlspecialchars($course['course_name']) . '</strong> <i class="fa fa-external-link-alt" style="font-size: 0.8em; color: #6b7280;"></i></td>';
        echo '<td>' . $course['enrolled_students'] . '</td>';
        echo '<td>' . $course['completed_students'] . '</td>';
        echo '<td><span class="badge">' . $course['completion_rate'] . '%</span></td>';
        echo '</tr>';
    }
    echo '</tbody>';
    echo '</table>';
    echo '</div>';
    echo '</div>';
}

// Year/Semester Comparison
if (!empty($school_performance_data['year_semester_comparison'])) {
    $comp = $school_performance_data['year_semester_comparison'];
    echo '<div class="analytics-section">';
    echo '<h3>Year-over-Year Comparison</h3>';
    echo '<div class="stats-grid" style="grid-template-columns: repeat(2, 1fr);">';
    echo '<div class="stat-card">';
    echo '<div class="stat-label">' . $comp['current_year'] . ' Average Grade</div>';
    echo '<div class="stat-value">' . $comp['current_year_avg_grade'] . '%</div>';
    echo '</div>';
    echo '<div class="stat-card">';
    echo '<div class="stat-label">' . $comp['previous_year'] . ' Average Grade</div>';
    echo '<div class="stat-value">' . $comp['previous_year_avg_grade'] . '%</div>';
    echo '</div>';
    echo '</div>';
    $change_class = $comp['change_percentage'] >= 0 ? 'positive' : 'negative';
    echo '<div class="comparison-badge ' . $change_class . '">';
    echo ($comp['change_percentage'] >= 0 ? '+' : '') . $comp['change_percentage'] . '% change';
    echo '</div>';
    echo '</div>';
}

// School Breakdown Table
if (!empty($school_performance_data['school_breakdown'])) {
    echo '<div class="analytics-section">';
    echo '<h3>School Performance Breakdown</h3>';
    echo '<div class="table-container">';
    echo '<table class="analytics-table">';
    echo '<thead>';
    echo '<tr>';
    echo '<th>School Name</th>';
    echo '<th>Students</th>';
    echo '<th>Courses</th>';
    echo '<th>Completion Rate</th>';
    echo '<th>Average Grade</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';
    foreach ($school_performance_data['school_breakdown'] as $school) {
        echo '<tr>';
        echo '<td><strong>' . htmlspecialchars($school['name']) . '</strong></td>';
        echo '<td>' . number_format($school['total_students']) . '</td>';
        echo '<td>' . number_format($school['total_courses']) . '</td>';
        echo '<td><span class="badge">' . $school['completion_rate'] . '%</span></td>';
        echo '<td><span class="badge">' . $school['average_grade'] . '%</span></td>';
        echo '</tr>';
    }
    echo '</tbody>';
    echo '</table>';
    echo '</div>';
    echo '</div>';
}

echo '</div>'; // End school-performance-tab

// Tab 2: Resource Utilization
echo '<div id="resource-utilization-tab" class="analytics-tab-content">';
echo '<div class="tab-header">';
echo '<h2><i class="fa fa-database"></i> Resource Utilization</h2>';
echo '<p>Analytics on curriculum resource usage, popular content, and areas needing enhancement</p>';
echo '</div>';

echo '<div class="stats-grid">';
echo '<div class="stat-card">';
echo '<div class="stat-icon"><i class="fa fa-cubes"></i></div>';
echo '<div class="stat-content">';
echo '<div class="stat-value">' . number_format($resource_utilization_data['total_resources']) . '</div>';
echo '<div class="stat-label">Total Resources</div>';
echo '</div>';
echo '</div>';
echo '</div>';

// Top 10 Popular Resources Widget
if (!empty($resource_utilization_data['top_10_popular'])) {
    echo '<div class="analytics-section">';
    echo '<h3><i class="fa fa-fire"></i> Top 10 Popular Resources</h3>';
    echo '<div class="table-container">';
    echo '<table class="analytics-table">';
    echo '<thead><tr><th>Rank</th><th>Resource Name</th><th>Type</th><th>Course</th><th>Views</th><th>Unique Users</th></tr></thead>';
    echo '<tbody>';
    $rank = 1;
    foreach ($resource_utilization_data['top_10_popular'] as $resource) {
        echo '<tr>';
        echo '<td><strong>#' . $rank++ . '</strong></td>';
        echo '<td>' . htmlspecialchars($resource['name'] ?? 'N/A') . '</td>';
        echo '<td><span class="badge">' . htmlspecialchars($resource['type']) . '</span></td>';
        echo '<td>' . htmlspecialchars($resource['course_name'] ?? 'N/A') . '</td>';
        echo '<td>' . number_format($resource['access_count']) . '</td>';
        echo '<td>' . number_format($resource['unique_users']) . '</td>';
        echo '</tr>';
    }
    echo '</tbody>';
    echo '</table>';
    echo '</div>';
    echo '</div>';
}

// Resource Time Spent
if (!empty($resource_utilization_data['resource_time_spent'])) {
    echo '<div class="analytics-section">';
    echo '<h3>Time Spent on Resources</h3>';
    echo '<div class="chart-card">';
    echo '<div class="chart-container"><canvas id="resourceTimeSpentChart"></canvas></div>';
    echo '</div>';
    echo '</div>';
}

// Dead Content Detection
if (!empty($resource_utilization_data['dead_content'])) {
    echo '<div class="analytics-section">';
    echo '<h3><i class="fa fa-exclamation-triangle"></i> Dead/Unused Content (No access in 90+ days)</h3>';
    echo '<div class="table-container">';
    echo '<table class="analytics-table">';
    echo '<thead><tr><th>Resource Type</th><th>Course</th><th>Days Since Access</th><th>Last Access</th></tr></thead>';
    echo '<tbody>';
    foreach (array_slice($resource_utilization_data['dead_content'], 0, 15) as $content) {
        echo '<tr>';
        echo '<td><span class="badge">' . htmlspecialchars($content['type']) . '</span></td>';
        echo '<td>' . htmlspecialchars($content['course_name']) . '</td>';
        echo '<td>' . $content['days_since_access'] . ' days</td>';
        echo '<td>' . htmlspecialchars($content['last_access']) . '</td>';
        echo '</tr>';
    }
    echo '</tbody>';
    echo '</table>';
    echo '</div>';
    echo '</div>';
}

// Resource Types Breakdown
if (!empty($resource_utilization_data['resource_types'])) {
    echo '<div class="analytics-section">';
    echo '<h3>Resource Types Distribution</h3>';
    echo '<div class="table-container">';
    echo '<table class="analytics-table">';
    echo '<thead>';
    echo '<tr>';
    echo '<th>Resource Type</th>';
    echo '<th>Total Count</th>';
    echo '<th>Courses Used In</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';
    foreach ($resource_utilization_data['resource_types'] as $type) {
        echo '<tr>';
        echo '<td><strong>' . htmlspecialchars($type['type']) . '</strong></td>';
        echo '<td>' . number_format($type['count']) . '</td>';
        echo '<td>' . number_format($type['courses_used']) . '</td>';
        echo '</tr>';
    }
    echo '</tbody>';
    echo '</table>';
    echo '</div>';
    echo '</div>';
}

// Popular Content
if (!empty($resource_utilization_data['popular_content'])) {
    echo '<div class="analytics-section">';
    echo '<h3>Most Popular Content</h3>';
    echo '<div class="table-container">';
    echo '<table class="analytics-table">';
    echo '<thead>';
    echo '<tr>';
    echo '<th>Resource Type</th>';
    echo '<th>Access Count</th>';
    echo '<th>Unique Users</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';
    foreach (array_slice($resource_utilization_data['popular_content'], 0, 10) as $content) {
        echo '<tr>';
        echo '<td><strong>' . htmlspecialchars($content['type']) . '</strong></td>';
        echo '<td>' . number_format($content['access_count']) . '</td>';
        echo '<td>' . number_format($content['unique_users']) . '</td>';
        echo '</tr>';
    }
    echo '</tbody>';
    echo '</table>';
    echo '</div>';
    echo '</div>';
}

// Underutilized Content
if (!empty($resource_utilization_data['underutilized_content'])) {
    echo '<div class="analytics-section">';
    echo '<h3>Underutilized Content (Needs Enhancement)</h3>';
    echo '<div class="table-container">';
    echo '<table class="analytics-table">';
    echo '<thead>';
    echo '<tr>';
    echo '<th>Resource Type</th>';
    echo '<th>Status</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';
    foreach (array_slice($resource_utilization_data['underutilized_content'], 0, 10) as $content) {
        echo '<tr>';
        echo '<td><strong>' . htmlspecialchars($content['type']) . '</strong></td>';
        echo '<td><span class="badge warning">No Recent Access</span></td>';
        echo '</tr>';
    }
    echo '</tbody>';
    echo '</table>';
    echo '</div>';
    echo '</div>';
}

// Charts for Resource Utilization
echo '<div class="charts-row">';
echo '<div class="chart-card">';
echo '<h4>Resource Type Distribution</h4>';
echo '<div class="chart-container"><canvas id="resourceDistributionChart"></canvas></div>';
echo '</div>';
echo '<div class="chart-card">';
echo '<h4>Popular Content Engagement</h4>';
echo '<div class="chart-container"><canvas id="popularContentChart"></canvas></div>';
echo '</div>';
echo '</div>';

echo '</div>'; // End resource-utilization-tab

// Tab 3: Teacher Effectiveness
echo '<div id="teacher-effectiveness-tab" class="analytics-tab-content">';
echo '<div class="tab-header">';
echo '<h2><i class="fa fa-chalkboard-teacher"></i> Teacher Effectiveness</h2>';
echo '<p>Metrics on teacher performance, student engagement in their classes, and professional development needs</p>';
echo '</div>';

echo '<div class="stats-grid">';
echo '<div class="stat-card">';
echo '<div class="stat-icon"><i class="fa fa-users"></i></div>';
echo '<div class="stat-content">';
echo '<div class="stat-value">' . number_format($teacher_effectiveness_data['total_teachers']) . '</div>';
echo '<div class="stat-label">Total Teachers</div>';
echo '</div>';
echo '</div>';

echo '<div class="stat-card">';
echo '<div class="stat-icon"><i class="fa fa-chart-line"></i></div>';
echo '<div class="stat-content">';
echo '<div class="stat-value">' . $teacher_effectiveness_data['average_engagement'] . '%</div>';
echo '<div class="stat-label">Average Engagement Score</div>';
echo '</div>';
echo '</div>';
echo '</div>';

// Teacher Score Cards Section
if (!empty($teacher_effectiveness_data['teachers'])) {
    echo '<div class="analytics-section">';
    echo '<h3><i class="fa fa-id-card"></i> Teacher Score Cards</h3>';
    echo '<div class="table-container">';
    echo '<table class="analytics-table">';
    echo '<thead>';
    echo '<tr>';
    echo '<th>Teacher Name</th>';
    echo '<th>Courses</th>';
    echo '<th>Students</th>';
    echo '<th>Overall Score</th>';
    echo '<th>Engagement</th>';
    echo '<th>Performance</th>';
    echo '<th>Activity</th>';
    echo '<th>Feedback Time</th>';
    echo '<th>Login Freq</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';
    foreach (array_slice($teacher_effectiveness_data['teachers'], 0, 20) as $teacher) {
        $score_card = $teacher['score_card'] ?? [];
        echo '<tr>';
        echo '<td><strong>' . htmlspecialchars($teacher['name']) . '</strong></td>';
        echo '<td>' . $teacher['courses_managed'] . '</td>';
        echo '<td>' . $teacher['students_enrolled'] . '</td>';
        echo '<td><span class="badge" style="background: ' . ($score_card['overall_score'] >= 70 ? '#10b981' : ($score_card['overall_score'] >= 50 ? '#f59e0b' : '#ef4444')) . '">' . $score_card['overall_score'] . '%</span></td>';
        echo '<td>' . round($score_card['engagement_score'] ?? 0, 1) . '%</td>';
        echo '<td>' . round($score_card['performance_score'] ?? 0, 1) . '%</td>';
        echo '<td>' . round($score_card['activity_score'] ?? 0, 1) . '%</td>';
        echo '<td>' . ($teacher['avg_feedback_time_hours'] > 0 ? $teacher['avg_feedback_time_hours'] . 'h' : 'N/A') . '</td>';
        echo '<td>' . $teacher['login_frequency'] . '</td>';
        echo '</tr>';
    }
    echo '</tbody>';
    echo '</table>';
    echo '</div>';
    echo '</div>';
}

// Teachers Table
if (!empty($teacher_effectiveness_data['teachers'])) {
    echo '<div class="analytics-section">';
    echo '<h3>Teacher Performance Metrics</h3>';
    echo '<div class="table-container">';
    echo '<table class="analytics-table">';
    echo '<thead>';
    echo '<tr>';
    echo '<th>Teacher Name</th>';
    echo '<th>Courses Managed</th>';
    echo '<th>Students Enrolled</th>';
    echo '<th>Avg Student Grade</th>';
    echo '<th>Engagement Score</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';
    foreach (array_slice($teacher_effectiveness_data['teachers'], 0, 50) as $teacher) {
        $engagement_class = $teacher['engagement_score'] >= 70 ? 'success' : ($teacher['engagement_score'] >= 50 ? 'warning' : 'danger');
        echo '<tr>';
        echo '<td><strong>' . htmlspecialchars($teacher['name']) . '</strong></td>';
        echo '<td>' . $teacher['courses_managed'] . '</td>';
        echo '<td>' . number_format($teacher['students_enrolled']) . '</td>';
        echo '<td>' . $teacher['avg_student_grade'] . '%</td>';
        echo '<td><span class="badge ' . $engagement_class . '">' . $teacher['engagement_score'] . '%</span></td>';
        echo '</tr>';
    }
    echo '</tbody>';
    echo '</table>';
    echo '</div>';
    echo '</div>';
}

// Teacher charts
echo '<div class="charts-row">';
echo '<div class="chart-card">';
echo '<h4>Teacher Engagement</h4>';
echo '<div class="chart-container"><canvas id="teacherEngagementChart"></canvas></div>';
echo '</div>';
echo '<div class="chart-card">';
echo '<h4>Average Student Grades</h4>';
echo '<div class="chart-container"><canvas id="teacherGradeChart"></canvas></div>';
echo '</div>';
echo '</div>';

echo '</div>'; // End teacher-effectiveness-tab

// Tab 4: System Usage Statistics
echo '<div id="system-usage-tab" class="analytics-tab-content">';
echo '<div class="tab-header">';
echo '<h2><i class="fa fa-server"></i> System Usage Statistics</h2>';
echo '<p>Comprehensive data on platform usage, peak times, and technical performance metrics</p>';
echo '</div>';

echo '<div class="stats-grid">';
echo '<div class="stat-card">';
echo '<div class="stat-icon"><i class="fa fa-sign-in-alt"></i></div>';
echo '<div class="stat-content">';
echo '<div class="stat-value">' . number_format($system_usage_data['total_logins']) . '</div>';
echo '<div class="stat-label">Total Logins</div>';
echo '</div>';
echo '</div>';

echo '<div class="stat-card">';
echo '<div class="stat-icon"><i class="fa fa-mouse-pointer"></i></div>';
echo '<div class="stat-content">';
echo '<div class="stat-value">' . number_format($system_usage_data['total_actions']) . '</div>';
echo '<div class="stat-label">Total Actions</div>';
echo '</div>';
echo '</div>';

echo '<div class="stat-card">';
echo '<div class="stat-icon"><i class="fa fa-clock"></i></div>';
echo '<div class="stat-content">';
echo '<div class="stat-value">' . ($system_usage_data['average_session_duration'] > 0 ? round($system_usage_data['average_session_duration'] / 60, 1) . ' min' : 'N/A') . '</div>';
echo '<div class="stat-label">Avg Session Duration</div>';
echo '</div>';
echo '</div>';
echo '</div>';

// Peak Usage Times
if (!empty($system_usage_data['peak_usage_times'])) {
    echo '<div class="analytics-section">';
    echo '<h3>Peak Usage Times</h3>';
    echo '<div class="table-container">';
    echo '<table class="analytics-table">';
    echo '<thead>';
    echo '<tr>';
    echo '<th>Time</th>';
    echo '<th>Action Count</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';
    foreach ($system_usage_data['peak_usage_times'] as $peak) {
        echo '<tr>';
        echo '<td><strong>' . htmlspecialchars($peak['hour']) . '</strong></td>';
        echo '<td>' . number_format($peak['action_count']) . '</td>';
        echo '</tr>';
    }
    echo '</tbody>';
    echo '</table>';
    echo '</div>';
    echo '</div>';
}

// Daily Active Users Chart
if (!empty($system_usage_data['daily_active_users'])) {
    echo '<div class="analytics-section">';
    echo '<h3>Daily Active Users Trend</h3>';
    echo '<div class="chart-card">';
    echo '<div class="chart-container"><canvas id="dailyActiveUsersChart"></canvas></div>';
    echo '</div>';
    echo '</div>';
}

// Add peak usage bar chart container
echo '<div class="charts-row">';
echo '<div class="chart-card">';
echo '<h4>Peak Usage Times</h4>';
echo '<div class="chart-container"><canvas id="peakUsageBarChart"></canvas></div>';
echo '</div>';
echo '<div class="chart-card">';
echo '<h4>System Activity Overview</h4>';
echo '<div class="chart-container"><canvas id="systemActivityChart"></canvas></div>';
echo '</div>';
echo '</div>';

echo '</div>'; // End system-usage-tab

// Tab 5: Compliance Reporting
echo '<div id="compliance-reporting-tab" class="analytics-tab-content">';
echo '<div class="tab-header">';
echo '<h2><i class="fa fa-shield-alt"></i> Compliance Reporting</h2>';
echo '<p>Automated reports for compliance with educational standards and administrative requirements</p>';
echo '</div>';

echo '<div class="stats-grid">';
echo '<div class="stat-card">';
echo '<div class="stat-icon"><i class="fa fa-book-open"></i></div>';
echo '<div class="stat-content">';
echo '<div class="stat-value">' . number_format($compliance_data['required_courses']) . '</div>';
echo '<div class="stat-label">Required Courses</div>';
echo '</div>';
echo '</div>';

echo '<div class="stat-card">';
echo '<div class="stat-icon"><i class="fa fa-check-circle"></i></div>';
echo '<div class="stat-content">';
echo '<div class="stat-value">' . number_format($compliance_data['completed_courses']) . '</div>';
echo '<div class="stat-label">Completed Courses</div>';
echo '</div>';
echo '</div>';

// Compliance Summary Section
if (!empty($compliance_data['compliance_summary'])) {
    $summary = $compliance_data['compliance_summary'];
    echo '<div class="analytics-section">';
    echo '<h3><i class="fa fa-shield-alt"></i> Overall Compliance Summary</h3>';
    echo '<div class="stats-grid" style="grid-template-columns: repeat(4, 1fr);">';
    echo '<div class="stat-card">';
    echo '<div class="stat-label">Overall Compliance</div>';
    $status_class = $summary['status'] === 'compliant' ? 'success' : ($summary['status'] === 'partial' ? 'warning' : 'danger');
    echo '<div class="stat-value ' . $status_class . '">' . $summary['overall_compliance_rate'] . '%</div>';
    echo '</div>';
    echo '<div class="stat-card">';
    echo '<div class="stat-label">Student Progress</div>';
    echo '<div class="stat-value">' . $summary['student_progress_rate'] . '%</div>';
    echo '</div>';
    echo '<div class="stat-card">';
    echo '<div class="stat-label">Mandatory Training</div>';
    echo '<div class="stat-value">' . $summary['mandatory_training_rate'] . '%</div>';
    echo '</div>';
    echo '<div class="stat-card">';
    echo '<div class="stat-label">Standards Adherence</div>';
    echo '<div class="stat-value">' . $summary['standards_adherence_rate'] . '%</div>';
    echo '</div>';
    echo '</div>';
    echo '</div>';
}

// Mandatory Training Completion
if (!empty($compliance_data['mandatory_training_completion'])) {
    echo '<div class="analytics-section">';
    echo '<h3>Mandatory Training Completion</h3>';
    echo '<div class="table-container">';
    echo '<table class="analytics-table">';
    echo '<thead>';
    echo '<tr><th>Course Name</th><th>Enrolled</th><th>Completed</th><th>Completion Rate</th><th>Status</th></tr>';
    echo '</thead>';
    echo '<tbody>';
    foreach (array_slice($compliance_data['mandatory_training_completion'], 0, 15) as $training) {
        $status_badge = $training['status'] === 'compliant' ? 'badge-success' : ($training['status'] === 'partial' ? 'badge-warning' : 'badge-danger');
        echo '<tr>';
        echo '<td>' . htmlspecialchars($training['course_name']) . '</td>';
        echo '<td>' . $training['enrolled_students'] . '</td>';
        echo '<td>' . $training['completed_students'] . '</td>';
        echo '<td>' . $training['completion_rate'] . '%</td>';
        echo '<td><span class="badge ' . $status_badge . '">' . ucfirst($training['status']) . '</span></td>';
        echo '</tr>';
    }
    echo '</tbody>';
    echo '</table>';
    echo '</div>';
    echo '</div>';
}

// Standards Adherence
if (!empty($compliance_data['standards_adherence']) && $compliance_data['standards_adherence']['total_competencies'] > 0) {
    $standards = $compliance_data['standards_adherence'];
    echo '<div class="analytics-section">';
    echo '<h3>Standards Adherence (Competencies)</h3>';
    echo '<div class="stats-grid" style="grid-template-columns: repeat(3, 1fr);">';
    echo '<div class="stat-card">';
    echo '<div class="stat-label">Total Competencies</div>';
    echo '<div class="stat-value">' . $standards['total_competencies'] . '</div>';
    echo '</div>';
    echo '<div class="stat-card">';
    echo '<div class="stat-label">Achieved</div>';
    echo '<div class="stat-value">' . $standards['achieved_competencies'] . '</div>';
    echo '</div>';
    echo '<div class="stat-card">';
    echo '<div class="stat-label">Adherence Rate</div>';
    $adherence_class = $standards['status'] === 'compliant' ? 'success' : ($standards['status'] === 'partial' ? 'warning' : 'danger');
    echo '<div class="stat-value ' . $adherence_class . '">' . $standards['adherence_rate'] . '%</div>';
    echo '</div>';
    echo '</div>';
    echo '</div>';
}

echo '<div class="stat-card">';
echo '<div class="stat-icon"><i class="fa fa-user-graduate"></i></div>';
echo '<div class="stat-content">';
echo '<div class="stat-value">' . $compliance_data['student_progress'] . '%</div>';
echo '<div class="stat-label">Student Progress Compliance</div>';
echo '</div>';
echo '</div>';

$compliance_status_class = $compliance_data['compliance_status'] == 'compliant' ? 'success' : 'warning';
echo '<div class="stat-card ' . $compliance_status_class . '">';
echo '<div class="stat-icon"><i class="fa fa-shield-alt"></i></div>';
echo '<div class="stat-content">';
echo '<div class="stat-value">' . ucfirst($compliance_data['compliance_status']) . '</div>';
echo '<div class="stat-label">Compliance Status</div>';
echo '</div>';
echo '</div>';
echo '</div>';

// Audit Trail
if (!empty($compliance_data['audit_trail'])) {
    echo '<div class="analytics-section">';
    echo '<h3>Recent Audit Trail</h3>';
    echo '<div class="table-container">';
    echo '<table class="analytics-table">';
    echo '<thead>';
    echo '<tr>';
    echo '<th>Time</th>';
    echo '<th>User</th>';
    echo '<th>Action</th>';
    echo '<th>Target</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';
    foreach (array_slice($compliance_data['audit_trail'], 0, 50) as $audit) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($audit['time']) . '</td>';
        echo '<td><strong>' . htmlspecialchars($audit['user']) . '</strong></td>';
        echo '<td><span class="badge">' . htmlspecialchars($audit['action']) . '</span></td>';
        echo '<td>' . htmlspecialchars($audit['target']) . '</td>';
        echo '</tr>';
    }
    echo '</tbody>';
    echo '</table>';
    echo '</div>';
    echo '</div>';
}

// Compliance charts
echo '<div class="charts-row">';
echo '<div class="chart-card">';
echo '<h4>Compliance Progress</h4>';
echo '<div class="chart-container"><canvas id="complianceProgressChart"></canvas></div>';
echo '</div>';
echo '<div class="chart-card">';
echo '<h4>Compliance Breakdown</h4>';
echo '<div class="chart-container"><canvas id="complianceStatusChart"></canvas></div>';
echo '</div>';
echo '</div>';

echo '</div>'; // End compliance-reporting-tab

echo '</div>'; // End analytics-tabs-content
echo '</div>'; // End analytics-tabs-container

echo '</div>'; // End admin-analytics-wrapper
echo '</div>'; // End admin-main-content

// Include JavaScript
include(__DIR__ . '/admin_analytics_scripts.php');
