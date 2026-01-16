<?php
/**
 * High School Assignments Page (Grade 9-12)
 * Displays assignments for Grade 9-12 students in a professional format
 */

require_once('../../config.php');
require_once($CFG->dirroot . '/cohort/lib.php');
require_once($CFG->dirroot . '/user/profile/lib.php');
require_once($CFG->libdir . '/completionlib.php');
require_once(__DIR__ . '/lib/highschool_sidebar.php');
require_login();

// Get current user
global $USER, $DB, $OUTPUT, $PAGE, $CFG;

// Set page context
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/highschool_assignments.php');
$PAGE->set_title('My Assignments');
$PAGE->set_heading('My Assignments');
$PAGE->set_pagelayout('base');
$PAGE->add_body_class('custom-dashboard-page');
$PAGE->add_body_class('has-student-sidebar');

// Check if user is a student (has student role)
$user_roles = get_user_roles($context, $USER->id);
$is_student = false;
foreach ($user_roles as $role) {
    if ($role->shortname === 'student') {
        $is_student = true;
        break;
    }
}

// Also check for editingteacher and teacher roles as they might be testing the page
foreach ($user_roles as $role) {
    if ($role->shortname === 'editingteacher' || $role->shortname === 'teacher' || $role->shortname === 'manager') {
        $is_student = true; // Allow teachers/managers to view the page
        break;
    }
}

// Redirect if not a student and not logged in
if (!$is_student && !isloggedin()) {
    redirect(new moodle_url('/'));
}

// Get user's grade level from profile or cohort
$user_grade = 'Grade 11'; // Default grade for testing
$is_highschool = false;
$user_cohorts = cohort_get_user_cohorts($USER->id);

// Check user profile custom field for grade
$user_profile_fields = profile_user_record($USER->id);
if (isset($user_profile_fields->grade)) {
    $user_grade = $user_profile_fields->grade;
    // If profile has a high school grade, mark as high school
    if (preg_match('/grade\s*(?:9|10|11|12)/i', $user_grade)) {
        $is_highschool = true;
    }
} else {
    // Fallback to cohort-based detection
    foreach ($user_cohorts as $cohort) {
        $cohort_name = strtolower($cohort->name);
        // Use regex for better matching
        if (preg_match('/grade\s*(?:9|10|11|12)/i', $cohort_name)) {
            // Extract grade number
            if (preg_match('/grade\s*9/i', $cohort_name)) {
                $user_grade = 'Grade 9';
            } elseif (preg_match('/grade\s*10/i', $cohort_name)) {
                $user_grade = 'Grade 10';
            } elseif (preg_match('/grade\s*11/i', $cohort_name)) {
                $user_grade = 'Grade 11';
            } elseif (preg_match('/grade\s*12/i', $cohort_name)) {
                $user_grade = 'Grade 12';
            }
            $is_highschool = true;
            break;
        }
    }
}

// More flexible verification - allow access if user has high school grade OR is in grades 9-12
// Don't redirect if user is a teacher/manager testing the page
$valid_grades = array('Grade 9', 'Grade 10', 'Grade 11', 'Grade 12', '9', '10', '11', '12');
$has_valid_grade = false;

foreach ($valid_grades as $grade) {
    if (stripos($user_grade, $grade) !== false) {
        $has_valid_grade = true;
        break;
    }
}

// Only redirect if NOT high school and NOT valid grade
// This is more permissive to avoid blocking legitimate users
if (!$is_highschool && !$has_valid_grade) {
    // For debugging: comment out redirect temporarily
    // redirect(new moodle_url('/my/'));
    // Instead, just show a warning and continue (for testing)
    // You can re-enable the redirect once everything is working
}

// Get assignments for the student
$assignments_data = array();

// Get all courses the user is enrolled in
$enrolled_courses = enrol_get_users_courses($USER->id, true, array('id', 'fullname', 'shortname', 'summary', 'category'));

