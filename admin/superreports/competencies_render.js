/**
 * Enhanced Render Competencies Tab - Complete Version
 * This file contains the enhanced competencies rendering function
 */

function renderCompetenciesTab(container, data) {
    const compData = data.data;
    let html = '';
    
    // Filter info banner
    if (currentFilters.school || currentFilters.grade || currentFilters.framework) {
        html += '<div class="filter-info-banner">';
        html += '<i class="fa fa-filter"></i> <strong>Filters Active:</strong> ';
        const filters = [];
        if (currentFilters.school) {
            const schoolSelect = document.getElementById('schoolFilter');
            const schoolName = schoolSelect ? schoolSelect.options[schoolSelect.selectedIndex].text : 'Selected School';
            filters.push('🏫 ' + schoolName);
        }
        if (currentFilters.grade) {
            const gradeSelect = document.getElementById('gradeFilter');
            const gradeName = gradeSelect ? gradeSelect.options[gradeSelect.selectedIndex].text : 'Grade ' + currentFilters.grade;
            filters.push('🎓 ' + gradeName);
        }
        if (currentFilters.framework) {
            const frameworkSelect = document.getElementById('frameworkFilter');
            const frameworkName = frameworkSelect ? frameworkSelect.options[frameworkSelect.selectedIndex].text : 'Selected Framework';
            filters.push('📚 ' + frameworkName);
        }
        html += filters.join(' • ');
        html += '</div>';
    }
    
    // Stats cards - Enhanced with Unmapped Competencies
    html += '<div class="stats-grid">';
    html += createStatCard('🧩', compData.total_competencies, 'Total Competencies', 1);
    html += createStatCard('📚', compData.total_mapped_competencies || 0, 'Mapped to Courses (' + ((compData.total_mapped_competencies / compData.total_competencies * 100) || 0).toFixed(0) + '%)', 2);
    html += createStatCard('✅', compData.overall_completion_rate + '%', 'Avg Completion Rate', 3);
    html += createStatCard('⚙️', compData.unmapped_competencies || 0, 'Unmapped (' + (compData.unmapped_percentage || 0) + '%)', 4);
    html += '</div>';
    
    // AI Insights - Enhanced with specific recommendations
    if (compData.insights && compData.insights.length > 0) {
        html += '<div class="ai-insights-card" style="margin: 20px 0;">';
        html += '<h3><i class="fa fa-lightbulb"></i> AI-Powered Insights</h3>';
        compData.insights.forEach(insight => {
            html += `<p>💡 ${insight}</p>`;
        });
        html += '</div>';
    }
    
    // CHART 1: Competency Coverage by Course (Bar Chart)
    if (compData.competency_coverage_by_course && compData.competency_coverage_by_course.length > 0) {
        html += '<div class="chart-card" style="margin: 20px 0;">';
        html += '<h3>📊 Competency Coverage by Course</h3>';
        html += '<p style="color: #666; margin: 10px 0;">Shows how many competencies are mapped to each course</p>';
        html += '<div class="chart-container"><canvas id="competencyCoverageChart"></canvas></div>';
        html += '</div>';
    }
    
    // CHART 2: Competency Completion Distribution (Radar Chart)
    if (compData.completion_distribution && compData.completion_distribution.length > 0) {
        html += '<div class="chart-card" style="margin: 20px 0;">';
        html += '<h3>🎯 Competency Completion Distribution</h3>';
        html += '<p style="color: #666; margin: 10px 0;">Completion rates across all competencies</p>';
        html += '<div class="chart-container" style="height: 800px;"><canvas id="completionDistributionChart"></canvas></div>';
        html += '</div>';
    }
    
    // CHART 3: Completion by Cohort (Grouped Bar Chart)
    if (compData.completion_by_cohort && compData.completion_by_cohort.length > 0) {
        html += '<div class="chart-card" style="margin: 20px 0;">';
        html += '<h3>📈 Competency Completion by Cohort</h3>';
        html += '<p style="color: #666; margin: 10px 0;">Compare completion rates across different cohorts</p>';
        html += '<div class="chart-container"><canvas id="completionByCohortChart"></canvas></div>';
        html += '</div>';
    }
    
    // Competency Mapping Table with Framework column
    if (compData.competencies && compData.competencies.length > 0) {
        html += '<div class="data-table-wrapper" style="margin: 30px 0;">';
        html += '<h3 style="margin: 20px 0 10px 0;">📘 Competency Mapping Details</h3>';
        html += '<table class="data-table clickable-rows">';
        html += '<thead><tr>';
        html += '<th>Competency</th>';
        html += '<th>Framework</th>';
        html += '<th># of Courses Mapped</th>';
        html += '<th># of Students Enrolled</th>';
        html += '<th>Avg Completion %</th>';
        html += '<th>Avg Grade %</th>';
        html += '<th>Action</th>';
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
            
            html += `<tr class="competency-row" data-competency-id="${comp.id}">
                <td><strong>${comp.name}</strong><br><small style="color:#666;">${comp.idnumber || 'No ID'}</small></td>
                <td><span class="badge badge-secondary">${comp.framework}</span></td>
                <td><strong>${comp.courses_mapped}</strong></td>
                <td>${comp.total_users}</td>
                <td>
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: ${comp.completion_rate}%; background-color: ${rateColor}"></div>
                    </div>
                    <strong>${comp.completion_rate}%</strong>
                </td>
                <td>${comp.avg_grade}%</td>
                <td>
                    <button class="btn-view-details" onclick="showCompetencyDetails(${comp.id}, '${comp.name.replace(/'/g, "\\'")}')">
                        <i class="fa fa-eye"></i> View Details
                    </button>
                </td>
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
    
    // Render charts after DOM is ready
    setTimeout(() => {
        // Chart 1: Competency Coverage by Course
        if (compData.competency_coverage_by_course && compData.competency_coverage_by_course.length > 0) {
            renderCompetencyCoverageChart(compData.competency_coverage_by_course);
        }
        
        // Chart 2: Completion Distribution Radar
        if (compData.completion_distribution && compData.completion_distribution.length > 0) {
            renderCompletionDistributionChart(compData.completion_distribution);
        }
        
        // Chart 3: Completion by Cohort
        if (compData.completion_by_cohort && compData.completion_by_cohort.length > 0) {
            renderCompletionByCohortChart(compData.completion_by_cohort);
        }
    }, 100);
}

/**
 * Render Competency Coverage Chart
 */
function renderCompetencyCoverageChart(data) {
    const ctx = document.getElementById('competencyCoverageChart');
    if (!ctx) return;
    
    if (chartInstances.competencyCoverage) {
        chartInstances.competencyCoverage.destroy();
    }
    
    const labels = data.map(d => d.course);
    const values = data.map(d => d.count);
    
    chartInstances.competencyCoverage = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Number of Competencies',
                data: values,
                backgroundColor: '#3498db',
                borderColor: '#2980b9',
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
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Number of Competencies'
                    }
                },
                x: {
                    title: {
                        display: true,
                        text: 'Courses'
                    }
                }
            }
        }
    });
}

/**
 * Render Completion Distribution Radar Chart
 */
function renderCompletionDistributionChart(data) {
    const ctx = document.getElementById('completionDistributionChart');
    if (!ctx) return;
    
    if (chartInstances.completionDistribution) {
        chartInstances.completionDistribution.destroy();
    }
    
    const labels = data.map(d => d.label);
    const values = data.map(d => d.value);
    
    chartInstances.completionDistribution = new Chart(ctx, {
        type: 'radar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Completion Rate (%)',
                data: values,
                backgroundColor: 'rgba(155, 89, 182, 0.2)',
                borderColor: '#9b59b6',
                borderWidth: 2,
                pointBackgroundColor: '#9b59b6',
                pointBorderColor: '#fff',
                pointHoverBackgroundColor: '#fff',
                pointHoverBorderColor: '#9b59b6'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: true,
                    position: 'top',
                    labels: {
                        font: {
                            size: 14
                        }
                    }
                }
            },
            scales: {
                r: {
                    beginAtZero: true,
                    max: 100,
                    ticks: {
                        stepSize: 20,
                        font: {
                            size: 12
                        }
                    },
                    pointLabels: {
                        font: {
                            size: 13
                        }
                    }
                }
            }
        }
    });
}

/**
 * Render Completion by Cohort Chart
 */
function renderCompletionByCohortChart(data) {
    const ctx = document.getElementById('completionByCohortChart');
    if (!ctx) return;
    
    if (chartInstances.completionByCohort) {
        chartInstances.completionByCohort.destroy();
    }
    
    // Group data by cohort
    const cohorts = [...new Set(data.map(d => d.cohort))];
    const competencies = [...new Set(data.map(d => d.competency))];
    
    const datasets = competencies.map((comp, index) => {
        const colors = ['#3498db', '#e74c3c', '#2ecc71', '#f39c12', '#9b59b6'];
        return {
            label: comp,
            data: cohorts.map(cohort => {
                const item = data.find(d => d.cohort === cohort && d.competency === comp);
                return item ? item.completion_rate : 0;
            }),
            backgroundColor: colors[index % colors.length]
        };
    });
    
    chartInstances.completionByCohort = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: cohorts,
            datasets: datasets
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: true,
                    position: 'top'
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    max: 100,
                    title: {
                        display: true,
                        text: 'Completion Rate (%)'
                    }
                },
                x: {
                    title: {
                        display: true,
                        text: 'Cohorts'
                    }
                }
            }
        }
    });
}

/**
 * Show Competency Details Modal
 */
function showCompetencyDetails(competencyId, competencyName) {
    // Create modal HTML
    const modalHtml = `
        <div class="modal-overlay" id="competencyModal" onclick="closeCompetencyModal()">
            <div class="modal-content" onclick="event.stopPropagation()">
                <div class="modal-header">
                    <h2><i class="fa fa-puzzle-piece"></i> ${competencyName}</h2>
                    <button class="modal-close" onclick="closeCompetencyModal()">×</button>
                </div>
                <div class="modal-body">
                    <div class="loading-spinner"><i class="fa fa-spinner fa-spin"></i> Loading details...</div>
                </div>
            </div>
        </div>
    `;
    
    // Add modal to body
    document.body.insertAdjacentHTML('beforeend', modalHtml);
    
    // Fetch competency details
    fetch(M.cfg.wwwroot + '/theme/remui_kids/admin/superreports/competency_details.php?id=' + competencyId + '&sesskey=' + M.cfg.sesskey)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                renderCompetencyDetailsModal(data.data);
            } else {
                document.querySelector('#competencyModal .modal-body').innerHTML = '<p>Failed to load details.</p>';
            }
        })
        .catch(error => {
            document.querySelector('#competencyModal .modal-body').innerHTML = '<p>Error loading details: ' + error.message + '</p>';
        });
}

/**
 * Render competency details in modal
 */
function renderCompetencyDetailsModal(data) {
    let html = '<h3>📚 Mapped Courses</h3>';
    
    if (data.courses && data.courses.length > 0) {
        html += '<table class="modal-table">';
        html += '<thead><tr><th>Course</th><th>Teacher</th><th># Students</th><th>Avg Grade</th><th>Completion %</th></tr></thead>';
        html += '<tbody>';
        
        data.courses.forEach(course => {
            html += `<tr>
                <td>${course.name}</td>
                <td>${course.teacher || 'N/A'}</td>
                <td>${course.students}</td>
                <td>${course.avg_grade}%</td>
                <td>${course.completion}%</td>
            </tr>`;
        });
        
        html += '</tbody></table>';
        
        html += '<div class="detail-summary">';
        html += `<p><strong>Summary:</strong> Mapped in ${data.courses.length} courses, average mastery ${data.avg_mastery || 0}%.</p>`;
        html += '</div>';
    } else {
        html += '<p>No course mapping details available.</p>';
    }
    
    document.querySelector('#competencyModal .modal-body').innerHTML = html;
}

/**
 * Close competency details modal
 */
function closeCompetencyModal() {
    const modal = document.getElementById('competencyModal');
    if (modal) {
        modal.remove();
    }
}



