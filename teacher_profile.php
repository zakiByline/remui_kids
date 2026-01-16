<?php
require_once('../../config.php');
require_once(__DIR__ . '/lang_init.php'); // Apply user's selected language
require_once($CFG->libdir . '/adminlib.php');

// Ensure user is logged in
require_login();

// Get teacher ID from URL parameter
$teacher_id = required_param('id', PARAM_INT);

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

// Get teacher information
$teacher = null;
try {
    $teacher = $DB->get_record_sql(
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
                GROUP_CONCAT(DISTINCT r.shortname SEPARATOR ',') AS roles
          FROM {user} u
          INNER JOIN {company_users} cu ON cu.userid = u.id AND cu.companyid = ?
          LEFT JOIN {role_assignments} ra ON ra.userid = u.id
          LEFT JOIN {role} r ON r.id = ra.roleid AND (r.shortname = 'teacher' OR r.shortname = 'editingteacher')
         WHERE u.id = ? AND u.deleted = 0
       GROUP BY u.id, u.firstname, u.lastname, u.email, u.phone1, u.phone2, u.address, u.city, u.country, u.timezone, u.lang, u.username, u.suspended, u.lastaccess, u.firstaccess, u.lastlogin, u.currentlogin, u.picture, u.description, u.descriptionformat, u.mailformat, u.maildigest, u.maildisplay, u.autosubscribe, u.trackforums, u.timecreated, u.timemodified, u.lastnamephonetic, u.firstnamephonetic, u.middlename, u.alternatename, u.idnumber, cu.educator",
        [$company_id, $teacher_id]
    );
} catch (Exception $e) {
    error_log("Error getting teacher info: " . $e->getMessage());
}

// Redirect if teacher not found or not in this company
if (!$teacher) {
    redirect(new moodle_url('/theme/remui_kids/teacher_management.php'), 'Teacher not found or access denied.', null, \core\output\notification::NOTIFY_ERROR);
}

// Get teacher's role display name
$role_display = 'Teacher';
if (!empty($teacher->roles)) {
    if (strpos($teacher->roles, 'editingteacher') !== false) {
        $role_display = 'Editing Teacher';
    } elseif (strpos($teacher->roles, 'teacher') !== false) {
        $role_display = 'Teacher';
    }
}

// Get courses where teacher is assigned (with accurate student counts)
$teaching_courses = [];
try {
    $teaching_courses = $DB->get_records_sql(
        "SELECT DISTINCT c.id,
                c.fullname,
                c.shortname,
                c.summary,
                c.startdate,
                c.enddate,
                c.visible,
                cc.courseid,
                (SELECT COUNT(DISTINCT ue2.userid)
                 FROM {enrol} e2
                 INNER JOIN {user_enrolments} ue2 ON ue2.enrolid = e2.id AND ue2.status = 0
                 INNER JOIN {user} u2 ON u2.id = ue2.userid AND u2.deleted = 0 AND u2.suspended = 0
                 INNER JOIN {company_users} cu2 ON cu2.userid = u2.id AND cu2.companyid = ?
                 INNER JOIN {role_assignments} sra ON sra.userid = u2.id
                 INNER JOIN {role} sr ON sr.id = sra.roleid AND sr.shortname = 'student'
                 WHERE e2.courseid = c.id AND e2.status = 0) as enrolled_students
           FROM {course} c
           INNER JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = 50
           INNER JOIN {role_assignments} ra ON ra.contextid = ctx.id AND ra.userid = ?
           INNER JOIN {role} r ON r.id = ra.roleid AND (r.shortname = 'teacher' OR r.shortname = 'editingteacher')
           INNER JOIN {company_course} cc ON cc.courseid = c.id AND cc.companyid = ?
          WHERE c.visible = 1
        GROUP BY c.id, c.fullname, c.shortname, c.summary, c.startdate, c.enddate, c.visible, cc.courseid
        ORDER BY c.fullname",
        [$company_id, $teacher_id, $company_id]
    );
} catch (Exception $e) {
    error_log("Error getting teaching courses: " . $e->getMessage());
}

