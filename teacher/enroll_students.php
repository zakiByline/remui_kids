<?php
// Teacher's Student Enrollment Dashboard - Modern UI for enrolling students
require_once('../../../config.php');

// Require login and proper access.
require_login();

// Load necessary libraries after config
require_once($CFG->dirroot . '/course/lib.php');

$selectedcourseid = optional_param('courseid', 0, PARAM_INT);

// Check if user is teacher
$isteacher = false;
$teacherroles = $DB->get_records_select('role', "shortname IN ('editingteacher','teacher','manager')");
$roleids = array_keys($teacherroles);

if (!empty($roleids)) {
    $userroles = $DB->get_records_sql(
        "SELECT DISTINCT r.shortname 
         FROM {role} r 
         JOIN {role_assignments} ra ON r.id = ra.roleid 
         WHERE ra.userid = ? AND r.shortname IN ('editingteacher','teacher','manager')",
        [$USER->id]
    );
    
    if (!empty($userroles)) {
        $isteacher = true;
    }
}

if (is_siteadmin()) {
    $isteacher = true;
}

if (!$isteacher) {
    throw new moodle_exception('nopermissions', 'error', '', 'You must be a teacher to access this page');
}

// Set up the page.
$PAGE->set_context(context_system::instance());
$PAGE->set_url('/theme/remui_kids/teacher/enroll_students.php');
$PAGE->set_pagelayout('base');
$PAGE->set_title('Enroll Students - Teacher Dashboard');
$PAGE->set_heading('');

// Add breadcrumb.
$PAGE->navbar->add('Enroll Students');

// Handle enrollment/unenrollment actions - Simple Direct Approach
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    require_sesskey(); // Security check
    
    $course_id = required_param('course_id', PARAM_INT);
    $user_id = required_param('user_id', PARAM_INT);
    $action = required_param('action', PARAM_ALPHA);
    
    // Debug: Log the attempt
    error_log("=== ENROLLMENT ATTEMPT ===");
    error_log("Course ID: $course_id");
    error_log("User ID: $user_id");
    error_log("Action: $action");
    error_log("POST Data: " . print_r($_POST, true));
    
    try {
        if ($action === 'enroll') {
            // Simple direct enrollment approach
            $course = $DB->get_record('course', ['id' => $course_id]);
            if (!$course) {
                throw new Exception("Course not found");
            }
            
            // Get or create manual enrollment instance
            $enrol_instance = $DB->get_record('enrol', [
                'courseid' => $course_id,
                'enrol' => 'manual',
                'status' => 0
            ]);
            
            if (!$enrol_instance) {
                // Create manual enrollment instance
                $enrol_instance = new stdClass();
                $enrol_instance->courseid = $course_id;
                $enrol_instance->enrol = 'manual';
                $enrol_instance->status = 0;
                $enrol_instance->timecreated = time();
                $enrol_instance->timemodified = time();
                $enrol_instance->id = $DB->insert_record('enrol', $enrol_instance);
                error_log("Created enrollment instance: " . $enrol_instance->id);
            }
            
            // Check if already enrolled
            $existing = $DB->get_record('user_enrolments', [
                'enrolid' => $enrol_instance->id,
                'userid' => $user_id
            ]);
            
            if (!$existing) {
                // Enroll user directly
                $enrollment = new stdClass();
                $enrollment->enrolid = $enrol_instance->id;
                $enrollment->userid = $user_id;
                $enrollment->timestart = time();
                $enrollment->timeend = 0;
                $enrollment->modifierid = $USER->id;
                $enrollment->timecreated = time();
                $enrollment->timemodified = time();
                $enrollment->status = 0; // Active
                
                $enrollment_id = $DB->insert_record('user_enrolments', $enrollment);
                error_log("Created enrollment record: " . $enrollment_id);
                
                // Assign student role
                $context = context_course::instance($course_id);
                $student_role = $DB->get_record('role', ['shortname' => 'student']);
                if ($student_role) {
                    role_assign($student_role->id, $user_id, $context->id, 'enrol_manual', $enrol_instance->id);
                    error_log("Assigned student role");
                }
                
                $success_message = "Student enrolled successfully!";
            } else {
                $error_message = "Student is already enrolled in this course.";
            }
            
        } elseif ($action === 'unenroll') {
            // Simple direct unenrollment approach
            $enrol_instance = $DB->get_record('enrol', [
                'courseid' => $course_id,
                'enrol' => 'manual'
            ]);
            
            if ($enrol_instance) {
                // Remove enrollment
                $deleted = $DB->delete_records('user_enrolments', [
                    'enrolid' => $enrol_instance->id,
                    'userid' => $user_id
                ]);
                
                if ($deleted) {
                    // Remove role assignments
                    $context = context_course::instance($course_id);
                    $student_role = $DB->get_record('role', ['shortname' => 'student']);
                    if ($student_role) {
                        role_unassign($student_role->id, $user_id, $context->id, 'enrol_manual', $enrol_instance->id);
                    }
                    error_log("Removed enrollment and role assignment");
                    $success_message = "Student unenrolled successfully!";
                } else {
                    $error_message = "Student was not enrolled in this course.";
                }
            } else {
                $error_message = "Could not find enrollment instance.";
            }
        }
    } catch (Exception $e) {
        $error_message = "Error: " . $e->getMessage();
        error_log('Enrollment error: ' . $e->getMessage());
    }
    
    // Store message in session
    if (isset($success_message)) {
        $_SESSION['enrollment_success'] = $success_message;
    }
    if (isset($error_message)) {
        $_SESSION['enrollment_error'] = $error_message;
    }
    
    // Simple redirect
    redirect(new moodle_url('/theme/remui_kids/teacher/enroll_students.php'));
}

// Get teacher's courses
$teacher_courses = $DB->get_records_sql(
    "SELECT DISTINCT c.*
     FROM {course} c
     JOIN {context} ctx ON c.id = ctx.instanceid
     JOIN {role_assignments} ra ON ctx.id = ra.contextid
     JOIN {role} r ON ra.roleid = r.id
     WHERE ra.userid = ? AND r.shortname IN ('editingteacher','teacher','manager') 
     AND c.id > 1 AND c.visible = 1
     ORDER BY c.fullname ASC",
    [$USER->id]
);

$selected_course = null;
if ($selectedcourseid && isset($teacher_courses[$selectedcourseid])) {
    $selected_course = $teacher_courses[$selectedcourseid];
} else {
    $selectedcourseid = 0;
}

// Get all students (excluding guest and admin users)
$all_students = $DB->get_records_sql(
    "SELECT DISTINCT u.*
     FROM {user} u
     WHERE u.deleted = 0 AND u.suspended = 0 AND u.id > 2
     AND u.firstname != '' AND u.lastname != ''
     ORDER BY u.firstname, u.lastname ASC"
);

