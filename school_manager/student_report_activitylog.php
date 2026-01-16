<?php
/**
 * Student Report - Activity Log Tab (AJAX fragment)
 * Based on login reports tab from course_reports.php
 */

require_once(__DIR__ . '/../../../config.php');
require_login();

global $USER, $DB, $CFG;

$companymanagerrole = $DB->get_record('role', ['shortname' => 'companymanager']);
$is_company_manager = false;

if ($companymanagerrole) {
    $context = context_system::instance();
    $is_company_manager = user_has_role_assignment($USER->id, $companymanagerrole->id, $context->id);
}

if (!$is_company_manager) {
    redirect($CFG->wwwroot . '/my/', 'Access denied. School manager role required.', null, \core\output\notification::NOTIFY_ERROR);
}

$ajax = optional_param('ajax', 0, PARAM_BOOL);

$company_info = null;
if ($DB->get_manager()->table_exists('company') && $DB->get_manager()->table_exists('company_users')) {
    $company_info = $DB->get_record_sql(
        "SELECT c.*
         FROM {company} c
         JOIN {company_users} cu ON c.id = cu.companyid
         WHERE cu.userid = ? AND cu.managertype = 1",
        [$USER->id]
    );
    
    // Try alternative query if first one fails
    if (!$company_info) {
        $company_info = $DB->get_record_sql(
            "SELECT c.*
             FROM {company} c
             JOIN {company_users} cu ON c.id = cu.companyid
             WHERE cu.userid = ?",
            [$USER->id]
        );
    }
}

if (!$ajax) {
    $target = new moodle_url('/theme/remui_kids/school_manager/student_report.php', ['tab' => 'activitylog']);
    redirect($target);
}

// Initialize login trend data (matching course_reports.php pattern)
$login_trend_data = [
    'dates' => [],
    'student_logins' => [],
    'teacher_logins' => []
];

$student_login_activity = [];

if ($company_info) {
    // Generate last 30 days dates
    $dates = [];
    for ($i = 29; $i >= 0; $i--) {
        $dates[] = date('Y-m-d', strtotime("-$i days"));
    }
    $login_trend_data['dates'] = $dates;
    
    // Calculate the timestamp for 30 days ago
    $thirty_days_ago = strtotime("-30 days");
    
    // Get all student logins in the last 30 days (using lastaccess like course_reports.php)
    $student_login_records = $DB->get_records_sql(
        "SELECT DISTINCT u.id, u.lastaccess
         FROM {user} u
         INNER JOIN {company_users} cu ON cu.userid = u.id
         INNER JOIN {role_assignments} ra ON ra.userid = u.id
         INNER JOIN {context} ctx ON ctx.id = ra.contextid
         INNER JOIN {role} r ON r.id = ra.roleid
         WHERE cu.companyid = ?
         AND r.shortname = 'student'
         AND u.deleted = 0
         AND u.suspended = 0
         AND u.lastaccess >= ?",
        [$company_info->id, $thirty_days_ago]
    );
    
    // Get all teacher logins in the last 30 days
    $teacher_login_records = $DB->get_records_sql(
        "SELECT DISTINCT u.id, u.lastaccess
         FROM {user} u
         INNER JOIN {company_users} cu ON cu.userid = u.id
         INNER JOIN {role_assignments} ra ON ra.userid = u.id
         INNER JOIN {context} ctx ON ctx.id = ra.contextid
         INNER JOIN {role} r ON r.id = ra.roleid
         WHERE cu.companyid = ?
         AND r.shortname IN ('teacher', 'editingteacher', 'manager')
         AND u.deleted = 0
         AND u.suspended = 0
         AND u.lastaccess >= ?",
        [$company_info->id, $thirty_days_ago]
    );
    
    // Count logins per day for students (matching course_reports.php pattern)
    foreach ($dates as $date) {
        $count = 0;
        $date_start = strtotime($date . ' 00:00:00');
        $date_end = strtotime($date . ' 23:59:59');
        
        if ($student_login_records) {
            foreach ($student_login_records as $record) {
                if ($record->lastaccess >= $date_start && $record->lastaccess <= $date_end) {
                    $count++;
                }
            }
        }
        $login_trend_data['student_logins'][] = $count;
    }
    
    // Count logins per day for teachers
    foreach ($dates as $date) {
        $count = 0;
        $date_start = strtotime($date . ' 00:00:00');
        $date_end = strtotime($date . ' 23:59:59');
        
        if ($teacher_login_records) {
            foreach ($teacher_login_records as $record) {
                if ($record->lastaccess >= $date_start && $record->lastaccess <= $date_end) {
                    $count++;
                }
            }
        }
        $login_trend_data['teacher_logins'][] = $count;
    }
    
    // Get detailed student login activity (matching course_reports.php pattern)
    $log_table_exists = $DB->get_manager()->table_exists('logstore_standard_log');
    
    $student_login_activity = $DB->get_records_sql(
        "SELECT u.id, u.firstname, u.lastname, u.email, u.timecreated, u.firstaccess, u.lastaccess,
                " . ($log_table_exists ? "(SELECT COUNT(*) FROM {logstore_standard_log} WHERE userid = u.id AND action = 'loggedin') as login_count" : "0 as login_count") . "
         FROM {user} u
         INNER JOIN {company_users} cu ON cu.userid = u.id
         INNER JOIN {role_assignments} ra ON ra.userid = u.id
         INNER JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = 10
         INNER JOIN {role} r ON r.id = ra.roleid
         WHERE cu.companyid = ?
         AND r.shortname = 'student'
         AND u.deleted = 0
         AND u.suspended = 0
         ORDER BY u.lastname ASC, u.firstname ASC",
        [$company_info->id]
    );
}

