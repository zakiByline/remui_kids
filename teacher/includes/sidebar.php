<?php
/**
 * Reusable Teacher Sidebar Include
 * Include this file in all teacher pages
 * 
 * Usage: include(__DIR__ . '/includes/sidebar.php');
 * 
 * @package theme_remui_kids
 */

defined('MOODLE_INTERNAL') || die();

global $CFG, $PAGE, $USER, $DB;
require_once($CFG->dirroot . '/theme/remui_kids/lib/emulator_manager.php');
require_once($CFG->dirroot . '/theme/remui_kids/lib/teacher_school_helper.php');

// Check if Role Switch Access is enabled for this teacher's school
$role_switch_enabled = true; // Default enabled
if (function_exists('theme_remui_kids_get_teacher_company_id')) {
    $teacher_company_id = theme_remui_kids_get_teacher_company_id();
    if ($teacher_company_id) {
        $dbman = $DB->get_manager();
        $settings_table_exists = $dbman->table_exists(new xmldb_table('theme_remui_school_settings'));
        
        if ($settings_table_exists) {
            $school_setting = $DB->get_record('theme_remui_school_settings', ['schoolid' => $teacher_company_id]);
            if ($school_setting && isset($school_setting->role_switch_enabled)) {
                $role_switch_enabled = (bool)$school_setting->role_switch_enabled;
            }
        }
    }
}

// Get current page for highlighting
$current_url = $PAGE->url->out_omit_querystring();
$current_script = basename($_SERVER['SCRIPT_NAME']);

if (!function_exists('theme_remui_kids_sidebar_match')) {
    function theme_remui_kids_sidebar_match(string $script, array $patterns): bool {
        foreach ($patterns as $pattern) {
            if ($pattern === '') {
                continue;
            }
            if (strpos($pattern, '.php') !== false) {
                if ($script === $pattern) {
                    return true;
                }
            } else if (strpos($script, $pattern) !== false) {
                return true;
            }
        }
        return false;
    }
}

// Active page indicators
$is_dashboard = (strpos($current_url, '/my/') !== false);
$is_courses = theme_remui_kids_sidebar_match($current_script, [
    'teacher_courses.php',
    'course_preview.php'
]);
$is_resources = theme_remui_kids_sidebar_match($current_script, [
    'view_course.php',
    'pdf_viewer.php'
]);
$is_schedule = theme_remui_kids_sidebar_match($current_script, [
    'schedule.php',
    'debug_schedule.php',
    'test_schedule_data.php',
    'create_sample_events.php',
    'fix_schedule.php',
    'check_event_match.php'
]);
$is_lessons = theme_remui_kids_sidebar_match($current_script, [
    'lessonplan.php',
]);
$is_students = theme_remui_kids_sidebar_match($current_script, [
    'students.php',
]);
$is_lessonplan = theme_remui_kids_sidebar_match($current_script, [
    'lessonplan.php',
]);
$is_assignments = theme_remui_kids_sidebar_match($current_script, [
    'assignments.php',
    'create_assignment',
    'edit_assignment',
    'update_assignment',
    'save_manual_grade.php',
    'create_codeeditor',
    'grade_assignment.php',
    'create_codeeditor_page.php',
    'create_assignment_page.php'
]);
$is_quizzes = theme_remui_kids_sidebar_match($current_script, [
    'quizzes.php',
    'create_quiz.php',
    'create_quiz_page.php',
    'quiz_attempts.php',
    'quiz_review.php',
    'quiz_attempts_data.php'
]);
$is_competencies = theme_remui_kids_sidebar_match($current_script, [
    'competencies.php',
    'student_competencies.php',
    'student_competency_evidence.php',
    'competency_details.php',
    'save_competency_rating.php'
]);
$is_switchrole = theme_remui_kids_sidebar_match($current_script, [
    'index.php'
]) && strpos($current_url, '/local/teacherviewstudent/') !== false;
$is_rubrics = theme_remui_kids_sidebar_match($current_script, [
    'rubrics.php',
    'rubric_grading.php',
    'rubric_view.php',
    'grade_student.php',
    'grade_codeeditor_student.php'
]);
$is_gradebook = theme_remui_kids_sidebar_match($current_script, [
    'gradebook.php'
]);
$is_doubts = theme_remui_kids_sidebar_match($current_script, [
    'teacher_doubts.php',
    'questions_unified.php',
    'save_question.php',
    'get_question_bank.php',
    'get_question_categories.php'
]);
$is_emulators = theme_remui_kids_sidebar_match($current_script, [
    'emulators.php'
]);

$is_activity_logs = theme_remui_kids_sidebar_match($current_script, [
    'activity_logs.php'
]);

$is_reports = theme_remui_kids_sidebar_match($current_script, [
    'reports.php',
]);

$is_need_help = theme_remui_kids_sidebar_match($current_script, [
    'need_help.php'
]);

