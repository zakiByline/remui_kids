<?php
require_once('../../config.php');
require_once(__DIR__ . '/lang_init.php'); // Apply user's selected language
require_once($CFG->libdir . '/adminlib.php');

// Ensure user is logged in
require_login();

// Get student ID from URL parameter
$student_id = required_param('id', PARAM_INT);

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

// Get student information
$student = null;
try {
    $student = $DB->get_record_sql(
        "SELECT u.id,
                u.firstname,
                u.lastname,
                u.email,
                u.phone1,
                u.phone2,
                u.address,
                u.city,
                u.country,
                u.timezone,
                u.lang,
                u.username,
                u.suspended,
                u.lastaccess,
                u.firstaccess,
                u.lastlogin,
                u.currentlogin,
                u.picture,
                u.description,
                u.descriptionformat,
                u.mailformat,
                u.maildigest,
                u.maildisplay,
                u.autosubscribe,
                u.trackforums,
                u.timecreated,
                u.timemodified,
                u.lastnamephonetic,
                u.firstnamephonetic,
                u.middlename,
                u.alternatename,
                u.idnumber,
                cu.educator,
                GROUP_CONCAT(DISTINCT r.shortname SEPARATOR ',') AS roles,
                uifd.data AS grade_level,
                uifd2.data AS parent_contact,
                uifd3.data AS emergency_contact
           FROM {user} u
           INNER JOIN {company_users} cu ON cu.userid = u.id AND cu.companyid = ?
           LEFT JOIN {role_assignments} ra ON ra.userid = u.id
           LEFT JOIN {role} r ON r.id = ra.roleid AND r.shortname = 'student'
           LEFT JOIN {user_info_data} uifd ON uifd.userid = u.id
           LEFT JOIN {user_info_field} uiff ON uiff.id = uifd.fieldid AND uiff.shortname = 'gradelevel'
           LEFT JOIN {user_info_data} uifd2 ON uifd2.userid = u.id
           LEFT JOIN {user_info_field} uiff2 ON uiff2.id = uifd2.fieldid AND uiff2.shortname = 'parentcontact'
           LEFT JOIN {user_info_data} uifd3 ON uifd3.userid = u.id
           LEFT JOIN {user_info_field} uiff3 ON uiff3.id = uifd3.fieldid AND uiff3.shortname = 'emergencycontact'
          WHERE u.id = ? AND u.deleted = 0
        GROUP BY u.id, u.firstname, u.lastname, u.email, u.phone1, u.phone2, u.address, u.city, u.country, u.timezone, u.lang, u.username, u.suspended, u.lastaccess, u.firstaccess, u.lastlogin, u.currentlogin, u.picture, u.description, u.descriptionformat, u.mailformat, u.maildigest, u.maildisplay, u.autosubscribe, u.trackforums, u.timecreated, u.timemodified, u.lastnamephonetic, u.firstnamephonetic, u.middlename, u.alternatename, u.idnumber, cu.educator, uifd.data, uifd2.data, uifd3.data",
        [$company_id, $student_id]
    );
} catch (Exception $e) {
    error_log("Error getting student info: " . $e->getMessage());
}

// Redirect if student not found or not in this company
if (!$student) {
    redirect(new moodle_url('/theme/remui_kids/student_management.php'), 'Student not found or access denied.', null, \core\output\notification::NOTIFY_ERROR);
}

// Get student's course enrollments
$enrolled_courses = [];
try {
    $enrolled_courses = $DB->get_records_sql(
        "SELECT c.id,
                c.fullname,
                c.shortname,
                c.summary,
                c.startdate,
                c.enddate,
                c.visible,
                ue.timecreated as enrolled_date,
                ue.status as enrollment_status,
                cc.courseid
           FROM {course} c
           INNER JOIN {enrol} e ON e.courseid = c.id
           INNER JOIN {user_enrolments} ue ON ue.enrolid = e.id AND ue.userid = ?
           INNER JOIN {company_course} cc ON cc.courseid = c.id AND cc.companyid = ?
          WHERE c.visible = 1 AND ue.status = 0 AND e.status = 0
        ORDER BY c.fullname",
        [$student_id, $company_id]
    );
} catch (Exception $e) {
    error_log("Error getting enrolled courses: " . $e->getMessage());
}

// Get student's quiz attempts and grades
$quiz_stats = [];
try {
    $quiz_stats = $DB->get_records_sql(
        "SELECT qa.quiz,
                q.name as quiz_name,
                c.fullname as course_name,
                COUNT(qa.id) as total_attempts,
                MAX(qa.sumgrades) as best_grade,
                AVG(qa.sumgrades) as average_grade,
                MAX(qa.timefinish) as last_attempt
           FROM {quiz_attempts} qa
           INNER JOIN {quiz} q ON q.id = qa.quiz
           INNER JOIN {course} c ON c.id = q.course
           INNER JOIN {company_course} cc ON cc.courseid = c.id AND cc.companyid = ?
          WHERE qa.userid = ? AND qa.state = 'finished'
        GROUP BY qa.quiz, q.name, c.fullname
        ORDER BY last_attempt DESC",
        [$company_id, $student_id]
    );
} catch (Exception $e) {
    error_log("Error getting quiz stats: " . $e->getMessage());
}