header('Content-Type: text/html; charset=utf-8');

ob_start();
?>
<style>
.student-activity-log-container {
    padding: 0;
}

.student-activity-chart-card {
    background: #ffffff;
    border-radius: 16px;
    padding: 24px;
    margin-bottom: 28px;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
    border: 1px solid #e5e7eb;
}

.student-activity-chart-header {
    margin-bottom: 20px;
}

.student-activity-chart-header h4 {
    font-size: 1.2rem;
    font-weight: 700;
    color: #1f2937;
    margin: 0 0 8px 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.student-activity-chart-header h4 i {
    color: #3b82f6;
}

.student-activity-chart-helper {
    font-size: 0.9rem;
    color: #6b7280;
    margin: 0;
}

.student-activity-table-container {
    background: #ffffff;
    border-radius: 16px;
    padding: 24px;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
    border: 1px solid #e5e7eb;
}

.student-activity-table-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 16px;
}

.student-activity-table-title {
    font-size: 1.2rem;
    font-weight: 700;
    color: #1f2937;
    display: flex;
    align-items: center;
    gap: 10px;
}

.student-activity-table-title i {
    color: #3b82f6;
}

.student-activity-search-container {
    display: flex;
    gap: 10px;
    align-items: center;
    position: relative;
    flex: 1;
    max-width: 400px;
}

.student-activity-search-input {
    flex: 1;
    padding: 10px 16px 10px 40px;
    border: 1px solid #d1d5db;
    border-radius: 10px;
    font-size: 0.9rem;
    color: #1f2937;
    background: #ffffff;
}

.student-activity-search-input:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.student-activity-search-icon {
    position: absolute;
    left: 12px;
    color: #6b7280;
    pointer-events: none;
}

.student-activity-clear-btn {
    padding: 8px 16px;
    background: #ef4444;
    color: #ffffff;
    border: none;
    border-radius: 8px;
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 6px;
    white-space: nowrap;
}

.student-activity-clear-btn:hover {
    background: #dc2626;
}

.student-activity-table-wrapper {
    overflow-x: auto;
    margin-bottom: 20px;
}

.student-activity-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.92rem;
    min-width: 900px;
}

.student-activity-table thead {
    background: #f9fafb;
    border-bottom: 2px solid #e5e7eb;
}

