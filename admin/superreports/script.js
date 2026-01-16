/**
 * Super Admin Reports - Interactive Dashboard JavaScript
 *
 * @package   theme_remui_kids
 * @copyright 2025 Kodeit
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Chart instances storage
let chartInstances = {};

// Tab definitions (order + labels)
const DASHBOARD_TABS = [
    'overview',
    'assignments',
    'quizzes',
    'overall-grades',
    'competencies',
    'performance',
    'courses',
    'activity',
    'attendance'
];

const TAB_LABELS = {
    'overview': 'Overview',
    'assignments': 'Assignments',
    'quizzes': 'Quizzes',
    'overall-grades': 'Overall Grades',
    'competencies': 'Competencies',
    'performance': 'Performance',
    'courses': 'Courses',
    'activity': 'Activity & Engagement',
    'attendance': 'Attendance'
};

const CSV_GENERATORS = {};

// Tab data cache for CSV export
let tabDataCache = {};

// Current filters
let currentFilters = {
    school: '',
    cohort: '',
    grade: '',
    framework: '',
    dateRange: 'month',
    startDate: '',
    endDate: ''
};

/**
 * Initialize dashboard
 */
document.addEventListener('DOMContentLoaded', function() {
    setupTabSwitching();
    setupFilterHandlers();
    
    // Initialize cohort from grade filter (since gradeFilter contains cohort IDs)
    const gradeFilter = document.getElementById('gradeFilter');
    if (gradeFilter && gradeFilter.value) {
        currentFilters.grade = gradeFilter.value;
        currentFilters.cohort = gradeFilter.value;
    }
    
    loadTabData('overview');
    loadAISummary();
});

/**
 * Setup tab switching
 */
function setupTabSwitching() {
    const tabButtons = document.querySelectorAll('.tab-btn');
    
    tabButtons.forEach(button => {
        button.addEventListener('click', function() {
            const tab = this.getAttribute('data-tab');
            switchTab(tab);
        });
    });
}

/**
 * Switch to a specific tab
 */
function switchTab(tab) {
    // Update button states
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.classList.remove('active');
    });
    document.querySelector(`.tab-btn[data-tab="${tab}"]`).classList.add('active');
    
    // Update content visibility
    document.querySelectorAll('.tab-content').forEach(content => {
        content.classList.remove('active');
    });
    
    const tabContent = document.getElementById(`${tab}-tab`);
    tabContent.classList.add('active');
    
    // Show/hide framework filter based on tab
    const frameworkFilterGroup = document.getElementById('frameworkFilterGroup');
    if (frameworkFilterGroup) {
        if (tab === 'competencies') {
            frameworkFilterGroup.style.display = 'block';
        } else {
            frameworkFilterGroup.style.display = 'none';
        }
    }
    
    // Load data if not already loaded
    if (!tabContent.getAttribute('data-loaded')) {
        loadTabData(tab);
    }
}

/**
 * Setup filter handlers
 */
function setupFilterHandlers() {
    // School filter
    const schoolFilter = document.getElementById('schoolFilter');
    if (schoolFilter) {
        schoolFilter.addEventListener('change', function() {
            currentFilters.school = this.value;
            clearAllTabCache();
            loadAISummary();
            refreshCurrentTab();
        });
    }
    
    // Grade/Cohort filter (gradeFilter actually contains cohort IDs)
    const gradeFilter = document.getElementById('gradeFilter');
    if (gradeFilter) {
        gradeFilter.addEventListener('change', function() {
            // The gradeFilter dropdown contains cohort IDs, so set both
            currentFilters.grade = this.value;
            currentFilters.cohort = this.value; // Also set cohort since it contains cohort IDs
            console.log('Grade/Cohort filter changed - Grade:', currentFilters.grade, 'Cohort:', currentFilters.cohort);
            clearAllTabCache();
            loadAISummary();
            refreshCurrentTab();
        });
    }
    
    // Date range filter
    const dateRangeFilter = document.getElementById('dateRangeFilter');
    if (dateRangeFilter) {
        dateRangeFilter.addEventListener('change', function() {
            currentFilters.dateRange = this.value;
            
            // Show/hide custom date inputs
            const customInputs = document.getElementById('customDateInputs');
            if (this.value === 'custom') {
                customInputs.style.display = 'flex';
            } else {
                customInputs.style.display = 'none';
            }
            
            clearAllTabCache();
            loadAISummary();
            refreshCurrentTab();
        });
    }
    
    // Custom date inputs
    const startDate = document.getElementById('startDate');
    const endDate = document.getElementById('endDate');
    
    if (startDate) {
        startDate.addEventListener('change', function() {
            currentFilters.startDate = this.value;
            clearAllTabCache();
            loadAISummary();
            refreshCurrentTab();
        });
    }
    
    if (endDate) {
        endDate.addEventListener('change', function() {
            currentFilters.endDate = this.value;
            clearAllTabCache();
            loadAISummary();
            refreshCurrentTab();
        });
    }
    
    // Framework filter (for competencies tab)
    const frameworkFilter = document.getElementById('frameworkFilter');
    if (frameworkFilter) {
        frameworkFilter.addEventListener('change', function() {
            currentFilters.framework = this.value;
            clearAllTabCache();
            refreshCurrentTab();
        });
    }
}

/**
 * Clear all tab cache (when filters change)
 */
function clearAllTabCache() {
    // Clear data cache
    tabDataCache = {};
    
    // Clear loaded state
    const tabs = ['overview', 'assignments', 'quizzes', 'overall-grades', 'competencies', 'performance', 'courses', 'activity', 'attendance'];
    tabs.forEach(tab => {
        const tabContent = document.getElementById(`${tab}-tab`);
        if (tabContent) {
            tabContent.removeAttribute('data-loaded');
        }
    });
}

/**
 * Refresh current active tab
 */
function refreshCurrentTab() {
    const activeTab = document.querySelector('.tab-btn.active');
    if (activeTab) {
        const tab = activeTab.getAttribute('data-tab');
        loadTabData(tab);
    }
}

/**
 * Refresh dashboard (button handler)
 */
function refreshDashboard() {
    const btn = document.getElementById('refreshBtn');
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Refreshing...';
    
    clearAllTabCache();
    loadAISummary();
    refreshCurrentTab();
    
    setTimeout(() => {
        btn.innerHTML = '<i class="fa fa-sync-alt"></i> Refresh';
    }, 1000);
}

/**
 * Toggle export menu
 */
/**
 * Export all data to CSV
 */
function exportToCSV() {
    const exportBtn = document.querySelector('.export-btn');
    if (exportBtn) {
        exportBtn.disabled = true;
        exportBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Exporting...';
    }

    ensureDataForTabs(DASHBOARD_TABS)
        .then(() => {
            const csvContent = generateFullDashboardCSV(DASHBOARD_TABS);
            if (!csvContent) {
                alert('No data available to export');
                return;
            }
            const filename = `SuperAdmin_Full_Report_${new Date().toISOString().split('T')[0]}.csv`;
            downloadCSV(csvContent, filename);
        })
        .catch(error => {
            console.error('Export error:', error);
            alert('Unable to export dashboard data. Please try again.');
        })
        .finally(() => {
            if (exportBtn) {
                exportBtn.disabled = false;
                exportBtn.innerHTML = '<i class="fa fa-file-csv"></i> Export to CSV';
            }
        });
}

/**
 * Load tab data via AJAX
 */
function loadTabData(tab) {
    const tabContent = document.getElementById(`${tab}-tab`);
    if (tabContent) {
        tabContent.innerHTML = '<div class="loading-spinner"><i class="fa fa-spinner fa-spin"></i> Loading data...</div>';
    }

    fetchTabData(tab)
        .then(data => {
            if (tabContent) {
                renderTabContent(tab, data);
                tabContent.setAttribute('data-loaded', 'true');
            }
        })
        .catch(error => {
            console.error('Error loading data:', error);
            if (tabContent) {
                tabContent.innerHTML = `<div class="empty-state"><p>Failed to load data: ${error.message}</p></div>`;
            }
        });
}

/**
 * Build query params for tab AJAX call
 */
function buildTabParams(tab) {
    const params = new URLSearchParams({
        tab: tab,
        school: currentFilters.school || '',
        cohort: currentFilters.cohort || '',
        grade: currentFilters.grade || '',
        framework: currentFilters.framework || '',
        daterange: currentFilters.dateRange || 'month',
        startdate: currentFilters.startDate || '',
        enddate: currentFilters.endDate || '',
        sesskey: M.cfg.sesskey || ''
    });
    return params;
}

/**
 * Fetch tab data (used by both UI and export)
 */
function fetchTabData(tab) {
    if (tabDataCache[tab]) {
        return Promise.resolve(tabDataCache[tab]);
    }

    const params = buildTabParams(tab);
    const ajaxUrl = `${M.cfg.wwwroot}/theme/remui_kids/admin/superreports/ajax_data.php?${params.toString()}`;

    return fetch(ajaxUrl)
        .then(response => {
            if (!response.ok) {
                return response.json().then(data => {
                    throw new Error(data.error || 'Server error');
                }).catch(() => {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                });
            }
            return response.json();
        })
        .then(data => {
            if (!data.success) {
                throw new Error(data.error || 'Failed to load tab data');
            }
            tabDataCache[tab] = data;
            return data;
        });
}

/**
 * Ensure data is available for all tabs (used before exporting)
 */
function ensureDataForTabs(tabs) {
    const fetchPromises = tabs.map(tab => fetchTabData(tab));
    return Promise.all(fetchPromises);
}

/**
 * Generate combined CSV for all dashboard tabs
 */
function generateFullDashboardCSV(tabList) {
    const sections = [];

    tabList.forEach(tab => {
        const data = tabDataCache[tab];
        const generator = CSV_GENERATORS[tab];
        if (!data || !generator) {
            return;
        }

        const sectionCSV = generator(data);
        if (sectionCSV) {
            const title = TAB_LABELS[tab] || tab;
            sections.push(`"Section","${title}"\n${sectionCSV}`);
        }
    });

    return sections.join('\n\n');
}

/**
 * Load AI Summary (always visible section)
 */
function loadAISummary() {
    const summaryContent = document.getElementById('aiSummaryContent');
    if (!summaryContent) return;
    
    summaryContent.innerHTML = '<div class="loading-spinner"><i class="fa fa-spinner fa-spin"></i> Generating insights...</div>';
    
    const sesskey = M.cfg.sesskey;
    const params = new URLSearchParams({
        tab: 'ai-summary',
        school: currentFilters.school,
        cohort: currentFilters.cohort,
        grade: currentFilters.grade,
        daterange: currentFilters.dateRange,
        startdate: currentFilters.startDate,
        enddate: currentFilters.endDate,
        sesskey: sesskey
    });
    
    const ajaxUrl = M.cfg.wwwroot + '/theme/remui_kids/admin/superreports/ajax_data.php?' + params.toString();
    
    fetch(ajaxUrl)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.insights) {
                let html = '';
                data.insights.forEach(insight => {
                    html += `<p>${insight}</p>`;
                });
                summaryContent.innerHTML = html;
            } else {
                summaryContent.innerHTML = '<p>No insights available at this time.</p>';
            }
        })
        .catch(error => {
            console.error('Error loading AI summary:', error);
            summaryContent.innerHTML = '<p>Failed to load insights.</p>';
        });
}

