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

// Handle download requests
if (isset($_GET['download'])) {
    $download_type = $_GET['download'];
    
    switch ($download_type) {
        case 'all_users':
            download_all_users($company_id);
            break;
        case 'teachers':
            download_teachers($company_id);
            break;
        case 'all_students':
            download_all_students($company_id);
            break;
        case 'students_course_wise':
            download_students_course_wise($company_id);
            break;
        case 'students_cohort_wise':
            download_students_cohort_wise($company_id);
            break;
        case 'course_students':
            if (isset($_GET['course_id'])) {
                download_course_students($company_id, $_GET['course_id']);
            }
            break;
        case 'cohort_students':
            if (isset($_GET['cohort'])) {
                download_cohort_students($company_id, $_GET['cohort']);
            }
            break;
        default:
            redirect(new moodle_url('/theme/remui_kids/bulk_download.php'), 'Invalid download type.', null, \core\output\notification::NOTIFY_ERROR);
    }
}

// Function to download all users
function download_all_users($company_id) {
    global $DB;
    
    $users = $DB->get_records_sql(
        "SELECT u.id, u.firstname, u.lastname, u.email, u.phone1, u.phone2, u.username, u.suspended, u.timecreated, u.lastaccess,
                GROUP_CONCAT(DISTINCT r.shortname SEPARATOR ',') AS roles
         FROM {user} u
         INNER JOIN {company_users} cu ON cu.userid = u.id AND cu.companyid = ?
         LEFT JOIN {role_assignments} ra ON ra.userid = u.id
         LEFT JOIN {role} r ON r.id = ra.roleid
         WHERE u.deleted = 0
         GROUP BY u.id, u.firstname, u.lastname, u.email, u.phone1, u.phone2, u.username, u.suspended, u.timecreated, u.lastaccess
         ORDER BY u.firstname, u.lastname",
        [$company_id]
    );
    
    $filename = 'all_users_' . date('Y-m-d_H-i-s') . '.csv';
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'First Name', 'Last Name', 'Email', 'Phone', 'Username', 'Roles', 'Status', 'Created', 'Last Access']);
    
    foreach ($users as $user) {
        fputcsv($output, [
            $user->id,
            $user->firstname,
            $user->lastname,
            $user->email,
            $user->phone,
            $user->username,
            $user->roles,
            $user->suspended ? 'Suspended' : 'Active',
            date('Y-m-d H:i:s', $user->timecreated),
            $user->lastaccess ? date('Y-m-d H:i:s', $user->lastaccess) : 'Never'
        ]);
    }
    
    fclose($output);
    exit;
}

// Function to download teachers
function download_teachers($company_id) {
    global $DB;
    
    $teachers = $DB->get_records_sql(
        "SELECT u.id, u.firstname, u.lastname, u.email, u.phone1, u.phone2, u.username, u.suspended, u.timecreated, u.lastaccess,
                GROUP_CONCAT(DISTINCT r.shortname SEPARATOR ',') AS roles
         FROM {user} u
         INNER JOIN {company_users} cu ON cu.userid = u.id AND cu.companyid = ?
         LEFT JOIN {role_assignments} ra ON ra.userid = u.id
         LEFT JOIN {role} r ON r.id = ra.roleid AND r.shortname IN ('teacher', 'editingteacher', 'coursecreator')
         WHERE u.deleted = 0 AND r.shortname IS NOT NULL
         GROUP BY u.id, u.firstname, u.lastname, u.email, u.phone1, u.phone2, u.username, u.suspended, u.timecreated, u.lastaccess
         ORDER BY u.firstname, u.lastname",
        [$company_id]
    );
    
    $filename = 'teachers_' . date('Y-m-d_H-i-s') . '.csv';
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'First Name', 'Last Name', 'Email', 'Phone', 'Username', 'Roles', 'Status', 'Created', 'Last Access']);
    
    foreach ($teachers as $teacher) {
        fputcsv($output, [
            $teacher->id,
            $teacher->firstname,
            $teacher->lastname,
            $teacher->email,
            $teacher->phone1,
            $teacher->username,
            $teacher->roles,
            $teacher->suspended ? 'Suspended' : 'Active',
            date('Y-m-d H:i:s', $teacher->timecreated),
            $teacher->lastaccess ? date('Y-m-d H:i:s', $teacher->lastaccess) : 'Never'
        ]);
    }
    
    fclose($output);
    exit;
}

