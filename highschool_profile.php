<?php
/**
 * High School Profile Settings Page (Grade 9-12)
 * Displays profile settings for Grade 9-12 students in a professional format
 */

require_once('../../config.php');
require_once(__DIR__ . '/lang_init.php'); // Apply user's selected language
require_once($CFG->dirroot . '/cohort/lib.php');
require_once($CFG->dirroot . '/user/profile/lib.php');
require_once($CFG->libdir . '/completionlib.php');
require_once(__DIR__ . '/lib/highschool_sidebar.php');
require_login();

// Get current user
global $USER, $DB, $OUTPUT, $PAGE, $CFG;

// Reload user from database to ensure we have the latest data
$user_record = $DB->get_record('user', array('id' => $USER->id), '*', MUST_EXIST);
if ($user_record) {
    // Update $USER object with fresh data from database
    foreach ($user_record as $key => $value) {
        $USER->$key = $value;
    }
}

// Set page context
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/highschool_profile.php');
$PAGE->set_title('Profile Settings');
$PAGE->set_heading('Profile Settings');
$PAGE->set_pagelayout('base');
$PAGE->add_body_class('custom-dashboard-page');
$PAGE->add_body_class('has-student-sidebar');
$PAGE->requires->css('/theme/remui_kids/style/highschool_reports.css');
$PAGE->requires->css('/theme/remui_kids/style/fontawesome.css');
$PAGE->requires->jquery();

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

// Handle form submission BEFORE building template data
if (optional_param('submitbutton', '', PARAM_TEXT) && confirm_sesskey()) {
    require_once($CFG->dirroot . '/user/lib.php');
    
    // Get the current user object
    $user = $DB->get_record('user', array('id' => $USER->id), '*', MUST_EXIST);
    
    // Update user profile fields
    $user->phone1 = optional_param('phone1', '', PARAM_TEXT);
    $user->city = optional_param('city', '', PARAM_TEXT);
    $user->country = optional_param('country', '', PARAM_TEXT);
    $user->description = optional_param('description', '', PARAM_RAW);
    
    // Use Moodle's proper user update function
    user_update_user($user, false, false);
    
    // Show success notification
    \core\notification::success(get_string('changessaved', 'moodle'));
    
    // Redirect to prevent resubmission and show updated data
    redirect($PAGE->url);
}

// Get user profile information (after potential form submission)
// Ensure we have the latest user data from database - fetch ALL fields
$current_user = $DB->get_record('user', array('id' => $USER->id), '*', MUST_EXIST);
if ($current_user) {
    // Update $USER object with ALL fresh data from database
    foreach ($current_user as $key => $value) {
        $USER->$key = $value;
    }
}

// Load all custom profile fields into user object
profile_load_custom_fields($USER);

// Get all profile fields (including custom fields)
$user_profile = profile_user_record($USER->id);
$user_picture = $OUTPUT->user_picture($USER, array('size' => 100));

// Get all custom profile fields with data
$custom_profile_fields = profile_get_user_fields_with_data($USER->id);

// Get user's enrolled courses count (excluding site course)
$enrolled_courses = enrol_get_users_courses($USER->id, true);
$courses_count = 0;
$completed_courses = 0;
$total_progress = 0;
$courses_with_progress = 0;

// Prepare course details array
$course_details = array();

