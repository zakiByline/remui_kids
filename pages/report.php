<?php
// Report page for generating course reports

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
$PAGE->set_url(new moodle_url('/theme/remui_kids/pages/report.php', ['id' => $courseid]));
$PAGE->set_pagelayout('standard');
$PAGE->set_title('Course Report - ' . $course->fullname);
$PAGE->set_heading('Course Report - ' . $course->fullname);

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
echo html_writer::start_div('', ['style' => 'background: linear-gradient(135deg, #ef4444, #dc2626); color: white; padding: 20px 24px; margin-bottom: 24px; border-radius: 12px; display: flex; justify-content: space-between; align-items: center;']);
echo html_writer::start_div('', ['style' => 'display: flex; align-items: center; gap: 16px;']);
echo html_writer::tag('i', '', ['class' => 'fas fa-file-pdf', 'style' => 'font-size: 24px; color: white;']);
echo html_writer::start_div('', ['style' => 'display: flex; flex-direction: column;']);
echo html_writer::tag('h1', 'Course Report', ['style' => 'margin: 0; font-size: 24px; font-weight: 700;']);
echo html_writer::tag('p', $course->fullname . ' - ' . date('F Y'), ['style' => 'margin: 0; font-size: 14px; opacity: 0.9;']);
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::start_div('', ['style' => 'display: flex; gap: 8px;']);
echo html_writer::tag('button', '<i class="fas fa-download"></i> Download PDF', ['style' => 'padding: 8px 16px; background: rgba(255,255,255,0.2); color: white; border: 1px solid rgba(255,255,255,0.3); border-radius: 6px; cursor: pointer;', 'onclick' => 'downloadPDF()']);
echo html_writer::tag('button', '<i class="fas fa-arrow-left"></i> Back', ['style' => 'padding: 8px 16px; background: rgba(255,255,255,0.2); color: white; border: 1px solid rgba(255,255,255,0.3); border-radius: 6px; cursor: pointer;', 'onclick' => 'window.close()']);
echo html_writer::end_div();
echo html_writer::end_div();

// Report Content
echo html_writer::start_div('', ['style' => 'background: white; border-radius: 16px; padding: 24px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); margin-bottom: 24px;']);

// Executive Summary
echo html_writer::tag('h2', 'Executive Summary', ['style' => 'margin: 0 0 20px 0; font-size: 20px; font-weight: 600; color: #1f2937; border-bottom: 2px solid #e5e7eb; padding-bottom: 8px;']);

echo html_writer::start_div('', ['style' => 'display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 24px;']);

echo html_writer::start_div('', ['style' => 'padding: 16px; background: #f8fafc; border-radius: 8px; border-left: 4px solid #8b5cf6;']);
echo html_writer::tag('h3', 'Student Enrollment', ['style' => 'margin: 0 0 8px 0; font-size: 16px; font-weight: 600; color: #1f2937;']);
echo html_writer::tag('p', 'Total enrolled students: <strong>' . $performance_data['student_count'] . '</strong>', ['style' => 'margin: 0; font-size: 14px; color: #374151;']);
echo html_writer::tag('p', 'Active students (last 30 days): <strong>' . round(($performance_data['attendance_rate'] / 100) * $performance_data['student_count']) . '</strong>', ['style' => 'margin: 4px 0 0 0; font-size: 14px; color: #374151;']);
echo html_writer::end_div();

echo html_writer::start_div('', ['style' => 'padding: 16px; background: #f8fafc; border-radius: 8px; border-left: 4px solid #10b981;']);
echo html_writer::tag('h3', 'Course Activities', ['style' => 'margin: 0 0 8px 0; font-size: 16px; font-weight: 600; color: #1f2937;']);
echo html_writer::tag('p', 'Total activities: <strong>' . $performance_data['course_stats']->total_activities . '</strong>', ['style' => 'margin: 0; font-size: 14px; color: #374151;']);
echo html_writer::tag('p', 'Completed activities: <strong>' . $performance_data['course_stats']->completed_activities . '</strong>', ['style' => 'margin: 4px 0 0 0; font-size: 14px; color: #374151;']);
echo html_writer::end_div();