// Get assignments from enrolled courses
foreach ($enrolled_courses as $course) {
    if ($course->id == 1) continue; // Skip site course
    
    try {
        $course_context = context_course::instance($course->id);
        
        // Get assignment instances for this course
        $assignments = $DB->get_records('assign', array('course' => $course->id), 'duedate ASC');
        
        foreach ($assignments as $assignment) {
            // Get assignment submission info
            $submission = $DB->get_record('assign_submission', 
                array('assignment' => $assignment->id, 'userid' => $USER->id));
            
            // Get assignment grade
            $grade = $DB->get_record('assign_grades', 
                array('assignment' => $assignment->id, 'userid' => $USER->id));
            
            // Determine assignment status
            $status = 'not_started';
            $progress = 0;
            
            if ($submission) {
                if ($submission->status == 'submitted') {
                    $status = 'submitted';
                    $progress = 100;
                } elseif ($submission->status == 'draft') {
                    $status = 'in_progress';
                    $progress = 50;
                }
            }
            
            // Check if assignment is overdue
            $is_overdue = false;
            if ($assignment->duedate > 0 && $assignment->duedate < time() && $status != 'submitted') {
                $is_overdue = true;
                $status = 'overdue';
            }
            
            // Get course URL
            $course_url = new moodle_url('/course/view.php', array('id' => $course->id));
            $assignment_url = new moodle_url('/mod/assign/view.php', array('id' => $assignment->id));
            
            $assignment_data = array(
                'id' => $assignment->id,
                'name' => $assignment->name,
                'description' => format_text($assignment->intro, FORMAT_HTML),
                'course_name' => $course->fullname,
                'course_shortname' => $course->shortname,
                'course_url' => $course_url->out(),
                'assignment_url' => $assignment_url->out(),
                'duedate' => $assignment->duedate,
                'duedate_formatted' => $assignment->duedate > 0 ? date('M j, Y g:i A', $assignment->duedate) : 'No due date',
                'time_remaining' => $assignment->duedate > 0 ? ($assignment->duedate - time()) : 0,
                'status' => $status,
                'progress' => $progress,
                'grade' => $grade ? $grade->grade : null,
                'is_overdue' => $is_overdue,
                'submission_status' => $submission ? $submission->status : 'not_started'
            );
            
            $assignments_data[] = $assignment_data;
        }
        
    } catch (Exception $e) {
        // Skip courses that don't exist or have permission issues
        continue;
    }
}

// Sort assignments by due date
usort($assignments_data, function($a, $b) {
    if ($a['duedate'] == $b['duedate']) return 0;
    return ($a['duedate'] < $b['duedate']) ? -1 : 1;
});

// Calculate statistics
$total_assignments = count($assignments_data);
$submitted_assignments = 0;
$in_progress_assignments = 0;
$overdue_assignments = 0;
$not_started_assignments = 0;

foreach ($assignments_data as $assignment) {
    if ($assignment['status'] == 'submitted') {
        $submitted_assignments++;
    } elseif ($assignment['status'] == 'in_progress') {
        $in_progress_assignments++;
    } elseif ($assignment['status'] == 'overdue') {
        $overdue_assignments++;
    } else {
        $not_started_assignments++;
    }
}

// Build sidebar context using common function
$sidebar_context = remui_kids_build_highschool_sidebar_context('assignments', $USER);

// Prepare template data
$template_data = array_merge($sidebar_context, array(
    'user_grade' => $user_grade,
    'assignments' => $assignments_data,
    'total_assignments' => $total_assignments,
    'submitted_assignments' => $submitted_assignments,
    'in_progress_assignments' => $in_progress_assignments,
    'overdue_assignments' => $overdue_assignments,
    'not_started_assignments' => $not_started_assignments,
    'user_name' => fullname($USER),
    'dashboard_url' => $sidebar_context['dashboardurl'],
    'current_url' => $PAGE->url->out(),
    'grades_url' => (new moodle_url('/grade/report/overview/index.php'))->out(),
    'assignments_url' => $sidebar_context['assignmentsurl'],
    'messages_url' => $sidebar_context['messagesurl'],
    'profile_url' => $sidebar_context['profileurl'],
    'logout_url' => $sidebar_context['logouturl'],
    'is_highschool' => true
));

// Output page header with Moodle navigation
echo $OUTPUT->header();

// Render common sidebar
echo $OUTPUT->render_from_template('theme_remui_kids/highschool_sidebar', $template_data);