.student-activity-table th {
    padding: 12px;
    text-align: left;
    color: #374151;
    font-weight: 700;
    font-size: 0.78rem;
    text-transform: uppercase;
}

.student-activity-table th:last-child {
    text-align: center;
}

.student-activity-table tbody tr {
    border-bottom: 1px solid #e5e7eb;
    transition: background-color 0.2s;
}

.student-activity-table tbody tr:hover {
    background: #f9fafb;
}

.student-activity-table td {
    padding: 14px 12px;
    color: #1f2937;
}

.student-activity-table td:first-child {
    font-weight: 600;
}

.student-activity-table td:last-child {
    text-align: center;
}

.student-activity-login-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 44px;
    padding: 6px 14px;
    border-radius: 999px;
    font-weight: 700;
    background: rgba(16, 185, 129, 0.15);
    color: #047857;
}

.student-activity-empty-state {
    text-align: center;
    padding: 60px 20px;
    color: #6b7280;
}

.student-activity-empty-state i {
    font-size: 3rem;
    margin-bottom: 15px;
    color: #d1d5db;
}

.student-activity-pagination {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 16px;
    padding-top: 20px;
    border-top: 1px solid #e5e7eb;
}

.student-activity-pagination-info {
    font-size: 0.9rem;
    color: #6b7280;
}

.student-activity-pagination-controls {
    display: flex;
    align-items: center;
    gap: 10px;
}

.student-activity-pagination-btn {
    padding: 8px 16px;
    border: 1px solid #d1d5db;
    background: #ffffff;
    color: #1f2937;
    border-radius: 8px;
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}

.student-activity-pagination-btn:hover:not(:disabled) {
    background: #f1f5f9;
    border-color: #9ca3af;
}

.student-activity-pagination-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.student-activity-page-numbers {
    display: flex;
    gap: 8px;
    align-items: center;
}

.student-activity-page-number {
    padding: 8px 12px;
    border: 1px solid #d1d5db;
    background: #ffffff;
    color: #1f2937;
    border-radius: 8px;
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer;
    min-width: 40px;
    text-align: center;
    transition: all 0.2s;
}

.student-activity-page-number:hover {
    background: #f1f5f9;
    border-color: #9ca3af;
}

.student-activity-page-number.active {
    background: #3b82f6;
    color: #ffffff;
    border-color: #3b82f6;
}

.student-activity-show-entries {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.9rem;
    color: #6b7280;
}

.student-activity-show-entries select {
    padding: 6px 12px;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    font-size: 0.9rem;
    color: #1f2937;
    background: #ffffff;
    cursor: pointer;
}
</style>