/**
 * Render tab content based on data
 */
function renderTabContent(tab, data) {
    const tabContent = document.getElementById(`${tab}-tab`);
    
    switch(tab) {
        case 'overview':
            renderOverviewTab(tabContent, data);
            break;
        case 'assignments':
            renderAssignmentsTab(tabContent, data);
            break;
        case 'quizzes':
            renderQuizzesTab(tabContent, data);
            break;
        case 'overall-grades':
            renderOverallGradesTab(tabContent, data);
            break;
        case 'competencies':
            renderCompetenciesTab(tabContent, data);
            break;
        case 'performance':
            renderPerformanceTab(tabContent, data);
            break;
        case 'courses':
            renderCoursesTab(tabContent, data);
            break;
        case 'activity':
            renderActivityTab(tabContent, data);
            break;
        case 'attendance':
            renderAttendanceTab(tabContent, data);
            break;
    }
}

/**
 * Render Overview Tab
 */
function renderOverviewTab(container, data) {
    const stats = data.stats;
    
    let html = '';
    
    // Stats cards
    html += '<div class="stats-grid">';
    html += createStatCard('fa fa-building', stats.total_schools, 'Total Schools', 1);
    html += createStatCard('fa fa-chalkboard-teacher', stats.total_teachers, 'Total Teachers', 2);
    html += createStatCard('fa fa-user-graduate', stats.total_students, 'Total Students', 3);
    html += createStatCard('fa fa-check-circle', stats.avg_completion + '%', 'Avg Course Completion', 4);
    html += createStatCard('fa fa-book', stats.total_courses, 'Total Courses', 5);
    html += '</div>';
    
    // Charts row
    html += '<div class="charts-row">';
    html += '<div class="chart-card"><h3><i class="fa fa-chart-line"></i> Student Activity Performance</h3><div class="chart-container"><canvas id="activityTrendChart"></canvas></div></div>';
    html += '<div class="chart-card"><h3>Course Completion by School</h3><div class="chart-container"><canvas id="courseCompletionChart"></canvas></div></div>';
    html += '<div class="chart-card"><h3>Active Users by Role</h3><div class="chart-container"><canvas id="usersByRoleChart"></canvas></div></div>';
    html += '</div>';
    
    // Recent activity
    html += '<div class="charts-row">';
    html += '<div class="activity-feed"><h3>Recent Activity</h3>' + renderActivityFeed(data.recentActivity) + '</div>';
    html += '</div>';
    
    container.innerHTML = html;
    
    // Render charts
    setTimeout(() => {
        renderActivityTrendChart(data.activityTrend);
        renderCourseCompletionChart(data.courseCompletion);
        renderUsersByRoleChart(data.usersByRole);
    }, 100);
}

/**
 * Create stat card HTML
 */
function createStatCard(icon, value, label, index) {
    // Pastel color palette matching the reference image
    const colors = [
        { bg: '#e3f2fd', text: '#1976d2', icon: '#42a5f5' },      // Light blue
        { bg: '#e8f5e9', text: '#388e3c', icon: '#66bb6a' },      // Light green
        { bg: '#f3e5f5', text: '#7b1fa2', icon: '#ab47bc' },      // Light purple
        { bg: '#fff3e0', text: '#f57c00', icon: '#ffa726' },      // Light orange
        { bg: '#e1f5fe', text: '#0277bd', icon: '#29b6f6' },      // Light cyan blue
        { bg: '#fce4ec', text: '#c2185b', icon: '#ec407a' },      // Light pink/red
        { bg: '#f1f8e9', text: '#689f38', icon: '#9ccc65' },      // Light lime
        { bg: '#fff9c4', text: '#f9a825', icon: '#fdd835' }       // Light yellow
    ];
    
    const colorIndex = (index - 1) % colors.length;
    const color = colors[colorIndex];
    
    return `
        <div class="stat-card" style="background: ${color.bg}; border: 1px solid ${color.icon}20; border-radius: 12px; padding: 25px 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.08);">
            <div style="display: flex; align-items: center; gap: 15px;">
                <div style="font-size: 32px; color: ${color.icon};"><i class="${icon}"></i></div>
                <div style="flex: 1;">
                    <div style="font-size: 32px; font-weight: 700; color: ${color.text}; line-height: 1.2; margin-bottom: 5px;">${value}</div>
                    <div style="font-size: 12px; font-weight: 600; color: ${color.text}; text-transform: uppercase; letter-spacing: 0.5px; opacity: 0.8;">${label}</div>
                </div>
            </div>
        </div>
    `;
}

/**
 * Create stat card with trend indicator
 */
function createStatCardWithTrend(icon, value, label, trend, index) {
    // Pastel color palette matching the reference image
    const colors = [
        { bg: '#e3f2fd', text: '#1976d2', icon: '#42a5f5' },      // Light blue
        { bg: '#e8f5e9', text: '#388e3c', icon: '#66bb6a' },      // Light green
        { bg: '#f3e5f5', text: '#7b1fa2', icon: '#ab47bc' },      // Light purple
        { bg: '#fff3e0', text: '#f57c00', icon: '#ffa726' },      // Light orange
        { bg: '#e1f5fe', text: '#0277bd', icon: '#29b6f6' },      // Light cyan blue
        { bg: '#fce4ec', text: '#c2185b', icon: '#ec407a' },      // Light pink/red
        { bg: '#f1f8e9', text: '#689f38', icon: '#9ccc65' },      // Light lime
        { bg: '#fff9c4', text: '#f9a825', icon: '#fdd835' }       // Light yellow
    ];
    
    const colorIndex = (index - 1) % colors.length;
    const color = colors[colorIndex];
    
    const trendValue = parseFloat(trend) || 0;
    const isPositive = trendValue >= 0;
    const trendColor = isPositive ? '#2ecc71' : '#e74c3c';
    const trendIcon = isPositive ? 'fa-arrow-up' : 'fa-arrow-down';
    const trendSign = isPositive ? '+' : '';
    
    return `
        <div class="stat-card" style="background: ${color.bg}; border: 1px solid ${color.icon}20; border-radius: 12px; padding: 25px 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.08);">
            <div style="display: flex; align-items: center; gap: 15px;">
                <div style="font-size: 32px; color: ${color.icon};"><i class="${icon}"></i></div>
                <div style="flex: 1;">
                    <div style="font-size: 32px; font-weight: 700; color: ${color.text}; line-height: 1.2; margin-bottom: 5px;">${value}</div>
                    <div style="font-size: 12px; font-weight: 600; color: ${color.text}; text-transform: uppercase; letter-spacing: 0.5px; opacity: 0.8;">${label}</div>
                    <div style="color: ${trendColor}; font-size: 11px; margin-top: 8px; font-weight: 600;">
                        <i class="fa ${trendIcon}"></i> ${trendSign}${Math.abs(trendValue)}% vs last period
                    </div>
                </div>
            </div>
        </div>
    `;
}

/**
 * Render activity feed
 */
function renderActivityFeed(activities) {
    if (!activities || activities.length === 0) {
        return '<p>No recent activity.</p>';
    }
    
    let html = '';
    activities.forEach(activity => {
        html += `
            <div class="activity-item">
                <div class="activity-icon"><i class="fa fa-user"></i></div>
                <div class="activity-content">
                    <div class="activity-text">${activity.user} ${activity.action} ${activity.target}</div>
                    <div class="activity-time">${activity.timeago}</div>
                </div>
            </div>
        `;
    });
    
    return html;
}

/**
 * Render Assignments Tab
 */
function renderAssignmentsTab(container, data) {
    const assignmentData = data.data;
    let html = '';
    
    // Filter info banner
    if (currentFilters.school || currentFilters.grade) {
        html += '<div class="filter-info-banner">';
        html += '<i class="fa fa-filter"></i> <strong>Filters Active:</strong> ';
        const filters = [];
        if (currentFilters.school) {
            const schoolSelect = document.getElementById('schoolFilter');
            const schoolName = schoolSelect ? schoolSelect.options[schoolSelect.selectedIndex].text : 'Selected School';
            filters.push('<i class="fa fa-building"></i> ' + schoolName);
        }
        if (currentFilters.grade) {
            filters.push('<i class="fa fa-graduation-cap"></i> Grade ' + currentFilters.grade);
        }
        html += filters.join(' • ');
        html += '</div>';
    }
    
    // Stats cards with trends
    html += '<div class="stats-grid">';
    html += createStatCardWithTrend('fa fa-check-circle', assignmentData.completion_rate + '%', 'Completion Rate', assignmentData.completion_trend, 1);
    html += createStatCardWithTrend('fa fa-star', assignmentData.avg_grade + '%', 'Average Grade', assignmentData.grade_trend, 2);
    html += createStatCard('fa fa-clock', assignmentData.ontime_rate + '%', 'On-time Submissions', 3);
    html += createStatCard('fa fa-chart-bar', assignmentData.total_submissions, 'Total Submissions', 4);
    html += '</div>';
    
    // Charts Section (Two columns)
    html += '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin: 20px 0;">';
    
    // Assignment Completion by Course Chart (Left)
    if (data.chartData && data.chartData.labels && data.chartData.labels.length > 0) {
        html += '<div class="chart-card">';
        html += '<h3><i class="fa fa-chart-bar"></i> Completion Rate by Course</h3>';
        html += '<div class="chart-container" style="height: 400px;">';
        html += '<canvas id="assignmentCompletionChart"></canvas>';
        html += '</div>';
        html += '</div>';
    }
    
    // Average Grade Trend Chart (Right)
    if (data.trendData && data.trendData.labels && data.trendData.labels.length > 0) {
        html += '<div class="chart-card">';
        html += '<h3><i class="fa fa-chart-line"></i> Average Grade Trend</h3>';
        html += '<div class="chart-container" style="height: 400px;">';
        html += '<canvas id="assignmentGradeTrendChart"></canvas>';
        html += '</div>';
        html += '</div>';
    }
    
    html += '</div>'; // End charts grid
    
    // AI Insight Box
    html += '<div class="ai-insights-card" style="margin: 20px 0;">';
    html += '<h3><i class="fa fa-lightbulb"></i> Insights</h3>';
    if (assignmentData.completion_rate >= 80) {
        html += '<p><i class="fa fa-chart-line"></i> Excellent assignment completion rate! Students are actively engaging with coursework.</p>';
    } else if (assignmentData.completion_rate >= 60) {
        html += '<p><i class="fa fa-chart-bar"></i> Assignment completion is moderate. Consider additional support strategies.</p>';
    } else {
        html += '<p><i class="fa fa-exclamation-triangle"></i> Assignment completion needs attention. Review assignment difficulty and student support.</p>';
    }
    html += '</div>';
    
    // Data table
    if (assignmentData.assignments && assignmentData.assignments.length > 0) {
        html += '<div class="data-table-wrapper">';
        html += '<table class="data-table">';
        html += '<thead><tr><th>Assignment</th><th>Course</th><th>Submissions</th><th>Completed</th><th>Due Date</th></tr></thead>';
        html += '<tbody>';
        
        assignmentData.assignments.forEach(assignment => {
            const duedate = assignment.duedate > 0 ? new Date(assignment.duedate * 1000).toLocaleDateString() : 'No due date';
            html += `<tr>
                <td>${assignment.name}</td>
                <td>${assignment.coursename}</td>
                <td>${assignment.submissions}</td>
                <td>${assignment.submitted}</td>
                <td>${duedate}</td>
            </tr>`;
        });
        
        html += '</tbody></table></div>';
    } else {
        html += '<div class="empty-state"><p>No assignments found for the selected period.</p></div>';
    }
    
    container.innerHTML = html;
    
    // Render charts if data is available
    setTimeout(() => {
        if (data.chartData && data.chartData.labels && data.chartData.labels.length > 0) {
            renderAssignmentCompletionChart(data.chartData);
        }
        if (data.trendData && data.trendData.labels && data.trendData.labels.length > 0) {
            renderAssignmentGradeTrendChart(data.trendData);
        }
    }, 100);
}

