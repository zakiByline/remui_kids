<?php
require_once('../../config.php');
require_once($CFG->libdir . '/adminlib.php');

// Ensure user is logged in
require_login();

// Get current user and company information
$user = $USER;
$company_id = null;
$company_info = null;

// Get company information for the current user
try {
    $company_info = $DB->get_record_sql(
        "SELECT c.*, cu.managertype 
         FROM {company} c 
         JOIN {company_users} cu ON c.id = cu.companyid 
         WHERE cu.userid = ? AND cu.managertype = 1",
        [$user->id]
    );
    
    if ($company_info) {
        $company_id = $company_info->id;
    }
} catch (Exception $e) {
    error_log("Error getting company info: " . $e->getMessage());
}

// Redirect if not a school manager
if (!$company_info) {
    redirect(new moodle_url('/my/'), 'Access denied. You must be a school manager to access this page.', null, \core\output\notification::NOTIFY_ERROR);
}

// Get school departments
$departments = [];
try {
    $departments = $DB->get_records_sql(
        "SELECT cd.*, COUNT(cu.userid) as user_count
         FROM {company_departments} cd
         LEFT JOIN {company_users} cu ON cu.departmentid = cd.id AND cu.companyid = ?
         WHERE cd.companyid = ?
         GROUP BY cd.id, cd.name, cd.description, cd.companyid
         ORDER BY cd.name",
        [$company_id, $company_id]
    );
} catch (Exception $e) {
    error_log("Error getting departments: " . $e->getMessage());
}

// Get email templates
$email_templates = [];
try {
    $email_templates = $DB->get_records_sql(
        "SELECT et.*, c.name as company_name
         FROM {email_templates} et
         INNER JOIN {company} c ON c.id = et.companyid
         WHERE et.companyid = ?
         ORDER BY et.name",
        [$company_id]
    );
} catch (Exception $e) {
    error_log("Error getting email templates: " . $e->getMessage());
}

// Get user profile fields
$profile_fields = [];
try {
    $profile_fields = $DB->get_records('user_info_field', null, 'sortorder ASC');
} catch (Exception $e) {
    error_log("Error getting profile fields: " . $e->getMessage());
}

// Prepare sidebar context
$sidebarcontext = [
    'company_name' => $company_info->name,
    'user_info' => [
        'fullname' => fullname($user),
        'firstname' => $user->firstname,
        'lastname' => $user->lastname
    ],
    'current_page' => 'settings',
    'settings_active' => true,
    'dashboard_active' => false,
    'teachers_active' => false,
    'students_active' => false,
    'courses_active' => false,
    'enrollments_active' => false,
    'add_users_active' => false,
    'analytics_active' => false,
    'reports_active' => false,
    'course_reports_active' => false,
    'help_active' => false,
    'config' => [
        'wwwroot' => $CFG->wwwroot
    ]
];

// Set page context
$PAGE->set_context(context_system::instance());
$PAGE->set_url('/theme/remui_kids/school_manager_settings.php');
$PAGE->set_title('Settings - ' . $company_info->name);
$PAGE->set_heading('School Manager Settings');

// Output the header first
echo $OUTPUT->header();