<div class="student-activity-log-container">
    <div class="student-activity-chart-card">
        <div class="student-activity-chart-header">
            <h4><i class="fa fa-line-chart"></i> Number of Logins</h4>
            <p class="student-activity-chart-helper">Track daily student login activity over the past 30 days</p>
        </div>
        <?php if (!empty($login_trend_data['dates'])): ?>
        <div style="position: relative; height: 340px;">
            <canvas id="studentLoginActivityChart"></canvas>
        </div>
        <?php else: ?>
        <div style="padding: 60px 20px; text-align: center; color: #9ca3af;">
            <i class="fa fa-chart-line" style="font-size: 3rem; margin-bottom: 15px; display: block;"></i>
            <p>No login data available for the last 30 days.</p>
        </div>
        <?php endif; ?>
    </div>

    <div class="student-activity-table-container">
        <div class="student-activity-table-header">
            <div class="student-activity-table-title">
                <i class="fa fa-id-card"></i>
                Student Login Activity Details
            </div>
            <div class="student-activity-search-container">
                <i class="fa fa-search student-activity-search-icon"></i>
                <input type="search" id="studentActivitySearch" class="student-activity-search-input" placeholder="Search students by name or email..." autocomplete="off" />
                <button type="button" id="studentActivityClear" class="student-activity-clear-btn">
                    <i class="fa fa-times"></i> Clear
                </button>
            </div>
        </div>
        
        <?php if (!empty($student_login_activity)): ?>
        <div class="student-activity-table-wrapper">
            <table class="student-activity-table">
                <thead>
                    <tr>
                        <th>Student Name</th>
                        <th>Email</th>
                        <th>User Created</th>
                        <th>First Login</th>
                        <th>Last Login</th>
                        <th>Total Logins</th>
                    </tr>
                </thead>
                <tbody id="studentActivityTableBody">
                    <?php foreach ($student_login_activity as $student): ?>
                    <tr class="student-row" data-name="<?php echo strtolower(htmlspecialchars($student->firstname . ' ' . $student->lastname)); ?>" data-email="<?php echo strtolower(htmlspecialchars($student->email)); ?>">
                        <td><?php echo htmlspecialchars($student->firstname . ' ' . $student->lastname); ?></td>
                        <td><?php echo htmlspecialchars($student->email); ?></td>
                        <td><?php echo $student->timecreated ? userdate($student->timecreated, get_string('strftimedatefullshort')) : 'N/A'; ?></td>
                        <td><?php echo $student->firstaccess ? userdate($student->firstaccess, get_string('strftimedatefullshort')) : 'Never'; ?></td>
                        <td><?php echo $student->lastaccess ? userdate($student->lastaccess, get_string('strftimedatefullshort')) : 'Never'; ?></td>
                        <td><span class="student-activity-login-badge"><?php echo (int)$student->login_count; ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <div id="studentActivityEmpty" class="student-activity-empty-state" style="display: none;">
            <i class="fa fa-info-circle"></i>
            <p style="font-weight: 600; margin: 0;">No students found matching your search.</p>
        </div>
        
        <div class="student-activity-pagination">
            <div class="student-activity-show-entries">
                <span>Show:</span>
                <select id="studentActivityEntriesPerPage">
                    <option value="10" selected>10 entries</option>
                    <option value="25">25 entries</option>
                    <option value="50">50 entries</option>
                    <option value="100">100 entries</option>
                </select>
            </div>
            <div id="studentActivityPaginationInfo" class="student-activity-pagination-info">Showing 0 to 0 of <?php echo count($student_login_activity); ?> entries</div>
            <div class="student-activity-pagination-controls">
                <button type="button" id="studentActivityPrev" class="student-activity-pagination-btn">&lt; Previous</button>
                <div id="studentActivityPageNumbers" class="student-activity-page-numbers"></div>
                <button type="button" id="studentActivityNext" class="student-activity-pagination-btn">Next &gt;</button>
            </div>
        </div>
        <?php else: ?>
        <div class="student-activity-empty-state">
            <i class="fa fa-info-circle"></i>
            <p style="font-weight: 600; margin: 0;">No student login activity data available.</p>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($login_trend_data['dates'])): ?>