/**
 * Render Assignment Completion by Course Chart
 */
function renderAssignmentCompletionChart(data) {
    const ctx = document.getElementById('assignmentCompletionChart');
    if (!ctx) return;

    // Destroy existing chart if it exists
    if (chartInstances.assignmentCompletion) {
        chartInstances.assignmentCompletion.destroy();
    }

    chartInstances.assignmentCompletion = new Chart(ctx, {
        type: 'bar',
        data: data,
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    backgroundColor: 'rgba(0, 0, 0, 0.8)',
                    padding: 12,
                    bodyFont: {
                        size: 14
                    },
                    callbacks: {
                        label: function(context) {
                            return context.parsed.y + '%';
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    max: 100,
                    ticks: {
                        callback: function(value) {
                            return value + '%';
                        },
                        font: {
                            size: 12
                        }
                    },
                    grid: {
                        color: 'rgba(0, 0, 0, 0.05)',
                        drawBorder: false
                    }
                },
                x: {
                    grid: {
                        display: false,
                        drawBorder: false
                    },
                    ticks: {
                        font: {
                            size: 12
                        }
                    }
                }
            }
        }
    });
}

/**
 * Render Assignment Grade Trend Chart
 */
function renderAssignmentGradeTrendChart(data) {
    const ctx = document.getElementById('assignmentGradeTrendChart');
    if (!ctx) return;

    // Destroy existing chart if it exists
    if (chartInstances.assignmentGradeTrend) {
        chartInstances.assignmentGradeTrend.destroy();
    }

    // Update dataset styling to match mockup
    const updatedData = {
        labels: data.labels,
        datasets: [{
            label: data.datasets[0].label,
            data: data.datasets[0].data,
            borderColor: '#3498db',
            backgroundColor: '#3498db',
            borderWidth: 3,
            fill: false,
            tension: 0.4,
            pointRadius: 5,
            pointHoverRadius: 7,
            pointBackgroundColor: '#3498db',
            pointBorderColor: '#fff',
            pointBorderWidth: 2,
            pointHoverBackgroundColor: '#3498db',
            pointHoverBorderColor: '#fff'
        }]
    };

    chartInstances.assignmentGradeTrend = new Chart(ctx, {
        type: 'line',
        data: updatedData,
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    backgroundColor: 'rgba(0, 0, 0, 0.8)',
                    padding: 12,
                    bodyFont: {
                        size: 14
                    },
                    callbacks: {
                        label: function(context) {
                            return context.parsed.y + '%';
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    max: 100,
                    ticks: {
                        callback: function(value) {
                            return value + '%';
                        },
                        font: {
                            size: 12
                        },
                        stepSize: 20
                    },
                    grid: {
                        color: 'rgba(0, 0, 0, 0.05)',
                        drawBorder: false
                    }
                },
                x: {
                    grid: {
                        display: false,
                        drawBorder: false
                    },
                    ticks: {
                        font: {
                            size: 12
                        }
                    }
                }
            }
        }
    });
}

/**
 * Render Quizzes Tab
 */
function renderQuizzesTab(container, data) {
    const quizData = data.data;
    let html = '';
    
    // Filter info banner
    if (currentFilters.school || currentFilters.grade) {
        html += '<div class="filter-info-banner">';
        html += '<i class="fa fa-filter"></i> <strong>Filters Active:</strong> ';
        const filters = [];
        if (currentFilters.school) {
            const schoolSelect = document.getElementById('schoolFilter');
            const schoolName = schoolSelect ? schoolSelect.options[schoolSelect.selectedIndex].text : 'Selected School';
            filters.push('<i class="fa fa-building"></i> ' + schoolName);
        }
        if (currentFilters.grade) {
            filters.push('<i class="fa fa-graduation-cap"></i> Grade ' + currentFilters.grade);
        }
        html += filters.join(' • ');
        html += '</div>';
    }
    
    // Stats cards
    html += '<div class="stats-grid">';
    html += createStatCard('fa fa-question-circle', quizData.total_quizzes, 'Total Quizzes', 1);
    html += createStatCard('fa fa-bullseye', quizData.avg_score + '%', 'Avg Quiz Score', 2);
    html += createStatCard('fa fa-sync', quizData.avg_attempts_per_student, 'Avg Attempts/Student', 3);
    html += createStatCard('fa fa-chart-line', quizData.total_attempts, 'Total Attempts', 4);
    html += '</div>';
    
    // Quiz Analytics Section
    html += '<div style="margin: 30px 0;">';
    html += '<h2 style="margin-bottom: 20px; color: #2c3e50; font-size: 24px; font-weight: 700;"><i class="fa fa-chart-pie" style="color: #3498db; margin-right: 10px;"></i>Quiz Analytics</h2>';
    
    // Radar Chart: Quiz Score by Competency/Topic
    if (data.radarData && data.radarData.labels && data.radarData.labels.length > 0) {
        html += '<div class="chart-card">';
        html += '<h3><i class="fa fa-spider"></i> Quiz Score by Competency/Topic</h3>';
        html += '<div class="chart-container" style="height: 400px;">';
        html += '<canvas id="quizCompetencyRadarChart"></canvas>';
        html += '</div>';
        html += '</div>';
    }
    
    html += '</div>'; // End analytics section
    
    // AI Insight Box
    html += '<div class="ai-insights-card" style="margin: 20px 0;">';
    html += '<h3><i class="fa fa-lightbulb"></i> Insights</h3>';
    if (quizData.avg_score >= 80) {
        html += '<p><i class="fa fa-bullseye"></i> Outstanding quiz performance! Assessment objectives are being met effectively.</p>';
    } else if (quizData.avg_score >= 60) {
        html += '<p><i class="fa fa-chart-bar"></i> Quiz scores are satisfactory. Consider targeted interventions for improvement.</p>';
    } else {
        html += '<p><i class="fa fa-exclamation-triangle"></i> Quiz scores indicate learning gaps. Review content difficulty and teaching strategies.</p>';
    }
    html += '</div>';
    
    // Data table
    if (quizData.quizzes && quizData.quizzes.length > 0) {
        html += '<div class="data-table-wrapper">';
        html += '<table class="data-table">';
        html += '<thead><tr><th>Quiz</th><th>Course</th><th>Students</th><th>Attempts</th><th>Avg Score</th></tr></thead>';
        html += '<tbody>';
        
        quizData.quizzes.forEach(quiz => {
            const avgscore = quiz.avg_score ? Math.round(quiz.avg_score) + '%' : 'N/A';
            html += `<tr>
                <td>${quiz.name}</td>
                <td>${quiz.coursename}</td>
                <td>${quiz.students}</td>
                <td>${quiz.attempts}</td>
                <td>${avgscore}</td>
            </tr>`;
        });
        
        html += '</tbody></table></div>';
    } else {
        html += '<div class="empty-state"><p>No quizzes found for the selected period.</p></div>';
    }
    
    container.innerHTML = html;
    
    // Render radar chart if data is available
    setTimeout(() => {
        if (data.radarData && data.radarData.labels && data.radarData.labels.length > 0) {
            renderQuizCompetencyRadarChart(data.radarData);
        }
    }, 100);
}

/**
 * Render Quiz Competency Radar Chart
 */
function renderQuizCompetencyRadarChart(data) {
    const ctx = document.getElementById('quizCompetencyRadarChart');
    if (!ctx) return;

    // Destroy existing chart if it exists
    if (chartInstances.quizCompetencyRadar) {
        chartInstances.quizCompetencyRadar.destroy();
    }

    chartInstances.quizCompetencyRadar = new Chart(ctx, {
        type: 'radar',
        data: data,
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    backgroundColor: 'rgba(0, 0, 0, 0.8)',
                    padding: 12,
                    bodyFont: {
                        size: 14
                    },
                    callbacks: {
                        label: function(context) {
                            return 'Score: ' + context.parsed.r + '%';
                        }
                    }
                }
            },
            scales: {
                r: {
                    beginAtZero: true,
                    max: 100,
                    ticks: {
                        callback: function(value) {
                            return value + '%';
                        },
                        font: {
                            size: 11
                        },
                        stepSize: 20,
                        backdropColor: 'transparent'
                    },
                    grid: {
                        color: 'rgba(0, 0, 0, 0.1)'
                    },
                    pointLabels: {
                        font: {
                            size: 12,
                            weight: '600'
                        },
                        color: '#2c3e50'
                    }
                }
            }
        }
    });
}

/**
 * Render Quiz School Column Chart
 */
function renderQuizSchoolColumnChart(data) {
    const ctx = document.getElementById('quizSchoolColumnChart');
    if (!ctx) return;

    // Destroy existing chart if it exists
    if (chartInstances.quizSchoolColumn) {
        chartInstances.quizSchoolColumn.destroy();
    }

    chartInstances.quizSchoolColumn = new Chart(ctx, {
        type: 'bar',
        data: data,
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    backgroundColor: 'rgba(0, 0, 0, 0.8)',
                    padding: 12,
                    bodyFont: {
                        size: 14
                    },
                    callbacks: {
                        label: function(context) {
                            return 'Avg Score: ' + context.parsed.y + '%';
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    max: 100,
                    ticks: {
                        callback: function(value) {
                            return value + '%';
                        },
                        font: {
                            size: 12
                        }
                    },
                    grid: {
                        color: 'rgba(0, 0, 0, 0.05)',
                        drawBorder: false
                    }
                },
                x: {
                    grid: {
                        display: false,
                        drawBorder: false
                    },
                    ticks: {
                        font: {
                            size: 12
                        }
                    }
                }
            }
        }
    });
}

/**
 * Render Overall Grades Tab
 */