// Calculate completion statistics dynamically
foreach ($enrolled_courses as $course) {
    if ($course->id == 1) {
        continue; // Skip site course
    }
    
    $courses_count++; // Count valid courses
    
    $course_progress = 0;
    $course_completed = false;
    $course_url = new moodle_url('/course/view.php', array('id' => $course->id));
    
    try {
        $completion = new completion_info($course);
        if ($completion->is_enabled()) {
            $progress = (int) \core_completion\progress::get_course_progress_percentage($course, $USER->id);
            if ($progress !== null && $progress >= 0) {
                $course_progress = $progress;
                $total_progress += $progress;
                $courses_with_progress++;
                if ($progress >= 100) {
                    $completed_courses++;
                    $course_completed = true;
                }
            }
        }
        
        // Get course category
        $category = $DB->get_record('course_categories', array('id' => $course->category));
        $category_name = $category ? $category->name : get_string('general', 'moodle');
        
        // Add course details
        $course_details[] = array(
            'id' => $course->id,
            'fullname' => $course->fullname,
            'shortname' => $course->shortname,
            'summary' => !empty($course->summary) ? strip_tags($course->summary) : '',
            'progress' => $course_progress,
            'completed' => $course_completed,
            'category' => $category_name,
            'url' => $course_url->out(),
            'status' => $course_completed ? 'completed' : ($course_progress > 0 ? 'in_progress' : 'not_started')
        );
    } catch (Exception $e) {
        // Skip courses that don't exist or have errors
        continue;
    }
}

// Calculate average progress (only for courses with progress tracking)
$average_progress = $courses_with_progress > 0 ? round($total_progress / $courses_with_progress) : 0;

// Calculate completion percentage for progress bar
$completion_percentage = $courses_count > 0 ? round(($completed_courses / $courses_count) * 100) : 0;

$sidebar_context = remui_kids_build_highschool_sidebar_context('profile', $USER);

// Get login activity data
$last_login = $USER->lastlogin ? userdate($USER->lastlogin) : get_string('never', 'moodle');
$current_login = $USER->currentlogin ? userdate($USER->currentlogin) : get_string('never', 'moodle');
$first_access = $USER->firstaccess ? userdate($USER->firstaccess) : get_string('never', 'moodle');
$account_created = userdate($USER->timecreated);
$last_ip = !empty($USER->lastip) ? $USER->lastip : get_string('unknown', 'admin');
$last_access = $USER->lastaccess ? userdate($USER->lastaccess) : get_string('never', 'moodle');

// Calculate days since last login
$days_since_login = 0;
if ($USER->lastlogin > 0) {
    $days_since_login = floor((time() - $USER->lastlogin) / 86400);
}

// Prepare all user details - fetch all standard and custom fields
$all_user_details = array(
    // Basic Information
    'user_id' => $USER->id,
    'user_username' => isset($USER->username) ? trim($USER->username) : '',
    'user_firstname' => isset($USER->firstname) ? trim($USER->firstname) : '',
    'user_lastname' => isset($USER->lastname) ? trim($USER->lastname) : '',
    'user_middlename' => isset($USER->middlename) ? trim($USER->middlename) : '',
    'user_alternatename' => isset($USER->alternatename) ? trim($USER->alternatename) : '',
    'user_firstnamephonetic' => isset($USER->firstnamephonetic) ? trim($USER->firstnamephonetic) : '',
    'user_lastnamephonetic' => isset($USER->lastnamephonetic) ? trim($USER->lastnamephonetic) : '',
    'user_name' => fullname($USER),
    
    // Contact Information
    'user_email' => isset($USER->email) ? trim($USER->email) : '',
    'user_phone1' => isset($USER->phone1) ? trim($USER->phone1) : '',
    'user_phone2' => isset($USER->phone2) ? trim($USER->phone2) : '',
    'user_address' => isset($USER->address) ? trim($USER->address) : '',
    'user_city' => isset($USER->city) ? trim($USER->city) : '',
    'user_country' => isset($USER->country) ? trim($USER->country) : '',
    
    // Institution Information
    'user_institution' => isset($USER->institution) ? trim($USER->institution) : '',
    'user_department' => isset($USER->department) ? trim($USER->department) : '',
    'user_idnumber' => isset($USER->idnumber) ? trim($USER->idnumber) : '',
    
    // Profile Information
    'user_description' => isset($USER->description) ? trim($USER->description) : '',
    'user_descriptionformat' => isset($USER->descriptionformat) ? $USER->descriptionformat : FORMAT_HTML,
    'user_interests' => isset($USER->interests) ? trim($USER->interests) : '',
    
    // Preferences
    'user_lang' => isset($USER->lang) ? trim($USER->lang) : $CFG->lang,
    'user_timezone' => isset($USER->timezone) ? trim($USER->timezone) : $CFG->timezone,
    'user_theme' => isset($USER->theme) ? trim($USER->theme) : '',
    'user_mailformat' => isset($USER->mailformat) ? $USER->mailformat : 1,
    'user_maildisplay' => isset($USER->maildisplay) ? $USER->maildisplay : 2,
    
    // Authentication
    'user_auth' => isset($USER->auth) ? trim($USER->auth) : 'manual',
    'user_confirmed' => isset($USER->confirmed) ? $USER->confirmed : 0,
    'user_suspended' => isset($USER->suspended) ? $USER->suspended : 0,
    
    // Dates
    'user_timecreated' => isset($USER->timecreated) ? $USER->timecreated : 0,
    'user_timemodified' => isset($USER->timemodified) ? $USER->timemodified : 0,
    'user_lastaccess' => isset($USER->lastaccess) ? $USER->lastaccess : 0,
    'user_lastlogin' => isset($USER->lastlogin) ? $USER->lastlogin : 0,
    'user_firstaccess' => isset($USER->firstaccess) ? $USER->firstaccess : 0,
    'user_currentlogin' => isset($USER->currentlogin) ? $USER->currentlogin : 0,
    'user_lastip' => isset($USER->lastip) ? trim($USER->lastip) : '',
);