echo $OUTPUT->header();

// Include Font Awesome CSS via HTML link tag
echo '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">';

// Add custom CSS including sidebar styles
echo '<style>
:root {
    --color-page-bg: #f8fafc;
    --color-surface: #ffffff;
    --color-border: #e2e8f0;
    --color-shadow: rgba(15, 23, 42, 0.08);
    --color-muted: #64748b;
    --color-heading: #0f172a;
    --color-accent: #6366f1;
    --color-accent-soft: #e0e7ff;
    --color-success: #10b981;
    --color-success-soft: #d1fae5;
    --color-danger: #ef4444;
    --color-danger-soft: #fee2e2;
}

#region-main,
[role="main"] {
    background: transparent !important;
    box-shadow: none !important;
    border: 0 !important;
    padding: 0 !important;
    margin: 0 !important;
}

.teacher-main-content {
    background: var(--color-page-bg);
    padding: 0 !important;
}

.enrollment-dashboard {
    width: 100%;
    max-width: none;
    margin: 0;
    padding: 32px 32px 48px;
    background: var(--color-page-bg);
    min-height: 100vh;
    box-sizing: border-box;
}

.horizontal-stats-container {
    background: linear-gradient(135deg, #eef2ff 0%, #f5f3ff 100%);
    border-radius: 16px;
    padding: 18px 20px;
    margin-bottom: 24px;
    border: 1px solid rgba(148, 163, 184, 0.3);
    box-shadow: 0 8px 18px rgba(79, 70, 229, 0.1);
}

.stats-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 16px;
}

.stat-item {
    background: rgba(255, 255, 255, 0.95);
    border-radius: 14px;
    padding: 16px;
    border: 1px solid rgba(226, 232, 240, 0.7);
    box-shadow: 0 6px 14px rgba(148, 163, 184, 0.18);
}

.stat-number {
    font-size: 1.9rem;
    font-weight: 700;
    color: var(--color-heading);
    margin-bottom: 4px;
}

.stat-label {
    font-size: 0.75rem;
    font-weight: 600;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: var(--color-muted);
}

.search-section {
    background: var(--color-surface);
    border-radius: 20px;
    padding: 24px 28px;
    margin-bottom: 28px;
    border: 1px solid var(--color-border);
    box-shadow: 0 18px 38px rgba(15, 23, 42, 0.08);
}

.search-header {
    position: relative;
    display: flex;
    align-items: center;
    gap: 16px;
    margin-bottom: 20px;
}

.search-input {
    flex: 1;
    padding: 14px 18px 14px 48px;
    border-radius: 14px;
    border: 1px solid var(--color-border);
    background: #f1f5f9;
    font-size: 1rem;
    transition: all 0.18s ease;
}

.search-input:focus {
    background: var(--color-surface);
    border-color: var(--color-accent);
    box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
    outline: none;
}

.filter-buttons {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
}

.filter-btn {
    padding: 10px 18px;
    border-radius: 999px;
    border: 1px solid var(--color-border);
    background: var(--color-surface);
    color: var(--color-muted);
    font-weight: 600;
    cursor: pointer;
    transition: all 0.18s ease;
}

.filter-btn:hover {
    border-color: var(--color-accent);
    color: var(--color-accent);
    background: var(--color-accent-soft);
}

.filter-btn.active {
    border-color: var(--color-accent);
    background: linear-gradient(135deg, #6366f1 0%, #7c3aed 100%);
    color: #ffffff;
    box-shadow: 0 8px 20px rgba(99, 102, 241, 0.2);
}

.course-selector {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 18px;
    margin-bottom: 32px;
}

.course-selector-card {
    border: 1px solid var(--color-border);
    border-radius: 18px;
    background: #ffffff;
    padding: 18px 20px;
    text-align: left;
    cursor: pointer;
    transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
    font-weight: 600;
    color: var(--color-heading);
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.course-selector-card:hover,
.course-selector-card.active {
    transform: translateY(-3px);
    border-color: var(--color-accent);
    box-shadow: 0 12px 26px rgba(99, 102, 241, 0.18);
}

.course-selector-title {
    display: flex;
    align-items: center;
    gap: 10px;
}

.course-selector-title i {
    color: var(--color-accent);
}

.course-selector-meta {
    font-size: 0.85rem;
    color: var(--color-muted);
    margin-top: 6px;
}

.courses-grid {
    display: none;
    gap: 28px;
    grid-template-columns: repeat(auto-fit, minmax(420px, 1fr));
}

.courses-grid.active {
    display: grid;
}

.course-placeholder {
    padding: 80px 32px;
    text-align: center;
    border: 2px dashed var(--color-border);
    border-radius: 20px;
    color: var(--color-muted);
    background: #f8fafc;
}

.course-card {
    background: var(--color-surface);
    border-radius: 20px;
    border: 1px solid var(--color-border);
    box-shadow: 0 18px 48px rgba(15, 23, 42, 0.1);
    overflow: hidden;
    transition: transform 0.18s ease, box-shadow 0.18s ease;
    min-height: 100%;
}

.course-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 24px 56px rgba(15, 23, 42, 0.14);
}

.course-header {
    background-size: cover;
    background-position: center;
    padding: 26px 28px 24px;
    position: relative;
    min-height: 200px;
    display: grid;
    grid-template-rows: auto 1fr auto;
    gap: 18px;
    border-radius: 20px 20px 0 0;
    overflow: hidden;
}

.course-header::before {
    content: "";
    position: absolute;
    inset: 0;
    background: linear-gradient(135deg, rgb(255, 255, 255), rgb(244, 247, 248));
    mix-blend-mode: multiply;
}

.course-header-top {
    display: flex;
    align-items: center;
    gap: 14px;
    position: relative;
    z-index: 1;
}

.course-icon-chip {
    width: 48px;
    height: 48px;
    border-radius: 14px;
    background: rgba(99, 102, 241, 0.18);
    color: var(--color-accent);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.6rem;
}

.course-category-tag {
    position: absolute;
    top: 18px;
    right: 24px;
    padding: 6px 12px;
    border-radius: 999px;
    background: rgba(99, 102, 241, 0.1);
    color: var(--color-accent);
    font-size: 0.75rem;
    font-weight: 600;
}

.course-title {
    margin: 0;
    font-size: 1.45rem;
    font-weight: 700;
    color: #ffffff;
}

.course-code {
    margin: 6px 0 18px;
    font-size: 0.9rem;
    font-weight: 600;
    color: rgba(255, 255, 255, 0.85);
    padding: 6px 10px;
    background: rgba(255, 255, 255, 0.18);
    border-radius: 8px;
    display: inline-block;
    position: relative;
    z-index: 1;
}

.course-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 12px;
    position: relative;
    z-index: 1;
}