function renderOverallGradesTab(container, data) {
    const gradeData = data.data;
    let html = '';
    
    // Stats cards
    html += '<div class="stats-grid">';
    html += createStatCard('fa fa-graduation-cap', gradeData.system_avg + '%', 'System-wide Avg Grade', 1);
    html += createStatCard('fa fa-building', gradeData.school_grades.length, 'Schools Analyzed', 2);
    html += createStatCard('fa fa-medal', gradeData.top_students.length, 'Top Performers', 3);
    html += '</div>';
    
    // Top 5 Students Table
    html += '<div class="top-performers" style="margin: 20px 0;">';
    html += '<h3><i class="fa fa-medal"></i> Top 5 Students</h3>';
    if (gradeData.top_students && gradeData.top_students.length > 0) {
        gradeData.top_students.forEach((student, index) => {
            const rankClass = index === 0 ? 'gold' : (index === 1 ? 'silver' : (index === 2 ? 'bronze' : ''));
            html += `
                <div class="performer-item">
                    <div class="performer-rank ${rankClass}">${student.rank}</div>
                    <div class="performer-info">
                        <div class="performer-name">${student.name}</div>
                        <div class="performer-detail">${student.completed} courses completed</div>
                    </div>
                    <div class="performer-score">${student.avg_grade}%</div>
                </div>
            `;
        });
    } else {
        html += '<p>No student data available.</p>';
    }
    html += '</div>';
    
    // School Grades Bar Chart
    html += '<div class="chart-card" style="margin: 20px 0;">';
    html += '<h3><i class="fa fa-chart-bar"></i> Average Grades by School</h3>';
    html += '<div class="chart-container"><canvas id="schoolGradesChart"></canvas></div>';
    html += '</div>';
    
    container.innerHTML = html;
    
    // Render chart
    setTimeout(() => {
        if (gradeData.school_grades && gradeData.school_grades.length > 0) {
            const ctx = document.getElementById('schoolGradesChart');
            if (ctx && chartInstances.schoolGrades) {
                chartInstances.schoolGrades.destroy();
            }
            
            const labels = gradeData.school_grades.map(s => s.school);
            const dataPoints = gradeData.school_grades.map(s => s.avg_grade);
            
            if (ctx) {
                chartInstances.schoolGrades = new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: labels,
                        datasets: [{
                            label: 'Average Grade (%)',
                            data: dataPoints,
                            backgroundColor: '#3498db'
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
            }
        }
    }, 100);
}

/**
 * Render Competencies Tab
 */
function renderCompetenciesTab(container, data) {
    const compData = data.data;
    let html = '';
    
    // Filter info banner
    if (currentFilters.school || currentFilters.grade) {
        html += '<div class="filter-info-banner">';
        html += '<i class="fa fa-filter"></i> <strong>Filters Active:</strong> ';
        const filters = [];
        if (currentFilters.school) {
            const schoolSelect = document.getElementById('schoolFilter');
            const schoolName = schoolSelect ? schoolSelect.options[schoolSelect.selectedIndex].text : 'Selected School';
            filters.push('<i class="fa fa-building"></i> ' + schoolName);
        }
        if (currentFilters.grade) {
            const gradeSelect = document.getElementById('gradeFilter');
            const gradeName = gradeSelect ? gradeSelect.options[gradeSelect.selectedIndex].text : 'Grade ' + currentFilters.grade;
            filters.push('<i class="fa fa-graduation-cap"></i> ' + gradeName);
        }
        html += filters.join(' • ');
        html += '</div>';
    }
    
    // Stats cards
    html += '<div class="stats-grid">';
    html += createStatCard('fa fa-puzzle-piece', compData.total_competencies, 'Total Competencies Defined', 1);
    html += createStatCard('fa fa-book', compData.total_mapped_competencies || 0, 'Mapped to Courses', 2);
    html += createStatCard('fa fa-book-open', compData.total_courses_with_competencies || 0, 'Courses with Competencies', 3);
    html += createStatCard('fa fa-check-circle', compData.overall_completion_rate + '%', 'Overall Completion Rate', 4);
    html += createStatCard('fa fa-users', compData.total_users_tracked || 0, 'Users Tracked', 5);
    html += createStatCard('fa fa-bullseye', compData.total_proficient_users || 0, 'Proficient Users', 6);
    html += '</div>';
    
    // Summary message
    if (compData.summary && compData.summary.message) {
        html += '<div class="ai-insights-card" style="margin: 20px 0;">';
        html += '<h3><i class="fa fa-info-circle"></i> Summary</h3>';
        html += `<p>${compData.summary.message}</p>`;
        html += '</div>';
    }
    
    // AI Insight Box
    html += '<div class="ai-insights-card" style="margin: 20px 0;">';
    html += '<h3><i class="fa fa-lightbulb"></i> Insights</h3>';
    if (compData.overall_completion_rate >= 75) {
        html += '<p><i class="fa fa-bullseye"></i> Excellent competency completion rates! Students are achieving learning outcomes effectively.</p>';
    } else if (compData.overall_completion_rate >= 50) {
        html += '<p><i class="fa fa-chart-bar"></i> Competency completion is moderate. Consider additional assessment and support strategies.</p>';
    } else if (compData.overall_completion_rate > 0) {
        html += '<p><i class="fa fa-exclamation-triangle"></i> Competency completion needs attention. Review learning objectives and student progress.</p>';
    } else {
        html += '<p><i class="fa fa-info-circle"></i> No competency completion data available yet for the selected filters.</p>';
    }
    html += '</div>';
    
    // Competency table
    if (compData.competencies && compData.competencies.length > 0) {
        html += '<div class="data-table-wrapper">';
        html += '<h3 style="margin: 20px 0 10px 0;"><i class="fa fa-chart-bar"></i> Competency Details</h3>';
        html += '<table class="data-table">';
        html += '<thead><tr>';
        html += '<th>Competency Name</th>';
        html += '<th>ID Number</th>';
        html += '<th>Courses Mapped</th>';
        html += '<th>Total Users</th>';
        html += '<th>Proficient Users</th>';
        html += '<th>Completion Rate</th>';
        html += '<th>Avg Grade</th>';
        html += '</tr></thead>';
        html += '<tbody>';
        
        compData.competencies.forEach(comp => {
            // Determine color based on completion rate
            let rateColor = '#e74c3c'; // red
            if (comp.completion_rate >= 75) {
                rateColor = '#27ae60'; // green
            } else if (comp.completion_rate >= 50) {
                rateColor = '#f39c12'; // orange
            }
            
            html += `<tr>
                <td><strong>${comp.name}</strong></td>
                <td>${comp.idnumber || 'N/A'}</td>
                <td><span class="badge badge-info"><i class="fa fa-book"></i> ${comp.courses_mapped} courses</span></td>
                <td>${comp.total_users}</td>
                <td>${comp.proficient_users}</td>
                <td>
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: ${comp.completion_rate}%; background-color: ${rateColor}"></div>
                    </div>
                    <strong>${comp.completion_rate}%</strong>
                </td>
                <td>${comp.avg_grade}%</td>
            </tr>`;
        });
        
        html += '</tbody></table></div>';
    } else {
        html += '<div class="empty-state">';
        html += '<div class="empty-state-icon"><i class="fa fa-puzzle-piece"></i></div>';
        html += '<h3>No Competency Data</h3>';
        html += '<p>No competencies are mapped to courses for the selected filters.</p>';
        html += '</div>';
    }
    
    container.innerHTML = html;
}

/**
 * Render Performance Tab (Unified Teacher/Student)
 */
function renderPerformanceTab(container, data) {
    let html = '';
    
    // Performance Type Selector
    html += '<div class="filter-info-banner" style="margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between;">';
    html += '<div style="display: flex; align-items: center; gap: 15px;">';
    html += '<i class="fa fa-users"></i> <strong>View Performance:</strong>';
    html += '<select id="performanceTypeSelector" class="form-control" style="width: auto; display: inline-block; padding: 8px 12px; border-radius: 6px; border: 1px solid #ddd;">';
    html += '<option value="teacher" selected>Teachers</option>';
    html += '<option value="student">Students</option>';
    html += '</select>';
    html += '</div>';
    html += '</div>';
    
    // Container for dynamic content
    html += '<div id="performanceDataContainer"></div>';
    
    container.innerHTML = html;
    
    // Add event listener for dropdown change
    document.getElementById('performanceTypeSelector').addEventListener('change', function() {
        const selectedType = this.value;
        loadPerformanceData(selectedType);
    });
    
    // Load initial data (teachers by default)
    loadPerformanceData('teacher');
}

/**
 * Load performance data based on type (teacher or student)
 */
function loadPerformanceData(type) {
    const container = document.getElementById('performanceDataContainer');
    container.innerHTML = '<div class="loading-spinner"><i class="fa fa-spinner fa-spin"></i> Loading data...</div>';
    
    const sesskey = M.cfg.sesskey;
    const tabName = type === 'teacher' ? 'teacher-performance' : 'student-performance';
    
    const params = new URLSearchParams({
        tab: tabName,
        school: currentFilters.school,
        cohort: currentFilters.cohort,
        grade: currentFilters.grade,
        daterange: currentFilters.dateRange,
        startdate: currentFilters.startDate,
        enddate: currentFilters.endDate,
        sesskey: sesskey
    });
    
    const ajaxUrl = M.cfg.wwwroot + '/theme/remui_kids/admin/superreports/ajax_data.php?' + params.toString();
    
    fetch(ajaxUrl)
        .then(response => response.json())
        .then(data => {
            console.log('Performance Data Received:', type, data);
            if (data.success) {
                // Cache data for CSV export
                tabDataCache['performance'] = data;
                
                if (type === 'teacher') {
                    renderTeacherPerformanceData(container, data);
                } else {
                    console.log('Student Performance - Donut Data:', data.donutData);
                    console.log('Student Performance - Heatmap Data:', data.heatmapData);
                    renderStudentPerformanceData(container, data);
                }
            } else {
                container.innerHTML = `<div class="empty-state"><p>Error: ${data.error}</p></div>`;
            }
        })
        .catch(error => {
            console.error('Error loading performance data:', error);
            container.innerHTML = `<div class="empty-state"><p>Failed to load data: ${error.message}</p></div>`;
        });
}

/**
 * Render Teacher Performance Data
 */
function renderTeacherPerformanceData(container, data) {
    const teacherData = data.data;
    let html = '';
    
    if (!teacherData || teacherData.length === 0) {
        const message = data.total_count === 0 
            ? 'No teachers found for the selected school/cohort. Please check if teachers are assigned to this school.'
            : 'Teachers found but no performance data available for the selected period.';
        container.innerHTML = `<div class="empty-state">
            <p><i class="fa fa-info-circle"></i> ${message}</p>
        </div>`;
        return;
    }
    
    // Calculate averages
    let totalEngagement = 0, totalChange = 0;
    teacherData.forEach(t => {
        totalEngagement += t.engagement;
        totalChange += t.change;
    });
    
    const avgEngagement = (totalEngagement / teacherData.length).toFixed(0);
    const avgChange = (totalChange / teacherData.length).toFixed(1);
    
    // Stats cards
    html += '<div class="stats-grid">';
    html += createStatCard('fa fa-chalkboard-teacher', teacherData.length, 'Teachers Analyzed', 1);
    html += createStatCard('fa fa-chart-bar', avgEngagement, 'Avg Engagement Score', 2);
    html += createStatCard('fa fa-chart-line', avgChange + '%', 'Avg Change vs Previous', 3);
    html += '</div>';
    
    // Scatter Chart: Teacher Engagement vs Student Completion
    if (data.scatterData && data.scatterData.datasets && data.scatterData.datasets.length > 0) {
        html += '<div class="chart-card" style="margin: 20px 0;">';
        html += '<h3><i class="fa fa-chart-scatter"></i> Teacher Engagement vs Student Completion</h3>';
        html += '<div class="chart-container" style="height: 500px;">';
        html += '<canvas id="teacherEngagementScatterChart"></canvas>';
        html += '</div>';
        html += '</div>';
    }
    
    // AI Insight
    html += '<div class="ai-insights-card" style="margin: 20px 0;">';
    html += '<h3><i class="fa fa-lightbulb"></i> Insights</h3>';
    const improved = teacherData.filter(t => t.change > 10).length;
    if (improved > 0) {
        html += `<p><i class="fa fa-thumbs-up"></i> ${improved} teachers showed significant improvement (+10% or more) in engagement this period.</p>`;
    } else {
        html += '<p><i class="fa fa-chart-bar"></i> Teacher engagement is stable. Consider professional development opportunities.</p>';
    }
    html += '</div>';
    
    // Data table
    html += '<div class="data-table-wrapper">';
    html += '<table class="data-table">';
    html += '<thead><tr><th>Teacher</th><th>Courses</th><th>Current Engagement</th><th>Previous Period</th><th>Change (%)</th></tr></thead>';
    html += '<tbody>';
    
    teacherData.forEach(teacher => {
        const changeClass = teacher.change > 0 ? 'status-active' : (teacher.change < 0 ? 'status-inactive' : 'status-warning');
        const changeIcon = teacher.change > 0 ? '<i class="fa fa-arrow-up"></i>' : (teacher.change < 0 ? '<i class="fa fa-arrow-down"></i>' : '<i class="fa fa-arrow-right"></i>');
        html += `<tr>
            <td>${teacher.name}</td>
            <td>${teacher.courses}</td>
            <td>${teacher.engagement}</td>
            <td>${teacher.prev_engagement}</td>
            <td><span class="status-badge ${changeClass}">${changeIcon} ${teacher.change}%</span></td>
        </tr>`;
    });
    
    html += '</tbody></table></div>';
    
    container.innerHTML = html;
    
    // Render scatter chart if data is available
    if (data.scatterData && data.scatterData.datasets && data.scatterData.datasets.length > 0) {
        setTimeout(() => {
            renderTeacherEngagementScatterChart(data.scatterData);
        }, 100);
    }
}

/**
 * Render Teacher Engagement vs Student Completion Scatter Chart
 */
function renderTeacherEngagementScatterChart(data) {
    const ctx = document.getElementById('teacherEngagementScatterChart');
    if (!ctx) return;

    // Destroy existing chart if it exists
    if (chartInstances.teacherEngagementScatter) {
        chartInstances.teacherEngagementScatter.destroy();
    }

    chartInstances.teacherEngagementScatter = new Chart(ctx, {
        type: 'scatter',
        data: data,
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    backgroundColor: 'rgba(0, 0, 0, 0.8)',
                    padding: 12,
                    bodyFont: {
                        size: 14
                    },
                    callbacks: {
                        label: function(context) {
                            const point = context.raw;
                            return [
                                point.label || 'Teacher',
                                'Engagement: ' + point.x + ' activities',
                                'Student Completion: ' + point.y + '%'
                            ];
                        }
                    }
                }
            },
            scales: {
                x: {
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Teacher Engagement (Activity Count)',
                        font: {
                            size: 14,
                            weight: '600'
                        }
                    },
                    ticks: {
                        font: {
                            size: 12
                        }
                    },
                    grid: {
                        color: 'rgba(0, 0, 0, 0.05)',
                        drawBorder: false
                    }
                },
                y: {
                    beginAtZero: true,
                    max: 100,
                    title: {
                        display: true,
                        text: 'Student Completion Rate (%)',
                        font: {
                            size: 14,
                            weight: '600'
                        }
                    },
                    ticks: {
                        callback: function(value) {
                            return value + '%';
                        },
                        font: {
                            size: 12
                        }
                    },
                    grid: {
                        color: 'rgba(0, 0, 0, 0.05)',
                        drawBorder: false
                    }
                }
            }
        }
    });
}