// Prepare custom profile fields data
$custom_fields_data = array();
if (!empty($custom_profile_fields)) {
    foreach ($custom_profile_fields as $field) {
        if ($field->show_field_content()) {
            $custom_fields_data[$field->field->shortname] = array(
                'name' => $field->display_name(),
                'value' => $field->data,
                'displayvalue' => $field->display_data(),
                'type' => $field->field->datatype,
                'shortname' => $field->field->shortname,
                'category' => $field->get_category_name()
            );
        }
    }
}

// Prepare template data - ensure all user fields are properly loaded
$template_data = array_merge($sidebar_context, $all_user_details, array(
    'user_grade' => $user_grade,
    'user_phone' => $all_user_details['user_phone1'], // Keep for backward compatibility
    'custom_profile_fields' => $custom_fields_data,
    'user_profile' => $user_profile,
    'user_picture' => $user_picture,
    'courses_count' => $courses_count,
    'completed_courses' => $completed_courses,
    'average_progress' => $average_progress,
    'completion_percentage' => $completion_percentage,
    'course_details' => $course_details,
    'last_login' => $last_login,
    'current_login' => $current_login,
    'first_access' => $first_access,
    'account_created' => $account_created,
    'last_ip' => $last_ip,
    'last_access' => $last_access,
    'days_since_login' => $days_since_login,
    'dashboard_url' => $sidebar_context['dashboardurl'],
    'current_url' => $PAGE->url->out(),
    'grades_url' => (new moodle_url('/theme/remui_kids/highschool_grades.php'))->out(),
    'browser_sessions_url' => (new moodle_url('/report/usersessions/user.php'))->out(),
    'assignments_url' => $sidebar_context['assignmentsurl'],
    'courses_url' => $sidebar_context['mycoursesurl'],
    'messages_url' => (new moodle_url('/message/index.php'))->out(),
    'profile_url' => (new moodle_url('/user/profile.php', array('id' => $USER->id)))->out(),
    'logout_url' => $sidebar_context['logouturl'],
    'is_highschool' => true
));

// Output page header with Moodle navigation
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('theme_remui_kids/highschool_sidebar', $template_data);

// Add custom CSS for profile page
?>