// Function to download all students
function download_all_students($company_id) {
    global $DB;
    
    $students = $DB->get_records_sql(
        "SELECT u.id, u.firstname, u.lastname, u.email, u.phone1, u.phone2, u.username, u.suspended, u.timecreated, u.lastaccess,
                uifd.data AS grade_level
         FROM {user} u
         INNER JOIN {company_users} cu ON cu.userid = u.id AND cu.companyid = ?
         LEFT JOIN {user_info_data} uifd ON uifd.userid = u.id
         LEFT JOIN {user_info_field} uiff ON uiff.id = uifd.fieldid AND uiff.shortname = 'gradelevel'
         WHERE u.deleted = 0
         ORDER BY u.firstname, u.lastname",
        [$company_id]
    );
    
    $filename = 'all_students_' . date('Y-m-d_H-i-s') . '.csv';
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'First Name', 'Last Name', 'Email', 'Phone', 'Username', 'Grade Level', 'Status', 'Created', 'Last Access']);
    
    foreach ($students as $student) {
        fputcsv($output, [
            $student->id,
            $student->firstname,
            $student->lastname,
            $student->email,
            $student->phone1,
            $student->username,
            $student->grade_level ?: 'Not assigned',
            $student->suspended ? 'Suspended' : 'Active',
            date('Y-m-d H:i:s', $student->timecreated),
            $student->lastaccess ? date('Y-m-d H:i:s', $student->lastaccess) : 'Never'
        ]);
    }
    
    fclose($output);
    exit;
}

// Function to download students course-wise
function download_students_course_wise($company_id) {
    global $DB;
    
    $students = $DB->get_records_sql(
        "SELECT u.id, u.firstname, u.lastname, u.email, u.username, c.fullname as course_name, c.shortname as course_shortname,
                ue.timecreated as enrolled_date, ue.status as enrollment_status
         FROM {user} u
         INNER JOIN {company_users} cu ON cu.userid = u.id AND cu.companyid = ?
         INNER JOIN {user_enrolments} ue ON ue.userid = u.id
         INNER JOIN {enrol} e ON e.id = ue.enrolid
         INNER JOIN {course} c ON c.id = e.courseid
         WHERE u.deleted = 0
         ORDER BY c.fullname, u.firstname, u.lastname",
        [$company_id]
    );
    
    $filename = 'students_course_wise_' . date('Y-m-d_H-i-s') . '.csv';
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Student ID', 'First Name', 'Last Name', 'Email', 'Username', 'Course Name', 'Course Code', 'Enrolled Date', 'Status']);
    
    foreach ($students as $student) {
        fputcsv($output, [
            $student->id,
            $student->firstname,
            $student->lastname,
            $student->email,
            $student->username,
            $student->course_name,
            $student->course_shortname,
            date('Y-m-d H:i:s', $student->enrolled_date),
            $student->enrollment_status ? 'Active' : 'Inactive'
        ]);
    }
    
    fclose($output);
    exit;
}

