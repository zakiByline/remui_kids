<?php
// Analytics page for detailed course analytics

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/lib/moodlelib.php');
require_once($CFG->dirroot . '/lib/weblib.php');
require_once($CFG->dirroot . '/theme/remui_kids/lib.php');

require_login();

$courseid = optional_param('id', 0, PARAM_INT);
if (!$courseid) {
    redirect(new moodle_url('/my/'));
}

$course = get_course($courseid);
$context = context_course::instance($course->id);

if (!has_capability('moodle/course:view', $context)) {
    redirect(new moodle_url('/my/'));
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/theme/remui_kids/pages/analytics.php', ['id' => $courseid]));
$PAGE->set_pagelayout('standard');
$PAGE->set_title('Course Analytics - ' . $course->fullname);
$PAGE->set_heading('Course Analytics - ' . $course->fullname);

// Fetch performance data
$performance_data = theme_remui_kids_get_class_performance_data($courseid);

echo $OUTPUT->header();

// Add Font Awesome CSS
$PAGE->requires->js_init_code('
    var link = document.createElement("link");
    link.rel = "stylesheet";
    link.href = "https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css";
    document.head.appendChild(link);
');

echo '<div style="max-width: 1200px; margin: 0 auto; padding: 24px; font-family: \'Segoe UI\', Tahoma, Geneva, Verdana, sans-serif;">';

// Header
echo html_writer::start_div('', ['style' => 'background: linear-gradient(135deg, #8b5cf6, #7c3aed); color: white; padding: 20px 24px; margin-bottom: 24px; border-radius: 12px; display: flex; justify-content: space-between; align-items: center;']);
echo html_writer::start_div('', ['style' => 'display: flex; align-items: center; gap: 16px;']);
echo html_writer::tag('i', '', ['class' => 'fas fa-chart-line', 'style' => 'font-size: 24px; color: white;']);
echo html_writer::start_div('', ['style' => 'display: flex; flex-direction: column;']);
echo html_writer::tag('h1', 'Course Analytics', ['style' => 'margin: 0; font-size: 24px; font-weight: 700;']);
echo html_writer::tag('p', $course->fullname, ['style' => 'margin: 0; font-size: 14px; opacity: 0.9;']);
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::tag('button', '<i class="fas fa-arrow-left"></i> Back to Dashboard', ['style' => 'padding: 8px 16px; background: rgba(255,255,255,0.2); color: white; border: 1px solid rgba(255,255,255,0.3); border-radius: 6px; cursor: pointer;', 'onclick' => 'window.close()']);
echo html_writer::end_div();

// Analytics Grid
echo html_writer::start_div('', ['style' => 'display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 24px;']);

// Activity Trends
echo html_writer::start_div('', ['style' => 'background: white; border-radius: 16px; padding: 24px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);']);
echo html_writer::tag('h3', 'Activity Trends (Last 7 Days)', ['style' => 'margin: 0 0 20px 0; font-size: 18px; font-weight: 600; color: #1f2937;']);

if (!empty($performance_data['activity_trends'])) {
    foreach ($performance_data['activity_trends'] as $trend) {
        echo html_writer::start_div('', ['style' => 'display: flex; justify-content: space-between; align-items: center; padding: 12px; background: #f9fafb; border-radius: 8px; margin-bottom: 8px;']);
        echo html_writer::tag('span', $trend->activity_date, ['style' => 'font-size: 14px; font-weight: 600; color: #374151;']);
        echo html_writer::tag('span', $trend->activity_count . ' activities', ['style' => 'font-size: 14px; color: #6b7280;']);
        echo html_writer::end_div();
    }
} else {
    echo html_writer::tag('p', 'No activity data available', ['style' => 'text-align: center; color: #6b7280; font-style: italic;']);
}
echo html_writer::end_div();

// Engagement Metrics
echo html_writer::start_div('', ['style' => 'background: white; border-radius: 16px; padding: 24px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);']);
echo html_writer::tag('h3', 'Top Engaged Students', ['style' => 'margin: 0 0 20px 0; font-size: 18px; font-weight: 600; color: #1f2937;']);

if (!empty($performance_data['engagement_metrics'])) {
    foreach (array_slice($performance_data['engagement_metrics'], 0, 5) as $student) {
        echo html_writer::start_div('', ['style' => 'display: flex; justify-content: space-between; align-items: center; padding: 12px; background: #f9fafb; border-radius: 8px; margin-bottom: 8px;']);
        echo html_writer::start_div('', ['style' => 'flex: 1;']);
        echo html_writer::tag('h4', $student->firstname . ' ' . $student->lastname, ['style' => 'margin: 0 0 4px 0; font-size: 14px; font-weight: 600; color: #1f2937;']);
        echo html_writer::tag('p', 'Log entries: ' . $student->log_entries . ' | Completed: ' . $student->completed_modules, ['style' => 'margin: 0; font-size: 12px; color: #6b7280;']);
        echo html_writer::end_div();
        echo html_writer::tag('span', 'High', ['style' => 'padding: 4px 8px; background: #10b981; color: white; border-radius: 4px; font-size: 12px;']);
        echo html_writer::end_div();
    }
} else {
    echo html_writer::tag('p', 'No engagement data available', ['style' => 'text-align: center; color: #6b7280; font-style: italic;']);
}
echo html_writer::end_div();

echo html_writer::end_div(); // End analytics grid

// Detailed Statistics
echo html_writer::start_div('', ['style' => 'background: white; border-radius: 16px; padding: 24px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);']);
echo html_writer::tag('h3', 'Detailed Course Statistics', ['style' => 'margin: 0 0 20px 0; font-size: 18px; font-weight: 600; color: #1f2937;']);

echo html_writer::start_div('', ['style' => 'display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px;']);
echo html_writer::start_div('', ['style' => 'text-align: center; padding: 16px; background: #f9fafb; border-radius: 8px;']);
echo html_writer::tag('h4', $performance_data['student_count'], ['style' => 'margin: 0 0 4px 0; font-size: 24px; font-weight: 700; color: #8b5cf6;']);
echo html_writer::tag('p', 'Total Students', ['style' => 'margin: 0; font-size: 14px; color: #6b7280;']);
echo html_writer::end_div();

echo html_writer::start_div('', ['style' => 'text-align: center; padding: 16px; background: #f9fafb; border-radius: 8px;']);
echo html_writer::tag('h4', $performance_data['course_stats']->total_activities, ['style' => 'margin: 0 0 4px 0; font-size: 24px; font-weight: 700; color: #3b82f6;']);
echo html_writer::tag('p', 'Total Activities', ['style' => 'margin: 0; font-size: 14px; color: #6b7280;']);
echo html_writer::end_div();

echo html_writer::start_div('', ['style' => 'text-align: center; padding: 16px; background: #f9fafb; border-radius: 8px;']);
echo html_writer::tag('h4', $performance_data['course_stats']->completed_activities, ['style' => 'margin: 0 0 4px 0; font-size: 24px; font-weight: 700; color: #10b981;']);
echo html_writer::tag('p', 'Completed Activities', ['style' => 'margin: 0; font-size: 14px; color: #6b7280;']);
echo html_writer::end_div();

echo html_writer::start_div('', ['style' => 'text-align: center; padding: 16px; background: #f9fafb; border-radius: 8px;']);
echo html_writer::tag('h4', $performance_data['avg_grade'] . '%', ['style' => 'margin: 0 0 4px 0; font-size: 24px; font-weight: 700; color: #f59e0b;']);
echo html_writer::tag('p', 'Average Grade', ['style' => 'margin: 0; font-size: 14px; color: #6b7280;']);
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::end_div(); // End detailed statistics

echo '</div>'; // End main container

echo $OUTPUT->footer();
?>