// Get total students taught by this teacher (ONLY students enrolled in teacher's assigned courses)
$total_students = 0;
try {
    $total_students_result = $DB->get_record_sql(
        "SELECT COUNT(DISTINCT ue.userid) as total_students
           FROM {course} c
           INNER JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = 50
           INNER JOIN {role_assignments} ra ON ra.contextid = ctx.id AND ra.userid = ?
           INNER JOIN {role} r ON r.id = ra.roleid AND (r.shortname = 'teacher' OR r.shortname = 'editingteacher')
           INNER JOIN {company_course} cc ON cc.courseid = c.id AND cc.companyid = ?
           INNER JOIN {enrol} e ON e.courseid = c.id AND e.status = 0
           INNER JOIN {user_enrolments} ue ON ue.enrolid = e.id AND ue.status = 0
           INNER JOIN {user} u ON u.id = ue.userid AND u.deleted = 0 AND u.suspended = 0
           INNER JOIN {company_users} cu ON cu.userid = u.id AND cu.companyid = ?
           INNER JOIN {role_assignments} student_ra ON student_ra.userid = u.id
           INNER JOIN {role} student_role ON student_role.id = student_ra.roleid AND student_role.shortname = 'student'
          WHERE c.visible = 1",
        [$teacher_id, $company_id, $company_id]
    );
    
    if ($total_students_result && isset($total_students_result->total_students)) {
        $total_students = (int)$total_students_result->total_students;
    }
    
    // Debug logging
    error_log("Teacher Profile - Teacher ID: {$teacher_id}, Company ID: {$company_id}");
    error_log("Teacher Profile - Total Students in Teacher's Courses: {$total_students}");
    error_log("Teacher Profile - Total Teaching Courses: " . count($teaching_courses));
} catch (Exception $e) {
    error_log("Error getting total students: " . $e->getMessage());
}

// Get quiz statistics for courses taught by this teacher
$quiz_stats = [];
try {
    $quiz_stats = $DB->get_records_sql(
        "SELECT q.id,
                q.name as quiz_name,
                c.fullname as course_name,
                COUNT(DISTINCT qa.id) as total_attempts,
                COUNT(DISTINCT qa.userid) as unique_students,
                AVG(qa.sumgrades) as average_grade,
                MAX(qa.timefinish) as last_attempt
           FROM {quiz} q
           INNER JOIN {course} c ON c.id = q.course
           INNER JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = 50
           INNER JOIN {role_assignments} ra ON ra.contextid = ctx.id AND ra.userid = ?
           INNER JOIN {role} r ON r.id = ra.roleid AND (r.shortname = 'teacher' OR r.shortname = 'editingteacher')
           INNER JOIN {company_course} cc ON cc.courseid = c.id AND cc.companyid = ?
           LEFT JOIN {quiz_attempts} qa ON qa.quiz = q.id AND qa.state = 'finished'
          WHERE c.visible = 1
        GROUP BY q.id, q.name, c.fullname
        ORDER BY last_attempt DESC
        LIMIT 10",
        [$teacher_id, $company_id]
    );
} catch (Exception $e) {
    error_log("Error getting quiz stats: " . $e->getMessage());
}