<!-- Font Awesome CDN as fallback -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" integrity="sha512-9usAa10IRO0HhonpyAIVpjrylPvoDwiPUiKdWk5t3PyolY1cOd4DSE0Ga+ri4AuTroPR5aQvXU9xC6qOPnzFeg==" crossorigin="anonymous" referrerpolicy="no-referrer" />

<style>
    /* Enhanced Sidebar Styles */
    .student-sidebar {
        position: fixed;
        left: 0;
        top: 0;
        width: 320px;
        height: 100vh;
        background: #ffffff;
        overflow-y: auto;
        z-index: 1000;
        padding: 2rem 0;
        box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1);
    }

    .student-sidebar.enhanced-sidebar {
        padding: 1.5rem 0;
    }

    .nav-link {
        display: flex;
        align-items: center;
        padding: 0.75rem;
        border-radius: 8px;
        transition: all 0.3s ease;
    }

    .nav-link:hover {
        background: rgba(59, 130, 246, 0.12);
        transform: translateX(5px);
    }

    .nav-link.active {
        background: rgba(59, 130, 246, 0.18);
        font-weight: 600;
    }

    /* Hide Moodle default page heading */
    .highschool-profile-page .page-header,
    .highschool-profile-page #page-header,
    .highschool-profile-page .page-context-header {
        display: none !important;
    }
    .table thead th {
      background-color: #e9ecef;
      color: #2f3847;
    }
    .list-group-item {
      box-shadow: none !important;
      margin-bottom: 0rem;
    }

    /* Profile page specific styles */
    .highschool-profile-page {
        min-height: 100vh;
        background-color: #f8f9fa;
    }
    .bg-purple{
      background-color: #007bff;
    }
    .highschool-profile-page .card {
        border: none;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    }

    .highschool-profile-page .card-header {
        background-color: #fff;
        border-bottom: 1px solid #e9ecef;
    }

    .highschool-profile-page .nav-tabs .nav-link {
        border: none;
        border-bottom: 2px solid transparent;
        color: #6c757d;
    }

    .highschool-profile-page .nav-tabs .nav-link.active {
        border-bottom-color: #007bff;
        color: #007bff;
        background: transparent;
    }

    .highschool-profile-page .progress {
        height: 8px;
        border-radius: 4px;
    }

    .highschool-profile-page .badge {
        padding: 0.5rem 0.75rem;
        font-weight: 600;
    }

    .footer-copyright-wrapper,
    .footer-mainsection-wrapper {
        display: none !important;
    }

    @media (max-width: 768px) {
        body.has-student-sidebar #account-settings-page,
        body.has-enhanced-sidebar #account-settings-page {
            margin-left: 0;
        }
        
        .student-sidebar {
            transform: translateX(-100%);
            transition: transform 0.3s ease;
        }

        .student-sidebar.show {
            transform: translateX(0);
        }
    }
</style>

