<?php
/**
 * User Login Report - School Manager
 * Custom styled user login report for school managers
 */

require_once(__DIR__ . '/../../../config.php');
require_login();

global $USER, $DB, $CFG, $OUTPUT, $PAGE;

// Check if user has company manager role (school manager)
$companymanagerrole = $DB->get_record('role', ['shortname' => 'companymanager']);
$is_company_manager = false;

if ($companymanagerrole) {
    $context = context_system::instance();
    $is_company_manager = user_has_role_assignment($USER->id, $companymanagerrole->id, $context->id);
}

// If not a company manager, redirect
if (!$is_company_manager) {
    redirect($CFG->wwwroot . '/my/', 'Access denied. School manager role required.', null, \core\output\notification::NOTIFY_ERROR);
}

// Get company information for the current user
$company_info = null;
if ($DB->get_manager()->table_exists('company') && $DB->get_manager()->table_exists('company_users')) {
    $company_info = $DB->get_record_sql(
        "SELECT c.* 
         FROM {company} c 
         JOIN {company_users} cu ON c.id = cu.companyid 
         WHERE cu.userid = ? AND cu.managertype = 1",
        [$USER->id]
    );
}

if (!$company_info) {
    redirect($CFG->wwwroot . '/my/', 'Company information not found.', null, \core\output\notification::NOTIFY_ERROR);
}

// Get filter parameters
$firstname_filter = optional_param('firstname', '', PARAM_ALPHA);
$lastname_filter = optional_param('lastname', '', PARAM_ALPHA);

// Get all users for this company with their login stats
$sql = "SELECT u.id, u.firstname, u.lastname, u.email, u.username,
               u.timecreated, u.firstaccess, u.lastaccess, u.suspended,
               (SELECT COUNT(*) FROM {logstore_standard_log} l 
                WHERE l.userid = u.id AND l.action = 'loggedin') as login_count,
               GROUP_CONCAT(DISTINCT r.shortname SEPARATOR ', ') as roles
        FROM {user} u
        INNER JOIN {company_users} cu ON cu.userid = u.id
        LEFT JOIN {role_assignments} ra ON ra.userid = u.id
        LEFT JOIN {role} r ON r.id = ra.roleid AND r.shortname IN ('student', 'teacher', 'editingteacher')
        WHERE cu.companyid = ?
        AND u.deleted = 0
        AND u.id != 1";

$params = [$company_info->id];

// Apply filters
if (!empty($firstname_filter)) {
    $sql .= " AND " . $DB->sql_like('u.firstname', ':firstname', false);
    $params['firstname'] = $firstname_filter . '%';
}

if (!empty($lastname_filter)) {
    $sql .= " AND " . $DB->sql_like('u.lastname', ':lastname', false);
    $params['lastname'] = $lastname_filter . '%';
}

$sql .= " GROUP BY u.id, u.firstname, u.lastname, u.email, u.username, u.timecreated, u.firstaccess, u.lastaccess, u.suspended
          ORDER BY u.lastname ASC, u.firstname ASC";

$users = $DB->get_records_sql($sql, $params);

// Prepare sidebar context
$sidebarcontext = [
    'company_name' => $company_info->name,
    'user_info' => [
        'fullname' => fullname($USER),
        'firstname' => $USER->firstname,
        'lastname' => $USER->lastname
    ],
    'current_page' => 'user_reports',
    'user_reports_active' => true,
    'config' => [
        'wwwroot' => $CFG->wwwroot
    ]
];

// Set page context
$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/school_manager/user_login_report.php');
$PAGE->set_title('User Login Report - ' . $company_info->name);
$PAGE->set_heading('User Login Report');

echo $OUTPUT->header();

// Render sidebar
try {
    echo $OUTPUT->render_from_template('theme_remui_kids/school_manager_sidebar', $sidebarcontext);
} catch (Exception $e) {
    echo "<!-- Sidebar error: " . $e->getMessage() . " -->";
}

?>

<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');