// Render the school manager sidebar using the correct Moodle method
try {
    // Debug: Check if template exists
    $template_path = $CFG->dirroot . '/theme/remui_kids/templates/school_manager_sidebar.mustache';
    if (!file_exists($template_path)) {
        throw new Exception("Template file not found: " . $template_path);
    }
    
    // Debug: Log template rendering
    error_log("Rendering school_manager_sidebar template for settings page");
    error_log("Template path: " . $template_path);
    error_log("Settings active: " . ($sidebarcontext['settings_active'] ? 'true' : 'false'));
    
    echo $OUTPUT->render_from_template('theme_remui_kids/school_manager_sidebar', $sidebarcontext);
} catch (Exception $e) {
    // Fallback: show error message and basic sidebar
    echo "<div style='color: red; padding: 20px;'>Error loading sidebar: " . htmlspecialchars($e->getMessage()) . "</div>";
    echo "<div class='school-manager-sidebar' style='position: fixed; top: 0; left: 0; width: 280px; height: 100vh; background: #d4edda; z-index: 1000;'>";
    echo "<div style='padding: 20px; text-align: center;'>";
    echo "<h2 style='color: #2c3e50; margin: 0;'>" . htmlspecialchars($sidebarcontext['company_name']) . "</h2>";
    echo "<p style='color: #495057; margin: 10px 0;'>" . htmlspecialchars($sidebarcontext['user_info']['fullname']) . "</p>";
    echo "<div style='background: #007bff; color: white; padding: 5px 15px; border-radius: 15px; display: inline-block;'>School Manager</div>";
    echo "</div>";
    echo "<nav style='padding: 20px 0;'>";
    echo "<a href='{$CFG->wwwroot}/my/' style='display: block; padding: 15px 20px; color: #495057; text-decoration: none;'>School Admin Dashboard</a>";
    echo "<a href='{$CFG->wwwroot}/theme/remui_kids/teacher_management.php' style='display: block; padding: 15px 20px; color: #495057; text-decoration: none;'>Teacher Management</a>";
    echo "<a href='{$CFG->wwwroot}/theme/remui_kids/student_management.php' style='display: block; padding: 15px 20px; color: #495057; text-decoration: none;'>Student Management</a>";
    echo "<a href='{$CFG->wwwroot}/theme/remui_kids/school_manager_settings.php' style='display: block; padding: 15px 20px; color: #007cba; background: #e3f2fd; text-decoration: none; font-weight: 600;'>Settings</a>";
    echo "</nav>";
    echo "</div>";
}

// Custom CSS for the settings layout
echo "<style>";
echo "
/* Import Google Fonts - Must be at the top */
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');

/* School Manager Main Content Area with Sidebar */
.school-manager-main-content {
    position: fixed;
    top: 55px;
    left: 280px;
    right: 0;
    bottom: 0;
    overflow-y: auto;
    background: #f8fafc;
    font-family: 'Inter', sans-serif;
}

/* Main content positioning to work with the new sidebar template */
.main-content {
    padding: 20px 20px 20px 20px;
    padding-top: 35px;
    min-height: 100vh;
    max-width: 1400px;
    margin: 0 auto;
}

/* Page Header */
.page-header {
    background: linear-gradient(180deg, rgba(99, 102, 241, 0.25), rgba(99, 102, 241, 0.08), #ffffff);
    color: #2c3e50;
    padding: 25px 30px;
    border-radius: 15px;
    margin-bottom: 30px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.08);
    margin-top: 35px;
    position: relative;
    display: flex;
    align-items: center;
    border: 2px solid rgba(99, 102, 241, 0.3);
    justify-content: space-between;
}

.page-header-content {
    flex: 1;
    text-align: center;
}

.page-title {
    font-size: 2.2rem;
    font-weight: 700;
    margin: 0 0 8px 0;
    color: #2c3e50;
}

.page-subtitle {
    font-size: 1rem;
    color: #6c757d;
    margin: 0;
    font-weight: 400;
}

/* Back Button */
.back-button {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: rgba(44, 62, 80, 0.1);
    color: #2c3e50;
    text-decoration: none;
    padding: 10px 16px;
    border-radius: 8px;
    font-weight: 500;
    transition: all 0.3s ease;
    backdrop-filter: blur(10px);
    font-size: 0.9rem;
}

.back-button:hover {
    background: rgba(44, 62, 80, 0.2);
    color: #2c3e50;
    text-decoration: none;
    transform: translateY(-2px);
}

/* Simple Settings Grid */
.simple-settings-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1.5rem;
    margin-bottom: 2rem;
    margin-top: 20px;
}