echo html_writer::start_div('', ['style' => 'padding: 16px; background: #f8fafc; border-radius: 8px; border-left: 4px solid #f59e0b;']);
echo html_writer::tag('h3', 'Academic Performance', ['style' => 'margin: 0 0 8px 0; font-size: 16px; font-weight: 600; color: #1f2937;']);
echo html_writer::tag('p', 'Average grade: <strong>' . $performance_data['avg_grade'] . '%</strong>', ['style' => 'margin: 0; font-size: 14px; color: #374151;']);
echo html_writer::tag('p', 'Students with grades: <strong>' . $performance_data['course_stats']->students_with_grades . '</strong>', ['style' => 'margin: 4px 0 0 0; font-size: 14px; color: #374151;']);
echo html_writer::end_div();

echo html_writer::end_div(); // End executive summary grid

// Detailed Analysis
echo html_writer::tag('h2', 'Detailed Analysis', ['style' => 'margin: 24px 0 20px 0; font-size: 20px; font-weight: 600; color: #1f2937; border-bottom: 2px solid #e5e7eb; padding-bottom: 8px;']);

// Assignment Performance
if (!empty($performance_data['assignment_stats'])) {
    echo html_writer::tag('h3', 'Assignment Performance', ['style' => 'margin: 20px 0 12px 0; font-size: 18px; font-weight: 600; color: #1f2937;']);
    echo html_writer::start_div('', ['style' => 'overflow-x: auto;']);
    echo html_writer::start_tag('table', ['style' => 'width: 100%; border-collapse: collapse;']);
    echo html_writer::start_tag('thead');
    echo html_writer::start_tag('tr', ['style' => 'background: #f9fafb;']);
    echo html_writer::tag('th', 'Assignment', ['style' => 'padding: 12px; text-align: left; border: 1px solid #e5e7eb; font-weight: 600;']);
    echo html_writer::tag('th', 'Submissions', ['style' => 'padding: 12px; text-align: center; border: 1px solid #e5e7eb; font-weight: 600;']);
    echo html_writer::tag('th', 'Average Grade', ['style' => 'padding: 12px; text-align: center; border: 1px solid #e5e7eb; font-weight: 600;']);
    echo html_writer::tag('th', 'Max Grade', ['style' => 'padding: 12px; text-align: center; border: 1px solid #e5e7eb; font-weight: 600;']);
    echo html_writer::end_tag('tr');
    echo html_writer::end_tag('thead');
    echo html_writer::start_tag('tbody');
    
    foreach ($performance_data['assignment_stats'] as $assignment) {
        echo html_writer::start_tag('tr');
        echo html_writer::tag('td', $assignment->name, ['style' => 'padding: 12px; border: 1px solid #e5e7eb;']);
        echo html_writer::tag('td', $assignment->submissions, ['style' => 'padding: 12px; border: 1px solid #e5e7eb; text-align: center;']);
        echo html_writer::tag('td', round($assignment->avg_grade, 1), ['style' => 'padding: 12px; border: 1px solid #e5e7eb; text-align: center;']);
        echo html_writer::tag('td', round($assignment->max_grade, 1), ['style' => 'padding: 12px; border: 1px solid #e5e7eb; text-align: center;']);
        echo html_writer::end_tag('tr');
    }
    
    echo html_writer::end_tag('tbody');
    echo html_writer::end_tag('table');
    echo html_writer::end_div();
}