/**
 * Render Student Performance Data
 */
function renderStudentPerformanceData(container, data) {
    const studentData = data.data;
    let html = '';
    
    if (!studentData || studentData.length === 0) {
        container.innerHTML = '<div class="empty-state"><p>No student data available.</p></div>';
        return;
    }
    
    // Calculate stats
    let totalEnrolled = 0, totalGrade = 0, totalCompletion = 0, activeCount = 0;
    studentData.forEach(s => {
        totalEnrolled += s.enrolled;
        totalGrade += s.avg_grade;
        totalCompletion += s.completion;
        if (s.status === 'active') activeCount++;
    });
    
    const avgEnrolled = (totalEnrolled / studentData.length).toFixed(1);
    const avgGrade = (totalGrade / studentData.length).toFixed(1);
    const avgCompletion = (totalCompletion / studentData.length).toFixed(1);
    
    // Stats cards
    html += '<div class="stats-grid">';
    html += createStatCard('fa fa-user-graduate', studentData.length, 'Students Analyzed', 1);
    html += createStatCard('fa fa-book', avgEnrolled, 'Avg Enrolled Courses', 2);
    html += createStatCard('fa fa-star', avgGrade + '%', 'Avg Grade', 3);
    html += createStatCard('fa fa-check-circle', avgCompletion + '%', 'Avg Completion', 4);
    html += createStatCard('fa fa-user-check', activeCount, 'Active Students', 5);
    html += '</div>';
    
    // Charts Section (Two columns)
    html += '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin: 20px 0;">';
    
    // Course Completion Brackets Donut Chart (Left)
    html += '<div class="chart-card">';
    html += '<h3><i class="fa fa-chart-pie"></i> Course Completion Brackets</h3>';
    html += '<div class="chart-container" style="height: 400px;">';
    html += '<canvas id="courseCompletionDonutChart"></canvas>';
    html += '</div>';
    html += '</div>';
    
    // Learning Activity Heatmap (Right)
    html += '<div class="chart-card">';
    html += '<h3><i class="fa fa-calendar-alt"></i> Learning Activity by Day/Time</h3>';
    html += '<div class="chart-container" style="height: 400px;">';
    html += '<canvas id="learningActivityHeatmapChart"></canvas>';
    html += '</div>';
    html += '</div>';
    
    html += '</div>'; // End charts grid
    
    // Data table
    html += '<div class="data-table-wrapper">';
    html += '<table class="data-table">';
    html += '<thead><tr><th>Student</th><th>Enrolled</th><th>Avg Grade</th><th>Completion</th><th>Status</th></tr></thead>';
    html += '<tbody>';
    
    studentData.forEach(student => {
        const statusClass = student.status === 'active' ? 'status-active' : (student.status === 'warning' ? 'status-warning' : 'status-inactive');
        html += `<tr>
            <td>${student.name}</td>
            <td>${student.enrolled}</td>
            <td>${student.avg_grade}%</td>
            <td>
                <div class="progress-bar">
                    <div class="progress-fill" style="width: ${student.completion}%"></div>
                </div>
                ${student.completion}%
            </td>
            <td><span class="status-badge ${statusClass}">${student.status}</span></td>
        </tr>`;
    });
    
    html += '</tbody></table></div>';
    
    container.innerHTML = html;
    
    // Render charts if data is available
    if (data.donutData && data.donutData.labels && data.donutData.labels.length > 0) {
        console.log('Attempting to render donut chart...');
        setTimeout(() => {
            const canvas = document.getElementById('courseCompletionDonutChart');
            console.log('Donut canvas found:', canvas);
            renderCourseCompletionDonutChart(data.donutData);
        }, 100);
    } else {
        console.log('Donut data not available or empty');
    }
    
    if (data.heatmapData && data.heatmapData.labels && data.heatmapData.labels.length > 0) {
        console.log('Attempting to render heatmap chart...');
        setTimeout(() => {
            const canvas = document.getElementById('learningActivityHeatmapChart');
            console.log('Heatmap canvas found:', canvas);
            renderLearningActivityHeatmapChart(data.heatmapData);
        }, 100);
    } else {
        console.log('Heatmap data not available or empty');
    }
}

/**
 * Render Course Completion Brackets Donut Chart
 */
function renderCourseCompletionDonutChart(data) {
    const ctx = document.getElementById('courseCompletionDonutChart');
    if (!ctx) return;

    // Destroy existing chart if it exists
    if (chartInstances.courseCompletionDonut) {
        chartInstances.courseCompletionDonut.destroy();
    }

    chartInstances.courseCompletionDonut = new Chart(ctx, {
        type: 'doughnut',
        data: data,
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        padding: 20,
                        font: {
                            size: 12
                        }
                    }
                },
                tooltip: {
                    backgroundColor: 'rgba(0, 0, 0, 0.8)',
                    padding: 12,
                    bodyFont: {
                        size: 14
                    },
                    callbacks: {
                        label: function(context) {
                            const label = context.label || '';
                            const value = context.parsed;
                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                            const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                            return `${label}: ${value} students (${percentage}%)`;
                        }
                    }
                }
            },
            cutout: '60%'
        }
    });
}

/**
 * Render Learning Activity Heatmap Chart
 */
function renderLearningActivityHeatmapChart(data) {
    const ctx = document.getElementById('learningActivityHeatmapChart');
    if (!ctx) return;

    // Destroy existing chart if it exists
    if (chartInstances.learningActivityHeatmap) {
        chartInstances.learningActivityHeatmap.destroy();
    }

    chartInstances.learningActivityHeatmap = new Chart(ctx, {
        type: 'bar',
        data: data,
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: true,
                    position: 'top',
                    labels: {
                        padding: 20,
                        font: {
                            size: 12
                        }
                    }
                },
                tooltip: {
                    backgroundColor: 'rgba(0, 0, 0, 0.8)',
                    padding: 12,
                    bodyFont: {
                        size: 14
                    },
                    callbacks: {
                        title: function(context) {
                            return context[0].dataset.label + ' at ' + context[0].label;
                        },
                        label: function(context) {
                            return 'Active Students: ' + context.parsed.y;
                        }
                    }
                }
            },
            scales: {
                x: {
                    title: {
                        display: true,
                        text: 'Time of Day',
                        font: {
                            size: 14,
                            weight: '600'
                        }
                    },
                    ticks: {
                        font: {
                            size: 11
                        }
                    },
                    grid: {
                        display: false
                    }
                },
                y: {
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Active Students',
                        font: {
                            size: 14,
                            weight: '600'
                        }
                    },
                    ticks: {
                        font: {
                            size: 11
                        }
                    },
                    grid: {
                        color: 'rgba(0, 0, 0, 0.05)',
                        drawBorder: false
                    }
                }
            }
        }
    });
}

/**
 * Render Teacher Performance Tab
 */
