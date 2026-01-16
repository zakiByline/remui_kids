<?php
/**
 * Users Report - School Manager
 * Comprehensive user overview report
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

// Get company information
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

// Get all users for this company
$sql = "SELECT u.id, u.firstname, u.lastname, u.email, u.username,
               u.timecreated, u.lastaccess, u.suspended,
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

$sql .= " GROUP BY u.id, u.firstname, u.lastname, u.email, u.username, u.timecreated, u.lastaccess, u.suspended
          ORDER BY u.lastname ASC, u.firstname ASC";

$users = $DB->get_records_sql($sql, $params);

// Prepare sidebar context
$sidebarcontext = [
    'company_name' => $company_info->name,
    'user_info' => [
        'fullname' => fullname($USER),
    ],
    'current_page' => 'user_reports',
    'user_reports_active' => true,
    'config' => [
        'wwwroot' => $CFG->wwwroot
    ]
];

$PAGE->set_context(context_system::instance());
$PAGE->set_url('/theme/remui_kids/school_manager/users_report.php');
$PAGE->set_title('Users Report - ' . $company_info->name);

echo $OUTPUT->header();

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
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 16px;
    padding: 40px;
    margin-bottom: 30px;
    color: white;
    box-shadow: 0 10px 30px rgba(102, 126, 234, 0.3);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.page-title {
    font-size: 2.5rem;
    font-weight: 700;
    margin: 0 0 10px 0;
}

.page-subtitle {
    font-size: 1.1rem;
    opacity: 0.95;
    margin: 0;
}

.back-btn {
    background: rgba(255, 255, 255, 0.2);
    color: white;
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
    background: rgba(255, 255, 255, 0.3);
    transform: translateY(-2px);
}

.report-card {
    background: white;
    border-radius: 16px;
    padding: 35px;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
    margin-bottom: 25px;
}

.report-title {
    font-size: 1.8rem;
    font-weight: 700;
    color: #1f2937;
    margin: 0 0 25px 0;
}

.department-display {
    margin-bottom: 30px;
}

.department-label {
    font-weight: 700;
    color: #374151;
    font-size: 1.1rem;
    margin-bottom: 10px;
}

.department-value {
    padding: 12px 20px;
    background: #f3f4f6;
    border-radius: 10px;
    color: #6b7280;
    font-weight: 500;
}

.filters-section {
    margin-bottom: 30px;
}

.filters-title {
    font-size: 1.2rem;
    font-weight: 700;
    color: #374151;
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
    padding: 20px;
    background: #f9fafb;
    border-radius: 10px;
    margin-bottom: 25px;
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
}

.download-btn:hover {
    background: #5568d3;
}

.report-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
}

.report-table thead th {
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
    padding: 15px;
    text-align: left;
    font-weight: 600;
    font-size: 0.9rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.report-table thead th:first-child {
    border-radius: 10px 0 0 0;
}

.report-table thead th:last-child {
    border-radius: 0 10px 0 0;
}

.report-table tbody tr {
    background: white;
    transition: all 0.2s ease;
}

.report-table tbody tr:hover {
    background: #f8fafc;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
}

.report-table tbody td {
    padding: 15px;
    border-bottom: 1px solid #f3f4f6;
    color: #374151;
    font-size: 0.9rem;
}

.user-name-link {
    color: #667eea;
    font-weight: 600;
    text-decoration: none;
}

.user-name-link:hover {
    color: #764ba2;
    text-decoration: underline;
}

.never-accessed {
    color: #9ca3af;
    font-style: italic;
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
    }
    
    .filter-row {
        flex-direction: column;
        align-items: flex-start;
    }
}
</style>

<div class="school-manager-main-content">
    <div class="main-content">
        
        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h1 class="page-title">User Report</h1>
                <p class="page-subtitle">Comprehensive user overview for <?php echo htmlspecialchars($company_info->name); ?></p>
            </div>
            <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/school_manager/reports.php" class="back-btn">
                <i class="fa fa-arrow-left"></i> Back to Reports
            </a>
        </div>
        
        <!-- Report Card -->
        <div class="report-card">
            <h2 class="report-title">User Report</h2>
            
            <!-- Department Display -->
            <div class="department-display">
                <div class="department-label">Department: <?php echo htmlspecialchars($company_info->name); ?></div>
                <div class="department-value"><?php echo htmlspecialchars($company_info->name); ?></div>
            </div>
            
            <!-- Filters Section -->
            <div class="filters-section">
                <h3 class="filters-title">User search ></h3>
                
                <!-- First Name Filter -->
                <div class="filter-row">
                    <span class="filter-label">First name</span>
                    <div class="alpha-filter">
                        <a href="?" class="alpha-btn <?php echo empty($firstname_filter) ? 'active' : ''; ?>">All</a>
                        <?php
                        foreach (range('A', 'Z') as $letter) {
                            $active = ($firstname_filter === $letter) ? 'active' : '';
                            $current_lastname = !empty($lastname_filter) ? '&lastname=' . $lastname_filter : '';
                            echo "<a href='?firstname=" . $letter . $current_lastname . "' class='alpha-btn $active'>$letter</a>";
                        }
                        ?>
                    </div>
                </div>
                
                <!-- Last Name Filter -->
                <div class="filter-row">
                    <span class="filter-label">Last name</span>
                    <div class="alpha-filter">
                        <a href="?" class="alpha-btn <?php echo empty($lastname_filter) ? 'active' : ''; ?>">All</a>
                        <?php
                        foreach (range('A', 'Z') as $letter) {
                            $active = ($lastname_filter === $letter) ? 'active' : '';
                            $current_firstname = !empty($firstname_filter) ? '&firstname=' . $firstname_filter : '';
                            echo "<a href='?lastname=" . $letter . $current_firstname . "' class='alpha-btn $active'>$letter</a>";
                        }
                        ?>
                    </div>
                </div>
            </div>
            
            <!-- Download Section -->
            <div class="download-section">
                <span class="download-label">Download table data as</span>
                <select class="download-select" id="download_format">
                    <option value="csv">Comma separated values (.csv)</option>
                </select>
                <button class="download-btn" onclick="downloadReport()">
                    <i class="fa fa-download"></i> Download
                </button>
            </div>
            
            <!-- Report Table -->
            <table class="report-table" id="users_table">
                <thead>
                    <tr>
                        <th>First name / Last name <br><span style="font-size: 12px; font-weight: 400;">-</span></th>
                        <th>Department <br><span style="font-size: 12px; font-weight: 400;">-</span></th>
                        <th>Email address <br><span style="font-size: 12px; font-weight: 400;">-</span></th>
                        <th>User created <br><span style="font-size: 12px; font-weight: 400;">-</span></th>
                        <th>Last access <br><span style="font-size: 12px; font-weight: 400;">-</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if (empty($users)) {
                        echo "<tr><td colspan='5' style='text-align: center; padding: 40px; color: #9ca3af;'>No users found matching the filters.</td></tr>";
                    } else {
                        foreach ($users as $user) {
                            $user_created = date('Y-m-d', $user->timecreated);
                            $last_access = $user->lastaccess ? date('Y-m-d', $user->lastaccess) : 'Never';
                            
                            echo "<tr>";
                            echo "<td><a href='{$CFG->wwwroot}/theme/remui_kids/school_manager/user_detail.php?userid={$user->id}' class='user-name-link'>" . htmlspecialchars(fullname($user)) . "</a></td>";
                            echo "<td>" . htmlspecialchars($company_info->name) . "</td>";
                            echo "<td>" . htmlspecialchars($user->email) . "</td>";
                            echo "<td>" . htmlspecialchars($user_created) . "</td>";
                            echo "<td class='" . ($last_access === 'Never' ? 'never-accessed' : '') . "'>" . htmlspecialchars($last_access) . "</td>";
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
    const table = document.getElementById('users_table');
    let csv = [];
    const rows = table.querySelectorAll('tr');
    
    rows.forEach(row => {
        const cols = row.querySelectorAll('td, th');
        const rowData = [];
        
        cols.forEach(col => {
            let text = col.innerText.replace(/\n/g, ' ').trim();
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
    link.setAttribute('download', 'users_report_<?php echo date('Y-m-d'); ?>.csv');
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
</script>

<?php
echo $OUTPUT->footer();
?>

