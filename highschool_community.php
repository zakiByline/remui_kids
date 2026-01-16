<?php
/**
 * High School Community Page
 * Interactive community platform for high school students
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

// Set page context
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/highschool_community.php');
$PAGE->set_title('Community');

$PAGE->set_pagelayout('base');
$PAGE->add_body_class('custom-dashboard-page has-student-sidebar');
$PAGE->requires->css('/theme/remui_kids/style/highschool_reports.css');

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
$user_grade = 'High School'; // Default grade for testing
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
            $is_highschool = true;
            break;
        }
    }
}

// Get community posts data
$posts_data = array();
$users_data = array();

try {
    // Get recent forum posts as community posts
    $posts = $DB->get_records_sql("
        SELECT fp.*, 
               fd.name as forum_name,
               fd.course as course_id,
               c.fullname as course_name,
               c.shortname as course_shortname,
               u.firstname, u.lastname, u.email, u.picture, u.imagealt
        FROM {forum_posts} fp
        LEFT JOIN {forum_discussions} fd ON fp.discussion = fd.id
        LEFT JOIN {forum} f ON fd.forum = f.id
        LEFT JOIN {course} c ON f.course = c.id
        LEFT JOIN {user} u ON fp.userid = u.id
        WHERE fp.parent = 0 AND fp.deleted = 0
        ORDER BY fp.created DESC
        LIMIT 20
    ");
    
    foreach ($posts as $post) {
        // Get post author info
        $author = $DB->get_record('user', array('id' => $post->userid));
        $author_picture = $OUTPUT->user_picture($author, array('size' => 50, 'class' => 'author-profile-img'));
        
        // Get post likes (using forum ratings if available)
        $likes_count = 0;
        try {
            $likes_count = $DB->count_records('rating', array(
                'contextid' => $context->id,
                'itemid' => $post->id,
                'rating' => 1
            ));
        } catch (Exception $e) {
            // If ratings table doesn't exist, use random likes for demo
            $likes_count = rand(0, 15);
        }
        
        // Get comments count
        $comments_count = $DB->count_records('forum_posts', array(
            'discussion' => $post->discussion,
            'parent' => $post->id,
            'deleted' => 0
        ));
        
        // Determine post category
        $category = 'General';
        $post_content_lower = strtolower($post->subject . ' ' . $post->message);
        if (strpos($post_content_lower, 'homework') !== false || strpos($post_content_lower, 'assignment') !== false) {
            $category = 'Homework Help';
        } elseif (strpos($post_content_lower, 'study') !== false || strpos($post_content_lower, 'exam') !== false) {
            $category = 'Study Group';
        } elseif (strpos($post_content_lower, 'project') !== false || strpos($post_content_lower, 'collaboration') !== false) {
            $category = 'Projects';
        } elseif (strpos($post_content_lower, 'question') !== false || strpos($post_content_lower, 'help') !== false) {
            $category = 'Q&A';
        } elseif (strpos($post_content_lower, 'announcement') !== false) {
            $category = 'Announcements';
        }
        
        $posts_data[] = array(
            'id' => $post->id,
            'title' => $post->subject,
            'content' => format_text($post->message, FORMAT_HTML),
            'content_preview' => substr(strip_tags($post->message), 0, 200) . '...',
            'author_name' => fullname($author),
            'author_picture' => $author_picture,
            'author_id' => $post->userid,
            'course_name' => $post->course_name ?: 'General Discussion',
            'course_shortname' => $post->course_shortname ?: 'GEN',
            'category' => $category,
            'created_time' => $post->created,
            'created_formatted' => date('M j, Y g:i A', $post->created),
            'time_ago' => $this->time_ago($post->created),
            'likes_count' => $likes_count,
            'comments_count' => $comments_count,
            'is_liked' => false, // TODO: Check if current user liked this post
            'post_url' => new moodle_url('/mod/forum/discuss.php', array('d' => $post->discussion))
        );
    }
    
    // Get active users data
    $active_users = $DB->get_records_sql("
        SELECT u.id, u.firstname, u.lastname, u.email, u.picture, u.imagealt,
               COUNT(fp.id) as post_count,
               MAX(fp.created) as last_post_time
        FROM {user} u
        LEFT JOIN {forum_posts} fp ON u.id = fp.userid
        WHERE u.deleted = 0 AND u.suspended = 0
        GROUP BY u.id, u.firstname, u.lastname, u.email, u.picture, u.imagealt
        HAVING post_count > 0
        ORDER BY post_count DESC, last_post_time DESC
        LIMIT 10
    ");
    
    foreach ($active_users as $user) {
        $user_picture = $OUTPUT->user_picture($user, array('size' => 40, 'class' => 'user-profile-img'));
        $users_data[] = array(
            'id' => $user->id,
            'name' => fullname($user),
            'picture' => $user_picture,
            'post_count' => $user->post_count,
            'last_active' => $user->last_post_time,
            'last_active_formatted' => date('M j', $user->last_post_time),
            'profile_url' => new moodle_url('/user/profile.php', array('id' => $user->id))
        );
    }
    
} catch (Exception $e) {
    error_log("Community data fetch error: " . $e->getMessage());
}

// Helper function to get time ago
function time_ago($timestamp) {
    $time_difference = time() - $timestamp;
    
    if ($time_difference < 60) {
        return 'just now';
    } elseif ($time_difference < 3600) {
        $minutes = floor($time_difference / 60);
        return $minutes . ' minute' . ($minutes > 1 ? 's' : '') . ' ago';
    } elseif ($time_difference < 86400) {
        $hours = floor($time_difference / 3600);
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    } elseif ($time_difference < 2592000) {
        $days = floor($time_difference / 86400);
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    } else {
        return date('M j, Y', $timestamp);
    }
}

// Calculate statistics
$total_posts = count($posts_data);
$total_users = count($users_data);
$today_posts = 0;
$today_start = strtotime('today');

foreach ($posts_data as $post) {
    if ($post['created_time'] >= $today_start) {
        $today_posts++;
    }
}

$sidebar_context = remui_kids_build_highschool_sidebar_context('community', $USER);

// Prepare template data
$template_data = array_merge($sidebar_context, array(
    'user_grade' => $user_grade,
    'user_name' => fullname($USER),
    'user_firstname' => $USER->firstname,
    'user_lastname' => $USER->lastname,
    'user_email' => $USER->email,
    'user_picture' => $OUTPUT->user_picture($USER, array('size' => 50, 'class' => 'current-user-profile-img')),
    'posts' => $posts_data,
    'active_users' => $users_data,
    'total_posts' => $total_posts,
    'total_users' => $total_users,
    'today_posts' => $today_posts,
    'current_user' => [
        'name' => fullname($USER),
        'email' => $USER->email,
        'picture' => $OUTPUT->user_picture($USER, ['size' => 50, 'class' => 'current-user-profile-img']),
        'cohort' => $user_grade ?? ''
    ],
    'settingsurl' => (new moodle_url('/user/preferences.php'))->out(),
    'dashboard_url' => $sidebar_context['dashboardurl'],
    'current_url' => $PAGE->url->out(),
    'courses_url' => $sidebar_context['mycoursesurl'],
    'assignments_url' => $sidebar_context['assignmentsurl'],
    'grades_url' => $sidebar_context['gradesurl'],
    'calendar_url' => $sidebar_context['calendarurl'],
    'messages_url' => $sidebar_context['messagesurl'],
    'profile_url' => $sidebar_context['profileurl'],
    'logout_url' => $sidebar_context['logouturl'],
    'is_highschool' => true
));

// Output page header with Moodle navigation
echo $OUTPUT->header();
// Include the community page template
echo $OUTPUT->render_from_template('theme_remui_kids/highschool_community_page', $template_data);
echo $OUTPUT->footer();
?>