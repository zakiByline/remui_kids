<?php
/**
 * Elementary Dashboard Demo Page
 * 
 * This is a demo page to showcase the child-friendly elementary dashboard
 * with colorful summary cards and proper scrollbar positioning.
 * 
 * @package    theme_remui_kids
 * @copyright  2025 KodeIt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lang_init.php');

require_login();

global $USER, $PAGE, $OUTPUT;

// Set page context
$PAGE->set_context(context_system::instance());
$PAGE->set_url('/theme/remui_kids/elementary_dashboard_demo.php');
$PAGE->set_title('Elementary Dashboard Demo');
$PAGE->set_heading('Elementary Dashboard Demo');
$PAGE->set_pagelayout('standard');

// Add custom CSS for the demo
$PAGE->requires->css('/theme/remui_kids/scss/elementary_dashboard.scss');
$PAGE->requires->css('/theme/remui_kids/scss/sidebar_scrollbar.scss');

// Mock data for demo
$demo_data = [
    'student_name' => $USER->firstname ?: 'Ella',
    'courses_count' => 5,
    'lessons_completed' => 2,
    'activities_done' => 10,
    'overall_progress' => 5,
    'grade_average' => 73,
    'lessons_average' => 15,
    'activities_percentage' => 0,
    'progress_average' => 58,
    'courses' => [
        [
            'id' => 1,
            'fullname' => 'Math Adventures',
            'summary' => 'Fun with numbers and counting!',
            'progress' => 75,
            'grade' => '85%',
            'course_level' => 'Grade 1',
            'status_class' => 'in-progress',
            'status_icon' => 'fa-clock',
            'course_image' => null
        ],
        [
            'id' => 2,
            'fullname' => 'Reading Rainbow',
            'summary' => 'Discover amazing stories and learn to read!',
            'progress' => 60,
            'grade' => '78%',
            'course_level' => 'Grade 1',
            'status_class' => 'in-progress',
            'status_icon' => 'fa-clock',
            'course_image' => null
        ],
        [
            'id' => 3,
            'fullname' => 'Science Explorers',
            'summary' => 'Explore the world around us!',
            'progress' => 90,
            'grade' => '92%',
            'course_level' => 'Grade 1',
            'status_class' => 'completed',
            'status_icon' => 'fa-check',
            'course_image' => null
        ]
    ],
    'upcoming_events' => [
        [
            'day' => '15',
            'month' => 'Jan',
            'title' => 'Math Quiz',
            'description' => 'Addition and Subtraction practice',
            'time' => '10:00 AM'
        ],
        [
            'day' => '18',
            'month' => 'Jan',
            'title' => 'Science Project',
            'description' => 'Show and tell about animals',
            'time' => '2:00 PM'
        ]
    ],
    // Sidebar context
    'is_dashboard_page' => true,
    'dashboardurl' => '/my/',
    'elementary_mycoursesurl' => '/theme/remui_kids/elementary_my_course.php',
    'lessonsurl' => '/my/',
    'activitiesurl' => '/my/',
    'achievementsurl' => '/my/',
    'competenciesurl' => '/my/',
    'scheduleurl' => '/my/',
    'myreportsurl' => '/my/',
    'communityurl' => '/my/',
    'settingsurl' => '/user/preferences.php',
    'profileurl' => '/user/profile.php',
    'logouturl' => '/login/logout.php',
    'mycoursesurl' => '/theme/remui_kids/elementary_my_course.php'
];

echo $OUTPUT->header();
?>

<style>
/* Include the elementary dashboard styles directly */
<?php include(__DIR__ . '/scss/elementary_dashboard.scss'); ?>

/* Additional demo styles */
body {
    margin: 0;
    padding: 0;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
}

.demo-notice {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 15px;
    text-align: center;
    margin-bottom: 0;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    z-index: 2000;
    font-weight: 600;
}

.demo-content {
    margin-top: 60px; /* Account for demo notice */
}
</style>

<div class="demo-notice">
    🎨 Elementary Dashboard Demo - Child-Friendly Interface for Grades 1-3
</div>

<div class="demo-content">
    <!-- Render sidebar -->
    <?php echo $OUTPUT->render_from_template('theme_remui_kids/dashboard/elementary_sidebar_new', $demo_data); ?>

    <!-- Render main dashboard content -->
    <div class="elementary-dashboard">
        <?php echo $OUTPUT->render_from_template('theme_remui_kids/dashboard/elementary_dashboard', $demo_data); ?>
    </div>
</div>

<script>
// Add some interactive demo features
document.addEventListener('DOMContentLoaded', function() {
    // Animate the stat cards on load
    const statCards = document.querySelectorAll('.elementary-stat-card');
    statCards.forEach((card, index) => {
        setTimeout(() => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(20px)';
            card.style.transition = 'all 0.6s ease';
            
            setTimeout(() => {
                card.style.opacity = '1';
                card.style.transform = 'translateY(0)';
            }, 100);
        }, index * 200);
    });
    
    // Add click handlers for demo
    document.querySelectorAll('.elementary-stat-card').forEach(card => {
        card.addEventListener('click', function() {
            this.style.transform = 'scale(0.95)';
            setTimeout(() => {
                this.style.transform = 'scale(1)';
            }, 150);
        });
    });
});
</script>

<?php
echo $OUTPUT->footer();
?>