// Function to download students cohort-wise
function download_students_cohort_wise($company_id) {
    global $DB;
    
    $students = $DB->get_records_sql(
        "SELECT u.id, u.firstname, u.lastname, u.email, u.username, uifd.data AS grade_level,
                COUNT(DISTINCT c.id) as total_courses
         FROM {user} u
         INNER JOIN {company_users} cu ON cu.userid = u.id AND cu.companyid = ?
         LEFT JOIN {user_info_data} uifd ON uifd.userid = u.id
         LEFT JOIN {user_info_field} uiff ON uiff.id = uifd.fieldid AND uiff.shortname = 'gradelevel'
         LEFT JOIN {user_enrolments} ue ON ue.userid = u.id
         LEFT JOIN {enrol} e ON e.id = ue.enrolid
         LEFT JOIN {course} c ON c.id = e.courseid
         WHERE u.deleted = 0
         GROUP BY u.id, u.firstname, u.lastname, u.email, u.username, uifd.data
         ORDER BY uifd.data, u.firstname, u.lastname",
        [$company_id]
    );
    
    $filename = 'students_cohort_wise_' . date('Y-m-d_H-i-s') . '.csv';
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Student ID', 'First Name', 'Last Name', 'Email', 'Username', 'Grade Level', 'Total Courses']);
    
    foreach ($students as $student) {
        fputcsv($output, [
            $student->id,
            $student->firstname,
            $student->lastname,
            $student->email,
            $student->username,
            $student->grade_level ?: 'Not assigned',
            $student->total_courses
        ]);
    }
    
    fclose($output);
    exit;
}

// Function to download students for a specific course
function download_course_students($company_id, $course_id) {
    global $DB;
    
    $course = $DB->get_record('course', ['id' => $course_id]);
    if (!$course) {
        redirect(new moodle_url('/theme/remui_kids/bulk_download.php'), 'Course not found.', null, \core\output\notification::NOTIFY_ERROR);
    }
    
    $students = $DB->get_records_sql(
        "SELECT u.id, u.firstname, u.lastname, u.email, u.username, uifd.data AS grade_level,
                c.fullname as course_name, c.shortname as course_shortname,
                ue.timecreated as enrolled_date, ue.status as enrollment_status
         FROM {user} u
         INNER JOIN {company_users} cu ON cu.userid = u.id AND cu.companyid = ?
         INNER JOIN {user_enrolments} ue ON ue.userid = u.id
         INNER JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = ?
         INNER JOIN {course} c ON c.id = e.courseid
         LEFT JOIN {user_info_data} uifd ON uifd.userid = u.id
         LEFT JOIN {user_info_field} uiff ON uiff.id = uifd.fieldid AND uiff.shortname = 'gradelevel'
         WHERE u.deleted = 0
         ORDER BY u.firstname, u.lastname",
        [$company_id, $course_id]
    );
    
    $filename = 'course_students_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $course->shortname) . '_' . date('Y-m-d_H-i-s') . '.csv';
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Student ID', 'First Name', 'Last Name', 'Email', 'Username', 'Grade Level', 'Course Name', 'Course Code', 'Enrolled Date', 'Status']);
    
    foreach ($students as $student) {
        fputcsv($output, [
            $student->id,
            $student->firstname,
            $student->lastname,
            $student->email,
            $student->username,
            $student->grade_level ?: 'Not assigned',
            $student->course_name,
            $student->course_shortname,
            date('Y-m-d H:i:s', $student->enrolled_date),
            $student->enrollment_status ? 'Active' : 'Inactive'
        ]);
    }
    
    fclose($output);
    exit;
}

// Function to download students for a specific cohort
function download_cohort_students($company_id, $cohort) {
    global $DB;
    
    $students = $DB->get_records_sql(
        "SELECT u.id, u.firstname, u.lastname, u.email, u.username, uifd.data AS grade_level,
                COUNT(DISTINCT c.id) as total_courses
         FROM {user} u
         INNER JOIN {company_users} cu ON cu.userid = u.id AND cu.companyid = ?
         LEFT JOIN {user_info_data} uifd ON uifd.userid = u.id
         LEFT JOIN {user_info_field} uiff ON uiff.id = uifd.fieldid AND uiff.shortname = 'gradelevel'
         LEFT JOIN {user_enrolments} ue ON ue.userid = u.id
         LEFT JOIN {enrol} e ON e.id = ue.enrolid
         LEFT JOIN {course} c ON c.id = e.courseid
         WHERE u.deleted = 0 AND uifd.data = ?
         GROUP BY u.id, u.firstname, u.lastname, u.email, u.username, uifd.data
         ORDER BY u.firstname, u.lastname",
        [$company_id, $cohort]
    );
    
    $filename = 'cohort_students_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $cohort) . '_' . date('Y-m-d_H-i-s') . '.csv';
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Student ID', 'First Name', 'Last Name', 'Email', 'Username', 'Grade Level', 'Total Courses']);
    
    foreach ($students as $student) {
        fputcsv($output, [
            $student->id,
            $student->firstname,
            $student->lastname,
            $student->email,
            $student->username,
            $student->grade_level ?: 'Not assigned',
            $student->total_courses
        ]);
    }
    
    fclose($output);
    exit;
}