// Quiz Performance
if (!empty($performance_data['quiz_stats'])) {
    echo html_writer::tag('h3', 'Quiz Performance', ['style' => 'margin: 20px 0 12px 0; font-size: 18px; font-weight: 600; color: #1f2937;']);
    echo html_writer::start_div('', ['style' => 'overflow-x: auto;']);
    echo html_writer::start_tag('table', ['style' => 'width: 100%; border-collapse: collapse;']);
    echo html_writer::start_tag('thead');
    echo html_writer::start_tag('tr', ['style' => 'background: #f9fafb;']);
    echo html_writer::tag('th', 'Quiz', ['style' => 'padding: 12px; text-align: left; border: 1px solid #e5e7eb; font-weight: 600;']);
    echo html_writer::tag('th', 'Attempts', ['style' => 'padding: 12px; text-align: center; border: 1px solid #e5e7eb; font-weight: 600;']);
    echo html_writer::tag('th', 'Average Score', ['style' => 'padding: 12px; text-align: center; border: 1px solid #e5e7eb; font-weight: 600;']);
    echo html_writer::tag('th', 'Max Score', ['style' => 'padding: 12px; text-align: center; border: 1px solid #e5e7eb; font-weight: 600;']);
    echo html_writer::end_tag('tr');
    echo html_writer::end_tag('thead');
    echo html_writer::start_tag('tbody');
    
    foreach ($performance_data['quiz_stats'] as $quiz) {
        echo html_writer::start_tag('tr');
        echo html_writer::tag('td', $quiz->name, ['style' => 'padding: 12px; border: 1px solid #e5e7eb;']);
        echo html_writer::tag('td', $quiz->attempts, ['style' => 'padding: 12px; border: 1px solid #e5e7eb; text-align: center;']);
        echo html_writer::tag('td', round($quiz->avg_score, 1), ['style' => 'padding: 12px; border: 1px solid #e5e7eb; text-align: center;']);
        echo html_writer::tag('td', round($quiz->max_score, 1), ['style' => 'padding: 12px; border: 1px solid #e5e7eb; text-align: center;']);
        echo html_writer::end_tag('tr');
    }
    
    echo html_writer::end_tag('tbody');
    echo html_writer::end_tag('table');
    echo html_writer::end_div();
}

// Recommendations
echo html_writer::tag('h2', 'Recommendations', ['style' => 'margin: 24px 0 20px 0; font-size: 20px; font-weight: 600; color: #1f2937; border-bottom: 2px solid #e5e7eb; padding-bottom: 8px;']);

echo html_writer::start_div('', ['style' => 'background: #f0f9ff; border: 1px solid #0ea5e9; border-radius: 8px; padding: 16px; margin-bottom: 16px;']);
echo html_writer::tag('h4', '<i class="fas fa-lightbulb"></i> Key Insights', ['style' => 'margin: 0 0 12px 0; font-size: 16px; font-weight: 600; color: #0c4a6e;']);
echo html_writer::start_tag('ul', ['style' => 'margin: 0; padding-left: 20px;']);
echo html_writer::tag('li', 'Student attendance rate is ' . $performance_data['attendance_rate'] . '%, which is ' . ($performance_data['attendance_rate'] >= 80 ? 'excellent' : 'needs improvement') . '.', ['style' => 'margin-bottom: 8px; color: #0c4a6e;']);
echo html_writer::tag('li', 'Average grade of ' . $performance_data['avg_grade'] . '% indicates ' . ($performance_data['avg_grade'] >= 70 ? 'good' : 'room for improvement') . ' academic performance.', ['style' => 'margin-bottom: 8px; color: #0c4a6e;']);
echo html_writer::tag('li', 'Course has ' . $performance_data['course_stats']->total_activities . ' activities with ' . $performance_data['course_stats']->completed_activities . ' completed.', ['style' => 'margin-bottom: 8px; color: #0c4a6e;']);
echo html_writer::end_tag('ul');
echo html_writer::end_div();

echo html_writer::end_div(); // End report content

// Footer
echo html_writer::start_div('', ['style' => 'text-align: center; padding: 20px; color: #6b7280; font-size: 14px;']);
echo html_writer::tag('p', 'Report generated on ' . date('F j, Y \a\t g:i A'), ['style' => 'margin: 0;']);
echo html_writer::tag('p', 'Course: ' . $course->fullname, ['style' => 'margin: 4px 0 0 0;']);
echo html_writer::end_div();

echo '</div>'; // End main container

// JavaScript
echo '<script>';
echo 'function downloadPDF() {';
echo '    window.print();';
echo '}';
echo '</script>';

echo $OUTPUT->footer();
?>