<div id="account-settings-page" class="highschool-profile-page">
  <!-- Main Content -->
  <main id="account-settings-main" class="container-fluid py-4">
    <div class="row">
      <div class="col-12">
        <!-- Page Header -->
        <div id="page-header" class="mb-4">
          <div class="d-flex align-items-center mb-2">
            <a href="<?php echo $template_data['dashboard_url']; ?>" class="btn btn-link p-0 text-primary">
              <?php echo $OUTPUT->pix_icon('t/left', get_string('back'), 'moodle'); ?> <?php echo get_string('back', 'moodle'); ?>
            </a>
          </div>
          <h1 class="h2 font-weight-bold"><?php echo get_string('accountsettings', 'theme_remui_kids'); ?></h1>
          <p class="text-muted"><?php echo get_string('manageprofilepreferences', 'theme_remui_kids'); ?></p>
        </div>
      
        <!-- Settings Content -->
        <div id="settings-content" class="card shadow-sm">
          <!-- Profile Information -->
          <div id="profile-information-form" class="card-body p-4">
            <div class="row">
              <!-- Left Column - Profile Picture -->
              <div id="profile-picture-section" class="col-lg-4 mb-4 mb-lg-0">
                <div class="text-center mb-4">
                  <div class="position-relative d-inline-block">
                    <?php echo $template_data['user_picture']; ?>
                  </div>
                </div>
                <div class="text-center">
                  <h3 class="h5 font-weight-bold"><?php echo htmlspecialchars($template_data['user_name']); ?></h3>
                  <p class="text-muted small">Student</p>
                  <p class="text-muted small"><?php echo htmlspecialchars($template_data['user_grade']); ?></p>
                </div>
              </div>
              
              <!-- Right Column - Form Fields -->
              <div id="profile-details-section" class="col-lg-8">
                <div class="row mb-3">
                  <div class="col-md-6">
                    <label for="first-name" class="form-label"><?php echo get_string('firstname', 'moodle'); ?></label>
                    <input type="text" id="first-name" name="firstname" class="form-control" value="<?php echo htmlspecialchars($template_data['user_firstname']); ?>" readonly>
                  </div>
                  
                  <div class="col-md-6">
                    <label for="last-name" class="form-label"><?php echo get_string('lastname', 'moodle'); ?></label>
                    <input type="text" id="last-name" name="lastname" class="form-control" value="<?php echo htmlspecialchars($template_data['user_lastname']); ?>" readonly>
                  </div>
                </div>
                
                <div class="mb-3">
                  <label for="email" class="form-label"><?php echo get_string('email', 'moodle'); ?></label>
                  <input type="email" id="email" name="email" class="form-control" value="<?php echo htmlspecialchars($template_data['user_email']); ?>" readonly>
                  <small class="form-text text-muted">Email used for notifications</small>
                </div>
              </div>
            </div>
          </div>
        </div>
      
        <!-- Course Details Section -->
        <div id="course-details-section" class="card shadow-sm mt-4">
          <div class="card-header">
            <h2 class="h5 mb-0 font-weight-bold">Course details</h2>
          </div>
          <div class="card-body">
            <?php if (!empty($template_data['course_details'])): ?>
              <div class="table-responsive">
                <table class="table table-hover">
                  <thead>
                    <tr>
                      <th>Course name</th>
                      <th><?php echo get_string('category', 'moodle'); ?></th>
                      <th><?php echo get_string('action', 'moodle'); ?></th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($template_data['course_details'] as $course): ?>
                      <tr>
                        <td>
                          <strong><?php echo htmlspecialchars($course['fullname']); ?></strong>
                          <?php if (!empty($course['summary'])): ?>
                            <br><small class="text-muted"><?php echo htmlspecialchars(substr($course['summary'], 0, 100)) . (strlen($course['summary']) > 100 ? '...' : ''); ?></small>
                          <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($course['category']); ?></td>
                        <td>
                          <a href="<?php echo htmlspecialchars($course['url']); ?>" class="btn btn-sm btn-primary">
                            View course
                          </a>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php else: ?>
              <p class="text-muted text-center py-4"><?php echo get_string('nocourses', 'moodle'); ?></p>
            <?php endif; ?>
          </div>
        </div>
      
        <!-- Additional Settings Sections -->
        <div id="additional-settings" class="row mt-4">
          <!-- Login Activity -->
          <div id="login-activity" class="col-md-6 mb-4">
            <div class="card shadow-sm">
              <div class="card-header d-flex justify-content-between align-items-center">
                <h2 class="h5 mb-0 font-weight-bold">Login Activity</h2>
              </div>
              <div class="card-body">
                <div class="mb-3">
                  <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="text-muted small">First Access :</span>
                    <span class="font-weight-bold"><?php echo htmlspecialchars($template_data['first_access']); ?></span>
                  </div>
                </div>
                
                <div class="mb-3">
                  <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="text-muted small">Last Access :</span>
                    <span class="font-weight-bold"><?php echo htmlspecialchars($template_data['last_access']); ?></span>
                  </div>
                </div>
              </div>
            </div>
          </div>
          
          <!-- Reports -->
          <div id="reports" class="col-md-6 mb-4">
            <div class="card shadow-sm">
              <div class="card-header d-flex justify-content-between align-items-center">
                <h2 class="h5 mb-0 font-weight-bold">Reports</h2>
              </div>
              <div class="card-body">
                <div class="list-group list-group-flush">
                  <a href="<?php echo htmlspecialchars($template_data['browser_sessions_url']); ?>" class="list-group-item list-group-item-action border-0 px-0 py-2">
                    Browser Sessions
                  </a>
                  <a href="<?php echo htmlspecialchars($template_data['grades_url']); ?>" class="list-group-item list-group-item-action border-0 px-0 py-2">
                    Grades Overview
                  </a>
                </div>
              </div>
            </div>
          </div>
        </div>
            
            <div class="alert alert-info mt-4">
              <div class="d-flex align-items-start">
                <?php echo $OUTPUT->pix_icon('i/info', '', 'moodle', array('class' => 'mr-3 mt-1')); ?>
                <div>
                  <h3 class="h6 font-weight-bold mb-1"><?php echo get_string('academicgrowthopportunity', 'theme_remui_kids'); ?></h3>
                  <p class="small mb-2"><?php echo get_string('continuelearningjourney', 'theme_remui_kids'); ?></p>
                  <a href="<?php echo $template_data['courses_url']; ?>" class="btn btn-sm btn-primary">
                    <?php echo get_string('explorecourses', 'theme_remui_kids'); ?>
                    <?php echo $OUTPUT->pix_icon('t/right', '', 'moodle'); ?>
                  </a>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>