// Get student's assignment submissions
$assignment_stats = [];
try {
    $assignment_stats = $DB->get_records_sql(
        "SELECT a.id,
                a.name as assignment_name,
                c.fullname as course_name,
                s.status,
                s.grade,
                s.timemodified as submitted_date
           FROM {assign} a
           INNER JOIN {course} c ON c.id = a.course
           INNER JOIN {company_course} cc ON cc.courseid = c.id AND cc.companyid = ?
           LEFT JOIN {assign_submission} s ON s.assignment = a.id AND s.userid = ?
          WHERE c.visible = 1
        ORDER BY s.timemodified DESC",
        [$company_id, $student_id]
    );
} catch (Exception $e) {
    error_log("Error getting assignment stats: " . $e->getMessage());
}

// Get recent activity
$recent_activity = [];
try {
    $recent_activity = $DB->get_records_sql(
        "SELECT l.id,
                l.action,
                l.target,
                l.objecttable,
                l.objectid,
                l.crud,
                l.edulevel,
                l.contextid,
                l.contextlevel,
                l.contextinstanceid,
                l.userid,
                l.courseid,
                l.relateduserid,
                l.anonymous,
                l.other,
                l.timecreated,
                c.fullname as course_name
           FROM {logstore_standard_log} l
           LEFT JOIN {course} c ON c.id = l.courseid
          WHERE l.userid = ? AND l.timecreated > ?
        ORDER BY l.timecreated DESC
        LIMIT 10",
        [$student_id, time() - (30 * 24 * 60 * 60)] // Last 30 days
    );
} catch (Exception $e) {
    error_log("Error getting recent activity: " . $e->getMessage());
}

// Prepare sidebar context (complete with all required fields)
$sidebarcontext = [
    'config' => [
        'wwwroot' => $CFG->wwwroot
    ],
    'company_name' => $company_info->name,
    'company_logo_url' => '',
    'has_logo' => false,
    'user_info' => [
        'fullname' => fullname($USER)
    ],
    'students_active' => true, // Highlight Student Management in sidebar
    'dashboard_active' => false,
    'teachers_active' => false,
    'courses_active' => false,
    'enrollments_active' => false,
    'add_users_active' => false,
    'analytics_active' => false,
    'reports_active' => false,
    'course_reports_active' => false,
    'settings_active' => false,
    'help_active' => false
];

// Set page context
$PAGE->set_context(context_system::instance());
$PAGE->set_url('/theme/remui_kids/student_profile.php', ['id' => $student_id]);
$PAGE->set_title('Student Profile - ' . fullname($student) . ' - ' . $company_info->name);
$PAGE->set_heading('Student Profile');

// Output the header first
echo $OUTPUT->header();

// Render the school manager sidebar using the correct Moodle method
try {
    echo $OUTPUT->render_from_template('theme_remui_kids/school_manager_sidebar', $sidebarcontext);
    // Force sidebar to always stay visible on student profile page
    echo "<script>window.forceSidebarAlways = true;</script>";
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
    echo "<a href='{$CFG->wwwroot}/theme/remui_kids/student_management.php' style='display: block; padding: 15px 20px; color: #007cba; background: #e3f2fd; text-decoration: none; font-weight: 600;'>Student Management</a>";
    echo "</nav>";
    echo "</div>";
    // Force sidebar to always stay visible even on fallback
    echo "<script>window.forceSidebarAlways = true;</script>";
}

// Custom CSS for the student profile layout
echo "<style>";
echo "
/* Import Google Fonts - Must be at the top */
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');

/* Force sidebar to stay visible on student profile page - CRITICAL */
.school-manager-sidebar {
    display: flex !important;
    visibility: visible !important;
    opacity: 1 !important;
    transform: translateX(0) !important;
}

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
    padding: 20px;
    box-sizing: border-box;
}

/* Main content positioning to work with the new sidebar template */
.main-content {
    padding: 0;
    min-height: 100vh;
    max-width: 1400px;
    margin: 0 auto;
}