// Prepare template data for the school manager sidebar
$sidebarcontext = [
    'config' => [
        'wwwroot' => $CFG->wwwroot
    ],
    'company_name' => $company_info ? $company_info->name : 'School Manager',
    'company_logo_url' => '',
    'has_logo' => false,
    'user_info' => [
        'fullname' => fullname($USER),
        'firstname' => $USER->firstname,
        'lastname' => $USER->lastname
    ],
    'bulk_download_active' => true,
    'dashboard_active' => false,
    'teachers_active' => false,
    'students_active' => false,
    'courses_active' => false,
    'enrollments_active' => false,
];

// Set page context
$PAGE->set_context(context_system::instance());
$PAGE->set_url('/theme/remui_kids/bulk_download.php');
$PAGE->set_title('Bulk Download - ' . $company_info->name);
$PAGE->set_heading('Bulk Download');

echo $OUTPUT->header();

// Include the sidebar template
echo $OUTPUT->render_from_template('theme_remui_kids/school_manager_sidebar', $sidebarcontext);

echo "<div class='school-manager-main-content'>";
echo "<div class='main-content'>";

// Page Header
echo "<div class='page-header'>";
echo "<div class='page-header-content'>";
echo "<h1 class='page-title'>Bulk Download</h1>";
echo "<p class='page-subtitle'>Download user data in Excel format</p>";
echo "</div>";
echo "<a href='{$CFG->wwwroot}/my/' class='back-button'>";
echo "<i class='fa fa-arrow-left'></i> Back to Dashboard";
echo "</a>";
echo "</div>";

// Download Options
echo "<div class='download-options'>";

// Download All Users
echo "<div class='download-card'>";
echo "<div class='download-header'>";
echo "<i class='fa fa-users download-icon'></i>";
echo "<h3>Download All Users</h3>";
echo "</div>";
echo "<p class='download-description'>Download complete user data including teachers, students, and administrators.</p>";
echo "<a href='?download=all_users' class='download-btn'>";
echo "<i class='fa fa-download'></i> Download All Users";
echo "</a>";
echo "</div>";

// Download Teachers
echo "<div class='download-card'>";
echo "<div class='download-header'>";
echo "<i class='fa fa-chalkboard-teacher download-icon'></i>";
echo "<h3>Download Teachers</h3>";
echo "</div>";
echo "<p class='download-description'>Download all teacher data with their roles and information.</p>";
echo "<a href='?download=teachers' class='download-btn'>";
echo "<i class='fa fa-download'></i> Download Teachers";
echo "</a>";
echo "</div>";

