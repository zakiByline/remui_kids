<?php
/**
 * Front-end logic for the school performance analytics dashboard.
 */
?>
<script>
(function() {
    if (window.schoolAnalyticsV2Loaded) {
        return;
    }
    window.schoolAnalyticsV2Loaded = true;

    // Store chart instances globally for PDF export
    window.schoolAnalyticsCharts = {};

    function getPayload() {
        if (window.schoolAnalyticsData && Object.keys(window.schoolAnalyticsData).length) {
            return window.schoolAnalyticsData;
        }
        const root = document.querySelector('[data-analytics-root]');
        if (!root) {
            return {};
        }
        const raw = root.getAttribute('data-analytics');
        try {
            window.schoolAnalyticsData = raw ? JSON.parse(raw) : {};
        } catch (e) {
            window.schoolAnalyticsData = {};
        }
        return window.schoolAnalyticsData;
    }

    function captureAllCharts() {
        const chartImages = {};
        const chartIds = [
            'academicTrendChart',
            'gradeLevelChart',
            'courseInsightsChart',
            'resourceBarChart',
            'courseCompletionChart',
            'attendanceChart',
            'competenciesStatusChart',
            'competenciesFrameworkChart',
            'competenciesCourseChart',
            'teacherRadar',
            'resourceDonut',
            'systemUsageOverallChart',
            'systemUsageCohortChart',
            'systemUsagePeakHoursChart',
            'systemUsagePeakDaysChart'
        ];

        chartIds.forEach(chartId => {
            const canvas = document.getElementById(chartId);
            if (canvas) {
                const chart = window.schoolAnalyticsCharts[chartId];
                if (chart && typeof chart.toBase64Image === 'function') {
                    try {
                        chartImages[chartId] = chart.toBase64Image();
                    } catch (e) {
                        console.warn('Failed to capture chart:', chartId, e);
                    }
                } else if (canvas.toDataURL) {
                    // Fallback: capture canvas directly
                    try {
                        chartImages[chartId] = canvas.toDataURL('image/png');
                    } catch (e) {
                        console.warn('Failed to capture canvas:', chartId, e);
                    }
                }
            }
        });

        return chartImages;
    }

    function renderAcademicTrend(chartData) {
        const ctx = document.getElementById('academicTrendChart');
        if (!ctx || !chartData || !chartData.labels) {
            return;
        }
        const chart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: chartData.labels,
                datasets: [
                    {
                        label: 'Average Grade',
                        data: chartData.avgGrade,
                        borderColor: '#2563eb',
                        backgroundColor: 'rgba(37, 99, 235, 0.15)',
                        tension: 0.4,
                        fill: true,
                    },
                    {
                        label: 'Assignment Completion %',
                        data: chartData.assignmentCompletion,
                        borderColor: '#0ea5e9',
                        backgroundColor: 'rgba(14, 165, 233, 0.1)',
                        tension: 0.3,
                        fill: false,
                    },
                    {
                        label: 'Quiz Score %',
                        data: chartData.quizScore,
                        borderColor: '#f97316',
                        backgroundColor: 'rgba(249, 115, 22, 0.1)',
                        tension: 0.3,
                        fill: false,
                    },
                    {
                        label: 'Course Completion %',
                        data: chartData.completionRate,
                        borderColor: '#22c55e',
                        backgroundColor: 'rgba(34, 197, 94, 0.1)',
                        tension: 0.3,
                        fill: false,
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                scales: { y: { beginAtZero: true, max: 100 } }
            }
        });
        window.schoolAnalyticsCharts['academicTrendChart'] = chart;
    }

    function renderGradeLevels(data) {
        const ctx = document.getElementById('gradeLevelChart');
        if (!ctx || !Array.isArray(data) || !data.length) {
            return;
        }
        const chart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: data.map(item => item.label),
                datasets: [
                    {
                        label: 'Avg Grade %',
                        data: data.map(item => item.avg_grade),
                        backgroundColor: '#6366f1'
                    },
                    {
                        label: 'Completion %',
                        data: data.map(item => item.completion_rate),
                        backgroundColor: '#34d399'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: { y: { beginAtZero: true, max: 100 } }
            }
        });
        window.schoolAnalyticsCharts['gradeLevelChart'] = chart;
    }

    function renderCourseInsights(data) {
        const ctx = document.getElementById('courseInsightsChart');
        if (!ctx || !Array.isArray(data) || !data.length) {
            return;
        }
        const chart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: data.map(item => item.course_name),
                datasets: [
                    {
                        label: 'Avg Grade %',
                        data: data.map(item => item.avg_grade),
                        backgroundColor: '#2563eb'
                    },
                    {
                        label: 'Quiz Performance %',
                        data: data.map(item => item.quiz_performance),
                        backgroundColor: '#f97316'
                    }
                ]
            },
            options: {
                responsive: true,
                indexAxis: 'y',
                maintainAspectRatio: false,
                scales: { x: { beginAtZero: true, max: 100 } }
            }
        });
        window.schoolAnalyticsCharts['courseInsightsChart'] = chart;
    }

    function renderResourceBarChart(data) {
        const ctx = document.getElementById('resourceBarChart');
        if (!ctx || !data || !data.labels || !data.labels.length) {
            return;
        }
        
        // Generate colors dynamically for all resource types
        const colorPalette = [
            '#2563eb', '#0ea5e9', '#f97316', '#22c55e', '#8b5cf6', 
            '#ec4899', '#f59e0b', '#10b981', '#3b82f6', '#6366f1',
            '#a855f7', '#ef4444', '#14b8a6', '#f43f5e', '#84cc16'
        ];
        
        const colors = data.labels.map((_, index) => {
            return colorPalette[index % colorPalette.length];
        });
        
        const chart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: data.labels.map(label => label.toUpperCase()),
                datasets: [{
                    label: 'Resource Views',
                    data: data.values,
                    backgroundColor: colors,
                    borderColor: colors.map(c => c + '80'),
                    borderWidth: 1
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.parsed.x.toLocaleString() + ' views';
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0
                        },
                        title: {
                            display: true,
                            text: 'Number of Views'
                        }
                    },
                    y: {
                        ticks: {
                            font: {
                                size: 11
                            }
                        }
                    }
                }
            }
        });
        window.schoolAnalyticsCharts['resourceBarChart'] = chart;
    }

    function renderTeacherRadar(data) {
        const ctx = document.getElementById('teacherRadar');
        if (!ctx || !data || !Array.isArray(data.datasets) || !data.datasets.length) {
            return;
        }
        const chart = new Chart(ctx, {
            type: 'radar',
            data: {
                labels: data.labels,
                datasets: data.datasets.map((dataset, index) => ({
                    label: dataset.label,
                    data: dataset.data,
                    backgroundColor: `rgba(37, 99, 235, ${0.05 + index * 0.08})`,
                    borderColor: '#2563eb'
                }))
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: { r: { suggestedMin: 0, suggestedMax: 100 } }
            }
        });
        window.schoolAnalyticsCharts['teacherRadar'] = chart;
    }

    function renderCourseCompletion(chartData) {
        const ctx = document.getElementById('courseCompletionChart');
        if (!ctx || !chartData || !chartData.labels) {
            return;
        }
        const chart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: chartData.labels,
                datasets: [
                    {
                        type: 'bar',
                        label: 'Completed Courses',
                        data: chartData.completed,
                        backgroundColor: '#22c55e'
                    },
                    {
                        type: 'line',
                        label: 'Completion Rate %',
                        data: chartData.completionRate,
                        borderColor: '#2563eb',
                        backgroundColor: 'rgba(37, 99, 235, 0.1)',
                        tension: 0.3,
                        yAxisID: 'y1'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: { beginAtZero: true },
                    y1: { beginAtZero: true, position: 'right', suggestedMax: 100 }
                }
            }
        });
        window.schoolAnalyticsCharts['courseCompletionChart'] = chart;
    }

    function renderAttendance(chartData) {
        const ctx = document.getElementById('attendanceChart');
        if (!ctx || !chartData || !chartData.labels) {
            return;
        }
        const chart = new Chart(ctx, {
            type: 'pie',
            data: {
                labels: chartData.labels,
                datasets: [{
                    data: chartData.values,
                    backgroundColor: ['#10b981', '#f97316', '#ef4444', '#3b82f6']
                }]
            },
            options: { responsive: true, maintainAspectRatio: false }
        });
        window.schoolAnalyticsCharts['attendanceChart'] = chart;
    }

    function initPagination() {
        const itemsPerPage = 10;
        
        function paginateTable(tableId) {
            const table = document.querySelector(`[data-table-id="${tableId}"]`);
            if (!table) return;
            
            const tbody = table.querySelector('tbody');
            const allRows = Array.from(tbody.querySelectorAll('tr'));
            let currentPage = 1;
            
            function getFilteredRows() {
                if (tableId === 'early-warning') {
                    const studentFilter = document.getElementById('filter-student')?.value || '';
                    const gradeFilter = document.getElementById('filter-grade')?.value || '';
                    
                    if (!studentFilter && !gradeFilter) {
                        return allRows;
                    }
                    
                    return allRows.filter(row => {
                        const studentName = row.getAttribute('data-student-name') || '';
                        const studentGrade = row.getAttribute('data-student-grade') || '';
                        
                        const matchesStudent = !studentFilter || studentName === studentFilter;
                        const matchesGrade = !gradeFilter || studentGrade === gradeFilter;
                        
                        return matchesStudent && matchesGrade;
                    });
                }
                
                if (tableId === 'course-completion') {
                    const courseFilter = document.getElementById('filter-course')?.value || '';
                    
                    if (!courseFilter) {
                        return allRows;
                    }
                    
                    return allRows.filter(row => {
                        const courseName = row.getAttribute('data-course-name') || '';
                        return courseName === courseFilter;
                    });
                }
                
                return allRows;
            }
            
            function showPage(page) {
                currentPage = page;
                const filteredRows = getFilteredRows();
                const totalPages = Math.ceil(filteredRows.length / itemsPerPage);
                
                if (totalPages === 0) {
                    allRows.forEach(row => row.style.display = 'none');
                    const paginationDiv = document.querySelector(`[data-pagination-for="${tableId}"]`);
                    if (paginationDiv) {
                        paginationDiv.innerHTML = '<span class="page-info">No results found</span>';
                    }
                    return;
                }
                
                if (currentPage > totalPages) {
                    currentPage = totalPages;
                }
                
                const start = (currentPage - 1) * itemsPerPage;
                const end = start + itemsPerPage;
                
                allRows.forEach(row => {
                    const index = filteredRows.indexOf(row);
                    if (index >= start && index < end && index !== -1) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                });
                
                updatePaginationControls(totalPages, filteredRows.length);
            }
            
            function updatePaginationControls(totalPages, filteredCount) {
                const paginationDiv = document.querySelector(`[data-pagination-for="${tableId}"]`);
                if (!paginationDiv) return;
                
                if (totalPages <= 1 && filteredCount > 0) {
                    paginationDiv.innerHTML = '';
                    return;
                }
                
                paginationDiv.innerHTML = '';
                
                const prevBtn = document.createElement('button');
                prevBtn.textContent = 'Previous';
                prevBtn.disabled = currentPage === 1;
                prevBtn.addEventListener('click', () => {
                    if (currentPage > 1) {
                        showPage(currentPage - 1);
                    }
                });
                paginationDiv.appendChild(prevBtn);
                
                const pageInfo = document.createElement('span');
                pageInfo.className = 'page-info';
                pageInfo.textContent = `Page ${currentPage} of ${totalPages} (${filteredCount} total)`;
                paginationDiv.appendChild(pageInfo);
                
                const nextBtn = document.createElement('button');
                nextBtn.textContent = 'Next';
                nextBtn.disabled = currentPage === totalPages;
                nextBtn.addEventListener('click', () => {
                    if (currentPage < totalPages) {
                        showPage(currentPage + 1);
                    }
                });
                paginationDiv.appendChild(nextBtn);
            }
            
            if (tableId === 'early-warning') {
                const studentFilter = document.getElementById('filter-student');
                const gradeFilter = document.getElementById('filter-grade');
                
                if (studentFilter) {
                    studentFilter.addEventListener('change', () => {
                        showPage(1);
                    });
                }
                
                if (gradeFilter) {
                    gradeFilter.addEventListener('change', () => {
                        showPage(1);
                    });
                }
            }
            
            if (tableId === 'course-completion') {
                const courseFilter = document.getElementById('filter-course');
                
                if (courseFilter) {
                    courseFilter.addEventListener('change', () => {
                        showPage(1);
                    });
                }
            }
            
            showPage(1);
        }
        
        function paginateList(listId) {
            const list = document.querySelector(`[data-list-id="${listId}"]`);
            if (!list) return;
            
            const items = Array.from(list.querySelectorAll('.resource-row, .attendance-row, .framework-item'));
            const totalPages = Math.ceil(items.length / itemsPerPage);
            
            if (totalPages <= 1) return;
            
            const paginationDiv = document.querySelector(`[data-pagination-for="${listId}"]`);
            if (!paginationDiv) return;
            
            let currentPage = 1;
            
            function showPage(page) {
                const start = (page - 1) * itemsPerPage;
                const end = start + itemsPerPage;
                
                items.forEach((item, index) => {
                    if (index >= start && index < end) {
                        item.classList.add('show');
                        item.style.display = ''; // For framework-item which uses display: grid
                    } else {
                        item.classList.remove('show');
                        item.style.display = 'none'; // For framework-item which uses display: grid
                    }
                });
                
                updatePaginationControls();
            }
            
            function updatePaginationControls() {
                paginationDiv.innerHTML = '';
                
                const prevBtn = document.createElement('button');
                prevBtn.textContent = 'Previous';
                prevBtn.disabled = currentPage === 1;
                prevBtn.addEventListener('click', () => {
                    if (currentPage > 1) {
                        currentPage--;
                        showPage(currentPage);
                    }
                });
                paginationDiv.appendChild(prevBtn);
                
                const pageInfo = document.createElement('span');
                pageInfo.className = 'page-info';
                pageInfo.textContent = `Page ${currentPage} of ${totalPages}`;
                paginationDiv.appendChild(pageInfo);
                
                const nextBtn = document.createElement('button');
                nextBtn.textContent = 'Next';
                nextBtn.disabled = currentPage === totalPages;
                nextBtn.addEventListener('click', () => {
                    if (currentPage < totalPages) {
                        currentPage++;
                        showPage(currentPage);
                    }
                });
                paginationDiv.appendChild(nextBtn);
            }
            
            showPage(1);
        }
        
        const tableIds = [
            'grade-levels',
            'course-insights',
            'top-students',
            'bottom-students',
            'teacher-effectiveness',
            'course-completion',
            'early-warning',
            'competencies-course',
            'system-usage-cohort'
        ];
        
        const listIds = [
            'top-resources',
            'least-resources',
            'competencies-frameworks'
        ];
        
        tableIds.forEach(id => paginateTable(id));
        listIds.forEach(id => paginateList(id));
    }

    function renderCompetenciesStatusChart(data) {
        const ctx = document.getElementById('competenciesStatusChart');
        if (!ctx || !data || !data.labels || !data.labels.length) {
            return;
        }
        
        const chart = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: data.labels,
                datasets: [{
                    data: data.values,
                    backgroundColor: data.colors || ['#22c55e', '#f97316'],
                    borderWidth: 2,
                    borderColor: '#ffffff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '65%',
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
                        callbacks: {
                            label: function(context) {
                                const label = context.label || '';
                                const value = context.parsed || 0;
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                                return `${label}: ${value} (${percentage}%)`;
                            }
                        }
                    }
                }
            }
        });
        window.schoolAnalyticsCharts['competenciesStatusChart'] = chart;
    }

    function renderCompetenciesFrameworkChart(data) {
        const ctx = document.getElementById('competenciesFrameworkChart');
        if (!ctx || !data || !data.labels || !data.labels.length) {
            return;
        }
        
        const colorPalette = [
            '#2563eb', '#0ea5e9', '#f97316', '#22c55e', '#8b5cf6', 
            '#ec4899', '#f59e0b', '#10b981', '#3b82f6', '#6366f1'
        ];
        
        const colors = data.labels.map((_, index) => {
            return colorPalette[index % colorPalette.length];
        });
        
        const chart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: data.labels.map(label => label.length > 20 ? label.substring(0, 20) + '...' : label),
                datasets: [{
                    label: 'Competencies',
                    data: data.values,
                    backgroundColor: colors,
                    borderColor: colors.map(c => c + '80'),
                    borderWidth: 1
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.parsed.x + ' competencies';
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0
                        },
                        title: {
                            display: true,
                            text: 'Number of Competencies'
                        }
                    },
                    y: {
                        ticks: {
                            font: {
                                size: 10
                            }
                        }
                    }
                }
            }
        });
        window.schoolAnalyticsCharts['competenciesFrameworkChart'] = chart;
    }

    function renderCompetenciesCourseChart(data) {
        const ctx = document.getElementById('competenciesCourseChart');
        if (!ctx || !data || !data.labels || !data.labels.length) {
            return;
        }
        
        const chart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: data.labels.map(label => label.length > 30 ? label.substring(0, 30) + '...' : label),
                datasets: [
                    {
                        label: 'Assigned',
                        data: data.assigned,
                        backgroundColor: '#3b82f6',
                        borderColor: '#2563eb',
                        borderWidth: 1
                    },
                    {
                        label: 'Achieved',
                        data: data.achieved,
                        backgroundColor: '#22c55e',
                        borderColor: '#16a34a',
                        borderWidth: 1
                    },
                    {
                        label: 'Pending',
                        data: data.pending,
                        backgroundColor: '#f97316',
                        borderColor: '#ea580c',
                        borderWidth: 1
                    },
                    {
                        label: 'Achievement Rate %',
                        data: data.achievement_rates,
                        type: 'line',
                        borderColor: '#8b5cf6',
                        backgroundColor: 'rgba(139, 92, 246, 0.1)',
                        borderWidth: 3,
                        fill: false,
                        yAxisID: 'y1',
                        tension: 0.4
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                        labels: {
                            padding: 10,
                            font: {
                                size: 11
                            }
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                if (context.parsed.y !== null) {
                                    if (label.includes('Rate')) {
                                        label += context.parsed.y.toFixed(1) + '%';
                                    } else {
                                        label += context.parsed.y;
                                    }
                                }
                                return label;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        stacked: false,
                        ticks: {
                            font: {
                                size: 10
                            },
                            maxRotation: 45,
                            minRotation: 45
                        }
                    },
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Number of Competencies'
                        },
                        ticks: {
                            precision: 0
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
                            text: 'Achievement Rate (%)'
                        },
                        grid: {
                            drawOnChartArea: false
                        }
                    }
                }
            }
        });
        window.schoolAnalyticsCharts['competenciesCourseChart'] = chart;
    }

    function renderSystemUsageOverall(data) {
        const ctx = document.getElementById('systemUsageOverallChart');
        if (!ctx || !data || !data.labels || !data.labels.length) {
            return;
        }
        const chart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: data.labels,
                datasets: data.datasets.map((ds, idx) => ({
                    label: ds.label,
                    data: ds.data,
                    backgroundColor: ds.backgroundColor || ['#3b82f6', '#10b981', '#f59e0b', '#8b5cf6'],
                    borderColor: ds.backgroundColor || ['#3b82f6', '#10b981', '#f59e0b', '#8b5cf6'],
                    borderWidth: 1
                }))
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: true,
                        position: 'top'
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0
                        }
                    }
                }
            }
        });
        window.schoolAnalyticsCharts['systemUsageOverallChart'] = chart;
    }

    function renderSystemUsageCohort(data) {
        const ctx = document.getElementById('systemUsageCohortChart');
        if (!ctx || !data || !data.labels || !data.labels.length) {
            return;
        }
        const chart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: data.labels,
                datasets: data.datasets.map((ds, idx) => ({
                    label: ds.label,
                    data: ds.data,
                    backgroundColor: ds.backgroundColor || (idx === 0 ? '#3b82f6' : '#10b981'),
                    borderColor: ds.backgroundColor || (idx === 0 ? '#3b82f6' : '#10b981'),
                    borderWidth: 1
                }))
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: true,
                        position: 'top'
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false
                    }
                },
                scales: {
                    x: {
                        stacked: false
                    },
                    y: {
                        beginAtZero: true,
                        stacked: false,
                        ticks: {
                            precision: 0
                        }
                    }
                }
            }
        });
        window.schoolAnalyticsCharts['systemUsageCohortChart'] = chart;
    }

    function renderSystemUsagePeakHours(data) {
        const ctx = document.getElementById('systemUsagePeakHoursChart');
        if (!ctx || !data || !data.labels || !data.labels.length) {
            return;
        }
        const chart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: data.labels,
                datasets: [{
                    label: 'Page Views',
                    data: data.values,
                    borderColor: '#3b82f6',
                    backgroundColor: 'rgba(59, 130, 246, 0.1)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: true,
                        position: 'top'
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0
                        }
                    }
                }
            }
        });
        window.schoolAnalyticsCharts['systemUsagePeakHoursChart'] = chart;
    }

    function renderSystemUsagePeakDays(data) {
        const ctx = document.getElementById('systemUsagePeakDaysChart');
        if (!ctx || !data || !data.labels || !data.labels.length) {
            return;
        }
        const chart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: data.labels,
                datasets: [{
                    label: 'Page Views',
                    data: data.values,
                    backgroundColor: '#10b981',
                    borderColor: '#10b981',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: true,
                        position: 'top'
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0
                        }
                    }
                }
            }
        });
        window.schoolAnalyticsCharts['systemUsagePeakDaysChart'] = chart;
    }

    document.addEventListener('DOMContentLoaded', function() {
        const payload = getPayload();
        renderAcademicTrend(payload.academicTrend);
        renderGradeLevels(payload.gradeLevels);
        renderCourseInsights(payload.courseInsights);
        renderResourceBarChart(payload.resources);
        renderTeacherRadar(payload.teachers);
        renderCourseCompletion(payload.courseCompletions);
        renderAttendance(payload.attendance);
        if (payload.competencies) {
            renderCompetenciesStatusChart(payload.competencies.status);
            renderCompetenciesFrameworkChart(payload.competencies.frameworks);
            renderCompetenciesCourseChart(payload.competencies.courses);
        }
        if (payload.systemusage) {
            renderSystemUsageOverall(payload.systemusage.overall_usage);
            renderSystemUsageCohort(payload.systemusage.cohort_comparison);
            renderSystemUsagePeakHours(payload.systemusage.peak_hours);
            renderSystemUsagePeakDays(payload.systemusage.peak_days);
        }
        initPagination();
    });
})();
</script>