/* Page Header */
.page-header {
    background: linear-gradient(180deg, rgba(79, 172, 254, 0.25), rgba(79, 172, 254, 0.08), #ffffff);
    color: #2c3e50;
    padding: 25px 30px;
    border-radius: 15px;
    margin-bottom: 30px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.08);
    border: 2px solid rgba(79, 172, 254, 0.3);
    margin-top: 35px;
    position: relative;
    display: flex;
    align-items: center;
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

/* Student Profile Container */
.student-profile-container {
    display: grid;
    grid-template-columns: 1fr 2fr;
    gap: 30px;
    margin-bottom: 30px;
}

/* Profile Card */
.profile-card {
    background: white;
    border-radius: 20px;
    padding: 30px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
    border: 1px solid #e2e8f0;
    position: sticky;
    top: 20px;
    height: fit-content;
}

.profile-header {
    text-align: center;
    margin-bottom: 30px;
    padding-bottom: 25px;
    border-bottom: 2px solid #f1f5f9;
}

.profile-avatar {
    width: 120px;
    height: 120px;
    border-radius: 50%;
    background: linear-gradient(135deg, #667eea, #764ba2);
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 20px;
    font-size: 3rem;
    font-weight: 700;
    color: white;
    box-shadow: 0 10px 25px rgba(102, 126, 234, 0.3);
    position: relative;
    overflow: hidden;
}

.profile-avatar img {
    width: 100%;
    height: 100%;
    border-radius: 50%;
    object-fit: cover;
    position: absolute;
    top: 0;
    left: 0;
    image-rendering: -webkit-optimize-contrast;
    image-rendering: crisp-edges;
    image-rendering: high-quality;
    -webkit-backface-visibility: hidden;
    backface-visibility: hidden;
    transform: translateZ(0);
    transition: all 0.3s ease;
}

.profile-avatar img:hover {
    transform: scale(1.05);
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
}

/* Profile Modal Styles */
.profile-modal {
    display: none;
    position: fixed;
    z-index: 10000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.8);
    backdrop-filter: blur(5px);
    animation: fadeIn 0.3s ease;
}

.modal-content {
    position: relative;
    background-color: white;
    margin: 5% auto;
    padding: 0;
    border-radius: 20px;
    width: 90%;
    max-width: 600px;
    max-height: 80vh;
    overflow: hidden;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
    animation: slideIn 0.3s ease;
}

.modal-close {
    position: absolute;
    top: 15px;
    right: 20px;
    color: #aaa;
    font-size: 28px;
    font-weight: bold;
    cursor: pointer;
    z-index: 10001;
    background: rgba(255, 255, 255, 0.9);
    border-radius: 50%;
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s ease;
}

.modal-close:hover {
    color: #000;
    background: rgba(255, 255, 255, 1);
    transform: scale(1.1);
}

.modal-image {
    width: 100%;
    height: auto;
    max-height: 60vh;
    object-fit: cover;
    display: block;
    image-rendering: -webkit-optimize-contrast;
    image-rendering: crisp-edges;
    image-rendering: high-quality;
}

.modal-info {
    padding: 30px;
    text-align: center;
    background: linear-gradient(135deg, #f8fafc, #e2e8f0);
}

.modal-info h3 {
    margin: 0 0 10px 0;
    font-size: 2rem;
    font-weight: 700;
    color: #1a202c;
}

.modal-info p {
    margin: 5px 0;
    color: #4a5568;
    font-size: 1.1rem;
}

.modal-info p:first-of-type {
    font-weight: 600;
    color: #2d3748;
}

/* Animations */
@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

@keyframes slideIn {
    from { 
        opacity: 0;
        transform: translateY(-50px) scale(0.9);
    }
    to { 
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

/* Responsive Design */
@media (max-width: 768px) {
    .modal-content {
        width: 95%;
        margin: 10% auto;
        max-height: 85vh;
    }
    
    .modal-info {
        padding: 20px;
    }
    
    .modal-info h3 {
        font-size: 1.5rem;
    }
    
    .modal-info p {
        font-size: 1rem;
    }
}

.status-indicator {
    position: absolute;
    bottom: 5px;
    right: 5px;
    width: 25px;
    height: 25px;
    border-radius: 50%;
    border: 3px solid white;
}

.status-active {
    background: #22c55e;
}

.status-suspended {
    background: #ef4444;
}

.profile-name {
    font-size: 1.8rem;
    font-weight: 700;
    color: #1e293b;
    margin: 0 0 8px 0;
}

.profile-username {
    font-size: 1rem;
    color: #64748b;
    margin: 0 0 15px 0;
}

.profile-role {
    display: inline-block;
    background: linear-gradient(135deg, #3b82f6, #1d4ed8);
    color: white;
    padding: 8px 16px;
    border-radius: 20px;
    font-size: 0.9rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.profile-info {
    margin-bottom: 25px;
}

.info-section {
    margin-bottom: 20px;
}

.info-section:last-child {
    margin-bottom: 0;
}

.info-label {
    font-size: 0.85rem;
    font-weight: 600;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 8px;
    display: block;
}

.info-value {
    font-size: 1rem;
    color: #1e293b;
    font-weight: 500;
    word-break: break-word;
}

.info-value.empty {
    color: #94a3b8;
    font-style: italic;
}

/* Contact Information */
.contact-info {
    background: #f8fafc;
    border-radius: 15px;
    padding: 20px;
    margin-top: 20px;
}

.contact-item {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 15px;
    padding: 10px;
    border-radius: 10px;
    transition: background 0.3s ease;
}

.contact-item:hover {
    background: white;
}

.contact-item:last-child {
    margin-bottom: 0;
}

.contact-icon {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.2rem;
    color: white;
}

.contact-icon.email {
    background: linear-gradient(135deg, #f59e0b, #d97706);
}

.contact-icon.phone {
    background: linear-gradient(135deg, #10b981, #059669);
}

.contact-icon.location {
    background: linear-gradient(135deg, #ef4444, #dc2626);
}

.contact-details {
    flex: 1;
}

.contact-label {
    font-size: 0.8rem;
    color: #64748b;
    font-weight: 500;
    margin-bottom: 2px;
}

.contact-value {
    font-size: 0.95rem;
    color: #1e293b;
    font-weight: 600;
}

/* Action Buttons */
.profile-actions {
    display: flex;
    flex-direction: column;
    gap: 12px;
    margin-top: 25px;
}

.action-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    padding: 12px 20px;
    border: none;
    border-radius: 12px;
    font-size: 0.95rem;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.3s ease;
    cursor: pointer;
}

.btn-primary {
    background: linear-gradient(135deg, #3b82f6, #1d4ed8);
    color: white;
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(59, 130, 246, 0.3);
    color: white;
    text-decoration: none;
}

.btn-secondary {
    background: #f1f5f9;
    color: #475569;
    border: 2px solid #e2e8f0;
}

.btn-secondary:hover {
    background: #e2e8f0;
    color: #334155;
    text-decoration: none;
    transform: translateY(-1px);
}

.btn-warning {
    background: linear-gradient(135deg, #f59e0b, #d97706);
    color: white;
}

.btn-warning:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(245, 158, 11, 0.3);
    color: white;
    text-decoration: none;
}

/* Content Area */
.content-area {
    display: flex;
    flex-direction: column;
    gap: 30px;
}

/* Stats Cards */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.stat-card {
    background: white;
    border-radius: 15px;
    padding: 25px;
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
    border: 1px solid #e2e8f0;
    text-align: center;
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
}

.stat-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
}

.stat-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(90deg, #667eea, #764ba2);
}

.stat-icon {
    width: 60px;
    height: 60px;
    border-radius: 15px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.8rem;
    color: white;
    margin: 0 auto 15px;
    background: linear-gradient(135deg, #667eea, #764ba2);
}

.stat-number {
    font-size: 2.5rem;
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

/* Content Sections */
.content-section {
    background: white;
    border-radius: 20px;
    padding: 30px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
    border: 1px solid #e2e8f0;
}

.section-header {
    display: flex;
    align-items: center;
    gap: 15px;
    margin-bottom: 25px;
    padding-bottom: 15px;
    border-bottom: 2px solid #f1f5f9;
}

.section-icon {
    width: 50px;
    height: 50px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    color: white;
    background: linear-gradient(135deg, #667eea, #764ba2);
}

.section-title {
    font-size: 1.5rem;
    font-weight: 700;
    color: #1e293b;
    margin: 0;
}

.section-subtitle {
    font-size: 0.9rem;
    color: #64748b;
    margin: 5px 0 0 0;
}

/* Course List */
.course-list {
    display: flex;
    flex-direction: column;
    gap: 15px;
}

.course-item {
    display: flex;
    align-items: center;
    gap: 15px;
    padding: 20px;
    background: #f8fafc;
    border-radius: 15px;
    border: 1px solid #e2e8f0;
    transition: all 0.3s ease;
}

.course-item:hover {
    background: white;
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
}

.course-icon {
    width: 50px;
    height: 50px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.3rem;
    color: white;
    background: linear-gradient(135deg, #10b981, #059669);
    flex-shrink: 0;
}

.course-info {
    flex: 1;
}

.course-name {
    font-size: 1.1rem;
    font-weight: 600;
    color: #1e293b;
    margin: 0 0 5px 0;
}

.course-meta {
    font-size: 0.9rem;
    color: #64748b;
    margin: 0;
}

.course-status {
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.status-enrolled {
    background: #dcfce7;
    color: #166534;
}

.status-completed {
    background: #dbeafe;
    color: #1e40af;
}

/* Quiz Stats */
.quiz-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 20px;
}

.quiz-item {
    background: #f8fafc;
    border-radius: 15px;
    padding: 20px;
    border: 1px solid #e2e8f0;
    transition: all 0.3s ease;
}

.quiz-item:hover {
    background: white;
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
}

.quiz-name {
    font-size: 1.1rem;
    font-weight: 600;
    color: #1e293b;
    margin: 0 0 10px 0;
}

.quiz-course {
    font-size: 0.9rem;
    color: #64748b;
    margin: 0 0 15px 0;
}

.quiz-metrics {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 15px;
}

.metric {
    text-align: center;
}

.metric-value {
    font-size: 1.5rem;
    font-weight: 700;
    color: #1e293b;
    margin: 0 0 5px 0;
}

.metric-label {
    font-size: 0.8rem;
    color: #64748b;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* Activity Timeline */
.activity-timeline {
    position: relative;
    padding-left: 30px;
}

.activity-timeline::before {
    content: '';
    position: absolute;
    left: 15px;
    top: 0;
    bottom: 0;
    width: 2px;
    background: linear-gradient(180deg, #667eea, #764ba2);
}

.activity-item {
    position: relative;
    margin-bottom: 25px;
    padding: 20px;
    background: #f8fafc;
    border-radius: 15px;
    border: 1px solid #e2e8f0;
    margin-left: 20px;
}

.activity-item::before {
    content: '';
    position: absolute;
    left: -25px;
    top: 25px;
    width: 12px;
    height: 12px;
    border-radius: 50%;
    background: #667eea;
    border: 3px solid white;
    box-shadow: 0 0 0 3px #e2e8f0;
}

.activity-header {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 10px;
}

.activity-icon {
    width: 30px;
    height: 30px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.9rem;
    color: white;
    background: linear-gradient(135deg, #667eea, #764ba2);
}

.activity-title {
    font-size: 1rem;
    font-weight: 600;
    color: #1e293b;
    margin: 0;
}

.activity-time {
    font-size: 0.8rem;
    color: #64748b;
    margin: 0 0 0 auto;
}

.activity-description {
    font-size: 0.9rem;
    color: #475569;
    margin: 0;
}

/* Empty States */
.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: #64748b;
}

.empty-state i {
    font-size: 4rem;
    color: #cbd5e0;
    margin-bottom: 20px;
}

.empty-state h3 {
    font-size: 1.5rem;
    color: #475569;
    margin-bottom: 10px;
}

.empty-state p {
    font-size: 1rem;
    margin-bottom: 0;
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
    
    .student-profile-container {
        grid-template-columns: 1fr;
        gap: 20px;
    }
    
    .profile-card {
        position: relative;
        top: auto;
    }
}

@media (max-width: 768px) {
    .page-title {
        font-size: 1.8rem;
    }
    
    .profile-card {
        padding: 20px;
    }
    
    .profile-avatar {
        width: 100px;
        height: 100px;
        font-size: 2.5rem;
    }
    
    .profile-name {
        font-size: 1.5rem;
    }
    
    .stats-grid {
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 15px;
    }
    
    .content-section {
        padding: 20px;
    }
    
    .quiz-stats {
        grid-template-columns: 1fr;
    }
    
    .quiz-metrics {
        grid-template-columns: repeat(2, 1fr);
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
echo "<h1 class='page-title'>Student Profile</h1>";
echo "<p class='page-subtitle'>Detailed information for " . fullname($student) . "</p>";
echo "</div>";
echo "<a href='{$CFG->wwwroot}/theme/remui_kids/student_management.php' class='back-button'>";
echo "<i class='fa fa-arrow-left'></i> Back to Students";
echo "</a>";
echo "</div>";

// Student Profile Container
echo "<div class='student-profile-container'>";

// Profile Card
echo "<div class='profile-card'>";
echo "<div class='profile-header'>";

// Profile Avatar - Get high-definition student profile image
$student_user = $DB->get_record('user', ['id' => $student->id]);

// Try to get the highest quality image available
$profile_image_url = '';
$fallback_urls = [];
$has_profile_picture = false;

if ($student_user && $student_user->picture > 0) {
    // Verify the file actually exists in file storage
    $user_context = context_user::instance($student_user->id);
    $fs = get_file_storage();
    
    // Check if file exists in 'icon' area (where Moodle stores profile pics)
    $files = $fs->get_area_files($user_context->id, 'user', 'icon', 0, 'itemid', false);
    
    if (!empty($files)) {
        // File exists, safe to generate URLs
        try {
            $user_picture = new user_picture($student_user);
            
            // Primary: Get full size profile picture
            $user_picture->size = 1; // f1 = full size
            $profile_image_url = $user_picture->get_url($PAGE)->out(false);
            
            // If we have a valid URL, set the flag
            if (!empty($profile_image_url)) {
                $has_profile_picture = true;
                
                // Generate fallback URLs in different sizes
                $user_picture->size = 2; // f2 = large size
                $fallback_urls[] = $user_picture->get_url($PAGE)->out(false);
                
                $user_picture->size = 3; // f3 = medium size
                $fallback_urls[] = $user_picture->get_url($PAGE)->out(false);
            }
        } catch (Exception $e) {
            error_log("Error getting student profile image: " . $e->getMessage());
            $profile_image_url = '';
            $has_profile_picture = false;
        }
    } else {
        // Picture field is set but file doesn't exist - reset the field
        $student_user->picture = 0;
        $student_user->timemodified = time();
        $DB->update_record('user', $student_user);
        $has_profile_picture = false;
    }
}

// Fallback initials if no profile image
$initials = strtoupper(substr($student->firstname, 0, 1) . substr($student->lastname, 0, 1));

echo "<div class='profile-avatar'>";
if ($has_profile_picture && !empty($profile_image_url)) {
    // Show actual profile picture
    $fallback_data = json_encode($fallback_urls);
    echo "<img src='" . htmlspecialchars($profile_image_url) . "' ";
    echo "     alt='" . htmlspecialchars($student->firstname . ' ' . $student->lastname) . "' ";
    echo "     data-fallbacks='" . htmlspecialchars($fallback_data) . "' ";
    echo "     data-hd-url='" . htmlspecialchars($profile_image_url) . "' ";
    echo "     onclick='openProfileModal(this)' ";
    echo "     onerror='console.log(\"Profile pic load failed:\", this.src); handleImageError(this);' ";
    echo "     style='width: 100%; height: 100%; border-radius: 50%; object-fit: cover; cursor: pointer; display: block;'>";
    echo "<!-- User ID: " . $student_user->id . ", Picture field: " . $student_user->picture . " -->";
} else {
    echo "<img src='' style='display: none;'>";
}
echo "<span style='display: none;'>" . htmlspecialchars($initials) . "</span>";
echo "<div class='status-indicator " . ($student->suspended ? 'status-suspended' : 'status-active') . "'></div>";
echo "</div>";

echo "<h2 class='profile-name'>" . fullname($student) . "</h2>";
echo "<p class='profile-username'>@" . htmlspecialchars($student->username) . "</p>";
echo "<span class='profile-role'>Student</span>";
echo "</div>";

// Profile Information
echo "<div class='profile-info'>";
echo "<div class='info-section'>";
echo "<span class='info-label'>Student ID</span>";
echo "<span class='info-value'>" . ($student->idnumber ? htmlspecialchars($student->idnumber) : 'Not assigned') . "</span>";
echo "</div>";

echo "<div class='info-section'>";
echo "<span class='info-label'>Grade Level</span>";
echo "<span class='info-value'>" . ($student->grade_level ? htmlspecialchars($student->grade_level) : 'Not specified') . "</span>";
echo "</div>";

echo "<div class='info-section'>";
echo "<span class='info-label'>Status</span>";
echo "<span class='info-value'>" . ($student->suspended ? 'Suspended' : 'Active') . "</span>";
echo "</div>";

echo "<div class='info-section'>";
echo "<span class='info-label'>Member Since</span>";
echo "<span class='info-value'>" . date('M j, Y', $student->timecreated) . "</span>";
echo "</div>";

echo "<div class='info-section'>";
echo "<span class='info-label'>Last Access</span>";
echo "<span class='info-value'>" . ($student->lastaccess ? date('M j, Y g:i A', $student->lastaccess) : 'Never') . "</span>";
echo "</div>";
echo "</div>";

// Contact Information
echo "<div class='contact-info'>";
echo "<div class='contact-item'>";
echo "<div class='contact-icon email'>";
echo "<i class='fa fa-envelope'></i>";
echo "</div>";
echo "<div class='contact-details'>";
echo "<div class='contact-label'>Email</div>";
echo "<div class='contact-value'>" . htmlspecialchars($student->email) . "</div>";
echo "</div>";
echo "</div>";

if ($student->phone1) {
    echo "<div class='contact-item'>";
    echo "<div class='contact-icon phone'>";
    echo "<i class='fa fa-phone'></i>";
    echo "</div>";
    echo "<div class='contact-details'>";
    echo "<div class='contact-label'>Phone</div>";
    echo "<div class='contact-value'>" . htmlspecialchars($student->phone1) . "</div>";
    echo "</div>";
    echo "</div>";
}

if ($student->city || $student->country) {
    echo "<div class='contact-item'>";
    echo "<div class='contact-icon location'>";
    echo "<i class='fa fa-map-marker'></i>";
    echo "</div>";
    echo "<div class='contact-details'>";
    echo "<div class='contact-label'>Location</div>";
    echo "<div class='contact-value'>" . htmlspecialchars(trim(($student->city ?: '') . ', ' . ($student->country ?: ''), ', ')) . "</div>";
    echo "</div>";
    echo "</div>";
}
echo "</div>";

// Action Buttons
echo "<div class='profile-actions'>";
echo "<a href='{$CFG->wwwroot}/user/edit.php?id=" . $student->id . "' class='action-btn btn-primary'>";
echo "<i class='fa fa-edit'></i> Edit Profile";
echo "</a>";
echo "<a href='{$CFG->wwwroot}/user/view.php?id=" . $student->id . "&course=1' class='action-btn btn-secondary'>";
echo "<i class='fa fa-user'></i> View Public Profile";
echo "</a>";
if (!$student->suspended) {
    echo "<a href='{$CFG->wwwroot}/admin/user.php?action=suspend&user=" . $student->id . "' class='action-btn btn-warning' onclick='return confirm(\"Are you sure you want to suspend this student?\")'>";
    echo "<i class='fa fa-ban'></i> Suspend Student";
    echo "</a>";
} else {
    echo "<a href='{$CFG->wwwroot}/admin/user.php?action=unsuspend&user=" . $student->id . "' class='action-btn btn-primary' onclick='return confirm(\"Are you sure you want to unsuspend this student?\")'>";
    echo "<i class='fa fa-check'></i> Unsuspend Student";
    echo "</a>";
}
echo "</div>";

echo "</div>"; // End profile-card

// Content Area
echo "<div class='content-area'>";

// Statistics Cards
echo "<div class='stats-grid'>";
echo "<div class='stat-card'>";
echo "<div class='stat-icon'>";
echo "<i class='fa fa-graduation-cap'></i>";
echo "</div>";
echo "<div class='stat-number'>" . count($enrolled_courses) . "</div>";
echo "<div class='stat-label'>Enrolled Courses</div>";
echo "</div>";

echo "<div class='stat-card'>";
echo "<div class='stat-icon'>";
echo "<i class='fa fa-question-circle'></i>";
echo "</div>";
echo "<div class='stat-number'>" . count($quiz_stats) . "</div>";
echo "<div class='stat-label'>Quiz Attempts</div>";
echo "</div>";

echo "<div class='stat-card'>";
echo "<div class='stat-icon'>";
echo "<i class='fa fa-tasks'></i>";
echo "</div>";
echo "<div class='stat-number'>" . count($assignment_stats) . "</div>";
echo "<div class='stat-label'>Assignments</div>";
echo "</div>";

echo "<div class='stat-card'>";
echo "<div class='stat-icon'>";
echo "<i class='fa fa-clock-o'></i>";
echo "</div>";
echo "<div class='stat-number'>" . count($recent_activity) . "</div>";
echo "<div class='stat-label'>Recent Activities</div>";
echo "</div>";
echo "</div>";

// Enrolled Courses Section
echo "<div class='content-section'>";
echo "<div class='section-header'>";
echo "<div class='section-icon'>";
echo "<i class='fa fa-graduation-cap'></i>";
echo "</div>";
echo "<div>";
echo "<h3 class='section-title'>Enrolled Courses</h3>";
echo "<p class='section-subtitle'>" . count($enrolled_courses) . " courses enrolled</p>";
echo "</div>";
echo "</div>";

if (empty($enrolled_courses)) {
    echo "<div class='empty-state'>";
    echo "<i class='fa fa-graduation-cap'></i>";
    echo "<h3>No Courses Enrolled</h3>";
    echo "<p>This student is not enrolled in any courses yet.</p>";
    echo "</div>";
} else {
    echo "<div class='course-list'>";
    foreach ($enrolled_courses as $course) {
        echo "<div class='course-item'>";
        echo "<div class='course-icon'>";
        echo "<i class='fa fa-book'></i>";
        echo "</div>";
        echo "<div class='course-info'>";
        echo "<h4 class='course-name'>" . htmlspecialchars($course->fullname) . "</h4>";
        echo "<p class='course-meta'>Enrolled: " . date('M j, Y', $course->enrolled_date) . "</p>";
        echo "</div>";
        echo "<span class='course-status status-enrolled'>Enrolled</span>";
        echo "</div>";
    }
    echo "</div>";
}
echo "</div>";

// Quiz Statistics Section
echo "<div class='content-section'>";
echo "<div class='section-header'>";
echo "<div class='section-icon'>";
echo "<i class='fa fa-question-circle'></i>";
echo "</div>";
echo "<div>";
echo "<h3 class='section-title'>Quiz Performance</h3>";
echo "<p class='section-subtitle'>" . count($quiz_stats) . " quizzes attempted</p>";
echo "</div>";
echo "</div>";

if (empty($quiz_stats)) {
    echo "<div class='empty-state'>";
    echo "<i class='fa fa-question-circle'></i>";
    echo "<h3>No Quiz Attempts</h3>";
    echo "<p>This student has not attempted any quizzes yet.</p>";
    echo "</div>";
} else {
    echo "<div class='quiz-stats'>";
    foreach ($quiz_stats as $quiz) {
        echo "<div class='quiz-item'>";
        echo "<h4 class='quiz-name'>" . htmlspecialchars($quiz->quiz_name) . "</h4>";
        echo "<p class='quiz-course'>" . htmlspecialchars($quiz->course_name) . "</p>";
        echo "<div class='quiz-metrics'>";
        echo "<div class='metric'>";
        echo "<div class='metric-value'>" . $quiz->total_attempts . "</div>";
        echo "<div class='metric-label'>Attempts</div>";
        echo "</div>";
        echo "<div class='metric'>";
        echo "<div class='metric-value'>" . number_format($quiz->best_grade, 1) . "</div>";
        echo "<div class='metric-label'>Best Grade</div>";
        echo "</div>";
        echo "<div class='metric'>";
        echo "<div class='metric-value'>" . number_format($quiz->average_grade, 1) . "</div>";
        echo "<div class='metric-label'>Average</div>";
        echo "</div>";
        echo "</div>";
        echo "</div>";
    }
    echo "</div>";
}
echo "</div>";

// Recent Activity Section
echo "<div class='content-section'>";
echo "<div class='section-header'>";
echo "<div class='section-icon'>";
echo "<i class='fa fa-clock-o'></i>";
echo "</div>";
echo "<div>";
echo "<h3 class='section-title'>Recent Activity</h3>";
echo "<p class='section-subtitle'>Last 30 days activity</p>";
echo "</div>";
echo "</div>";

if (empty($recent_activity)) {
    echo "<div class='empty-state'>";
    echo "<i class='fa fa-clock-o'></i>";
    echo "<h3>No Recent Activity</h3>";
    echo "<p>No activity recorded in the last 30 days.</p>";
    echo "</div>";
} else {
    echo "<div class='activity-timeline'>";
    foreach ($recent_activity as $activity) {
        $activity_icon = 'fa-circle';
        $activity_title = 'Activity';
        
        // Determine activity type and icon
        switch ($activity->action) {
            case 'viewed':
                $activity_icon = 'fa-eye';
                $activity_title = 'Viewed';
                break;
            case 'submitted':
                $activity_icon = 'fa-paper-plane';
                $activity_title = 'Submitted';
                break;
            case 'attempted':
                $activity_icon = 'fa-question-circle';
                $activity_title = 'Quiz Attempt';
                break;
            case 'loggedin':
                $activity_icon = 'fa-sign-in';
                $activity_title = 'Logged In';
                break;
            default:
                $activity_icon = 'fa-circle';
                $activity_title = ucfirst($activity->action);
        }
        
        echo "<div class='activity-item'>";
        echo "<div class='activity-header'>";
        echo "<div class='activity-icon'>";
        echo "<i class='fa " . $activity_icon . "'></i>";
        echo "</div>";
        echo "<h4 class='activity-title'>" . $activity_title . "</h4>";
        echo "<span class='activity-time'>" . date('M j, g:i A', $activity->timecreated) . "</span>";
        echo "</div>";
        echo "<p class='activity-description'>";
        if ($activity->course_name) {
            echo "In " . htmlspecialchars($activity->course_name);
        } else {
            echo "System activity";
        }
        echo "</p>";
        echo "</div>";
    }
    echo "</div>";
}
echo "</div>";

echo "</div>"; // End content-area
echo "</div>"; // End student-profile-container

echo "</div>"; // End main content
echo "</div>"; // End school-manager-main-content

// Profile Image Modal
echo "<div id='profileModal' class='profile-modal' onclick='closeProfileModal()'>";
echo "<div class='modal-content' onclick='event.stopPropagation()'>";
echo "<span class='modal-close' onclick='closeProfileModal()'>&times;</span>";
echo "<img id='modalImage' src='' alt='Profile Picture' class='modal-image'>";
echo "<div class='modal-info'>";
echo "<h3 id='modalName'>" . htmlspecialchars(fullname($student)) . "</h3>";
echo "<p id='modalUsername'>@" . htmlspecialchars($student->username) . "</p>";
echo "<p id='modalRole'>Student</p>";
echo "</div>";
echo "</div>";
echo "</div>";

echo $OUTPUT->footer();
?>

<script>
// Enhanced image loading with fallback support
function handleImageError(img) {
    const fallbacks = JSON.parse(img.getAttribute('data-fallbacks') || '[]');
    const currentSrc = img.src;
    let fallbackIndex = -1;
    
    // Find current image in fallback list
    for (let i = 0; i < fallbacks.length; i++) {
        if (fallbacks[i] === currentSrc) {
            fallbackIndex = i;
            break;
        }
    }
    
    // Try next fallback
    if (fallbackIndex < fallbacks.length - 1) {
        img.src = fallbacks[fallbackIndex + 1];
        return;
    }
    
    // If all fallbacks failed, show initials
    img.style.display = 'none';
    const initialsSpan = img.nextElementSibling;
    if (initialsSpan && initialsSpan.tagName === 'SPAN') {
        initialsSpan.style.display = 'flex';
    }
}

// Profile Modal Functions
function openProfileModal(img) {
    const modal = document.getElementById('profileModal');
    const modalImg = document.getElementById('modalImage');
    const modalName = document.getElementById('modalName');
    const modalUsername = document.getElementById('modalUsername');
    const modalRole = document.getElementById('modalRole');
    
    if (modal && modalImg) {
        // Get HD image URL
        const hdUrl = img.getAttribute('data-hd-url') || img.src;
        
        // Set modal content
        modalImg.src = hdUrl;
        modalImg.alt = img.alt;
        
        // Update modal info if elements exist
        if (modalName) modalName.textContent = img.alt;
        if (modalUsername) modalUsername.textContent = '@' + img.alt.split(' ').join('').toLowerCase();
        if (modalRole) modalRole.textContent = 'Student';
        
        // Show modal - DO NOT set window.isModalOpen to keep sidebar visible
        modal.style.display = 'block';
        document.body.style.overflow = 'hidden'; // Prevent background scrolling
        
        // Add escape key listener
        document.addEventListener('keydown', handleEscapeKey);
    }
}

function closeProfileModal() {
    const modal = document.getElementById('profileModal');
    if (modal) {
        modal.style.display = 'none';
        document.body.style.overflow = 'auto'; // Restore scrolling
        document.removeEventListener('keydown', handleEscapeKey);
    }
}

function handleEscapeKey(event) {
    if (event.key === 'Escape') {
        closeProfileModal();
    }
}

// Preload high-quality images for better performance
document.addEventListener('DOMContentLoaded', function() {
    const profileImg = document.querySelector('.profile-avatar img');
    if (profileImg && profileImg.src) {
        // Create a new image to preload the high-quality version
        const preloadImg = new Image();
        preloadImg.onload = function() {
            // Image loaded successfully, replace the current one
            profileImg.src = this.src;
        };
        preloadImg.src = profileImg.src;
    }
    
    // Add click event to profile avatar for better UX
    const profileAvatar = document.querySelector('.profile-avatar');
    if (profileAvatar) {
        profileAvatar.style.cursor = 'pointer';
        profileAvatar.title = 'Click to view in HD';
    }
});
</script>