<script>
// Initialize Login Trend Chart (matching course_reports.php pattern exactly)
(function() {
    const dates = <?php echo json_encode($login_trend_data['dates']); ?>;
    const studentLogins = <?php echo json_encode($login_trend_data['student_logins']); ?>;
    
    // Format dates for display (show only every 5th date to avoid crowding)
    const formattedDates = dates.map((date, index) => {
        if (index % 5 === 0 || index === dates.length - 1) {
            return new Date(date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
        }
        return '';
    });
    
    function createChart() {
        const loginTrendCtx = document.getElementById('studentLoginActivityChart');
        
        if (!loginTrendCtx) {
            return false;
        }
        
        if (typeof Chart === 'undefined') {
            return false;
        }
        
        // Destroy existing chart if any
        if (window.studentLoginChartInstance) {
            try {
                window.studentLoginChartInstance.destroy();
            } catch(e) {}
            window.studentLoginChartInstance = null;
        }
        
        try {
            window.studentLoginChartInstance = new Chart(loginTrendCtx, {
        type: 'line',
        data: {
            labels: formattedDates,
            datasets: [{
                label: 'Student Logins',
                data: studentLogins || [],
                borderColor: '#3b82f6',
                backgroundColor: 'rgba(59, 130, 246, 0.1)',
                borderWidth: 3,
                fill: true,
                tension: 0.4,
                pointBackgroundColor: '#3b82f6',
                pointBorderColor: '#ffffff',
                pointBorderWidth: 2,
                pointRadius: 5,
                pointHoverRadius: 7
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
                        usePointStyle: true,
                        padding: 20,
                        font: {
                            size: 12,
                            weight: '500'
                        }
                    }
                },
                tooltip: {
                    backgroundColor: 'rgba(0, 0, 0, 0.8)',
                    titleColor: '#ffffff',
                    bodyColor: '#ffffff',
                    borderColor: '#e5e7eb',
                    borderWidth: 1,
                    cornerRadius: 8,
                    displayColors: true,
                    callbacks: {
                        title: function(context) {
                            const dataIndex = context[0].dataIndex;
                            return new Date(dates[dataIndex]).toLocaleDateString('en-US', { 
                                weekday: 'long', 
                                year: 'numeric', 
                                month: 'long', 
                                day: 'numeric' 
                            });
                        },
                        label: function(context) {
                            const label = context.dataset.label || '';
                            const value = context.parsed.y;
                            return label + ': ' + value + (value === 1 ? ' login' : ' logins');
                        }
                    }
                }
            },
            scales: {
                x: {
                    display: true,
                    title: {
                        display: true,
                        text: 'Date',
                        font: {
                            size: 12,
                            weight: 'bold'
                        },
                        color: '#6b7280'
                    },
                    grid: {
                        display: false
                    },
                    ticks: {
                        color: '#6b7280',
                        font: {
                            size: 11
                        },
                        maxRotation: 45,
                        minRotation: 45
                    }
                },
                y: {
                    display: true,
                    title: {
                        display: true,
                        text: 'Number of Logins',
                        font: {
                            size: 12,
                            weight: 'bold'
                        },
                        color: '#6b7280'
                    },
                    grid: {
                        color: '#f3f4f6',
                        drawBorder: false
                    },
                    ticks: {
                        color: '#6b7280',
                        font: {
                            size: 11
                        },
                        beginAtZero: true,
                        stepSize: 1
                    }
                }
            },
            interaction: {
                intersect: false,
                mode: 'index'
            }
        }
            });
            return true;
        } catch (error) {
            console.error('Error initializing student login chart:', error);
            return false;
        }
    }
    
    // Make function globally accessible
    window.initStudentLoginChart = function() {
        return createChart();
    };
    
    // Try to create chart - wait for both canvas and Chart.js
    if (createChart()) {
        // Success
    } else {
        // Retry for AJAX-loaded content
        let attempts = 0;
        const maxAttempts = 100;
        
        const retryInterval = setInterval(function() {
            attempts++;
            if (createChart() || attempts >= maxAttempts) {
                clearInterval(retryInterval);
            }
        }, 100);
    }
})();
</script>
<?php endif; ?>