function renderTeacherPerformanceTab(container, data) {
    const teacherData = data.data;
    let html = '';
    
    // Debug logging
    console.log('Teacher Performance Data Received:', {
        total_count: data.total_count,
        displayed_count: data.displayed_count,
        teachers_array_length: teacherData ? teacherData.length : 0,
        teachers: teacherData
    });
    
    if (!teacherData || teacherData.length === 0) {
        const message = data.total_count === 0 
            ? 'No teachers found for the selected school/cohort. Please check if teachers are assigned to this school.'
            : 'Teachers found but no performance data available for the selected period.';
        container.innerHTML = `<div class="empty-state">
            <p><i class="fa fa-info-circle"></i> ${message}</p>
            <p style="font-size: 0.9em; color: #666; margin-top: 10px;">
                ${data.total_count === 0 ? 'Hint: Ensure teachers are assigned to this school in the system.' : 'Hint: Try adjusting the date range or check if teachers have course assignments.'}
            </p>
        </div>`;
        return;
    }
    
    
    // Calculate averages
    let totalEngagement = 0, totalChange = 0;
    teacherData.forEach(t => {
        totalEngagement += t.engagement;
        totalChange += t.change;
    });
    
    const avgEngagement = (totalEngagement / teacherData.length).toFixed(0);
    const avgChange = (totalChange / teacherData.length).toFixed(1);
    
    // Stats cards
    html += '<div class="stats-grid">';
    html += createStatCard('fa fa-chalkboard-teacher', teacherData.length, 'Teachers Analyzed', 1);
    html += createStatCard('fa fa-chart-bar', avgEngagement, 'Avg Engagement Score', 2);
    html += createStatCard('fa fa-chart-line', avgChange + '%', 'Avg Change vs Previous', 3);
    html += '</div>';
    
    // AI Insight
    html += '<div class="ai-insights-card" style="margin: 20px 0;">';
    html += '<h3><i class="fa fa-lightbulb"></i> Insights</h3>';
    const improved = teacherData.filter(t => t.change > 10).length;
    if (improved > 0) {
        html += `<p><i class="fa fa-thumbs-up"></i> ${improved} teachers showed significant improvement (+10% or more) in engagement this period.</p>`;
    } else {
        html += '<p><i class="fa fa-chart-bar"></i> Teacher engagement is stable. Consider professional development opportunities.</p>';
    }
    html += '</div>';
    
    // Data table
    html += '<div class="data-table-wrapper">';
    html += '<table class="data-table">';
    html += '<thead><tr><th>Teacher</th><th>Courses</th><th>Current Engagement</th><th>Previous Period</th><th>Change (%)</th></tr></thead>';
    html += '<tbody>';
    
    teacherData.forEach(teacher => {
        const changeClass = teacher.change > 0 ? 'status-active' : (teacher.change < 0 ? 'status-inactive' : 'status-warning');
        const changeIcon = teacher.change > 0 ? '<i class="fa fa-arrow-up"></i>' : (teacher.change < 0 ? '<i class="fa fa-arrow-down"></i>' : '<i class="fa fa-arrow-right"></i>');
        html += `<tr>
            <td>${teacher.name}</td>
            <td>${teacher.courses}</td>
            <td>${teacher.engagement}</td>
            <td>${teacher.prev_engagement}</td>
            <td><span class="status-badge ${changeClass}">${changeIcon} ${teacher.change}%</span></td>
        </tr>`;
    });
    
    html += '</tbody></table></div>';
    
    container.innerHTML = html;
}

/**
 * Render Student Performance Tab
 */
function renderStudentPerformanceTab(container, data) {
    const studentData = data.data;
    let html = '';
    
    if (!studentData || studentData.length === 0) {
        container.innerHTML = '<div class="empty-state"><p>No student data available.</p></div>';
        return;
    }
    
    // Filter info banner
    if (currentFilters.school || currentFilters.grade) {
        html += '<div class="filter-info-banner">';
        html += '<i class="fa fa-filter"></i> <strong>Filters Active:</strong> ';
        const filters = [];
        if (currentFilters.school) {
            const schoolSelect = document.getElementById('schoolFilter');
            const schoolName = schoolSelect ? schoolSelect.options[schoolSelect.selectedIndex].text : 'Selected School';
            filters.push('<i class="fa fa-building"></i> ' + schoolName);
        }
        if (currentFilters.grade) {
            filters.push('<i class="fa fa-graduation-cap"></i> Grade ' + currentFilters.grade);
        }
        html += filters.join(' • ');
        html += '</div>';
    }
    
    // Calculate stats
    let totalEnrolled = 0, totalGrade = 0, totalCompletion = 0, activeCount = 0;
    studentData.forEach(s => {
        totalEnrolled += s.enrolled;
        totalGrade += s.avg_grade;
        totalCompletion += s.completion;
        if (s.status === 'active') activeCount++;
    });
    
    const avgEnrolled = (totalEnrolled / studentData.length).toFixed(1);
    const avgGrade = (totalGrade / studentData.length).toFixed(1);
    const avgCompletion = (totalCompletion / studentData.length).toFixed(1);
    
    // Stats cards
    html += '<div class="stats-grid">';
    html += createStatCard('fa fa-user-graduate', studentData.length, 'Students Analyzed', 1);
    html += createStatCard('fa fa-book', avgEnrolled, 'Avg Enrolled Courses', 2);
    html += createStatCard('fa fa-star', avgGrade + '%', 'Avg Grade', 3);
    html += createStatCard('fa fa-check-circle', avgCompletion + '%', 'Avg Completion', 4);
    html += createStatCard('fa fa-user-check', activeCount, 'Active Students', 5);
    html += '</div>';
    
    // Charts Section (Two columns)
    html += '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin: 20px 0;">';
    
    // Course Completion Brackets Donut Chart (Left)
    if (data.donutData && data.donutData.labels && data.donutData.labels.length > 0) {
        html += '<div class="chart-card">';
        html += '<h3><i class="fa fa-chart-pie"></i> Course Completion Brackets</h3>';
        html += '<div class="chart-container" style="height: 400px;">';
        html += '<canvas id="courseCompletionDonutChart"></canvas>';
        html += '</div>';
        html += '</div>';
    }
    
    // Learning Activity Heatmap (Right)
    if (data.heatmapData && data.heatmapData.labels && data.heatmapData.labels.length > 0) {
        html += '<div class="chart-card">';
        html += '<h3><i class="fa fa-calendar-alt"></i> Learning Activity by Day/Time</h3>';
        html += '<div class="chart-container" style="height: 400px;">';
        html += '<canvas id="learningActivityHeatmapChart"></canvas>';
        html += '</div>';
        html += '</div>';
    }
    
    html += '</div>'; // End charts grid
    
    // Data table
    html += '<div class="data-table-wrapper">';
    html += '<table class="data-table">';
    html += '<thead><tr><th>Student</th><th>Enrolled</th><th>Avg Grade</th><th>Completion</th><th>Status</th></tr></thead>';
    html += '<tbody>';
    
    studentData.forEach(student => {
        const statusClass = student.status === 'active' ? 'status-active' : (student.status === 'warning' ? 'status-warning' : 'status-inactive');
        html += `<tr>
            <td>${student.name}</td>
            <td>${student.enrolled}</td>
            <td>${student.avg_grade}%</td>
            <td>
                <div class="progress-bar">
                    <div class="progress-fill" style="width: ${student.completion}%"></div>
                </div>
                ${student.completion}%
            </td>
            <td><span class="status-badge ${statusClass}">${student.status}</span></td>
        </tr>`;
    });
    
    html += '</tbody></table></div>';
    
    container.innerHTML = html;
    
    // Render charts if data is available
    if (data.donutData && data.donutData.labels && data.donutData.labels.length > 0) {
        setTimeout(() => {
            renderCourseCompletionDonutChart(data.donutData);
        }, 100);
    }
    
    if (data.heatmapData && data.heatmapData.labels && data.heatmapData.labels.length > 0) {
        setTimeout(() => {
            renderLearningActivityHeatmapChart(data.heatmapData);
        }, 100);
    }
}

/**
 * Render Courses Tab
 */
function renderCoursesTab(container, data) {
    let html = '';
    
    // KPI Summary Cards (5 cards matching the image)
    html += '<div class="stats-grid" style="grid-template-columns: repeat(5, 1fr); margin-bottom: 30px;">';
    html += createStatCard('fa fa-book', data.kpi.active_courses || 0, 'Active Courses', 1);
    html += createStatCard('fa fa-user-plus', data.kpi.avg_enrollment || 0, 'Avg. Enroll. Per', 2);
    html += createStatCard('fa fa-check-circle', (data.kpi.avg_completion || 0) + '%', 'Avg. Completion', 3);
    html += createStatCard('fa fa-clock', (data.kpi.avg_time_to_complete || 0) + ' days', 'Avg. Time to Complete', 4);
    html += createStatCard('fa fa-times-circle', (data.kpi.dropout_rate || 0) + '%', 'Dropout Rate', 5);
    html += '</div>';
    
    // First Row: Course Enrollment Trend (Line) + Completion vs Dropout (Stacked Bar)
    html += '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin: 20px 0;">';
    
    // Course Enrollment Trend (Line Chart)
    html += '<div class="chart-card">';
    html += '<h3><i class="fa fa-chart-line"></i> Course Enrollment Trend</h3>';
    html += '<div class="chart-container" style="height: 350px;">';
    html += '<canvas id="courseEnrollmentTrendChart"></canvas>';
    html += '</div>';
    html += '</div>';
    
    // Completion vs Dropout (Stacked Bar Chart)
    html += '<div class="chart-card">';
    html += '<h3><i class="fa fa-chart-bar"></i> Completion vs Dropout</h3>';
    html += '<div class="chart-container" style="height: 350px;">';
    html += '<canvas id="completionVsDropoutChart"></canvas>';
    html += '</div>';
    html += '</div>';
    
    html += '</div>'; // End first row
    
    // Second Row: Top 10 Courses Table + Course Distribution (Donut)
    html += '<div style="display: grid; grid-template-columns: 1.5fr 1fr; gap: 20px; margin: 20px 0;">';
    
    // Top 10 Performing Courses Table
    html += '<div class="chart-card">';
    html += '<h3><i class="fa fa-trophy"></i> Top 10 Performing Courses</h3>';
    html += '<div style="overflow-x: auto; max-height: 400px;">';
    html += '<table class="data-table">';
    html += '<thead><tr><th>Course Name</th><th>Avg Grade</th><th>Completion</th><th>Score</th></tr></thead>';
    html += '<tbody>';
    
    if (data.topCourses && data.topCourses.length > 0) {
        data.topCourses.forEach(course => {
            html += `<tr>
                <td><strong>${course.name}</strong></td>
                <td>${course.avg_grade}%</td>
                <td>${course.completion}%</td>
                <td><span class="status-badge status-active">${course.score}%</span></td>
            </tr>`;
        });
    } else {
        html += '<tr><td colspan="4" style="text-align: center;">No data available</td></tr>';
    }
    
    html += '</tbody></table></div></div>';
    
    // Course Distribution by Category (Donut Chart)
    html += '<div class="chart-card">';
    html += '<h3><i class="fa fa-chart-pie"></i> Course Distribution by Category</h3>';
    html += '<div class="chart-container" style="height: 400px;">';
    html += '<canvas id="courseDistributionDonutChart"></canvas>';
    html += '</div>';
    html += '</div>';
    
    html += '</div>'; // End second row
    
    // Third Row: Course Engagement vs Completion (Bubble) + Insight Summary
    html += '<div style="display: grid; grid-template-columns: 1.5fr 1fr; gap: 20px; margin: 20px 0;">';
    
    // Course Engagement vs Completion (Bubble Chart)
    html += '<div class="chart-card">';
    html += '<h3><i class="fa fa-bullseye"></i> Course Engagement vs Completion</h3>';
    html += '<div class="chart-container" style="height: 400px;">';
    html += '<canvas id="engagementVsCompletionBubbleChart"></canvas>';
    html += '</div>';
    html += '</div>';
    
    // Insight Summary
    html += '<div class="chart-card">';
    html += '<h3><i class="fa fa-lightbulb"></i> Insight Summary</h3>';
    html += '<div style="padding: 20px; font-size: 14px; line-height: 1.8;">';
    
    if (data.insights && data.insights.length > 0) {
        data.insights.forEach(insight => {
            html += `<div style="margin-bottom: 15px; padding: 10px; background: #f8f9fa; border-left: 3px solid #3498db; border-radius: 4px;">
                <i class="fa fa-info-circle" style="color: #3498db; margin-right: 8px;"></i>
                ${insight}
            </div>`;
        });
    } else {
        html += '<p style="color: #999;">No insights available at this time.</p>';
    }
    
    html += '</div></div>';
    
    html += '</div>'; // End third row
    
    container.innerHTML = html;
    
    // Render all charts
    setTimeout(() => {
        if (data.enrollmentTrend) renderCourseEnrollmentTrendChart(data.enrollmentTrend);
        if (data.completionVsDropout) renderCompletionVsDropoutChart(data.completionVsDropout);
        if (data.categoryDistribution) renderCourseDistributionDonut(data.categoryDistribution);
        if (data.engagementVsCompletion) renderEngagementVsCompletionBubble(data.engagementVsCompletion);
    }, 100);
}