// Download All Students with sub-options
echo "<div class='download-card'>";
echo "<div class='download-header'>";
echo "<i class='fa fa-user-graduate download-icon'></i>";
echo "<h3>Download All Students</h3>";
echo "</div>";
echo "<p class='download-description'>Download student data with various organization options.</p>";
echo "<div class='download-sub-options'>";
echo "<a href='?download=all_students' class='download-btn'>";
echo "<i class='fa fa-download'></i> Download All Students";
echo "</a>";
echo "<button onclick='toggleCourseDropdown()' class='download-btn sub-btn dropdown-btn'>";
echo "<i class='fa fa-book'></i> Download Course-wise";
echo "</button>";
echo "<div id='courseDropdown' class='dropdown-content' style='display: none;'>";
echo "<select id='courseSelect' class='dropdown-select'>";
echo "<option value=''>Select a course...</option>";
// Get courses for this company
$courses = $DB->get_records_sql(
    "SELECT c.id, c.fullname, c.shortname 
     FROM {course} c 
     INNER JOIN {enrol} e ON e.courseid = c.id 
     INNER JOIN {enrol} e2 ON e2.courseid = c.id 
     WHERE c.id > 1 
     GROUP BY c.id, c.fullname, c.shortname 
     ORDER BY c.fullname",
    []
);
foreach ($courses as $course) {
    echo "<option value='{$course->id}'>{$course->fullname}</option>";
}
echo "</select>";
echo "<button onclick='downloadCourseStudents()' class='download-btn sub-btn download-specific-btn' disabled>";
echo "<i class='fa fa-download'></i> Download Students";
echo "</button>";
echo "</div>";
echo "<button onclick='toggleCohortDropdown()' class='download-btn sub-btn dropdown-btn'>";
echo "<i class='fa fa-layer-group'></i> Download Cohort-wise";
echo "</button>";
echo "<div id='cohortDropdown' class='dropdown-content' style='display: none;'>";
echo "<select id='cohortSelect' class='dropdown-select'>";
echo "<option value=''>Select a cohort...</option>";
// Get cohorts/grade levels for this company
$cohorts = $DB->get_records_sql(
    "SELECT DISTINCT uifd.data as grade_level 
     FROM {user_info_data} uifd 
     INNER JOIN {user_info_field} uiff ON uiff.id = uifd.fieldid 
     INNER JOIN {company_users} cu ON cu.userid = uifd.userid 
     WHERE uiff.shortname = 'gradelevel' 
     AND uifd.data IS NOT NULL 
     AND uifd.data != '' 
     AND cu.companyid = ? 
     ORDER BY uifd.data",
    [$company_id]
);
foreach ($cohorts as $cohort) {
    echo "<option value='{$cohort->grade_level}'>{$cohort->grade_level}</option>";
}
echo "</select>";
echo "<button onclick='downloadCohortStudents()' class='download-btn sub-btn download-specific-btn' disabled>";
echo "<i class='fa fa-download'></i> Download Students";
echo "</button>";
echo "</div>";
echo "</div>";
echo "</div>";

echo "</div>"; // End download-options

echo "</div>"; // End main-content
echo "</div>"; // End school-manager-main-content

echo $OUTPUT->footer();
?>

<script>
function toggleCourseDropdown() {
    const dropdown = document.getElementById('courseDropdown');
    const cohortDropdown = document.getElementById('cohortDropdown');
    
    // Close cohort dropdown if open
    if (cohortDropdown.style.display === 'block') {
        cohortDropdown.style.display = 'none';
    }
    
    // Toggle course dropdown
    if (dropdown.style.display === 'none' || dropdown.style.display === '') {
        dropdown.style.display = 'block';
    } else {
        dropdown.style.display = 'none';
    }
}

function toggleCohortDropdown() {
    const dropdown = document.getElementById('cohortDropdown');
    const courseDropdown = document.getElementById('courseDropdown');
    
    // Close course dropdown if open
    if (courseDropdown.style.display === 'block') {
        courseDropdown.style.display = 'none';
    }
    
    // Toggle cohort dropdown
    if (dropdown.style.display === 'none' || dropdown.style.display === '') {
        dropdown.style.display = 'block';
    } else {
        dropdown.style.display = 'none';
    }
}

function downloadCourseStudents() {
    const courseId = document.getElementById('courseSelect').value;
    if (courseId) {
        window.location.href = '?download=course_students&course_id=' + courseId;
    }
}

function downloadCohortStudents() {
    const cohort = document.getElementById('cohortSelect').value;
    if (cohort) {
        window.location.href = '?download=cohort_students&cohort=' + encodeURIComponent(cohort);
    }
}