$is_ebook = theme_remui_kids_sidebar_match($current_script, [
    'local/ebook/manage.php',
    'local/ebook/edit.php',
]) || (strpos($current_url, '/local/ebook/') !== false);

$is_certificates = theme_remui_kids_sidebar_match($current_script, [
    'admin_dashboard.php',
    'school_dashboard.php',
    'teacher_dashboard.php',
    'student_dashboard.php',
]) || (strpos($current_url, '/local/certificate_approval/') !== false);

$is_ebooks = theme_remui_kids_sidebar_match($current_script, [
    'ebooks.php',
    'student_book.php',
    'teacher_book.php',
    'practice_book.php'
]) || (strpos($current_url, '/theme/remui_kids/teacher/ebooks.php') !== false)
   || (strpos($current_url, '/theme/remui_kids/teacher/student_book.php') !== false)
   || (strpos($current_url, '/theme/remui_kids/teacher/teacher_book.php') !== false)
   || (strpos($current_url, '/theme/remui_kids/teacher/practice_book.php') !== false);

?>

<!-- Mobile Sidebar Toggle Button -->
<button class="sidebar-toggle" onclick="toggleTeacherSidebar()">
    <i class="fa fa-bars"></i>
</button>

<!-- Teacher Sidebar Navigation -->
<div class="teacher-sidebar" id="teacherSidebar">
    <div class="sidebar-content">
        <!-- DASHBOARD Section -->
        <div class="sidebar-section">
            <h3 class="sidebar-category">DASHBOARD</h3>
            <ul class="sidebar-menu">
                <li class="sidebar-item <?php echo $is_dashboard ? 'active' : ''; ?>">
                    <a href="<?php echo $CFG->wwwroot; ?>/my/" class="sidebar-link">
                        <i class="fa fa-th-large sidebar-icon"></i>
                        <span class="sidebar-text">Dashboard</span>
                    </a>
                </li>
                <li class="sidebar-item <?php echo $is_resources ? 'active' : ''; ?>">
                    <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/teacher/view_course.php" class="sidebar-link">
                        <i class="fa fa-folder-open sidebar-icon"></i>
                        <span class="sidebar-text">Resources</span>
                    </a>
                </li>
            </ul>
        </div>

        <!-- E-BOOKS Section -->
        <div class="sidebar-section">
            <h3 class="sidebar-category">E-BOOKS</h3>
            <ul class="sidebar-menu">
                <li class="sidebar-item <?php echo $is_ebooks ? 'active' : ''; ?>">
                    <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/teacher/ebooks.php" class="sidebar-link">
                        <i class="fa fa-book sidebar-icon"></i>
                        <span class="sidebar-text">E-Books</span>
                    </a>
                </li>
            </ul>
        </div>

    </div>
</div>

<style>
.teacher-quick-actions {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.teacher-quick-action {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    border-radius: 12px;
    text-decoration: none;
    border: none;
    color: #ffffff;
}

.teacher-quick-action .action-icon {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    background: rgba(255, 255, 255, 0.15);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #ffffff;
    font-size: 16px;
}

.teacher-quick-action .action-copy {
    flex: 1;
}

.teacher-quick-action .action-title {
    font-weight: 600;
    font-size: 14px;
    color: #ffffff;
}

.teacher-quick-action .action-arrow {
    color: rgba(255, 255, 255, 0.95);
    font-size: 12px;
}

</style>

<script>
// Teacher Sidebar JavaScript
function toggleTeacherSidebar() {
    const sidebar = document.getElementById('teacherSidebar');
    if (sidebar) {
        sidebar.classList.toggle('sidebar-open');
    }
}

// Close sidebar when clicking outside on mobile
document.addEventListener('click', function(event) {
    const sidebar = document.getElementById('teacherSidebar');
    const toggleButton = document.querySelector('.sidebar-toggle');
    
    if (sidebar && toggleButton && window.innerWidth <= 768) {
        if (!sidebar.contains(event.target) && !toggleButton.contains(event.target)) {
            sidebar.classList.remove('sidebar-open');
        }
    }
});

// Handle window resize
window.addEventListener('resize', function() {
    const sidebar = document.getElementById('teacherSidebar');
    if (sidebar && window.innerWidth > 768) {
        sidebar.classList.remove('sidebar-open');
    }
});

document.addEventListener('DOMContentLoaded', function() {
    const mainContent = document.querySelector('.teacher-main-content');
    if (!mainContent || mainContent.dataset.layout === 'custom') {
        return;
    }

    if (mainContent.querySelector('.teacher-standard-shell')) {
        return;
    }

    const shell = document.createElement('div');
    shell.className = 'teacher-standard-shell';
    if (mainContent.dataset.shell === 'wide') {
        shell.classList.add('wide');
    }

    const children = Array.from(mainContent.childNodes);
    children.forEach(child => {
        if (child.nodeType === Node.ELEMENT_NODE || child.nodeType === Node.TEXT_NODE) {
            shell.appendChild(child);
        }
    });

    mainContent.appendChild(shell);
});
</script>