// Add custom CSS for the assignments page
?>
<style>
        /* Enhanced Sidebar Styles */
        .student-sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 280px;
            height: 100vh;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            overflow-y: auto;
            z-index: 1000;
            padding: 2rem 0;
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
        }
        
        .student-sidebar.enhanced-sidebar {
            padding: 1.5rem 0;
        }
        
        .sidebar-nav {
            padding: 0 1rem;
        }
        
        .nav-section {
            margin-bottom: 2rem;
        }
        
        .section-title {
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 1px;
            color: rgba(255, 255, 255, 0.6);
            margin-bottom: 0.75rem;
            padding: 0 0.75rem;
        }
        
        .nav-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .nav-item {
            margin-bottom: 0.25rem;
        }
        
        .nav-link {
            display: flex;
            align-items: center;
            padding: 0.75rem;
            color: rgba(255, 255, 255, 0.85);
            text-decoration: none;
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        
        .nav-link:hover {
            background: rgba(255, 255, 255, 0.15);
            color: white;
            transform: translateX(5px);
        }
        
        .nav-link.active {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            font-weight: 600;
        }
        
        .nav-link i {
            width: 24px;
            margin-right: 0.75rem;
            font-size: 1.1rem;
        }
        
        .quick-actions {
            padding: 0 0.75rem;
        }
        
        .quick-action-buttons {
            display: grid;
            gap: 0.75rem;
        }
        
        .quick-action-btn {
            display: flex;
            align-items: center;
            padding: 0.75rem;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .quick-action-btn:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateY(-2px);
        }
        
        .action-icon {
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            margin-right: 0.75rem;
        }
        
        .quick-action-btn.purple .action-icon { background: rgba(147, 51, 234, 0.3); }
        .quick-action-btn.blue .action-icon { background: rgba(59, 130, 246, 0.3); }
        .quick-action-btn.green .action-icon { background: rgba(34, 197, 94, 0.3); }
        .quick-action-btn.orange .action-icon { background: rgba(249, 115, 22, 0.3); }
        
        .action-content {
            flex: 1;
        }
        
        .action-title {
            font-size: 0.9rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
        }
        
        .action-desc {
            font-size: 0.75rem;
            color: rgba(255, 255, 255, 0.7);
        }
        
        .sidebar-footer {
            padding: 1rem;
            margin-top: 2rem;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .user-info {
            display: flex;
            align-items: center;
            padding: 0.75rem;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 8px;
        }
        
        .user-avatar {
            width: 40px;
            height: 40px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 0.75rem;
        }
        
        .user-details {
            flex: 1;
        }
        
        .user-name {
            font-weight: 600;
            font-size: 0.9rem;
        }
        
        .user-role {
            font-size: 0.75rem;
            color: rgba(255, 255, 255, 0.7);
        }
        
        /* Custom styles for High School Assignments Page */
        .highschool-assignments-page {
            position: relative;
            background: #f8fafc;
            min-height: 100vh;
            margin-left: 280px;
            padding-left: 2rem;
        }
        
        .assignments-main-content {
            padding: 0;
            width: 100%;
        }
        
        /* Remove all padding for full-width feel */
        .container-fluid {
            padding-left: 0;
            padding-right: 0;
        }
        
        /* Remove all padding from main content */
        .assignments-main-content {
            padding: 0 !important;
        }
        
        /* Remove padding from page wrapper */
        #page-wrapper {
            padding: 0 !important;
        }
        
        /* Remove padding from page content */
        #page-content {
            padding: 0 !important;
        }
        
        /* Remove all margins and padding from main content areas */
        .main-content,
        .content,
        .region-main,
        .region-main-content {
            padding: 0 !important;
            margin: 0 !important;
        }
        
        /* Remove padding from row and column classes */
        .row {
            margin-left: 0 !important;
            margin-right: 0 !important;
        }
        
        .col-lg-3, .col-md-6, .col-12 {
            padding-left: 0.5rem !important;
            padding-right: 0.5rem !important;
        }
        
        /* Full width navbar and page adjustments */
        body.has-student-sidebar #page,
        body.has-enhanced-sidebar #page {
            margin-left: 0;
            width: 100%;
        }
        
        body.has-student-sidebar .highschool-assignments-page,
        body.has-enhanced-sidebar .highschool-assignments-page {
            margin-left: 20px;
        }
        
        body.has-student-sidebar #page-wrapper,
        body.has-enhanced-sidebar #page-wrapper {
            margin-left: 0;
            width: 100%;
        }
        
        /* Make navbar span full width and sticky */
        body.has-student-sidebar .navbar,
        body.has-enhanced-sidebar .navbar,
        body.has-student-sidebar .navbar-expand,
        body.has-enhanced-sidebar .navbar-expand {
            width: 100% !important;
            margin-left: 0 !important;
            left: 0 !important;
            right: 0 !important;
            position: fixed !important;
            top: 0 !important;
            z-index: 1030 !important;
            background: white !important;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1) !important;
        }
        
       
        
        /* Adjust main content area to account for sidebar */
        body.has-student-sidebar .main-content,
        body.has-enhanced-sidebar .main-content,
        body.has-student-sidebar .content,
        body.has-enhanced-sidebar .content {
            margin-left: 280px;
        }
        
        /* Ensure page header spans full width */
        body.has-student-sidebar .page-header,
        body.has-enhanced-sidebar .page-header {
            width: 100%;
            margin-left: 0;
        }
        
        .assignments-page-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem 0;
            margin-bottom: 1.5rem;
            margin-left: -2rem;
            margin-right: 0;
            width: calc(100% + 2rem);
        }
        
        .assignments-page-header .page-title {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        
        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
        }
        
        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
        }
        
        .stat-icon.total { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .stat-icon.progress { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); }
        .stat-icon.completed { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }
        .stat-icon.overdue { background: linear-gradient(135deg, #ff6b6b 0%, #ee5a24 100%); }
        
        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: #1a202c;
        }
        
        .assignments-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1rem;
            padding: 0;
            margin: 0;
        }
        
        .assignment-card {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
        }
        
        .assignment-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
        }
        
        .assignment-header {
            padding: 1.5rem;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .assignment-status-badge {
            display: inline-block;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 600;
            color: white;
            margin-bottom: 1rem;
        }
        
        .assignment-status-badge.submitted { background: #10b981; }
        .assignment-status-badge.in_progress { background: #f59e0b; }
        .assignment-status-badge.overdue { background: #ef4444; }
        .assignment-status-badge.not_started { background: #6b7280; }
        
        .assignment-title {
            font-size: 1.25rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            color: #1a202c;
        }
        
        .assignment-course {
            font-size: 0.9rem;
            color: #667eea;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        
        .assignment-due-date {
            font-size: 0.9rem;
            color: #718096;
        }
        
        .assignment-content {
            padding: 1.5rem;
        }
        
        .assignment-description {
            color: #4a5568;
            margin-bottom: 1rem;
            line-height: 1.6;
        }
        
        .assignment-progress {
            margin-bottom: 1rem;
        }
        
        .progress-bar-container {
            width: 100%;
            height: 8px;
            background: #e2e8f0;
            border-radius: 4px;
            overflow: hidden;
            margin: 0.5rem 0;
        }
        
        .progress-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            color: white;
            padding: 0.75rem;
            border-radius: 8px;
            font-weight: 600;
            width: 100%;
            text-decoration: none;
            display: block;
            text-align: center;
        }
        
        @media (max-width: 768px) {
            .student-sidebar {
                transform: translateX(-100%);
                transition: transform 0.3s ease;
            }
            
            .student-sidebar.show {
                transform: translateX(0);
            }
            
            .highschool-assignments-page {
                margin-left: 0 !important;
                padding-left: 1rem !important;
            }
            
            .assignments-page-header {
                margin-left: -1rem !important;
                width: calc(100% + 1rem) !important;
            }
            
            body.has-student-sidebar #page,
            body.has-enhanced-sidebar #page,
            body.has-student-sidebar #page-wrapper,
            body.has-enhanced-sidebar #page-wrapper {
                margin-left: 0 !important;
            }
            
            .assignments-page-header .page-title {
                font-size: 1.8rem;
            }
            .assignments-main-content {
                padding: 0.5rem;
            }
            
            .container-fluid {
                padding-left: 0.5rem;
                padding-right: 0.5rem;
            }
        }
</style>

<div class="highschool-assignments-page">
    <div class="assignments-main-content">
        <!-- Page Header -->
        <div class="assignments-page-header">
            <div class="container-fluid">
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <h1 class="page-title">
                            <i class="fa fa-clipboard-list me-3"></i>
                            My Assignments
                        </h1>
                        
                    </div>
                    <div class="col-md-4 text-md-end">
                        <a href="<?php echo $template_data['dashboard_url']; ?>" class="btn btn-outline-light">
                            <i class="fa fa-arrow-left me-2"></i>Back to Dashboard
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Assignment Statistics -->
        <div class="container-fluid">
            <div class="row g-2 mb-2">
                <div class="col-lg-3 col-md-6">
                    <div class="stat-card">
                        <div class="stat-icon total"><i class="fa fa-clipboard-list"></i></div>
                        <div>
                            <div class="stat-value"><?php echo $template_data['total_assignments']; ?></div>
                            <div>Total Assignments</div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6">
                    <div class="stat-card">
                        <div class="stat-icon progress"><i class="fa fa-spinner"></i></div>
                        <div>
                            <div class="stat-value"><?php echo $template_data['in_progress_assignments']; ?></div>
                            <div>In Progress</div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6">
                    <div class="stat-card">
                        <div class="stat-icon completed"><i class="fa fa-check-circle"></i></div>
                        <div>
                            <div class="stat-value"><?php echo $template_data['submitted_assignments']; ?></div>
                            <div>Submitted</div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6">
                    <div class="stat-card">
                        <div class="stat-icon overdue"><i class="fa fa-exclamation-triangle"></i></div>
                        <div>
                            <div class="stat-value"><?php echo $template_data['overdue_assignments']; ?></div>
                            <div>Overdue</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Assignments List -->
            <?php if (!empty($template_data['assignments'])): ?>
            <div class="assignments-grid">
                <?php foreach ($template_data['assignments'] as $assignment): ?>
                <div class="assignment-card">
                    <div class="assignment-header">
                        <div class="assignment-status-badge <?php echo $assignment['status']; ?>">
                            <?php 
                            if ($assignment['status'] == 'submitted') echo '<i class="fa fa-check-circle"></i> Submitted';
                            elseif ($assignment['status'] == 'in_progress') echo '<i class="fa fa-spinner"></i> In Progress';
                            elseif ($assignment['status'] == 'overdue') echo '<i class="fa fa-exclamation-triangle"></i> Overdue';
                            else echo '<i class="fa fa-play-circle"></i> Not Started';
                            ?>
                        </div>
                        <h3 class="assignment-title">
                            <a href="<?php echo $assignment['assignment_url']; ?>" style="color: #1a202c; text-decoration: none;">
                                <?php echo htmlspecialchars($assignment['name']); ?>
                            </a>
                        </h3>
                        <div class="assignment-course"><?php echo htmlspecialchars($assignment['course_name']); ?></div>
                        <div class="assignment-due-date">
                            <i class="fa fa-calendar"></i> Due: <?php echo $assignment['duedate_formatted']; ?>
                        </div>
                    </div>
                    
                    <div class="assignment-content">
                        <div class="assignment-description">
                            <?php echo $assignment['description']; ?>
                        </div>
                        
                        <div class="assignment-progress">
                            <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                                <span>Progress</span>
                                <span style="font-weight: 600;"><?php echo $assignment['progress']; ?>%</span>
                            </div>
                            <div class="progress-bar-container">
                                <div class="progress-bar-fill" style="width: <?php echo $assignment['progress']; ?>%"></div>
                            </div>
                        </div>
                        
                        <a href="<?php echo $assignment['assignment_url']; ?>" class="btn-primary">
                            <?php if ($assignment['status'] == 'submitted'): ?>
                                <i class="fa fa-eye"></i> View Submission
                            <?php elseif ($assignment['status'] == 'in_progress'): ?>
                                <i class="fa fa-edit"></i> Continue Working
                            <?php elseif ($assignment['status'] == 'overdue'): ?>
                                <i class="fa fa-exclamation-triangle"></i> Submit Now
                            <?php else: ?>
                                <i class="fa fa-play"></i> Start Assignment
                            <?php endif; ?>
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="text-center" style="padding: 4rem 0;">
                <i class="fa fa-clipboard-list" style="font-size: 4rem; color: #cbd5e0; margin-bottom: 1rem;"></i>
                <h3 style="font-size: 1.75rem; font-weight: 700; color: #1a202c; margin-bottom: 0.5rem;">No Assignments Found</h3>
                <p style="font-size: 1.1rem; color: #718096;">You don't have any assignments yet. Check back later or contact your teacher.</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// Initialize enhanced sidebar
document.addEventListener('DOMContentLoaded', function() {
    const enhancedSidebar = document.querySelector('.enhanced-sidebar');
    if (enhancedSidebar) {
        document.body.classList.add('has-student-sidebar', 'has-enhanced-sidebar');
        console.log('Enhanced sidebar initialized for high school assignments page');
    }
    
    // Handle sidebar navigation - set active state
    const currentUrl = window.location.href;
    const navLinks = document.querySelectorAll('.student-sidebar .nav-link');
    navLinks.forEach(link => {
        if (link.href === currentUrl) {
            link.classList.add('active');
        }
    });
    
    // Mobile sidebar toggle (if you add a toggle button in the future)
    const sidebarToggle = document.getElementById('sidebar-toggle');
    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', function() {
            enhancedSidebar.classList.toggle('show');
        });
    }
});
</script>
<?php
// Output page footer with Moodle navigation
echo $OUTPUT->footer();
?>