// Enable/disable download buttons based on selection
document.addEventListener('DOMContentLoaded', function() {
    const courseSelect = document.getElementById('courseSelect');
    const cohortSelect = document.getElementById('cohortSelect');
    const courseDownloadBtn = document.querySelector('#courseDropdown .download-specific-btn');
    const cohortDownloadBtn = document.querySelector('#cohortDropdown .download-specific-btn');
    
    if (courseSelect && courseDownloadBtn) {
        courseSelect.addEventListener('change', function() {
            courseDownloadBtn.disabled = !this.value;
        });
    }
    
    if (cohortSelect && cohortDownloadBtn) {
        cohortSelect.addEventListener('change', function() {
            cohortDownloadBtn.disabled = !this.value;
        });
    }
});
</script>

<style>
/* Import Google Fonts */
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');

/* School Manager Main Content - Clean Background */
.school-manager-main-content {
    position: fixed;
    top: 55px;
    left: 280px;
    right: 0;
    bottom: 0;
    overflow-y: auto;
    background: white;
    font-family: 'Inter', sans-serif;
    padding-top: 35px;
    transition: all 0.3s ease;
}

.main-content {
    padding: 0 30px 30px 30px;
    min-height: 100vh;
    max-width: 1800px;
    margin: 0 auto;
}

/* Page Header */
.page-header {
    background: linear-gradient(180deg, rgba(59, 130, 246, 0.25), rgba(59, 130, 246, 0.08), #ffffff);
    color: #2c3e50;
    padding: 25px 30px;
    border-radius: 15px;
    margin-bottom: 30px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.08);
    margin-top: 35px;
    position: relative;
    display: flex;
    align-items: center;
    border: 2px solid rgba(59, 130, 246, 0.3);
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
    background: white;
    color: #2c3e50;
    text-decoration: none;
    padding: 10px 16px;
    border-radius: 8px;
    font-weight: 500;
    transition: all 0.3s ease;
    font-size: 0.9rem;
    border: 1px solid rgba(44, 62, 80, 0.2);
}

.back-button:hover {
    background: rgba(255, 255, 255, 0.9);
    color: #2c3e50;
    text-decoration: none;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}

/* Download Options */
.download-options {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
    margin-top: 20px;
}

.download-section {
    width: 100%;
    margin-top: 30px;
}

.section-header {
    display: flex;
    align-items: center;
    gap: 15px;
    margin-bottom: 25px;
    padding-bottom: 15px;
    border-bottom: 2px solid #e2e8f0;
}

.section-icon {
    font-size: 2rem;
    color: #3b82f6;
}

.section-header h2 {
    font-size: 1.8rem;
    font-weight: 600;
    color: #1a202c;
    margin: 0;
}

.download-card {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    border: 1px solid #e5e7eb;
    border-top: 4px solid transparent;
    transition: all 0.3s ease;
    text-align: center;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: space-between;
    min-height: 320px;
}