.course-stat {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 14px;
    border-radius: 12px;
    background: rgba(255, 255, 255, 0.18);
    border: 1px solid rgba(255, 255, 255, 0.22);
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.25);
    font-weight: 600;
    font-size: 0.85rem;
    color: rgba(255, 255, 255, 0.92);
}

.course-stat i {
    color: var(--color-accent);
    margin-right: 8px;
}

.course-content {
    padding: 28px 28px 32px;
    background: #ffffff;
}

.course-search {
    display: flex;
    flex-direction: column;
    gap: 16px;
    margin-bottom: 28px;
    background: #f8fafc;
    border: 1px solid rgba(226, 232, 240, 0.8);
    border-radius: 14px;
    padding: 16px 20px;
}

.course-search-controls {
    display: flex;
    flex-wrap: wrap;
    gap: 16px;
    align-items: center;
    justify-content: space-between;
}

.course-search-field {
    position: relative;
    flex: 1 1 260px;
}

.course-search-field i {
    position: absolute;
    left: 14px;
    top: 50%;
    transform: translateY(-50%);
    color: #94a3b8;
    font-size: 0.95rem;
}

.course-search-input {
    width: 100%;
    padding: 12px 16px 12px 40px;
    border-radius: 10px;
    border: 1px solid var(--color-border);
    background: #ffffff;
    font-size: 0.95rem;
    transition: all 0.18s ease;
}

.course-search-input:focus {
    outline: none;
    border-color: var(--color-accent);
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.15);
}

.course-search-filters {
    display: inline-flex;
    flex-wrap: wrap;
    gap: 8px;
}

.course-search-filter {
    padding: 10px 16px;
    border-radius: 999px;
    border: 1px solid var(--color-border);
    background: #ffffff;
    color: var(--color-muted);
    font-weight: 600;
    font-size: 0.85rem;
    cursor: pointer;
    transition: all 0.18s ease;
}

.course-search-filter i {
    margin-right: 6px;
    font-size: 0.9rem;
}

.course-search-filter:hover {
    border-color: var(--color-accent);
    color: var(--color-accent);
    background: var(--color-accent-soft);
}

.course-search-filter.active {
    border-color: var(--color-accent);
    background: linear-gradient(135deg, #6366f1 0%, #7c3aed 100%);
    color: #ffffff;
    box-shadow: 0 8px 20px rgba(99, 102, 241, 0.18);
}

.course-search-hint {
    font-size: 0.85rem;
    color: var(--color-muted);
    display: flex;
    align-items: center;
    gap: 6px;
}

.course-search-empty {
    display: none;
    align-items: center;
    justify-content: center;
    gap: 10px;
    padding: 16px;
    border-radius: 12px;
    border: 1px dashed rgba(148, 163, 184, 0.6);
    background: #f8fafc;
    color: var(--color-muted);
    font-weight: 500;
}

.section-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    border-bottom: 1px solid var(--color-border);
    padding-bottom: 16px;
    margin-bottom: 20px;
}

.section-title {
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 1.15rem;
    font-weight: 700;
    color: var(--color-heading);
}

.section-icon {
    width: 40px;
    height: 40px;
    border-radius: 12px;
    background: #eef2ff;
    color: var(--color-accent);
    display: flex;
    align-items: center;
    justify-content: center;
}

.students-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
    gap: 18px;
    max-height: 380px;
    overflow-y: auto;
    padding-right: 6px;
}

.students-grid::-webkit-scrollbar {
    width: 6px;
}