</div>


<script>
// Wait for Moodle AMD loader to be available
if (typeof require !== 'undefined') {
    require(['jquery', 'core/notification'], function($, Notification) {
        // Initialize enhanced sidebar
        $(document).ready(function() {
            var enhancedSidebar = $('.enhanced-sidebar');
        if (enhancedSidebar.length) {
            $('body').addClass('has-student-sidebar has-enhanced-sidebar');
        }

        // Handle sidebar navigation - set active state
        var currentUrl = window.location.href;
        $('.student-sidebar .nav-link').each(function() {
            if ($(this).attr('href') === currentUrl) {
                $(this).addClass('active');
            }
        });

        // Mobile sidebar toggle
        $('#sidebar-toggle').on('click', function() {
            enhancedSidebar.toggleClass('show');
        });

        // Bootstrap tabs are handled automatically by Bootstrap JS
        
        // Form submission with loading state
        $('#profile-form').on('submit', function(e) {
            var saveBtn = $('#save-profile-btn');
            var originalHtml = saveBtn.html();
            
            saveBtn.prop('disabled', true);
            saveBtn.html('<span class="spinner-border spinner-border-sm mr-2" role="status" aria-hidden="true"></span><?php echo get_string('saving', 'moodle'); ?>...');
        });

        // Animate progress bars on page load
        setTimeout(function() {
            $('.progress-bar').each(function() {
                var $bar = $(this);
                var width = $bar.attr('style');
                if (width) {
                    var widthValue = width.match(/width:\s*(\d+)%/);
                    if (widthValue) {
                        $bar.css('width', '0%');
                        setTimeout(function() {
                            $bar.css('transition', 'width 1s ease-in-out');
                            $bar.attr('style', width);
                        }, 300);
                    }
                }
            });
        }, 500);
        });
    });
} else {
    // Fallback if AMD loader is not available
    document.addEventListener('DOMContentLoaded', function() {
        // Basic functionality without AMD dependencies
        var enhancedSidebar = document.querySelector('.enhanced-sidebar');
        if (enhancedSidebar) {
            document.body.classList.add('has-student-sidebar', 'has-enhanced-sidebar');
        }
    });
}
</script>
<?php
echo $OUTPUT->footer();
?>