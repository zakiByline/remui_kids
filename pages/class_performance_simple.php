<?php
// Simple Class Performance Overview page
require_once(__DIR__ . '/../../../config.php');
require_login();

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/theme/remui_kids/pages/class_performance_simple.php'));
$PAGE->set_title('Class Performance Overview');
$PAGE->set_heading('Class Performance Overview');

echo $OUTPUT->header();

// Simple dashboard layout
echo '<div style="min-height: 100vh; background: #f8fafc; padding: 24px; font-family: Arial, sans-serif;">';
echo '<div style="max-width: 1200px; margin: 0 auto;">';

// Header
echo '<div style="background: linear-gradient(135deg, #8b5cf6, #7c3aed); color: white; padding: 20px; border-radius: 12px; margin-bottom: 24px;">';
echo '<p style="margin: 5px 0 0 0; opacity: 0.9;">Student Performance Dashboard</p>';
echo '</div>';

// Summary Cards
echo '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 24px;">';
echo '<div style="background: white; padding: 20px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); text-align: center;">';
echo '<h3 style="margin: 0 0 10px 0; color: #8b5cf6;">3,457</h3>';
echo '<p style="margin: 0; color: #6b7280;">Student Count</p>';
echo '</div>';
echo '<div style="background: white; padding: 20px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); text-align: center;">';
echo '<h3 style="margin: 0 0 10px 0; color: #10b981;">83.7%</h3>';
echo '<p style="margin: 0; color: #6b7280;">Student Attendance</p>';
echo '</div>';
echo '<div style="background: white; padding: 20px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); text-align: center;">';
echo '<h3 style="margin: 0 0 10px 0; color: #f59e0b;">77.7%</h3>';
echo '<p style="margin: 0; color: #6b7280;">Exam Average</p>';
echo '</div>';
echo '</div>';

// Main Content
echo '<div style="display: grid; grid-template-columns: 2fr 1fr; gap: 24px;">';

// Left Column
echo '<div>';
echo '<div style="background: white; padding: 24px; border-radius: 16px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); margin-bottom: 24px;">';
echo '<h3 style="margin: 0 0 20px 0; color: #1f2937;">Student Count by Grade</h3>';
echo '<div style="display: flex; flex-direction: column; gap: 12px;">';
$grades = ['Grade 1: 457 students (13.2%)', 'Grade 2: 769 students (22.2%)', 'Grade 3: 1000 students (28.9%)', 'Grade 4: 553 students (15.9%)', 'Grade 5: 678 students (19.6%)'];
foreach ($grades as $grade) {
    echo '<div style="padding: 12px; background: #f9fafb; border-radius: 8px;">' . $grade . '</div>';
}
echo '</div>';
echo '</div>';

echo '<div style="background: white; padding: 24px; border-radius: 16px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">';
echo '<h3 style="margin: 0 0 20px 0; color: #1f2937;">Examination Results</h3>';
echo '<div style="display: flex; flex-direction: column; gap: 12px;">';
$subjects = ['Maths: 85% Pass Rate', 'English: 75% Pass Rate', 'Science: 80% Pass Rate', 'Arts: 90% Pass Rate'];
foreach ($subjects as $subject) {
    echo '<div style="padding: 12px; background: #f9fafb; border-radius: 8px;">' . $subject . '</div>';
}
echo '</div>';
echo '</div>';
echo '</div>';

// Right Column
echo '<div>';
echo '<div style="background: white; padding: 24px; border-radius: 16px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); margin-bottom: 24px;">';
echo '<h3 style="margin: 0 0 20px 0; color: #1f2937;">Top Performers</h3>';
echo '<div style="display: flex; flex-direction: column; gap: 12px;">';
$performers = ['Kinara Zuri - Grade 3 (87.9%)', 'Lea Jabulani - Grade 4 (89.3%)', 'Corny Niang - Grade 5 (79.3%)'];
foreach ($performers as $performer) {
    echo '<div style="padding: 12px; background: #f9fafb; border-radius: 8px;">' . $performer . '</div>';
}
echo '</div>';
echo '</div>';

echo '<div style="background: white; padding: 24px; border-radius: 16px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">';
echo '<h3 style="margin: 0 0 20px 0; color: #1f2937;">Student Details</h3>';
echo '<div style="display: flex; flex-direction: column; gap: 12px;">';
$students = ['Luka Magic - Male (73.7%)', 'Bianca Shangwe - Female (63.7%)', 'Alpha Kenya - Male (83.1%)'];
foreach ($students as $student) {
    echo '<div style="padding: 12px; background: #f9fafb; border-radius: 8px;">' . $student . '</div>';
}
echo '</div>';
echo '</div>';
echo '</div>';

echo '</div>';
echo '</div>';
echo '</div>';

echo $OUTPUT->footer();
?>