.simple-settings-card {
    background: white;
    border-radius: 16px;
    padding: 2rem;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
    border: 1px solid #e5e7eb;
    transition: all 0.3s ease;
    text-align: center;
    cursor: pointer;
    position: relative;
    overflow: hidden;
    min-height: 200px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    text-decoration: none;
    color: inherit;
}

.simple-settings-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 24px rgba(0, 0, 0, 0.15);
    text-decoration: none;
    color: inherit;
}

.simple-settings-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: linear-gradient(90deg, #3b82f6, #8b5cf6);
}

.simple-card-icon {
    width: 80px;
    height: 80px;
    border-radius: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2rem;
    color: white;
    margin: 0 auto 1.5rem auto;
    background: linear-gradient(135deg, #3b82f6, #2563eb);
    box-shadow: 0 8px 20px rgba(59, 130, 246, 0.3);
    transition: all 0.3s ease;
}

.simple-settings-card:hover .simple-card-icon {
    transform: scale(1.1);
    box-shadow: 0 12px 30px rgba(59, 130, 246, 0.4);
}

.simple-card-icon.edit-school {
    background: linear-gradient(135deg, #3b82f6, #2563eb);
}

.simple-card-icon.manager-department {
    background: linear-gradient(135deg, #10b981, #059669);
}

.simple-card-icon.optional-profiles {
    background: linear-gradient(135deg, #8b5cf6, #7c3aed);
}

.simple-card-icon.email-templates {
    background: linear-gradient(135deg, #f59e0b, #d97706);
}

.simple-card-title {
    font-size: 1.25rem;
    font-weight: 700;
    color: #1f2937;
    margin: 0;
    line-height: 1.3;
}
}

/* Statistics Section */
.stats-section {
    background: white;
    border-radius: 20px;
    padding: 30px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
    border: 1px solid #e2e8f0;
    margin-bottom: 50px;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
}

.stat-item {
    text-align: center;
    padding: 20px;
    background: linear-gradient(180deg, rgba(102, 126, 234, 0.08), #ffffff);
    border-radius: 15px;
    border: 1px solid #e2e8f0;
    border-top: 4px solid #667eea;
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
}

.stat-item:nth-child(1) {
    background: linear-gradient(180deg, rgba(102, 126, 234, 0.08), #ffffff);
    border-top-color: #667eea;
}

.stat-item:nth-child(2) {
    background: linear-gradient(180deg, rgba(102, 126, 234, 0.08), #ffffff);
    border-top-color: #667eea;
}

.stat-item:nth-child(3) {
    background: linear-gradient(180deg, rgba(102, 126, 234, 0.08), #ffffff);
    border-top-color: #667eea;
}

.stat-item:nth-child(4) {
    background: linear-gradient(180deg, rgba(102, 126, 234, 0.08), #ffffff);
    border-top-color: #667eea;
}

.stat-item:hover {
    background: white;
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
}

.stat-icon {
    width: 50px;
    height: 50px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    color: white;
    margin: 0 auto 15px;
    background: linear-gradient(135deg, #667eea, #764ba2);
}

.stat-number {
    font-size: 2rem;
    font-weight: 700;
    color: #1e293b;
    margin: 0 0 8px 0;
    line-height: 1;
}

.stat-label {
    font-size: 0.9rem;
    color: #64748b;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}


/* Responsive Design */
@media (max-width: 1200px) {
    .school-manager-main-content {
        position: relative;
        left: 0;
        right: 0;
        bottom: auto;
        overflow-y: visible;
    }
    
    .main-content {
        padding: 20px;
        min-height: 100vh;
    }
    
    .simple-settings-grid {
        grid-template-columns: 1fr;
        gap: 1rem;
    }
}

@media (max-width: 768px) {
    .page-title {
        font-size: 1.8rem;
    }
    
    .settings-card {
        padding: 20px;
    }
    
    .card-icon {
        width: 50px;
        height: 50px;
        font-size: 1.5rem;
    }
    
    .card-title {
        font-size: 1.3rem;
    }
    
    .stats-grid {
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 15px;
    }
    
    
    .card-actions {
        flex-direction: column;
    }
}
";
echo "</style>";

// Main content area
echo "<div class='school-manager-main-content'>";
echo "<div class='main-content'>";

// Page Header
echo "<div class='page-header'>";
echo "<div class='page-header-content'>";
echo "<h1 class='page-title'>Settings</h1>";
echo "<p class='page-subtitle'>Manage your school settings and configurations</p>";
echo "</div>";
echo "<a href='{$CFG->wwwroot}/my/' class='back-button'>";
echo "<i class='fa fa-arrow-left'></i> Back to Dashboard";
echo "</a>";
echo "</div>";

// Statistics Section
echo "<div class='stats-section'>";
echo "<div class='stats-grid'>";
echo "<div class='stat-item'>";
echo "<div class='stat-icon'>";
echo "<i class='fa fa-building'></i>";
echo "</div>";
echo "<div class='stat-number'>1</div>";
echo "<div class='stat-label'>School</div>";
echo "</div>";

echo "<div class='stat-item'>";
echo "<div class='stat-icon'>";
echo "<i class='fa fa-sitemap'></i>";
echo "</div>";
echo "<div class='stat-number'>" . count($departments) . "</div>";
echo "<div class='stat-label'>Departments</div>";
echo "</div>";

echo "<div class='stat-item'>";
echo "<div class='stat-icon'>";
echo "<i class='fa fa-envelope'></i>";
echo "</div>";
echo "<div class='stat-number'>" . count($email_templates) . "</div>";
echo "<div class='stat-label'>Email Templates</div>";
echo "</div>";

echo "<div class='stat-item'>";
echo "<div class='stat-icon'>";
echo "<i class='fa fa-user-cog'></i>";
echo "</div>";
echo "<div class='stat-number'>" . count($profile_fields) . "</div>";
echo "<div class='stat-label'>Profile Fields</div>";
echo "</div>";
echo "</div>";
echo "</div>";


// Simple Settings Cards
echo "<div class='simple-settings-grid'>";

// Edit School Card
echo "<a href='{$CFG->wwwroot}/blocks/iomad_company_admin/company_edit_form.php' class='simple-settings-card'>";
echo "<div class='simple-card-icon edit-school'>";
echo "<i class='fa fa-building'></i>";
echo "</div>";
echo "<h3 class='simple-card-title'>Edit School</h3>";
echo "</a>";

// Manager Department Card
echo "<a href='{$CFG->wwwroot}/blocks/iomad_company_admin/company_departments.php' class='simple-settings-card'>";
echo "<div class='simple-card-icon manager-department'>";
echo "<i class='fa fa-sitemap'></i>";
echo "</div>";
echo "<h3 class='simple-card-title'>Manager Department</h3>";
echo "</a>";

// Optional Profiles Card
echo "<a href='{$CFG->wwwroot}/blocks/iomad_company_admin/company_user_profiles.php' class='simple-settings-card'>";
echo "<div class='simple-card-icon optional-profiles'>";
echo "<i class='fa fa-user-cog'></i>";
echo "</div>";
echo "<h3 class='simple-card-title'>Optional Profiles</h3>";
echo "</a>";

// Email Templates Card
echo "<a href='{$CFG->wwwroot}/iomad/local/email/template_list.php' class='simple-settings-card'>";
echo "<div class='simple-card-icon email-templates'>";
echo "<i class='fa fa-envelope'></i>";
echo "</div>";
echo "<h3 class='simple-card-title'>Email Templates</h3>";
echo "</a>";

echo "</div>"; // End settings-grid

echo "</div>"; // End main content
echo "</div>"; // End school-manager-main-content

echo $OUTPUT->footer();
?>