/* Ensure sidebar is visible */
.school-manager-sidebar {
    position: fixed !important;
    top: 55px !important;
    left: 0 !important;
    width: 280px !important;
    height: calc(100vh - 55px) !important;
    background: linear-gradient(180deg, #2C3E50 0%, #34495E 100%) !important;
    z-index: 1000 !important;
    display: block !important;
    visibility: visible !important;
    opacity: 1 !important;
}

.school-manager-main-content {
    position: fixed;
    top: 55px;
    left: 280px;
    right: 0;
    bottom: 0;
    overflow-y: auto;
    background: #f8fafc;
    font-family: 'Inter', sans-serif;
    padding: 20px;
    box-sizing: border-box;
}

.main-content {
    max-width: 1600px;
    margin: 0 auto;
}

.page-header {
    background: linear-gradient(135deg, #e0bbe4 0%, #a7dbd8 100%);
    border-radius: 16px;
    padding: 40px;
    margin-bottom: 30px;
    box-shadow: 0 5px 20px rgba(167, 219, 216, 0.3);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.page-title {
    font-size: 2.5rem;
    font-weight: 700;
    margin: 0 0 10px 0;
    color: #36454f;
}

.page-subtitle {
    font-size: 1.1rem;
    margin: 0;
    color: #696969;
}

.back-btn {
    background: #b2dfdb;
    color: #36454f;
    padding: 12px 24px;
    border-radius: 10px;
    text-decoration: none;
    font-weight: 600;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.back-btn:hover {
    background: #a0cfc9;
    transform: translateY(-2px);
}

.filters-section {
    background: white;
    border-radius: 16px;
    padding: 30px;
    margin-bottom: 25px;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
}

.filters-title {
    font-size: 1.3rem;
    font-weight: 700;
    color: #1f2937;
    margin: 0 0 20px 0;
}

.filter-row {
    display: flex;
    gap: 15px;
    align-items: center;
    margin-bottom: 15px;
}

.filter-label {
    font-weight: 600;
    color: #4b5563;
    min-width: 100px;
}

.alpha-filter {
    display: flex;
    gap: 5px;
    flex-wrap: wrap;
}

.alpha-btn {
    padding: 8px 12px;
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 500;
    color: #374151;
    transition: all 0.3s ease;
    text-decoration: none;
    min-width: 40px;
    text-align: center;
}

.alpha-btn:hover {
    background: #f9fafb;
    border-color: #667eea;
    color: #667eea;
}

.alpha-btn.active {
    background: #667eea;
    border-color: #667eea;
    color: white;
}

.download-section {
    background: white;
    border-radius: 16px;
    padding: 20px 30px;
    margin-bottom: 25px;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
    display: flex;
    align-items: center;
    gap: 15px;
}

.download-label {
    font-weight: 600;
    color: #4b5563;
}

.download-select {
    padding: 10px 15px;
    border: 2px solid #e5e7eb;
    border-radius: 10px;
    font-size: 14px;
    color: #1f2937;
    background: white;
    cursor: pointer;
    transition: all 0.3s ease;
}

.download-select:focus {
    outline: none;
    border-color: #667eea;
}

.download-btn {
    padding: 10px 24px;
    background: #667eea;
    color: white;
    border: none;
    border-radius: 10px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    gap: 8px;
}

.download-btn:hover {
    background: #5568d3;
    transform: translateY(-2px);
}

.report-table-container {
    background: white;
    border-radius: 16px;
    padding: 30px;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
    overflow-x: auto;
}

.report-table {
    width: 100%;
    border-collapse: collapse;
}

.report-table thead {
    background: white;
    border-bottom: 2px solid #e5e7eb;
}

.report-table thead th {
    padding: 15px;
    text-align: left;
    font-weight: 600;
    font-size: 0.85rem;
    color: #6b7280;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border-bottom: 2px solid #e5e7eb;
}

.report-table tbody tr {
    background: white;
    transition: all 0.2s ease;
    border-bottom: 1px solid #f0f2f5;
}

.report-table tbody tr:hover {
    background: #f8fafc;
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.report-table tbody td {
    padding: 12px 15px;
    color: #1f2937;
    font-size: 0.9rem;
    vertical-align: middle;
}

.user-name-link {
    color: #3b82f6;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.2s ease;
}

.user-name-link:hover {
    color: #2563eb;
    text-decoration: underline;
}

.role-badge {
    display: inline-block;
    padding: 5px 14px;
    background: #dbeafe;
    color: #1e40af;
    border-radius: 15px;
    font-size: 0.85rem;
    font-weight: 600;
    white-space: nowrap;
}

.login-count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 5px 12px;
    background: #dbeafe;
    color: #1e40af;
    border-radius: 12px;
    font-weight: 700;
    min-width: 40px;
}

.never-accessed {
    color: #9ca3af;
    font-style: italic;
}

.status-badge {
    display: inline-block;
    padding: 5px 14px;
    border-radius: 15px;
    font-size: 0.85rem;
    font-weight: 600;
}

.status-active {
    background: #dcfce7;
    color: #166534;
}

.status-suspended {
    background: #fee2e2;
    color: #991b1b;
}

@media (max-width: 1024px) {
    .school-manager-main-content {
        left: 0;
        width: 100%;
    }
}

@media (max-width: 768px) {
    .page-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 15px;
    }
    
    .page-title {
        font-size: 2rem;
    }
    
    .filter-row {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .alpha-filter {
        width: 100%;
    }
}
</style>

<div class="school-manager-main-content">
    <div class="main-content">
        
        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h1 class="page-title">User Login Report</h1>
                <p class="page-subtitle">Detailed login statistics for <?php echo htmlspecialchars($company_info->name); ?></p>
            </div>
            <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/school_manager/reports.php" class="back-btn">
                <i class="fa fa-arrow-left"></i> Back to Reports
            </a>
        </div>
        
        <!-- Download Section -->
        <div class="download-section">
            <span class="download-label">Download table data as:</span>
            <select class="download-select" id="download_format">
                <option value="pdf">PDF (.pdf)</option>
                <option value="csv">Comma separated values (.csv)</option>
                <option value="excel">Excel (.xlsx)</option>
            </select>
            <button class="download-btn" onclick="downloadReport()">
                <i class="fa fa-download"></i> Download
            </button>
        </div>
        
        <!-- Report Table -->
        <div class="report-table-container">
            <table class="report-table" id="user_login_table">
                <thead>
                    <tr>
                        <th>First name / Last name</th>
                        <th>Email address</th>
                        <th>Role</th>
                        <th>User created</th>
                        <th>First access</th>
                        <th>Last access</th>
                        <th>Total logins</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if (empty($users)) {
                        echo "<tr><td colspan='8' style='text-align: center; padding: 40px; color: #9ca3af;'>No users found matching the filters.</td></tr>";
                    } else {
                        foreach ($users as $user) {
                            $user_created = date('Y-m-d', $user->timecreated);
                            $first_access = $user->firstaccess ? date('Y-m-d', $user->firstaccess) : 'Never';
                            $last_access = $user->lastaccess ? date('Y-m-d', $user->lastaccess) : 'Never';
                            $login_count = $user->login_count ?? 0;
                            
                            // Determine role display
                            $role_display = 'User';
                            if (!empty($user->roles)) {
                                $roles_array = explode(', ', $user->roles);
                                if (in_array('editingteacher', $roles_array)) {
                                    $role_display = 'Editing Teacher';
                                } elseif (in_array('teacher', $roles_array)) {
                                    $role_display = 'Teacher';
                                } elseif (in_array('student', $roles_array)) {
                                    $role_display = 'Student';
                                }
                            }
                            
                            $status_class = $user->suspended ? 'status-suspended' : 'status-active';
                            $status_text = $user->suspended ? 'Suspended' : 'Active';
                            
                            echo "<tr>";
                            echo "<td><a href='{$CFG->wwwroot}/theme/remui_kids/school_manager/user_detail.php?userid={$user->id}' class='user-name-link'>" . htmlspecialchars(fullname($user)) . "</a></td>";
                            echo "<td>" . htmlspecialchars($user->email) . "</td>";
                            echo "<td><span class='role-badge'>" . htmlspecialchars($role_display) . "</span></td>";
                            echo "<td>" . htmlspecialchars($user_created) . "</td>";
                            echo "<td class='" . ($first_access === 'Never' ? 'never-accessed' : '') . "'>" . htmlspecialchars($first_access) . "</td>";
                            echo "<td class='" . ($last_access === 'Never' ? 'never-accessed' : '') . "'>" . htmlspecialchars($last_access) . "</td>";
                            echo "<td><span class='login-count'>" . $login_count . "</span></td>";
                            echo "<td><span class='status-badge " . $status_class . "'>" . $status_text . "</span></td>";
                            echo "</tr>";
                        }
                    }
                    ?>
                </tbody>
            </table>
        </div>
        
    </div>
</div>

<script>
function downloadReport() {
    const format = document.getElementById('download_format').value;
    const table = document.getElementById('user_login_table');
    
    if (format === 'csv') {
        downloadCSV(table);
    } else if (format === 'excel') {
        alert('Excel export coming soon!');
    } else if (format === 'pdf') {
        alert('PDF export coming soon!');
    }
}

function downloadCSV(table) {
    let csv = [];
    const rows = table.querySelectorAll('tr');
    
    rows.forEach(row => {
        const cols = row.querySelectorAll('td, th');
        const rowData = [];
        
        cols.forEach(col => {
            let text = col.innerText;
            // Escape quotes and wrap in quotes if contains comma
            text = text.replace(/"/g, '""');
            if (text.includes(',')) {
                text = '"' + text + '"';
            }
            rowData.push(text);
        });
        
        csv.push(rowData.join(','));
    });
    
    const csvContent = csv.join('\n');
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    const url = URL.createObjectURL(blob);
    
    link.setAttribute('href', url);
    link.setAttribute('download', 'user_login_report_<?php echo date('Y-m-d'); ?>.csv');
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
</script>

<?php
echo $OUTPUT->footer();
?>