<script>
// Table search and pagination
(function() {
    const allRows = document.querySelectorAll('.student-row');
    let entriesPerPage = 10;
    let currentPage = 1;
    
    const searchInput = document.getElementById('studentActivitySearch');
    const clearBtn = document.getElementById('studentActivityClear');
    const tableBody = document.getElementById('studentActivityTableBody');
    const emptyState = document.getElementById('studentActivityEmpty');
    const prevBtn = document.getElementById('studentActivityPrev');
    const nextBtn = document.getElementById('studentActivityNext');
    const pageNumbers = document.getElementById('studentActivityPageNumbers');
    const paginationInfo = document.getElementById('studentActivityPaginationInfo');
    const entriesSelect = document.getElementById('studentActivityEntriesPerPage');
    
    if (!allRows.length || !tableBody) {
        return;
    }
    
    let filteredRows = Array.from(allRows);
    const totalRows = allRows.length;
    
    function updateDisplay() {
        const searchTerm = searchInput ? searchInput.value.trim().toLowerCase() : '';
        
        // Filter rows
        filteredRows = Array.from(allRows).filter(row => {
            if (!searchTerm) return true;
            const name = row.getAttribute('data-name') || '';
            const email = row.getAttribute('data-email') || '';
            return name.includes(searchTerm) || email.includes(searchTerm);
        });
        
        // Hide all rows
        allRows.forEach(row => row.style.display = 'none');
        
        // Calculate pagination
        const totalPages = Math.ceil(filteredRows.length / entriesPerPage);
        const startIndex = (currentPage - 1) * entriesPerPage;
        const endIndex = startIndex + entriesPerPage;
        const pageRows = filteredRows.slice(startIndex, endIndex);
        
        // Show page rows
        pageRows.forEach(row => row.style.display = '');
        
        // Update empty state
        if (emptyState) {
            emptyState.style.display = filteredRows.length === 0 ? 'block' : 'none';
        }
        if (tableBody) {
            tableBody.style.display = filteredRows.length === 0 ? 'none' : '';
        }
        
        // Update pagination info
        if (paginationInfo) {
            const start = filteredRows.length === 0 ? 0 : startIndex + 1;
            const end = Math.min(endIndex, filteredRows.length);
            paginationInfo.textContent = `Showing ${start} to ${end} of ${filteredRows.length} entries`;
        }
        
        // Update pagination buttons
        if (prevBtn) prevBtn.disabled = currentPage <= 1;
        if (nextBtn) nextBtn.disabled = currentPage >= totalPages || totalPages === 0;
        
        // Update page numbers
        if (pageNumbers) {
            const maxButtons = 5;
            let startPage = Math.max(1, currentPage - Math.floor(maxButtons / 2));
            let endPage = Math.min(startPage + maxButtons - 1, totalPages);
            
            if (endPage - startPage < maxButtons - 1) {
                startPage = Math.max(1, endPage - maxButtons + 1);
            }
            
            let html = '';
            if (startPage > 1) {
                html += `<button class="student-activity-page-number" data-page="1">1</button>`;
                if (startPage > 2) html += `<span style="padding: 8px; color: #6b7280;">...</span>`;
            }
            
            for (let i = startPage; i <= endPage; i++) {
                html += `<button class="student-activity-page-number ${i === currentPage ? 'active' : ''}" data-page="${i}">${i}</button>`;
            }
            
            if (endPage < totalPages) {
                if (endPage < totalPages - 1) html += `<span style="padding: 8px; color: #6b7280;">...</span>`;
                html += `<button class="student-activity-page-number" data-page="${totalPages}">${totalPages}</button>`;
            }
            
            pageNumbers.innerHTML = html;
            
            // Add click handlers
            pageNumbers.querySelectorAll('.student-activity-page-number').forEach(btn => {
                btn.addEventListener('click', function() {
                    currentPage = parseInt(this.getAttribute('data-page'));
                    updateDisplay();
                });
            });
        }
    }
    
    // Event listeners
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            currentPage = 1;
            updateDisplay();
        });
    }
    
    if (clearBtn) {
        clearBtn.addEventListener('click', function() {
            if (searchInput) {
                searchInput.value = '';
                currentPage = 1;
                updateDisplay();
            }
        });
    }
    
    if (prevBtn) {
        prevBtn.addEventListener('click', function() {
            if (currentPage > 1) {
                currentPage--;
                updateDisplay();
            }
        });
    }
    
    if (nextBtn) {
        nextBtn.addEventListener('click', function() {
            const totalPages = Math.ceil(filteredRows.length / entriesPerPage);
            if (currentPage < totalPages) {
                currentPage++;
                updateDisplay();
            }
        });
    }
    
    if (entriesSelect) {
        entriesSelect.addEventListener('change', function() {
            entriesPerPage = parseInt(this.value);
            currentPage = 1;
            updateDisplay();
        });
    }
    
    // Initial display
    updateDisplay();
})();
</script>
<?php
echo ob_get_clean();
exit;
?>