/**
 * Render Course Enrollment Trend Chart (Line Chart)
 */
function renderCourseEnrollmentTrendChart(data) {
    const ctx = document.getElementById('courseEnrollmentTrendChart');
    if (!ctx) return;

    if (chartInstances.courseEnrollmentTrend) {
        chartInstances.courseEnrollmentTrend.destroy();
    }

    chartInstances.courseEnrollmentTrend = new Chart(ctx, {
        type: 'line',
        data: data,
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: true,
                    position: 'top'
                },
                tooltip: {
                    backgroundColor: 'rgba(0, 0, 0, 0.8)',
                    padding: 12
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Enrollments'
                    }
                },
                x: {
                    title: {
                        display: true,
                        text: 'Month'
                    }
                }
            }
        }
    });
}

/**
 * Render Completion vs Dropout Chart (Stacked Bar)
 */
function renderCompletionVsDropoutChart(data) {
    const ctx = document.getElementById('completionVsDropoutChart');
    if (!ctx) return;

    if (chartInstances.completionVsDropout) {
        chartInstances.completionVsDropout.destroy();
    }

    chartInstances.completionVsDropout = new Chart(ctx, {
        type: 'bar',
        data: data,
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: true,
                    position: 'top'
                },
                tooltip: {
                    backgroundColor: 'rgba(0, 0, 0, 0.8)',
                    padding: 12
                }
            },
            scales: {
                x: {
                    stacked: true
                },
                y: {
                    stacked: true,
                    beginAtZero: true,
                    max: 100,
                    title: {
                        display: true,
                        text: 'Percentage'
                    }
                }
            }
        }
    });
}

/**
 * Render Course Distribution by Category (Donut)
 */
function renderCourseDistributionDonut(data) {
    const ctx = document.getElementById('courseDistributionDonutChart');
    if (!ctx) return;

    if (chartInstances.courseDistributionDonut) {
        chartInstances.courseDistributionDonut.destroy();
    }

    chartInstances.courseDistributionDonut = new Chart(ctx, {
        type: 'doughnut',
        data: data,
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        padding: 15,
                        font: {
                            size: 12
                        }
                    }
                },
                tooltip: {
                    backgroundColor: 'rgba(0, 0, 0, 0.8)',
                    padding: 12,
                    callbacks: {
                        label: function(context) {
                            const label = context.label || '';
                            const value = context.parsed;
                            return label + ': ' + value + '%';
                        }
                    }
                }
            },
            cutout: '60%'
        }
    });
}

/**
 * Render Engagement vs Completion Bubble Chart
 */
function renderEngagementVsCompletionBubble(data) {
    const ctx = document.getElementById('engagementVsCompletionBubbleChart');
    if (!ctx) return;

    if (chartInstances.engagementVsCompletionBubble) {
        chartInstances.engagementVsCompletionBubble.destroy();
    }

    chartInstances.engagementVsCompletionBubble = new Chart(ctx, {
        type: 'bubble',
        data: data,
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    backgroundColor: 'rgba(0, 0, 0, 0.8)',
                    padding: 12,
                    callbacks: {
                        label: function(context) {
                            const point = context.raw;
                            return [
                                point.label || 'Course',
                                'Engagement: ' + point.x + '%',
                                'Completion: ' + point.y + '%',
                                'Enrolled: ' + (point.r * 2)
                            ];
                        }
                    }
                }
            },
            scales: {
                x: {
                    beginAtZero: true,
                    max: 100,
                    title: {
                        display: true,
                        text: 'Avg Engagement %',
                        font: {
                            size: 14,
                            weight: '600'
                        }
                    }
                },
                y: {
                    beginAtZero: true,
                    max: 100,
                    title: {
                        display: true,
                        text: 'Completion %',
                        font: {
                            size: 14,
                            weight: '600'
                        }
                    }
                }
            }
        }
    });
}

/**
 * Render Activity Tab
 */
function renderActivityTab(container, data) {
    let html = '<h3>Recent System Activity</h3>';
    html += '<div class="activity-feed">' + renderActivityFeed(data.activities) + '</div>';
    
    container.innerHTML = html;
}

/**
 * Render Attendance Tab
 */
function renderAttendanceTab(container, data) {
    container.innerHTML = `
        <div class="empty-state">
            <div class="empty-state-icon"><i class="fa fa-calendar-check"></i></div>
            <h3>Attendance & Login Reports</h3>
            <p>${data.message || 'Attendance data will be displayed here'}</p>
        </div>
    `;
}

/**
 * Chart rendering functions
 */
function renderActivityTrendChart(data) {
    const ctx = document.getElementById('activityTrendChart');
    if (!ctx) return;
    
    if (chartInstances.activityTrend) {
        chartInstances.activityTrend.destroy();
    }
    
    chartInstances.activityTrend = new Chart(ctx, {
        type: 'bar',
        data: data,
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'top'
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            let label = context.dataset.label || '';
                            if (label) {
                                label += ': ';
                            }
                            if (context.parsed.y !== null) {
                                if (context.dataset.label === 'Active Students') {
                                    label += context.parsed.y + ' students';
                                } else {
                                    label += context.parsed.y + '%';
                                }
                            }
                            return label;
                        }
                    }
                }
            },
            scales: {
                y: {
                    type: 'linear',
                    display: true,
                    position: 'left',
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Number of Students'
                    }
                },
                y1: {
                    type: 'linear',
                    display: true,
                    position: 'right',
                    beginAtZero: true,
                    max: 100,
                    title: {
                        display: true,
                        text: 'Percentage (%)'
                    },
                    grid: {
                        drawOnChartArea: false
                    }
                }
            }
        }
    });
}

function renderCourseCompletionChart(data) {
    const ctx = document.getElementById('courseCompletionChart');
    if (!ctx) return;
    
    if (chartInstances.courseCompletion) {
        chartInstances.courseCompletion.destroy();
    }
    
    chartInstances.courseCompletion = new Chart(ctx, {
        type: 'bar',
        data: data,
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    max: 100
                }
            }
        }
    });
}

function renderUsersByRoleChart(data) {
    const ctx = document.getElementById('usersByRoleChart');
    if (!ctx) return;
    
    if (chartInstances.usersByRole) {
        chartInstances.usersByRole.destroy();
    }
    
    chartInstances.usersByRole = new Chart(ctx, {
        type: 'pie',
        data: data,
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom'
                }
            }
        }
    });
}

/**
 * CSV Export Functions - Export all detailed data
 */

