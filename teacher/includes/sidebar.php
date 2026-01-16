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
$is_community = theme_remui_kids_sidebar_match($current_script, [
    'community.php'
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
                        <span class="sidebar-text">Teacher Dashboard</span>
                    </a>
                </li>
                <li class="sidebar-item <?php echo $is_courses ? 'active' : ''; ?>">
                    <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/teacher/teacher_courses.php" class="sidebar-link">
                        <i class="fa fa-book sidebar-icon"></i>
                        <span class="sidebar-text">My Courses</span>
                    </a>
                </li>
                <li class="sidebar-item <?php echo $is_resources ? 'active' : ''; ?>">
                    <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/teacher/view_course.php" class="sidebar-link">
                        <i class="fa fa-folder-open sidebar-icon"></i>
                        <span class="sidebar-text">Teacher Resources</span>
                    </a>
                </li>
                <li class="sidebar-item <?php echo $is_lessons ? 'active' : ''; ?>">
                    <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/teacher/lessonplan.php" class="sidebar-link">
                        <i class="fa fa-file-alt sidebar-icon"></i>
                        <span class="sidebar-text">Lesson Plan</span>
                    </a>
                </li>
                <li class="sidebar-item <?php echo $is_schedule ? 'active' : ''; ?>">
                    <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/teacher/schedule.php" class="sidebar-link">
                        <i class="fa fa-calendar-alt sidebar-icon"></i>
                        <span class="sidebar-text">My Schedule</span>
                    </a>
                </li>
            </ul>
        </div>

        <!-- STUDENTS Section -->
        <div class="sidebar-section">
            <h3 class="sidebar-category">STUDENTS</h3>
            <ul class="sidebar-menu">
                <li class="sidebar-item <?php echo $is_students ? 'active' : ''; ?>">
                    <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/teacher/students.php" class="sidebar-link">
                        <i class="fa fa-users sidebar-icon"></i>
                        <span class="sidebar-text">All Students</span>
                    </a>
                </li>
                <li class="sidebar-item <?php echo $is_doubts ? 'active' : ''; ?>">
                    <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/pages/teacher_doubts.php" class="sidebar-link">
                        <i class="fa fa-question-circle sidebar-icon"></i>
                        <span class="sidebar-text">Student Queries</span>
                    </a>
                </li>
                <li class="sidebar-item <?php echo $is_community ? 'active' : ''; ?>">
                    <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/community.php" class="sidebar-link">
                        <i class="fa fa-people-group sidebar-icon"></i>
                        <span class="sidebar-text">Community</span>
                    </a>
                </li>
            </ul>
        </div>

        <!-- ASSESSMENTS Section -->
        <div class="sidebar-section">
            <h3 class="sidebar-category">ASSESSMENTS</h3>
            <ul class="sidebar-menu">
                <li class="sidebar-item <?php echo $is_assignments ? 'active' : ''; ?>">
                    <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/teacher/assignments.php" class="sidebar-link">
                        <i class="fa fa-tasks sidebar-icon"></i>
                        <span class="sidebar-text">Assignments</span>
                    </a>
                </li>
                <li class="sidebar-item <?php echo $is_quizzes ? 'active' : ''; ?>">
                    <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/teacher/quizzes.php" class="sidebar-link">
                        <i class="fa fa-question-circle sidebar-icon"></i>
                        <span class="sidebar-text">Quizzes</span>
                    </a>
                </li>
                <li class="sidebar-item <?php echo $is_competencies ? 'active' : ''; ?>">
                    <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/teacher/competencies.php" class="sidebar-link">
                        <i class="fa fa-sitemap sidebar-icon"></i>
                        <span class="sidebar-text">Competencies</span>
                    </a>
                </li>
                <li class="sidebar-item <?php echo $is_rubrics ? 'active' : ''; ?>">
                    <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/teacher/rubrics.php" class="sidebar-link">
                        <i class="fa fa-list-alt sidebar-icon"></i>
                        <span class="sidebar-text">Grade Rubrics</span>
                    </a>
                </li>
                <li class="sidebar-item <?php echo $is_gradebook ? 'active' : ''; ?>">
                    <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/teacher/gradebook.php" class="sidebar-link">
                        <i class="fa fa-star sidebar-icon"></i>
                        <span class="sidebar-text">Gradebook</span>
                    </a>
                </li>
            </ul>
        </div>

         <!-- Tools Section -->
         <div class="sidebar-section">
            <h3 class="sidebar-category">TOOLS</h3>
            <ul class="sidebar-menu">
                <li class="sidebar-item <?php echo $is_emulators ? 'active' : ''; ?>">
                    <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/emulators.php" class="sidebar-link">
                        <i class="fa fa-rocket sidebar-icon"></i>
                        <span class="sidebar-text">Emulators Hub</span>
                    </a>
                </li>
                <li class="sidebar-item <?php echo $is_ebook ? 'active' : ''; ?>">
                    <a href="<?php echo $CFG->wwwroot; ?>/local/ebook/manage.php" class="sidebar-link">
                        <i class="fa fa-book-reader sidebar-icon"></i>
                        <span class="sidebar-text">E-Book Manage</span>
                    </a>
                </li>
                <li class="sidebar-item <?php echo $is_certificates ? 'active' : ''; ?>">
                    <a href="<?php echo $CFG->wwwroot; ?>/local/certificate_approval/pages/teacher_dashboard.php" class="sidebar-link">
                        <i class="fa fa-certificate sidebar-icon"></i>
                        <span class="sidebar-text">Certificates</span>
                    </a>
                </li>
                <?php
                // Check for support videos
                require_once($CFG->dirroot . '/theme/remui_kids/lib/support_helper.php');
                $video_check = theme_remui_kids_check_support_videos('teachers');
                $has_help_videos = $video_check['has_videos'];
                if ($has_help_videos):
                ?>
                <li class="sidebar-item <?php echo $is_need_help ? 'active' : ''; ?>">
                    <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/need_help.php" class="sidebar-link">
                        <i class="fa fa-question-circle sidebar-icon"></i>
                        <span class="sidebar-text">Need Help</span>
                    </a>
                </li>
                <?php endif; ?>
                <?php if ($role_switch_enabled): ?>
                <li class="sidebar-item <?php echo $is_switchrole ? 'active' : ''; ?>">
                    <a href="<?php echo $CFG->wwwroot; ?>/local/teacherviewstudent/" class="sidebar-link <?php echo $is_switchrole ? 'active' : ''; ?>">
                        <i class="fa fa-user-graduate sidebar-icon"></i>
                        <span class="sidebar-text">Switch Role</span>
                    </a>
                </li>
                <?php endif; ?>
            </ul>
        </div>


        <!-- REPORTS Section -->
        <div class="sidebar-section">
            <h3 class="sidebar-category">REPORTS</h3>
            <ul class="sidebar-menu">
                <li class="sidebar-item <?php echo $is_activity_logs ? 'active' : ''; ?>">
                    <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/teacher/activity_logs.php" class="sidebar-link">
                        <i class="fa fa-chart-bar sidebar-icon"></i>
                        <span class="sidebar-text">Activity Logs</span>
                    </a>
                </li>
                <li class="sidebar-item <?php echo $is_reports ? 'active' : ''; ?>">
                    <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/teacher/reports.php" class="sidebar-link">
                        <i class="fa fa-file-alt sidebar-icon"></i>
                        <span class="sidebar-text">Course and Student Reports</span>
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