// Get assignment statistics
$assignment_stats = [];
try {
    $assignment_stats = $DB->get_records_sql(
        "SELECT a.id,
                a.name as assignment_name,
                c.fullname as course_name,
                COUNT(DISTINCT s.id) as total_submissions,
                COUNT(DISTINCT s.userid) as unique_students,
                AVG(CASE WHEN g.grade IS NOT NULL THEN (g.grade / g.grademax) * 100 ELSE NULL END) as average_percentage
           FROM {assign} a
           INNER JOIN {course} c ON c.id = a.course
           INNER JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = 50
           INNER JOIN {role_assignments} ra ON ra.contextid = ctx.id AND ra.userid = ?
           INNER JOIN {role} r ON r.id = ra.roleid AND (r.shortname = 'teacher' OR r.shortname = 'editingteacher')
           INNER JOIN {company_course} cc ON cc.courseid = c.id AND cc.companyid = ?
           LEFT JOIN {assign_submission} s ON s.assignment = a.id AND s.status = 'submitted'
           LEFT JOIN {assign_grades} g ON g.assignment = a.id AND g.userid = s.userid
          WHERE c.visible = 1
        GROUP BY a.id, a.name, c.fullname
        ORDER BY total_submissions DESC
        LIMIT 10",
        [$teacher_id, $company_id]
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
        [$teacher_id, time() - (30 * 24 * 60 * 60)] // Last 30 days
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
    'company_logo_url' => !empty($company_info->logo) ? $company_info->logo : '',
    'has_logo' => !empty($company_info->logo),
    'user_info' => [
        'fullname' => fullname($USER)
    ],
    'teachers_active' => true, // Highlight Teacher Management in sidebar
    'dashboard_active' => false,
    'students_active' => false,
    'courses_active' => false,
    'enrollments_active' => false,
    'bulk_download_active' => false,
    'bulk_profile_upload_active' => false,
    'add_users_active' => false,
    'analytics_active' => false,
    'reports_active' => false,
    'user_reports_active' => false,
    'course_reports_active' => false,
    'settings_active' => false,
    'help_active' => false
];

// Set page context
$PAGE->set_context(context_system::instance());
$PAGE->set_url('/theme/remui_kids/teacher_profile.php', ['id' => $teacher_id]);
$PAGE->set_title('Teacher Profile - ' . fullname($teacher) . ' - ' . $company_info->name);
$PAGE->set_heading('Teacher Profile');

// Output the header first
echo $OUTPUT->header();

// Render the school manager sidebar
try {
    echo $OUTPUT->render_from_template('theme_remui_kids/school_manager_sidebar', $sidebarcontext);
} catch (Exception $e) {
    // Fallback sidebar
    echo "<div style='color: red; padding: 20px;'>Error loading sidebar: " . htmlspecialchars($e->getMessage()) . "</div>";
}

// Force sidebar to remain visible on this page regardless of modals
echo "<script>window.forceSidebarAlways = true;</script>";

// Custom CSS for the teacher profile layout (same as student but adapted)
echo "<style>";
echo "
/* Import Google Fonts */
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');

/* Force sidebar to stay visible on teacher profile page - CRITICAL */
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

.main-content {
    padding: 0;
    min-height: 100vh;
    max-width: 1400px;
    margin: 0 auto;
}

/* Page Header */
.page-header {
    background: linear-gradient(180deg, rgba(102, 126, 234, 0.25), rgba(102, 126, 234, 0.08), #ffffff);
    color: #2c3e50;
    padding: 25px 30px;
    border-radius: 15px;
    margin-bottom: 30px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.08);
    border: 2px solid rgba(102, 126, 234, 0.3);
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

/* Teacher Profile Container */
.teacher-profile-container {
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
    background: linear-gradient(135deg, #f59e0b, #d97706);
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 20px;
    font-size: 3rem;
    font-weight: 700;
    color: white;
    box-shadow: 0 10px 25px rgba(245, 158, 11, 0.3);
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
    cursor: pointer;
    z-index: 2;
    transition: all 0.3s ease;
}

.profile-avatar img:hover {
    transform: scale(1.05);
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
}

.profile-avatar span {
    position: relative;
    z-index: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    height: 100%;
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
    background: linear-gradient(135deg, #f59e0b, #d97706);
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
    background: linear-gradient(90deg, #f59e0b, #d97706);
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
    background: linear-gradient(135deg, #f59e0b, #d97706);
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
    background: linear-gradient(135deg, #f59e0b, #d97706);
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
    
    .teacher-profile-container {
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
}

/* Profile Image Modal */
.profile-modal {
    display: none;
    position: fixed;
    z-index: 10000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.9);
    animation: fadeIn 0.3s ease;
}

.modal-content {
    position: relative;
    background-color: white;
    margin: 5% auto;
    padding: 0;
    border-radius: 20px;
    max-width: 800px;
    width: 90%;
    box-shadow: 0 10px 50px rgba(0, 0, 0, 0.5);
    overflow: hidden;
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
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(255, 255, 255, 0.9);
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
    border-radius: 20px 20px 0 0;
    image-rendering: high-quality;
}

.modal-info {
    padding: 30px;
    text-align: center;
    background: linear-gradient(135deg, #fef3c7, #fde68a);
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
    from { transform: translateY(-50px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}

/* Responsive Modal */
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
";
echo "</style>";

// Main content area
echo "<div class='school-manager-main-content'>";
echo "<div class='main-content'>";

// Page Header
echo "<div class='page-header'>";
echo "<div class='page-header-content'>";
echo "<h1 class='page-title'>Teacher Profile</h1>";
echo "<p class='page-subtitle'>Detailed information for " . fullname($teacher) . "</p>";
echo "</div>";
echo "<a href='{$CFG->wwwroot}/theme/remui_kids/teacher_management.php' class='back-button'>";
echo "<i class='fa fa-arrow-left'></i> Back to Teachers";
echo "</a>";
echo "</div>";

// Teacher Profile Container
echo "<div class='teacher-profile-container'>";

// Profile Card
echo "<div class='profile-card'>";
echo "<div class='profile-header'>";

// Profile Avatar
$teacher_user = $DB->get_record('user', ['id' => $teacher->id]);
$initials = strtoupper(substr($teacher->firstname, 0, 1) . substr($teacher->lastname, 0, 1));

// Only load image if user has a profile picture uploaded
$has_profile_picture = false;
$profile_image_url = '';

if ($teacher_user && $teacher_user->picture > 0) {
    // Verify the file actually exists in file storage
    $user_context = context_user::instance($teacher_user->id);
    $fs = get_file_storage();
    
    // Check if file exists in 'icon' area (where Moodle stores profile pics)
    $files = $fs->get_area_files($user_context->id, 'user', 'icon', 0, 'itemid', false);
    
    if (!empty($files)) {
        // File exists, safe to generate URL
        try {
            $user_picture = new user_picture($teacher_user);
            $user_picture->size = 1; // f1 = full size
            $profile_image_url = $user_picture->get_url($PAGE)->out(false);
            
            // If we have a valid URL, set the flag
            if (!empty($profile_image_url)) {
                $has_profile_picture = true;
            }
        } catch (Exception $e) {
            error_log("Error generating teacher profile image URL for user ID " . $teacher_user->id . ": " . $e->getMessage());
            $has_profile_picture = false;
        }
    } else {
        // Picture field is set but file doesn't exist - reset the field
        $teacher_user->picture = 0;
        $teacher_user->timemodified = time();
        $DB->update_record('user', $teacher_user);
        $has_profile_picture = false;
    }
}

echo "<div class='profile-avatar'>";
if ($has_profile_picture && !empty($profile_image_url)) {
    // Show actual profile picture with modal onclick
    echo "<img src='" . htmlspecialchars($profile_image_url) . "' ";
    echo "     alt='" . htmlspecialchars(fullname($teacher)) . "' ";
    echo "     onclick='openProfileModal(this)' ";
    echo "     onerror='console.log(\"Profile pic load failed:\", this.src); this.style.display=\"none\"; this.nextElementSibling.style.display=\"flex\";' ";
    echo "     style='width: 100%; height: 100%; border-radius: 50%; object-fit: cover; cursor: pointer; display: block;'>";
    echo "<span style='display: none; align-items: center; justify-content: center; width: 100%; height: 100%;'>" . htmlspecialchars($initials) . "</span>";
} else {
    // Show initials placeholder
    echo "<span style='display: flex; align-items: center; justify-content: center; width: 100%; height: 100%;'>" . htmlspecialchars($initials) . "</span>";
}
echo "<div class='status-indicator " . ($teacher->suspended ? 'status-suspended' : 'status-active') . "'></div>";
echo "<!-- Teacher ID: " . $teacher_user->id . ", Picture field: " . $teacher_user->picture . " -->";
echo "</div>";

echo "<h2 class='profile-name'>" . fullname($teacher) . "</h2>";
echo "<p class='profile-username'>@" . htmlspecialchars($teacher->username) . "</p>";
echo "<span class='profile-role'>" . htmlspecialchars($role_display) . "</span>";
echo "</div>";

// Profile Information
echo "<div class='profile-info'>";
echo "<div class='info-section'>";
echo "<span class='info-label'>Teacher ID</span>";
echo "<span class='info-value'>" . ($teacher->idnumber ? htmlspecialchars($teacher->idnumber) : 'Not assigned') . "</span>";
echo "</div>";

echo "<div class='info-section'>";
echo "<span class='info-label'>Role</span>";
echo "<span class='info-value'>" . htmlspecialchars($role_display) . "</span>";
echo "</div>";

echo "<div class='info-section'>";
echo "<span class='info-label'>Status</span>";
echo "<span class='info-value'>" . ($teacher->suspended ? 'Suspended' : 'Active') . "</span>";
echo "</div>";

echo "<div class='info-section'>";
echo "<span class='info-label'>Member Since</span>";
echo "<span class='info-value'>" . date('M j, Y', $teacher->timecreated) . "</span>";
echo "</div>";

echo "<div class='info-section'>";
echo "<span class='info-label'>Last Access</span>";
echo "<span class='info-value'>" . ($teacher->lastaccess ? date('M j, Y g:i A', $teacher->lastaccess) : 'Never') . "</span>";
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
echo "<div class='contact-value'>" . htmlspecialchars($teacher->email) . "</div>";
echo "</div>";
echo "</div>";

if ($teacher->phone1) {
    echo "<div class='contact-item'>";
    echo "<div class='contact-icon phone'>";
    echo "<i class='fa fa-phone'></i>";
    echo "</div>";
    echo "<div class='contact-details'>";
    echo "<div class='contact-label'>Phone</div>";
    echo "<div class='contact-value'>" . htmlspecialchars($teacher->phone1) . "</div>";
    echo "</div>";
    echo "</div>";
}

if ($teacher->city || $teacher->country) {
    echo "<div class='contact-item'>";
    echo "<div class='contact-icon location'>";
    echo "<i class='fa fa-map-marker'></i>";
    echo "</div>";
    echo "<div class='contact-details'>";
    echo "<div class='contact-label'>Location</div>";
    echo "<div class='contact-value'>" . htmlspecialchars(trim(($teacher->city ?: '') . ', ' . ($teacher->country ?: ''), ', ')) . "</div>";
    echo "</div>";
    echo "</div>";
}
echo "</div>";

// Action Buttons
echo "<div class='profile-actions'>";
echo "<a href='{$CFG->wwwroot}/user/edit.php?id=" . $teacher->id . "' class='action-btn btn-primary'>";
echo "<i class='fa fa-edit'></i> Edit Profile";
echo "</a>";
echo "<a href='{$CFG->wwwroot}/user/view.php?id=" . $teacher->id . "&course=1' class='action-btn btn-secondary'>";
echo "<i class='fa fa-user'></i> View Public Profile";
echo "</a>";
if (!$teacher->suspended) {
    echo "<a href='{$CFG->wwwroot}/theme/remui_kids/teacher_management.php?action=delete&teacher_id=" . $teacher->id . "' class='action-btn btn-warning' onclick='return confirm(\"Are you sure you want to suspend this teacher?\")'>";
    echo "<i class='fa fa-ban'></i> Suspend Teacher";
    echo "</a>";
} else {
    echo "<a href='{$CFG->wwwroot}/theme/remui_kids/teacher_management.php?action=activate&teacher_id=" . $teacher->id . "' class='action-btn btn-primary' onclick='return confirm(\"Are you sure you want to activate this teacher?\")'>";
    echo "<i class='fa fa-check'></i> Activate Teacher";
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
echo "<i class='fa fa-chalkboard-teacher'></i>";
echo "</div>";
echo "<div class='stat-number'>" . count($teaching_courses) . "</div>";
echo "<div class='stat-label'>Teaching Courses</div>";
echo "</div>";

echo "<div class='stat-card'>";
echo "<div class='stat-icon'>";
echo "<i class='fa fa-users'></i>";
echo "</div>";
echo "<div class='stat-number'>" . $total_students . "</div>";
echo "<div class='stat-label'>Total Students</div>";
echo "</div>";

echo "<div class='stat-card'>";
echo "<div class='stat-icon'>";
echo "<i class='fa fa-question-circle'></i>";
echo "</div>";
echo "<div class='stat-number'>" . count($quiz_stats) . "</div>";
echo "<div class='stat-label'>Quizzes Created</div>";
echo "</div>";

echo "<div class='stat-card'>";
echo "<div class='stat-icon'>";
echo "<i class='fa fa-tasks'></i>";
echo "</div>";
echo "<div class='stat-number'>" . count($assignment_stats) . "</div>";
echo "<div class='stat-label'>Assignments</div>";
echo "</div>";
echo "</div>";

// Teaching Courses Section
echo "<div class='content-section'>";
echo "<div class='section-header'>";
echo "<div class='section-icon'>";
echo "<i class='fa fa-chalkboard-teacher'></i>";
echo "</div>";
echo "<div>";
echo "<h3 class='section-title'>Teaching Courses</h3>";
echo "<p class='section-subtitle'>" . count($teaching_courses) . " courses assigned</p>";
echo "</div>";
echo "</div>";

if (empty($teaching_courses)) {
    echo "<div class='empty-state'>";
    echo "<i class='fa fa-chalkboard-teacher'></i>";
    echo "<h3>No Courses Assigned</h3>";
    echo "<p>This teacher is not assigned to any courses yet.</p>";
    echo "</div>";
} else {
    echo "<div class='course-list'>";
    foreach ($teaching_courses as $course) {
        echo "<div class='course-item'>";
        echo "<div class='course-icon'>";
        echo "<i class='fa fa-book'></i>";
        echo "</div>";
        echo "<div class='course-info'>";
        echo "<h4 class='course-name'>" . htmlspecialchars($course->fullname) . "</h4>";
        echo "<p class='course-meta'>" . ($course->enrolled_students ?? 0) . " students enrolled</p>";
        echo "</div>";
        echo "</div>";
    }
    echo "</div>";
}
echo "</div>";

// Quiz Statistics Section
if (!empty($quiz_stats)) {
    echo "<div class='content-section'>";
    echo "<div class='section-header'>";
    echo "<div class='section-icon'>";
    echo "<i class='fa fa-question-circle'></i>";
    echo "</div>";
    echo "<div>";
    echo "<h3 class='section-title'>Quiz Statistics</h3>";
    echo "<p class='section-subtitle'>" . count($quiz_stats) . " quizzes created</p>";
    echo "</div>";
    echo "</div>";
    
    echo "<div class='course-list'>";
    foreach ($quiz_stats as $quiz) {
        echo "<div class='course-item'>";
        echo "<div class='course-icon'>";
        echo "<i class='fa fa-question-circle'></i>";
        echo "</div>";
        echo "<div class='course-info'>";
        echo "<h4 class='course-name'>" . htmlspecialchars($quiz->quiz_name) . "</h4>";
        echo "<p class='course-meta'>" . htmlspecialchars($quiz->course_name) . " • " . ($quiz->total_attempts ?? 0) . " attempts by " . ($quiz->unique_students ?? 0) . " students</p>";
        echo "</div>";
        echo "</div>";
    }
    echo "</div>";
    echo "</div>";
}

// Assignment Statistics Section
if (!empty($assignment_stats)) {
    echo "<div class='content-section'>";
    echo "<div class='section-header'>";
    echo "<div class='section-icon'>";
    echo "<i class='fa fa-tasks'></i>";
    echo "</div>";
    echo "<div>";
    echo "<h3 class='section-title'>Assignment Statistics</h3>";
    echo "<p class='section-subtitle'>" . count($assignment_stats) . " assignments created</p>";
    echo "</div>";
    echo "</div>";
    
    echo "<div class='course-list'>";
    foreach ($assignment_stats as $assignment) {
        echo "<div class='course-item'>";
        echo "<div class='course-icon'>";
        echo "<i class='fa fa-file-alt'></i>";
        echo "</div>";
        echo "<div class='course-info'>";
        echo "<h4 class='course-name'>" . htmlspecialchars($assignment->assignment_name) . "</h4>";
        echo "<p class='course-meta'>" . htmlspecialchars($assignment->course_name) . " • " . ($assignment->total_submissions ?? 0) . " submissions from " . ($assignment->unique_students ?? 0) . " students</p>";
        echo "</div>";
        echo "</div>";
    }
    echo "</div>";
    echo "</div>";
}

echo "</div>"; // End content-area
echo "</div>"; // End teacher-profile-container

echo "</div>"; // End main content
echo "</div>"; // End school-manager-main-content

// Profile Image Modal
echo "<div id='profileModal' class='profile-modal' onclick='closeProfileModal()'>";
echo "<div class='modal-content' onclick='event.stopPropagation()'>";
echo "<span class='modal-close' onclick='closeProfileModal()'>&times;</span>";
echo "<img id='modalImage' src='' alt='Profile Picture' class='modal-image'>";
echo "<div class='modal-info'>";
echo "<h3 id='modalName'>" . htmlspecialchars(fullname($teacher)) . "</h3>";
echo "<p id='modalUsername'>@" . htmlspecialchars($teacher->username) . "</p>";
echo "<p id='modalRole'>Editing Teacher</p>";
echo "</div>";
echo "</div>";
echo "</div>";

// JavaScript for modal functionality
echo "<script>
// Profile Modal Functions
function openProfileModal(img) {
    const modal = document.getElementById('profileModal');
    const modalImg = document.getElementById('modalImage');
    const modalName = document.getElementById('modalName');
    const modalUsername = document.getElementById('modalUsername');
    const modalRole = document.getElementById('modalRole');
    
    if (modal && modalImg) {
        // Set modal content
        modalImg.src = img.src;
        modalImg.alt = img.alt;
        
        // Update modal info if elements exist
        if (modalName) modalName.textContent = img.alt;
        
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
</script>";

echo $OUTPUT->footer();
?>