.download-card:nth-child(1) {
    border-top-color: #3b82f6;
    background: linear-gradient(180deg, rgba(59, 130, 246, 0.08), #ffffff);
}

.download-card:nth-child(2) {
    border-top-color: #10b981;
    background: linear-gradient(180deg, rgba(16, 185, 129, 0.08), #ffffff);
}

.download-card:nth-child(3) {
    border-top-color: #f59e0b;
    background: linear-gradient(180deg, rgba(244, 63, 94, 0.08), #ffffff);
}

.download-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 20px rgba(0, 0, 0, 0.12);
}

.download-header {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 12px;
    margin-bottom: 15px;
}

.download-icon {
    width: 55px;
    height: 55px;
    border-radius: 50%;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    color: white;
    box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
}

.download-header h3 {
    font-size: 1.1rem;
    font-weight: 700;
    color: #1a202c;
    margin: 0;
    line-height: 1.3;
}

.download-description {
    color: #6b7280;
    font-size: 0.85rem;
    line-height: 1.5;
    margin-bottom: 15px;
    flex-grow: 1;
    text-align: center;
}

.download-btn {
    background: linear-gradient(135deg, #3b82f6, #1d4ed8);
    color: white;
    padding: 10px 20px;
    border-radius: 8px;
    text-decoration: none;
    font-weight: 600;
    font-size: 0.85rem;
    transition: all 0.3s ease;
    cursor: pointer;
    width: 100%;
    text-align: center;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    margin-top: auto;
    border: none;
    box-shadow: 0 3px 6px rgba(59, 130, 246, 0.25);
}

.download-btn:hover {
    background: linear-gradient(135deg, #2563eb, #1e40af);
    transform: translateY(-2px);
    box-shadow: 0 6px 15px rgba(59, 130, 246, 0.4);
    color: white;
    text-decoration: none;
}

/* Sub-options for students card */
.download-sub-options {
    display: flex;
    flex-direction: column;
    gap: 10px;
    width: 100%;
    margin-top: 12px;
}

.download-btn.sub-btn {
    background: linear-gradient(135deg, #3b82f6, #1d4ed8);
    color: white;
    padding: 8px 14px;
    border-radius: 6px;
    text-decoration: none;
    font-size: 0.8rem;
    font-weight: 600;
    border: none;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    box-shadow: 0 2px 4px rgba(59, 130, 246, 0.2);
}

.download-btn.sub-btn:hover {
    background: linear-gradient(135deg, #2563eb, #1e40af);
    color: white;
    text-decoration: none;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
}

/* Dropdown styling */
.dropdown-content {
    margin-top: 10px;
    padding: 15px;
    background: #f8fafc;
    border-radius: 8px;
    border: 1px solid #e2e8f0;
    animation: slideDown 0.3s ease;
}

@keyframes slideDown {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.dropdown-select {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    background: white;
    font-size: 0.9rem;
    margin-bottom: 12px;
    transition: border-color 0.3s ease;
}

.dropdown-select:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.download-specific-btn {
    width: 100%;
    margin-top: 8px;
}

.download-specific-btn:disabled {
    background: #9ca3af;
    cursor: not-allowed;
    transform: none;
    box-shadow: none;
}

.download-specific-btn:disabled:hover {
    background: #9ca3af;
    transform: none;
    box-shadow: none;
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
    }
    
    .download-options {
        grid-template-columns: 1fr;
    }
}

/* Tablet breakpoint */
@media (max-width: 1024px) and (min-width: 769px) {
    .main-content {
        padding: 0 25px 25px 25px;
    }
    
    .page-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 15px;
    }
    
    .back-button {
        align-self: flex-end;
    }
}

@media (max-width: 768px) {
    .school-manager-main-content {
        position: relative;
        left: 0;
        right: 0;
        bottom: auto;
        overflow-y: visible;
        padding-top: 20px;
    }
    
    .main-content {
        padding: 0 20px 20px 20px;
    }
    
    .page-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 15px;
        margin-top: 20px;
        padding: 20px;
    }
    
    .page-header-content {
        text-align: left;
    }
    
    .page-title {
        font-size: 1.8rem;
    }
    
    .page-subtitle {
        font-size: 0.9rem;
    }
    
    .back-button {
        align-self: flex-start;
    }
    
    .download-options {
        grid-template-columns: 1fr;
        gap: 20px;
    }
    
    .download-card {
        min-height: auto;
        padding: 1.5rem;
    }
    
    .download-icon {
        width: 60px;
        height: 60px;
        font-size: 1.5rem;
    }
    
    .download-header h3 {
        font-size: 1.1rem;
    }
    
    .download-description {
        font-size: 0.9rem;
    }
    
    .download-sub-options {
        gap: 10px;
    }
}

/* Small mobile breakpoint */
@media (max-width: 480px) {
    .page-title {
        font-size: 1.5rem;
    }
    
    .page-header {
        padding: 15px;
    }
    
    .download-card {
        padding: 1.25rem;
    }
    
    .download-btn {
        padding: 11px 18px;
        font-size: 0.9rem;
    }
}
</style>