.students-grid::-webkit-scrollbar-thumb {
    background: linear-gradient(180deg, #e2e8f0 0%, #cbd5f5 100%);
    border-radius: 999px;
}

.student-card {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: 14px;
    padding: 14px 16px;
    box-shadow: 0 10px 26px rgba(15, 23, 42, 0.08);
    transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
}

.student-card:hover {
    transform: translateY(-2px);
    border-color: rgba(99, 102, 241, 0.25);
    box-shadow: 0 14px 32px rgba(99, 102, 241, 0.14);
}

.student-info {
    display: flex;
    align-items: center;
    gap: 14px;
    min-width: 0;
}

.student-avatar {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    background: linear-gradient(135deg, #a5b4fc 0%, #f0abfc 100%);
    color: #fff;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
}

.student-details {
    min-width: 0;
}

.student-name {
    font-size: 1rem;
    font-weight: 600;
    color: var(--color-heading);
    margin-bottom: 2px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.student-email {
    font-size: 0.85rem;
    color: var(--color-muted);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.action-buttons {
    display: flex;
    gap: 8px;
}

.btn {
    border: none;
    border-radius: 10px;
    padding: 10px 18px;
    font-weight: 600;
    font-size: 0.85rem;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    transition: transform 0.15s ease, box-shadow 0.15s ease;
}

.btn:hover {
    transform: translateY(-1px);
}

.btn-enroll {
    background: linear-gradient(135deg, #34d399 0%, #10b981 100%);
    color: #ffffff;
    box-shadow: 0 10px 22px rgba(16, 185, 129, 0.2);
}

.btn-enroll:hover {
    box-shadow: 0 14px 28px rgba(16, 185, 129, 0.28);
}

.btn-unenroll {
    background: linear-gradient(135deg, #fca5a5 0%, #f87171 100%);
    color: #7f1d1d;
    box-shadow: 0 10px 22px rgba(248, 113, 113, 0.2);
}

.btn-unenroll:hover {
    box-shadow: 0 14px 28px rgba(248, 113, 113, 0.28);
}

.message {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 18px 20px;
    border-radius: 16px;
    border: 1px solid var(--color-border);
    box-shadow: 0 12px 30px rgba(15, 23, 42, 0.1);
    margin-bottom: 24px;
    font-weight: 600;
}

.success-message {
    background: var(--color-success-soft);
    color: #047857;
    border-color: rgba(16, 185, 129, 0.4);
}

.error-message {
    background: var(--color-danger-soft);
    color: #b91c1c;
    border-color: rgba(239, 68, 68, 0.4);
}

.more-students-banner {
    margin-top: 20px;
    padding: 18px;
    background: linear-gradient(135deg, #f8fafc 0%, #e0f2fe 100%);
    border-radius: 16px;
    border: 1px solid rgba(226, 232, 240, 0.9);
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
    color: var(--color-muted);
    font-weight: 500;
}

.btn-show-more {
    padding: 8px 16px;
    border-radius: 999px;
    border: none;
    background: linear-gradient(135deg, #a5b4fc 0%, #c4b5fd 100%);
    color: #312e81;
    font-weight: 600;
    cursor: pointer;
    transition: transform 0.15s ease, box-shadow 0.15s ease;
}

.btn-show-more:hover {
    transform: translateY(-1px);
    box-shadow: 0 12px 26px rgba(99, 102, 241, 0.18);
}

.no-courses {
    text-align: center;
    padding: 72px 32px;
    background: var(--color-surface);
    border-radius: 22px;
    border: 1px solid var(--color-border);
    box-shadow: 0 18px 48px rgba(15, 23, 42, 0.12);
    color: var(--color-muted);
}

.no-courses-icon {
    font-size: 3.6rem;
    color: var(--color-accent);
    opacity: 0.35;
    margin-bottom: 16px;
}

.loading-spinner {
    width: 18px;
    height: 18px;
    border-radius: 50%;
    border: 3px solid rgba(255, 255, 255, 0.4);
    border-top-color: #ffffff;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

.course-card,
.student-card,
.message {
    animation: fadeIn 0.3s ease;
}

@media (max-width: 1024px) {
    .enrollment-dashboard {
        padding: 28px 20px 36px;
    }
}

@media (max-width: 768px) {
    .stats-row {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 16px;
    }

    .course-search {
        padding: 14px 16px;
    }

    .course-search-controls {
        flex-direction: column;
        align-items: stretch;
    }

    .course-search-filters {
        justify-content: flex-start;
    }

    .courses-grid {
        grid-template-columns: 1fr;
    }

    .course-content {
        padding: 28px 24px;
    }

    .search-section {
        padding: 20px;
    }

    .students-grid {
        grid-template-columns: 1fr;
        max-height: none;
    }

    .student-card {
        flex-direction: column;
        align-items: flex-start;
    }

    .action-buttons {
        width: 100%;
        justify-content: flex-end;
    }
}

@media (max-width: 520px) {
    .stats-row {
        grid-template-columns: 1fr;
    }

    .horizontal-stats-container {
        padding: 24px;
    }

    .course-content {
        padding: 20px 16px;
    }
}
</style>';

// Start teacher dashboard wrapper with sidebar
echo '<div class="teacher-dashboard-wrapper">';

// Include reusable sidebar
include(__DIR__ . '/includes/sidebar.php');

// Main Content Area
echo '<div class="teacher-main-content" data-layout="custom">';
echo '<div class="enrollment-dashboard">';

// Success/Error Messages
if (isset($_SESSION['enrollment_success'])) {
    echo '<div class="success-message">';
    echo '<i class="fas fa-check-circle"></i> ' . $_SESSION['enrollment_success'];
    echo '</div>';
    unset($_SESSION['enrollment_success']);
}

if (isset($_SESSION['enrollment_error'])) {
    echo '<div class="error-message">';
    echo '<i class="fas fa-exclamation-triangle"></i> ' . $_SESSION['enrollment_error'];
    echo '</div>';
    unset($_SESSION['enrollment_error']);
}

// Also check for direct messages (for debugging)
if (isset($success_message)) {
    echo '<div class="success-message">';
    echo '<i class="fas fa-check-circle"></i> ' . $success_message;
    echo '</div>';
}

if (isset($error_message)) {
    echo '<div class="error-message">';
    echo '<i class="fas fa-exclamation-triangle"></i> ' . $error_message;
    echo '</div>';
}

// Debug information (remove in production)
if (debugging()) {
    echo '<div style="background: #f0f0f0; padding: 10px; margin: 10px 0; border-radius: 5px; font-family: monospace; font-size: 12px;">';
    echo '<strong>Debug Info:</strong><br>';
    echo 'Total Courses: ' . count($teacher_courses) . '<br>';
    echo 'Total Students: ' . count($all_students) . '<br>';
    echo 'Current User ID: ' . $USER->id . '<br>';
    echo 'Is Teacher: ' . ($isteacher ? 'Yes' : 'No') . '<br>';
    echo 'Request Method: ' . $_SERVER['REQUEST_METHOD'] . '<br>';
    echo 'POST Data: ' . print_r($_POST, true) . '<br>';
    echo '</div>';
    
    // Test form
    echo '<div style="background: #fff3cd; padding: 10px; margin: 10px 0; border-radius: 5px; border: 1px solid #ffeaa7;">';
    echo '<strong>Test Enrollment Form:</strong><br>';
    echo '<form method="post" style="margin: 10px 0;" onsubmit="console.log(\'Test form submitted\')">';
    echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';
    echo '<input type="hidden" name="action" value="enroll">';
    echo '<input type="hidden" name="course_id" value="1">';
    echo '<input type="hidden" name="user_id" value="2">';
    echo '<button type="submit" style="background: #28a745; color: white; padding: 5px 10px; border: none; border-radius: 3px;">Test Enroll User 2 in Course 1</button>';
    echo '</form>';
    echo '<p style="font-size: 12px; color: #666;">Check browser console and server logs for debugging info.</p>';
    echo '</div>';
}

// Calculate overall statistics with error handling
$total_courses = count($teacher_courses);
$total_students = count($all_students);
$total_enrollments = 0;
$active_courses = 0;

try {
    foreach ($teacher_courses as $course) {
        try {
            // Use Moodle's standard function to count enrolled users
            $context = context_course::instance($course->id);
            $enrolled_count = count_enrolled_users($context);
            $total_enrollments += $enrolled_count;
            if ($enrolled_count > 0) {
                $active_courses++;
            }
        } catch (Exception $e) {
            error_log('Error counting enrollments for course ' . $course->id . ': ' . $e->getMessage());
            // Continue with next course
            continue;
        }
    }
} catch (Exception $e) {
    error_log('Error calculating statistics: ' . $e->getMessage());
}

// Horizontal Statistics Container
echo '<div class="horizontal-stats-container">';
echo '<div class="stats-row">';
echo '<div class="stat-item">';
echo '<div class="stat-number">' . $total_courses . '</div>';
echo '<div class="stat-label">Total Courses</div>';
echo '</div>';
echo '<div class="stat-item">';
echo '<div class="stat-number">' . $total_students . '</div>';
echo '<div class="stat-label">Available Students</div>';
echo '</div>';
echo '<div class="stat-item">';
echo '<div class="stat-number">' . $total_enrollments . '</div>';
echo '<div class="stat-label">Total Enrollments</div>';
echo '</div>';
echo '<div class="stat-item">';
echo '<div class="stat-number">' . $active_courses . '</div>';
echo '<div class="stat-label">Active Courses</div>';
echo '</div>';
echo '</div>'; // stats-row
echo '</div>'; // horizontal-stats-container

// Search and Filter Section
echo '<div class="search-section">';
echo '<div class="search-header" style="position: relative;">';
echo '<i class="fas fa-search" style="position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: #9ca3af; font-size: 1.1rem; z-index: 10;"></i>';
echo '<input type="text" class="search-input" placeholder="Search students by name or email..." id="studentSearch">';
echo '</div>';
echo '<div class="filter-buttons">';
echo '<button class="filter-btn active" data-filter="all"><i class="fas fa-th-large" style="margin-right: 6px;"></i>All Students</button>';
echo '<button class="filter-btn" data-filter="enrolled"><i class="fas fa-user-check" style="margin-right: 6px;"></i>Enrolled</button>';
echo '<button class="filter-btn" data-filter="available"><i class="fas fa-user-plus" style="margin-right: 6px;"></i>Available</button>';
echo '<button class="filter-btn" data-filter="recent"><i class="fas fa-clock" style="margin-right: 6px;"></i>Recently Added</button>';
echo '</div>';
echo '</div>'; // search-section

if (!empty($teacher_courses)) {
    $baseurl = new moodle_url('/theme/remui_kids/teacher/enroll_students.php');
    echo '<div class="course-selector" data-navigation="page">';
    foreach ($teacher_courses as $course) {
        $cardurl = new moodle_url('/theme/remui_kids/teacher/enroll_students.php', ['courseid' => $course->id]);
        $isactive = ($selectedcourseid === (int)$course->id);
        $activeclass = $isactive ? ' active' : '';
        echo '<a href="' . $cardurl->out(false) . '" class="course-selector-card' . $activeclass . '" data-course-id="' . $course->id . '">';
        echo '<div class="course-selector-title">';
        echo '<span class="course-icon-chip"><i class="fas fa-graduation-cap"></i></span>';
        echo '<span>' . htmlspecialchars($course->fullname) . '</span>';
        echo '</div>';
        echo '<div class="course-selector-meta">' . htmlspecialchars($course->shortname) . '</div>';
        echo '<div class="course-selector-meta">' . count_enrolled_users(context_course::instance($course->id)) . ' students</div>';
        echo '</a>';
    }
    echo '</div>';

    $gridclasses = 'courses-grid';
    if ($selected_course) {
        $gridclasses .= ' active';
    }
    echo '<div class="' . $gridclasses . '" id="selectedCourseContainer" data-selected="' . ($selectedcourseid ?: 0) . '">';
    if (!$selected_course) {
        echo '<div class="course-placeholder">Select a course to manage enrollments.</div>';
    }
 
    $courseimageindex = 1;
    foreach ($teacher_courses as $course) {
        if (!$selectedcourseid || $course->id != $selectedcourseid) {
            continue;
        }
        $isactivecourse = ($selectedcourseid === (int)$course->id);
        $coverimage = theme_remui_kids_get_section_image($courseimageindex);
        $courseimageindex++;
        if ($courseimageindex > 8) {
            $courseimageindex = 1;
        }
        // Get enrolled students using Moodle's participant system
        $context = context_course::instance($course->id);
        $enrolled_users = get_enrolled_users($context, '', 0, 'u.*', 'u.firstname, u.lastname');
        $enrolled_students = [];
        
        foreach ($enrolled_users as $user) {
            if ($user->deleted == 0 && $user->suspended == 0) {
                $enrolled_students[$user->id] = $user;
            }
        }
        
        // Get course completion stats with error handling
        try {
            $completion_stats = $DB->get_record_sql(
                "SELECT 
                    COUNT(DISTINCT u.id) as total_students,
                    SUM(CASE WHEN cc.timecompleted IS NOT NULL THEN 1 ELSE 0 END) as completed_students
                 FROM {user} u
                 JOIN {user_enrolments} ue ON u.id = ue.userid
                 JOIN {enrol} e ON ue.enrolid = e.id
                 LEFT JOIN {course_completions} cc ON u.id = cc.userid AND cc.course = ?
                 WHERE e.courseid = ? AND u.deleted = 0 AND u.suspended = 0",
                [$course->id, $course->id]
            );
            
            if (!$completion_stats || !isset($completion_stats->total_students)) {
                $completion_stats = new stdClass();
                $completion_stats->total_students = count($enrolled_students);
                $completion_stats->completed_students = 0;
            }
            
            $completion_percentage = $completion_stats->total_students > 0 
                ? round(($completion_stats->completed_students / $completion_stats->total_students) * 100) 
                : 0;
        } catch (Exception $e) {
            // Fallback if completion tracking is not available
            $completion_stats = new stdClass();
            $completion_stats->total_students = count($enrolled_students);
            $completion_stats->completed_students = 0;
            $completion_percentage = 0;
            error_log('Enrollment page completion stats error: ' . $e->getMessage());
        }
        
        $carddisplay = $isactivecourse ? 'block' : 'none';
        $activecardclass = $isactivecourse ? ' active-card' : '';
        echo '<div class="course-card' . $activecardclass . '" data-course-id="' . $course->id . '" style="display:' . $carddisplay . ';">';
        echo '<div class="course-header" style="background-image: url(' . "'" . $coverimage . "'" . ');">';
        echo '<div class="course-category-tag">Grade 1</div>';
        echo '<div class="course-header-top">';
        echo '<span class="course-icon-chip"><i class="fas fa-graduation-cap"></i></span>';
        echo '<div>';
        echo '<h2 class="course-title">' . htmlspecialchars($course->fullname) . '</h2>';
        echo '<p class="course-code">' . htmlspecialchars($course->shortname) . '</p>';
        echo '</div>';
        echo '</div>';
        echo '<div class="course-stats">';
        echo '<div class="course-stat"><span><i class="fas fa-users"></i> Enrolled</span><span>' . count($enrolled_students) . '</span></div>';
        echo '<div class="course-stat"><span><i class="fas fa-chart-line"></i> Complete</span><span>' . $completion_percentage . '%</span></div>';
        echo '</div>';
        echo '</div>';
        
        echo '<div class="course-content">';
        echo '<div class="course-search" data-course-id="' . $course->id . '">';
        echo '<div class="course-search-controls">';
        echo '<div class="course-search-field">';
        echo '<i class="fas fa-search"></i>';
        echo '<input type="text" class="course-search-input" data-course-id="' . $course->id . '" placeholder="Search students in this course...">';
        echo '</div>';
        echo '<div class="course-search-filters">';
        echo '<button type="button" class="course-search-filter active" data-filter="all" data-course-id="' . $course->id . '"><i class="fas fa-users"></i>All</button>';
        echo '<button type="button" class="course-search-filter" data-filter="enrolled" data-course-id="' . $course->id . '"><i class="fas fa-user-check"></i>Enrolled</button>';
        echo '<button type="button" class="course-search-filter" data-filter="available" data-course-id="' . $course->id . '"><i class="fas fa-user-plus"></i>Available</button>';
        echo '</div>';
        echo '</div>';
        echo '<div class="course-search-hint">';
        echo '<i class="fas fa-info-circle"></i>Type a name or email, then use the filter buttons to focus on enrolled or available students.';
        echo '</div>';
        echo '</div>';
        echo '<div class="course-search-empty" data-course-id="' . $course->id . '">';
        echo '<i class="fas fa-face-frown"></i>No students match your search.';
        echo '</div>';
        
        // Enrolled Students Section
        echo '<div class="enrolled-students">';
        echo '<div class="section-header">';
        echo '<div class="section-title">';
        echo '<div class="section-icon"><i class="fas fa-users"></i></div>';
        echo 'Enrolled Students (' . count($enrolled_students) . ')';
        echo '</div>';
        echo '<div class="section-actions">';
        echo '<a href="' . $CFG->wwwroot . '/user/index.php?id=' . $course->id . '" class="btn btn-sm" style="background: #667eea; color: white; padding: 6px 12px; border-radius: 6px; text-decoration: none; font-size: 0.8rem;">';
        echo '<i class="fas fa-cog"></i> Manage Participants';
        echo '</a>';
        echo '</div>';
        echo '</div>';
        
        if (!empty($enrolled_students)) {
            echo '<div class="students-grid">';
            foreach ($enrolled_students as $student) {
                echo '<div class="student-card enrolled-student" data-student-id="' . $student->id . '">';
                echo '<div class="student-info">';
                echo '<div class="student-avatar">' . strtoupper(substr($student->firstname, 0, 1)) . '</div>';
                echo '<div class="student-details">';
                echo '<div class="student-name">' . fullname($student) . '</div>';
                echo '<div class="student-email">' . htmlspecialchars($student->email) . '</div>';
                echo '</div>';
                echo '</div>';
                echo '<div class="action-buttons">';
                echo '<form method="post" style="display: inline;" onsubmit="return confirmEnrollment(this)">';
                echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';
                echo '<input type="hidden" name="action" value="unenroll">';
                echo '<input type="hidden" name="course_id" value="' . $course->id . '">';
                echo '<input type="hidden" name="user_id" value="' . $student->id . '">';
                echo '<button type="submit" class="btn btn-unenroll" data-student-name="' . htmlspecialchars(fullname($student)) . '" data-course-name="' . htmlspecialchars($course->fullname) . '" onclick="console.log(\'Unenroll button clicked for user: ' . $student->id . ', course: ' . $course->id . '\')">';
                echo '<i class="fas fa-user-minus"></i> Unenroll';
                echo '</button>';
                echo '</form>';
                echo '</div>';
                echo '</div>';
            }
            echo '</div>';
        } else {
            echo '<div style="text-align: center; padding: 40px; color: #64748b; font-style: italic;">';
            echo '<i class="fas fa-users" style="font-size: 2rem; margin-bottom: 12px; opacity: 0.5;"></i><br>';
            echo 'No students enrolled in this course yet.';
            echo '</div>';
        }
        echo '</div>';
        
        // Available Students Section
        echo '<div class="available-students">';
        echo '<div class="section-header">';
        echo '<div class="section-title">';
        echo '<div class="section-icon"><i class="fas fa-user-plus"></i></div>';
        echo 'Available Students';
        echo '</div>';
        echo '<div class="section-actions">';
        echo '<a href="' . $CFG->wwwroot . '/enrol/users.php?id=' . $course->id . '" class="btn btn-sm" style="background: #10b981; color: white; padding: 6px 12px; border-radius: 6px; text-decoration: none; font-size: 0.8rem;">';
        echo '<i class="fas fa-user-plus"></i> Enroll Users';
        echo '</a>';
        echo '</div>';
        echo '</div>';
        
        // Get students not enrolled in this course
        $enrolled_user_ids = array_keys($enrolled_students);
        $available_students = array_filter($all_students, function($student) use ($enrolled_user_ids) {
            return !in_array($student->id, $enrolled_user_ids);
        });
        
        if (!empty($available_students)) {
            $students_to_show = 8; // Show fewer initially for better layout
            $total_available = count($available_students);
            $showing_count = min($students_to_show, $total_available);
            $show_all = isset($_GET['show_all_' . $course->id]) && $_GET['show_all_' . $course->id] == '1';
            
            echo '<div class="students-grid" id="available-students-' . $course->id . '">';
            $students_to_display = $show_all ? $available_students : array_slice($available_students, 0, $students_to_show);
            foreach ($students_to_display as $student) {
                echo '<div class="student-card available-student" data-student-id="' . $student->id . '">';
                echo '<div class="student-info">';
                echo '<div class="student-avatar">' . strtoupper(substr($student->firstname, 0, 1)) . '</div>';
                echo '<div class="student-details">';
                echo '<div class="student-name">' . fullname($student) . '</div>';
                echo '<div class="student-email">' . htmlspecialchars($student->email) . '</div>';
                echo '</div>';
                echo '</div>';
                echo '<div class="action-buttons">';
                echo '<form method="post" style="display: inline;" onsubmit="return confirmEnrollment(this)">';
                echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';
                echo '<input type="hidden" name="action" value="enroll">';
                echo '<input type="hidden" name="course_id" value="' . $course->id . '">';
                echo '<input type="hidden" name="user_id" value="' . $student->id . '">';
                echo '<button type="submit" class="btn btn-enroll" data-student-name="' . htmlspecialchars(fullname($student)) . '" data-course-name="' . htmlspecialchars($course->fullname) . '" onclick="console.log(\'Enroll button clicked for user: ' . $student->id . ', course: ' . $course->id . '\')">';
                echo '<i class="fas fa-user-plus"></i> Enroll';
                echo '</button>';
                echo '</form>';
                echo '</div>';
                echo '</div>';
            }
            echo '</div>';
            
            // Show banner with dynamic count and proper state
            if ($show_all) {
                echo '<div class="more-students-banner">';
                echo '<div class="more-students-content">';
                echo '<i class="fas fa-check-circle more-students-icon" style="color: #10b981;"></i>';
                echo '<span class="more-students-text">Showing all ' . $total_available . ' available students for this course.</span>';
                echo '<a href="' . $PAGE->url . '" class="btn-show-more" style="margin-left: 12px; padding: 6px 12px; background: #6b7280; color: white; border: none; border-radius: 8px; font-size: 0.8rem; cursor: pointer; text-decoration: none; display: inline-block;">Show Less</a>';
                echo '</div>';
                echo '</div>';
            } else if ($total_available > $students_to_show) {
                $remaining_count = $total_available - $students_to_show;
                echo '<div class="more-students-banner">';
                echo '<div class="more-students-content">';
                echo '<i class="fas fa-info-circle more-students-icon"></i>';
                echo '<span class="more-students-text">Showing ' . $showing_count . ' of ' . $total_available . ' available students. ' . $remaining_count . ' more students available for enrollment.</span>';
                echo '<a href="' . $PAGE->url . '?show_all_' . $course->id . '=1" class="btn-show-more" style="margin-left: 12px; padding: 6px 12px; background: linear-gradient(135deg, #d1fae5 0%, #fed7aa 100%); color: #000000; border: none; border-radius: 8px; font-size: 0.8rem; cursor: pointer; text-decoration: none; display: inline-block;">Show All</a>';
                echo '</div>';
                echo '</div>';
            } else {
                echo '<div class="more-students-banner">';
                echo '<div class="more-students-content">';
                echo '<i class="fas fa-check-circle more-students-icon" style="color: #10b981;"></i>';
                echo '<span class="more-students-text">Showing all ' . $total_available . ' available students for this course.</span>';
                echo '</div>';
                echo '</div>';
            }
        } else {
            echo '<div style="text-align: center; padding: 40px; color: #64748b; font-style: italic;">';
            echo '<i class="fas fa-check-circle" style="font-size: 2rem; margin-bottom: 12px; color: #10b981;"></i><br>';
            echo 'All students are already enrolled in this course.';
            echo '</div>';
        }
        echo '</div>';
        
        echo '</div>'; // course-content
        echo '</div>'; // course-card
    }
    
    echo '</div>'; // courses-grid
} else {
    echo '<div class="no-courses">';
    echo '<div class="no-courses-icon"><i class="fas fa-graduation-cap"></i></div>';
    echo '<h3>No Courses Found</h3>';
    echo '<p>You are not assigned as a teacher to any courses. Contact your administrator to get course access.</p>';
    echo '</div>';
}

echo '</div>'; // enrollment-dashboard
echo '</div>'; // teacher-main-content
echo '</div>'; // teacher-dashboard-wrapper

// Advanced JavaScript for Enrollment Dashboard
echo <<<'JS'
<script>
// Global confirmation function
function confirmEnrollment(form) {
    console.log("confirmEnrollment called");
    const button = form.querySelector("button[type=submit]");
    const studentName = button.getAttribute("data-student-name");
    const courseName = button.getAttribute("data-course-name");
    const action = form.querySelector("input[name=action]").value;
    
    console.log("Action:", action, "Student:", studentName, "Course:", courseName);
    
    // For now, just return true to allow submission
    return true;
    
    // Uncomment below for confirmation dialog
    /*
    if (action === "enroll") {
        return confirm(`Are you sure you want to enroll ${studentName} in ${courseName}?`);
    } else if (action === "unenroll") {
        return confirm(`Are you sure you want to unenroll ${studentName} from ${courseName}?`);
    }
    return true;
    */
}

document.addEventListener("DOMContentLoaded", function() {
    console.log("Enrollment Dashboard initialized");
    
    let globalFilterMode = "all";
    const courseCards = document.querySelectorAll(".course-card");
    
    function toggleTeacherSidebar() {
        const sidebar = document.querySelector(".teacher-sidebar");
        sidebar.classList.toggle("sidebar-open");
    }
    
    window.toggleTeacherSidebar = toggleTeacherSidebar;
    
    function updateCourseVisibility() {
        courseCards.forEach(courseCard => {
            const visibleStudents = courseCard.querySelectorAll(".student-card[style*='flex'], .student-card:not([style*='none'])");
            const enrolledSection = courseCard.querySelector(".enrolled-students");
            const availableSection = courseCard.querySelector(".available-students");
            
            if (visibleStudents.length === 0) {
                if (enrolledSection) {
                    const enrolledStudents = enrolledSection.querySelectorAll(".student-card");
                    const hasVisibleEnrolled = Array.from(enrolledStudents).some(card =>
                        card.style.display !== "none" && card.style.display !== ""
                    );
                    enrolledSection.style.display = hasVisibleEnrolled ? "block" : "none";
                }
                
                if (availableSection) {
                    const availableStudents = availableSection.querySelectorAll(".student-card");
                    const hasVisibleAvailable = Array.from(availableStudents).some(card =>
                        card.style.display !== "none" && card.style.display !== ""
                    );
                    availableSection.style.display = hasVisibleAvailable ? "block" : "none";
                }
            } else {
                if (enrolledSection) enrolledSection.style.display = "block";
                if (availableSection) availableSection.style.display = "block";
            }
        });
    }
    
    function applyCourseFilters(courseId, updateVisibility = true) {
        const courseCard = document.querySelector(`.course-card[data-course-id="${courseId}"]`);
        if (!courseCard) {
            return;
        }
        
        const searchInput = courseCard.querySelector(".course-search-input");
        const searchTerm = searchInput ? searchInput.value.trim().toLowerCase() : "";
        const activeFilterBtn = courseCard.querySelector(".course-search-filter.active");
        const localFilter = activeFilterBtn ? activeFilterBtn.dataset.filter : "all";
        
        let visibleCount = 0;
        
        courseCard.querySelectorAll(".student-card").forEach(card => {
            const name = card.querySelector(".student-name")?.textContent.toLowerCase() || "";
            const email = card.querySelector(".student-email")?.textContent.toLowerCase() || "";
            const matchesSearch = !searchTerm || name.includes(searchTerm) || email.includes(searchTerm);
            
            const matchesLocalFilter =
                localFilter === "all" ||
                (localFilter === "enrolled" && card.classList.contains("enrolled-student")) ||
                (localFilter === "available" && card.classList.contains("available-student"));
            
            const matchesGlobalFilter =
                globalFilterMode === "all" ||
                (globalFilterMode === "enrolled" && card.classList.contains("enrolled-student")) ||
                (globalFilterMode === "available" && card.classList.contains("available-student")) ||
                globalFilterMode === "recent";
            
            const globalSearchHidden = card.dataset.globalHidden === "true";
            
            if (!globalSearchHidden && matchesSearch && matchesLocalFilter && matchesGlobalFilter) {
                card.style.display = "flex";
                visibleCount++;
            } else {
                card.style.display = "none";
            }
        });
        
        const emptyState = courseCard.querySelector(".course-search-empty");
        if (emptyState) {
            emptyState.style.display = visibleCount === 0 ? "flex" : "none";
        }
        
        if (updateVisibility) {
            updateCourseVisibility();
        }
    }
    
    function applyAllCourseFilters() {
        courseCards.forEach(card => applyCourseFilters(card.dataset.courseId, false));
        updateCourseVisibility();
    }
    
    const searchInput = document.getElementById("studentSearch");
    if (searchInput) {
        searchInput.addEventListener("input", function() {
            const searchTerm = this.value.toLowerCase();
            const studentCards = document.querySelectorAll(".student-card");
            
            studentCards.forEach(card => {
                const studentName = card.querySelector(".student-name").textContent.toLowerCase();
                const studentEmail = card.querySelector(".student-email").textContent.toLowerCase();
                const matches = searchTerm === "" || studentName.includes(searchTerm) || studentEmail.includes(searchTerm);
                card.dataset.globalHidden = matches ? "false" : "true";
            });
            
            applyAllCourseFilters();
        });
    }
    
    const filterButtons = document.querySelectorAll(".filter-btn");
    filterButtons.forEach(btn => {
        btn.addEventListener("click", function() {
            filterButtons.forEach(b => b.classList.remove("active"));
            this.classList.add("active");
            globalFilterMode = this.dataset.filter || "all";
            applyAllCourseFilters();
        });
    });
    
    document.querySelectorAll(".course-search-input").forEach(input => {
        input.addEventListener("input", function() {
            applyCourseFilters(this.dataset.courseId);
        });
    });
    
    document.querySelectorAll(".course-search-filter").forEach(button => {
        button.addEventListener("click", function() {
            const courseId = this.dataset.courseId;
            const courseCard = document.querySelector(`.course-card[data-course-id="${courseId}"]`);
            if (!courseCard) {
                return;
            }
            courseCard.querySelectorAll(".course-search-filter").forEach(btn => btn.classList.remove("active"));
            this.classList.add("active");
            applyCourseFilters(courseId);
        });
    });
    
    const actionButtons = document.querySelectorAll(".btn");
    actionButtons.forEach(btn => {
        btn.addEventListener("click", function() {
            const originalText = this.innerHTML;
            this.innerHTML = '<div class="loading-spinner"></div> Processing...';
            this.disabled = true;
            setTimeout(() => {
                this.innerHTML = originalText;
                this.disabled = false;
            }, 3000);
        });
    });
    
    courseCards.forEach(card => {
        card.addEventListener("mouseenter", function() {
            this.style.transform = "translateY(-8px)";
        });
        card.addEventListener("mouseleave", function() {
            this.style.transform = "translateY(0)";
        });
    });
    
    const studentCards = document.querySelectorAll(".student-card");
    studentCards.forEach(card => {
        card.addEventListener("mouseenter", function() {
            this.style.transform = "translateY(-2px)";
            this.style.boxShadow = "0 8px 25px rgba(102, 126, 234, 0.15)";
        });
        card.addEventListener("mouseleave", function() {
            this.style.transform = "translateY(0)";
            this.style.boxShadow = "none";
        });
    });
    
    const statNumbers = document.querySelectorAll(".stat-number");
    statNumbers.forEach(stat => {
        const finalNumber = parseInt(stat.textContent, 10);
        let currentNumber = 0;
        const increment = finalNumber / 50 || 0;
        
        const timer = setInterval(() => {
            currentNumber += increment;
            if (currentNumber >= finalNumber) {
                stat.textContent = finalNumber;
                clearInterval(timer);
            } else {
                stat.textContent = Math.floor(currentNumber);
            }
        }, 30);
    });
    
    let refreshInterval;
    function startAutoRefresh() {
        refreshInterval = setInterval(() => {
            console.log("Auto-refresh check");
        }, 30000);
    }
    
    startAutoRefresh();
    
    window.addEventListener("beforeunload", function() {
        if (refreshInterval) {
            clearInterval(refreshInterval);
        }
    });
    
    function handleResize() {
        const sidebar = document.querySelector(".teacher-sidebar");
        if (!sidebar) {
            return;
        }
        if (window.innerWidth > 768) {
            sidebar.classList.remove("sidebar-open");
        }
    }
    
    window.addEventListener("resize", handleResize);
    
    document.addEventListener("click", function(event) {
        const sidebar = document.querySelector(".teacher-sidebar");
        const toggleButton = document.querySelector(".sidebar-toggle");
        
        if (window.innerWidth <= 768 &&
            sidebar && toggleButton &&
            !sidebar.contains(event.target) &&
            !toggleButton.contains(event.target) &&
            sidebar.classList.contains("sidebar-open")) {
            sidebar.classList.remove("sidebar-open");
        }
    });
    
    const style = document.createElement("style");
    style.textContent = `
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .student-card {
            animation: fadeIn 0.3s ease;
        }
        
        .course-card {
            animation: fadeIn 0.5s ease;
        }
        
        .stat-card {
            animation: fadeIn 0.6s ease;
        }
    `;
    document.head.appendChild(style);
    
    applyAllCourseFilters();

    const courseSelectorCards = document.querySelectorAll(".course-selector-card");
    const courseGrid = document.getElementById("selectedCourseContainer");
    const placeholder = courseGrid?.querySelector(".course-placeholder");

    function showCourseCard(courseId) {
        courseCards.forEach(card => {
            if (card.dataset.courseId === courseId) {
                card.style.display = "block";
                card.classList.add("active-card");
            } else {
                card.style.display = "none";
                card.classList.remove("active-card");
            }
        });

        if (courseGrid) {
            courseGrid.classList.add("active");
        }
        if (placeholder) {
            placeholder.style.display = "none";
        }
        applyCourseFilters(courseId);
    }

    courseSelectorCards.forEach(button => {
        if (button.tagName === "A") {
            return;
        }
        button.addEventListener("click", function() {
            courseSelectorCards.forEach(btn => btn.classList.remove("active"));
            this.classList.add("active");
            const courseId = this.dataset.courseId;
            showCourseCard(courseId);
        });
    });

    if (courseSelectorCards.length === 1 && courseSelectorCards[0].tagName !== "A") {
        courseSelectorCards[0].classList.add("active");
        showCourseCard(courseSelectorCards[0].dataset.courseId);
    }

    console.log("All enrollment dashboard features initialized successfully");
});

// Global functions for external access
window.enrollmentDashboard = {
    search: function(term) {
        const searchInput = document.getElementById("studentSearch");
        if (searchInput) {
            searchInput.value = term;
            searchInput.dispatchEvent(new Event("input"));
        }
    },
    
    filter: function(filterType) {
        const filterBtn = document.querySelector(`[data-filter="${filterType}"]`);
        if (filterBtn) {
            filterBtn.click();
        }
    },
    
    refresh: function() {
        location.reload();
    }
};

// Enhanced scrollbar functionality
document.addEventListener("DOMContentLoaded", function() {
    // Add smooth scrolling to all scrollable areas
    const scrollableElements = document.querySelectorAll(".students-grid, .questions-list");
    scrollableElements.forEach(element => {
        element.style.scrollBehavior = "smooth";
    });
    
    console.log("Enhanced scrollbar functionality initialized");
});
</script>
JS;

echo $OUTPUT->footer();
?>