// Utility function to escape CSV values
function escapeCSV(value) {
    if (value === null || value === undefined) return '';
    const stringValue = String(value);
    if (stringValue.includes(',') || stringValue.includes('"') || stringValue.includes('\n')) {
        return '"' + stringValue.replace(/"/g, '""') + '"';
    }
    return stringValue;
}

// Utility function to download CSV
function downloadCSV(csvContent, filename) {
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    const url = URL.createObjectURL(blob);
    link.setAttribute('href', url);
    link.setAttribute('download', filename);
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

// Generate Overview CSV
function generateOverviewCSV(data) {
    let csv = 'Overview Report\n\n';
    
    // Statistics Summary
    csv += 'Summary Statistics\n';
    csv += 'Metric,Value\n';
    if (data.stats) {
        csv += `Total Students,${data.stats.total_students || 0}\n`;
        csv += `Total Teachers,${data.stats.total_teachers || 0}\n`;
        csv += `Total Courses,${data.stats.total_courses || 0}\n`;
        csv += `Active Users,${data.stats.active_users || 0}\n`;
        csv += `Course Completion Rate,${data.stats.completion_rate || 0}%\n`;
    }
    
    csv += '\n\n';
    
    // Users by Role
    if (data.usersByRole && data.usersByRole.labels) {
        csv += 'Users by Role\n';
        csv += 'Role,Count\n';
        const usersByRoleData = data.usersByRole.datasets && data.usersByRole.datasets[0] && data.usersByRole.datasets[0].data 
            ? data.usersByRole.datasets[0].data 
            : (data.usersByRole.data || []);
        data.usersByRole.labels.forEach((label, index) => {
            csv += `${escapeCSV(label)},${usersByRoleData[index] || 0}\n`;
        });
        csv += '\n\n';
    }
    
    // Course Completion by School
    if (data.courseCompletion && data.courseCompletion.labels) {
        csv += 'Course Completion by School\n';
        csv += 'School,Completion Rate (%)\n';
        const courseCompletionData = data.courseCompletion.datasets && data.courseCompletion.datasets[0] && data.courseCompletion.datasets[0].data 
            ? data.courseCompletion.datasets[0].data 
            : (data.courseCompletion.data || []);
        data.courseCompletion.labels.forEach((label, index) => {
            csv += `${escapeCSV(label)},${courseCompletionData[index] || 0}\n`;
        });
        csv += '\n\n';
    }
    
    // Recent Activity
    if (data.recentActivity && data.recentActivity.length > 0) {
        csv += 'Recent Activity Log\n';
        csv += 'User,Action,Target,Time\n';
        data.recentActivity.forEach(activity => {
            csv += `${escapeCSV(activity.user)},${escapeCSV(activity.action)},${escapeCSV(activity.target)},${escapeCSV(activity.time)}\n`;
        });
    }
    
    return csv;
}

// Generate Assignments CSV
function generateAssignmentsCSV(data) {
    let csv = 'Assignments Report\n\n';
    
    // Statistics
    csv += 'Summary Statistics\n';
    csv += 'Metric,Value\n';
    if (data.stats) {
        csv += `Total Assignments,${data.stats.total_assignments || 0}\n`;
        csv += `Completion Rate,${data.stats.completion_rate || 0}%\n`;
        csv += `Average Grade,${data.stats.avg_grade || 0}%\n`;
        csv += `On-time Submissions,${data.stats.ontime_submissions || 0}%\n`;
    }
    
    csv += '\n\n';
    
    // Completion by Course
    if (data.chartData && data.chartData.labels && data.chartData.datasets && data.chartData.datasets[0]) {
        csv += 'Assignment Completion by Course\n';
        csv += 'Course,Total Assignments,Total Submissions,Completed Submissions,Completion Rate (%),Average Grade (%)\n';
        const dataset = data.chartData.datasets[0];
        data.chartData.labels.forEach((label, index) => {
            csv += `${escapeCSV(label)},${dataset.totalAssignments ? dataset.totalAssignments[index] || 0 : 0},${dataset.totalSubmissions ? dataset.totalSubmissions[index] || 0 : 0},${dataset.completedSubmissions ? dataset.completedSubmissions[index] || 0 : 0},${dataset.data ? (dataset.data[index] || 0) : 0},${dataset.avgGrades ? dataset.avgGrades[index] || 0 : 0}\n`;
        });
    }
    
    return csv;
}

// Generate Quizzes CSV
function generateQuizzesCSV(data) {
    let csv = 'Quizzes Report\n\n';
    
    // Statistics
    csv += 'Summary Statistics\n';
    csv += 'Metric,Value\n';
    if (data.stats) {
        csv += `Total Quizzes,${data.stats.total_quizzes || 0}\n`;
        csv += `Total Attempts,${data.stats.total_attempts || 0}\n`;
        csv += `Average Score,${data.stats.avg_score || 0}%\n`;
        csv += `Active Students,${data.stats.active_students || 0}\n`;
    }
    
    csv += '\n\n';
    
    // Quiz Scores by Course
    if (data.chartData && data.chartData.labels && data.chartData.datasets && data.chartData.datasets[0]) {
        csv += 'Quiz Scores by Course\n';
        csv += 'Course,Total Quizzes,Total Attempts,Average Score (%),Students\n';
        const dataset = data.chartData.datasets[0];
        data.chartData.labels.forEach((label, index) => {
            csv += `${escapeCSV(label)},${dataset.totalQuizzes ? dataset.totalQuizzes[index] || 0 : 0},${dataset.totalAttempts ? dataset.totalAttempts[index] || 0 : 0},${dataset.data ? (dataset.data[index] || 0) : 0},${dataset.students ? dataset.students[index] || 0 : 0}\n`;
        });
    }
    
    csv += '\n\n';
    
    // Quiz Score by Competency
    if (data.radarData && data.radarData.labels && data.radarData.datasets && data.radarData.datasets[0] && data.radarData.datasets[0].data) {
        csv += 'Quiz Score by Competency/Topic\n';
        csv += 'Competency,Average Score (%)\n';
        const radarData = data.radarData.datasets[0].data;
        data.radarData.labels.forEach((label, index) => {
            csv += `${escapeCSV(label)},${radarData[index] || 0}\n`;
        });
    }
    
    return csv;
}

// Generate Overall Grades CSV
function generateGradesCSV(data) {
    let csv = 'Overall Grades Report\n\n';
    
    // Statistics
    csv += 'Summary Statistics\n';
    csv += 'Metric,Value\n';
    if (data.stats) {
        csv += `Total Students,${data.stats.total_students || 0}\n`;
        csv += `Average Grade,${data.stats.avg_grade || 0}%\n`;
        csv += `Pass Rate,${data.stats.pass_rate || 0}%\n`;
        csv += `Fail Rate,${data.stats.fail_rate || 0}%\n`;
    }
    
    csv += '\n\n';
    
    // Grade Distribution
    if (data.gradeDistribution && data.gradeDistribution.labels) {
        csv += 'Grade Distribution\n';
        csv += 'Grade Range,Number of Students\n';
        const gradeDistributionData = data.gradeDistribution.datasets && data.gradeDistribution.datasets[0] && data.gradeDistribution.datasets[0].data 
            ? data.gradeDistribution.datasets[0].data 
            : (data.gradeDistribution.data || []);
        data.gradeDistribution.labels.forEach((label, index) => {
            csv += `${escapeCSV(label)},${gradeDistributionData[index] || 0}\n`;
        });
    }
    
    csv += '\n\n';
    
    // Top Performers
    if (data.topPerformers && data.topPerformers.length > 0) {
        csv += 'Top Performers\n';
        csv += 'Student,Grade (%),Courses Completed\n';
        data.topPerformers.forEach(student => {
            csv += `${escapeCSV(student.name)},${student.grade || 0},${student.courses || 0}\n`;
        });
    }
    
    return csv;
}

// Generate Competencies CSV
function generateCompetenciesCSV(data) {
    let csv = 'Competencies Report\n\n';
    
    // Statistics
    csv += 'Summary Statistics\n';
    csv += 'Metric,Value\n';
    if (data.stats) {
        csv += `Total Competencies,${data.stats.total_competencies || 0}\n`;
        csv += `Average Progress,${data.stats.avg_progress || 0}%\n`;
        csv += `Completed,${data.stats.completed || 0}\n`;
        csv += `In Progress,${data.stats.in_progress || 0}\n`;
    }
    
    csv += '\n\n';
    
    // Competency Progress
    if (data.competencyProgress && data.competencyProgress.length > 0) {
        csv += 'Competency Progress Detail\n';
        csv += 'Competency,Students,Completed,In Progress,Not Started,Completion Rate (%)\n';
        data.competencyProgress.forEach(comp => {
            csv += `${escapeCSV(comp.name)},${comp.students || 0},${comp.completed || 0},${comp.in_progress || 0},${comp.not_started || 0},${comp.completion_rate || 0}\n`;
        });
    }
    
    return csv;
}

// Generate Performance CSV (Teacher or Student)
function generatePerformanceCSV(data) {
    let csv = 'Performance Report\n\n';
    
    // Check if it's teacher or student data
    if (data.teacherPerformance) {
        csv += 'Teacher Performance\n';
        csv += 'Metric,Value\n';
        csv += `Total Teachers,${data.stats?.total_teachers || 0}\n`;
        csv += `Active Teachers,${data.stats?.active_teachers || 0}\n`;
        csv += `Average Student Completion,${data.stats?.avg_completion || 0}%\n`;
        csv += '\n\n';
        
        if (data.teacherPerformance.length > 0) {
            csv += 'Teacher Performance Detail\n';
            csv += 'Teacher,Courses Taught,Students Taught,Avg Student Grade (%),Engagement Score\n';
            data.teacherPerformance.forEach(teacher => {
                csv += `${escapeCSV(teacher.name)},${teacher.courses || 0},${teacher.students || 0},${teacher.avg_grade || 0},${teacher.engagement || 0}\n`;
            });
        }
    } else if (data.studentPerformance) {
        csv += 'Student Performance\n';
        csv += 'Metric,Value\n';
        csv += `Total Students,${data.stats?.total_students || 0}\n`;
        csv += `Active Students,${data.stats?.active_students || 0}\n`;
        csv += `Average Grade,${data.stats?.avg_grade || 0}%\n`;
        csv += '\n\n';
        
        if (data.studentPerformance.length > 0) {
            csv += 'Student Performance Detail\n';
            csv += 'Student,Courses Enrolled,Courses Completed,Average Grade (%),Activity Level\n';
            data.studentPerformance.forEach(student => {
                csv += `${escapeCSV(student.name)},${student.enrolled || 0},${student.completed || 0},${student.grade || 0},${student.activity || 0}\n`;
            });
        }
    }
    
    return csv;
}

// Generate Courses CSV
function generateCoursesCSV(data) {
    let csv = 'Courses Report\n\n';
    
    // KPIs
    csv += 'Summary Statistics\n';
    csv += 'Metric,Value\n';
    if (data.kpi) {
        csv += `Active Courses,${data.kpi.active_courses || 0}\n`;
        csv += `Average Enrollment,${data.kpi.avg_enrollment || 0}\n`;
        csv += `Average Completion,${data.kpi.avg_completion || 0}%\n`;
        csv += `Avg Time to Complete,${data.kpi.avg_time_to_complete || 0} days\n`;
        csv += `Dropout Rate,${data.kpi.dropout_rate || 0}%\n`;
    }
    
    csv += '\n\n';
    
    // Top Performing Courses
    if (data.topCourses && data.topCourses.length > 0) {
        csv += 'Top 10 Performing Courses\n';
        csv += 'Rank,Course,Enrollments,Completion Rate (%),Avg Grade (%),Engagement Score\n';
        data.topCourses.forEach((course, index) => {
            csv += `${index + 1},${escapeCSV(course.name)},${course.enrollments || 0},${course.completion_rate || 0},${course.avg_grade || 0},${course.engagement || 0}\n`;
        });
    }
    
    csv += '\n\n';
    
    // Category Distribution
    if (data.categoryDistribution && data.categoryDistribution.labels && data.categoryDistribution.datasets && data.categoryDistribution.datasets[0] && data.categoryDistribution.datasets[0].data) {
        csv += 'Course Distribution by Category\n';
        csv += 'Category,Number of Courses\n';
        const categoryData = data.categoryDistribution.datasets[0].data;
        data.categoryDistribution.labels.forEach((label, index) => {
            csv += `${escapeCSV(label)},${categoryData[index] || 0}\n`;
        });
    }
    
    return csv;
}

// Generate Activity CSV
function generateActivityCSV(data) {
    let csv = 'Activity & Engagement Report\n\n';
    
    // Statistics
    csv += 'Summary Statistics\n';
    csv += 'Metric,Value\n';
    if (data.stats) {
        csv += `Total Activities,${data.stats.total_activities || 0}\n`;
        csv += `Active Users,${data.stats.active_users || 0}\n`;
        csv += `Engagement Rate,${data.stats.engagement_rate || 0}%\n`;
    }
    
    csv += '\n\n';
    
    // Recent Activities
    if (data.recentActivity && data.recentActivity.length > 0) {
        csv += 'Recent Activity Log\n';
        csv += 'User,Action,Course,Module,Time\n';
        data.recentActivity.forEach(activity => {
            csv += `${escapeCSV(activity.user)},${escapeCSV(activity.action)},${escapeCSV(activity.course || '')},${escapeCSV(activity.target)},${escapeCSV(activity.time)}\n`;
        });
    }
    
    return csv;
}

// Generate Attendance CSV
function generateAttendanceCSV(data) {
    let csv = 'Attendance Report\n\n';
    
    // Statistics
    csv += 'Summary Statistics\n';
    csv += 'Metric,Value\n';
    if (data.stats) {
        csv += `Total Students,${data.stats.total_students || 0}\n`;
        csv += `Average Attendance,${data.stats.avg_attendance || 0}%\n`;
        csv += `Present Today,${data.stats.present_today || 0}\n`;
        csv += `Absent Today,${data.stats.absent_today || 0}\n`;
    }
    
    csv += '\n\n';
    
    // Attendance by Date
    if (data.attendanceTrend && data.attendanceTrend.labels && data.attendanceTrend.datasets) {
        csv += 'Attendance Trend\n';
        csv += 'Date,Present,Absent,Attendance Rate (%)\n';
        const presentData = data.attendanceTrend.datasets[0]?.data || [];
        const absentData = data.attendanceTrend.datasets[1]?.data || [];
        data.attendanceTrend.labels.forEach((label, index) => {
            const present = presentData[index] || 0;
            const absent = absentData[index] || 0;
            const total = present + absent;
            const rate = total > 0 ? ((present / total) * 100).toFixed(2) : 0;
            csv += `${escapeCSV(label)},${present},${absent},${rate}\n`;
        });
    }
    
    return csv;
}

// Register CSV generators for combined exports
CSV_GENERATORS['overview'] = generateOverviewCSV;
CSV_GENERATORS['assignments'] = generateAssignmentsCSV;
CSV_GENERATORS['quizzes'] = generateQuizzesCSV;
CSV_GENERATORS['overall-grades'] = generateGradesCSV;
CSV_GENERATORS['competencies'] = generateCompetenciesCSV;
CSV_GENERATORS['performance'] = generatePerformanceCSV;
CSV_GENERATORS['courses'] = generateCoursesCSV;
CSV_GENERATORS['activity'] = generateActivityCSV;
CSV_GENERATORS['attendance'] = generateAttendanceCSV;
