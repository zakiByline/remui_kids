<?php
// Exam Details page for detailed examination results

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
$PAGE->set_url(new moodle_url('/theme/remui_kids/pages/exam_details.php', ['id' => $courseid]));
$PAGE->set_pagelayout('standard');
$PAGE->set_title('Exam Details - ' . $course->fullname);
$PAGE->set_heading('Exam Details - ' . $course->fullname);

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
echo html_writer::tag('i', '', ['class' => 'fas fa-clipboard-list', 'style' => 'font-size: 24px; color: white;']);
echo html_writer::start_div('', ['style' => 'display: flex; flex-direction: column;']);
echo html_writer::tag('h1', 'Exam Details', ['style' => 'margin: 0; font-size: 24px; font-weight: 700;']);
echo html_writer::tag('p', $course->fullname, ['style' => 'margin: 0; font-size: 14px; opacity: 0.9;']);
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::tag('button', '<i class="fas fa-arrow-left"></i> Back to Dashboard', ['style' => 'padding: 8px 16px; background: rgba(255,255,255,0.2); color: white; border: 1px solid rgba(255,255,255,0.3); border-radius: 6px; cursor: pointer;', 'onclick' => 'window.close()']);
echo html_writer::end_div();

// Exam Results Overview
echo html_writer::start_div('', ['style' => 'background: white; border-radius: 16px; padding: 24px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); margin-bottom: 24px;']);
echo html_writer::tag('h2', 'Examination Results Overview', ['style' => 'margin: 0 0 20px 0; font-size: 20px; font-weight: 600; color: #1f2937;']);

if (!empty($performance_data['exam_results'])) {
    echo html_writer::start_div('', ['style' => 'overflow-x: auto;']);
    echo html_writer::start_tag('table', ['style' => 'width: 100%; border-collapse: collapse;']);
    echo html_writer::start_tag('thead');
    echo html_writer::start_tag('tr', ['style' => 'background: #f9fafb;']);
    echo html_writer::tag('th', 'Subject/Module', ['style' => 'padding: 12px; text-align: left; border: 1px solid #e5e7eb; font-weight: 600;']);
    echo html_writer::tag('th', 'Total Students', ['style' => 'padding: 12px; text-align: center; border: 1px solid #e5e7eb; font-weight: 600;']);
    echo html_writer::tag('th', 'Passed (≥70%)', ['style' => 'padding: 12px; text-align: center; border: 1px solid #e5e7eb; font-weight: 600; color: #1e40af;']);
    echo html_writer::tag('th', 'Average (50-69%)', ['style' => 'padding: 12px; text-align: center; border: 1px solid #e5e7eb; font-weight: 600; color: #3b82f6;']);
    echo html_writer::tag('th', 'Failed (<50%)', ['style' => 'padding: 12px; text-align: center; border: 1px solid #e5e7eb; font-weight: 600; color: #f59e0b;']);
    echo html_writer::tag('th', 'Pass Rate', ['style' => 'padding: 12px; text-align: center; border: 1px solid #e5e7eb; font-weight: 600;']);
    echo html_writer::end_tag('tr');
    echo html_writer::end_tag('thead');
    echo html_writer::start_tag('tbody');
    
    foreach ($performance_data['exam_results'] as $subject) {
        $pass_rate = $subject->total_count > 0 ? round(($subject->pass_count / $subject->total_count) * 100, 1) : 0;
        echo html_writer::start_tag('tr');
        echo html_writer::tag('td', ucfirst($subject->module_name), ['style' => 'padding: 12px; border: 1px solid #e5e7eb; font-weight: 600;']);
        echo html_writer::tag('td', $subject->total_count, ['style' => 'padding: 12px; border: 1px solid #e5e7eb; text-align: center;']);
        echo html_writer::tag('td', $subject->pass_count, ['style' => 'padding: 12px; border: 1px solid #e5e7eb; text-align: center; color: #1e40af; font-weight: 600;']);
        echo html_writer::tag('td', $subject->average_count, ['style' => 'padding: 12px; border: 1px solid #e5e7eb; text-align: center; color: #3b82f6; font-weight: 600;']);
        echo html_writer::tag('td', $subject->fail_count, ['style' => 'padding: 12px; border: 1px solid #e5e7eb; text-align: center; color: #f59e0b; font-weight: 600;']);
        echo html_writer::tag('td', $pass_rate . '%', ['style' => 'padding: 12px; border: 1px solid #e5e7eb; text-align: center; font-weight: 600; color: ' . ($pass_rate >= 70 ? '#10b981' : ($pass_rate >= 50 ? '#f59e0b' : '#ef4444')) . ';']);
        echo html_writer::end_tag('tr');
    }
    
    echo html_writer::end_tag('tbody');
    echo html_writer::end_tag('table');
    echo html_writer::end_div();
} else {
    echo html_writer::tag('p', 'No examination data available', ['style' => 'text-align: center; color: #6b7280; font-style: italic; padding: 40px;']);
}

echo html_writer::end_div();

// Subject-wise Performance Charts
if (!empty($performance_data['exam_results'])) {
    echo html_writer::start_div('', ['style' => 'background: white; border-radius: 16px; padding: 24px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); margin-bottom: 24px;']);
    echo html_writer::tag('h2', 'Subject-wise Performance', ['style' => 'margin: 0 0 20px 0; font-size: 20px; font-weight: 600; color: #1f2937;']);
    
    echo html_writer::start_div('', ['style' => 'display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px;']);
    
    foreach ($performance_data['exam_results'] as $subject) {
        $total = $subject->total_count;
        $pass_percent = $total > 0 ? round(($subject->pass_count / $total) * 100, 1) : 0;
        $avg_percent = $total > 0 ? round(($subject->average_count / $total) * 100, 1) : 0;
        $fail_percent = $total > 0 ? round(($subject->fail_count / $total) * 100, 1) : 0;
        
        echo html_writer::start_div('', ['style' => 'padding: 16px; background: #f9fafb; border-radius: 8px; border: 1px solid #e5e7eb;']);
        echo html_writer::tag('h3', ucfirst($subject->module_name), ['style' => 'margin: 0 0 12px 0; font-size: 16px; font-weight: 600; color: #1f2937;']);
        
        // Pass Rate Bar
        echo html_writer::start_div('', ['style' => 'margin-bottom: 8px;']);
        echo html_writer::tag('div', 'Passed: ' . $pass_percent . '%', ['style' => 'font-size: 12px; color: #374151; margin-bottom: 4px;']);
        echo html_writer::start_div('', ['style' => 'width: 100%; height: 8px; background: #e5e7eb; border-radius: 4px; overflow: hidden;']);
        echo html_writer::tag('div', '', ['style' => 'width: ' . $pass_percent . '%; height: 100%; background: #1e40af;']);
        echo html_writer::end_div();
        echo html_writer::end_div();
        
        // Average Rate Bar
        echo html_writer::start_div('', ['style' => 'margin-bottom: 8px;']);
        echo html_writer::tag('div', 'Average: ' . $avg_percent . '%', ['style' => 'font-size: 12px; color: #374151; margin-bottom: 4px;']);
        echo html_writer::start_div('', ['style' => 'width: 100%; height: 8px; background: #e5e7eb; border-radius: 4px; overflow: hidden;']);
        echo html_writer::tag('div', '', ['style' => 'width: ' . $avg_percent . '%; height: 100%; background: #3b82f6;']);
        echo html_writer::end_div();
        echo html_writer::end_div();
        
        // Fail Rate Bar
        echo html_writer::start_div('', ['style' => 'margin-bottom: 8px;']);
        echo html_writer::tag('div', 'Failed: ' . $fail_percent . '%', ['style' => 'font-size: 12px; color: #374151; margin-bottom: 4px;']);
        echo html_writer::start_div('', ['style' => 'width: 100%; height: 8px; background: #e5e7eb; border-radius: 4px; overflow: hidden;']);
        echo html_writer::tag('div', '', ['style' => 'width: ' . $fail_percent . '%; height: 100%; background: #f59e0b;']);
        echo html_writer::end_div();
        echo html_writer::end_div();
        
        echo html_writer::tag('p', 'Total: ' . $total . ' students', ['style' => 'margin: 8px 0 0 0; font-size: 12px; color: #6b7280; text-align: center;']);
        echo html_writer::end_div();
    }
    
    echo html_writer::end_div();
    echo html_writer::end_div();
}

// Performance Insights
echo html_writer::start_div('', ['style' => 'background: white; border-radius: 16px; padding: 24px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);']);
echo html_writer::tag('h2', 'Performance Insights', ['style' => 'margin: 0 0 20px 0; font-size: 20px; font-weight: 600; color: #1f2937;']);

echo html_writer::start_div('', ['style' => 'display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 16px;']);

// Overall Performance
$total_students = 0;
$total_passed = 0;
$total_average = 0;
$total_failed = 0;

if (!empty($performance_data['exam_results'])) {
    foreach ($performance_data['exam_results'] as $subject) {
        $total_students += $subject->total_count;
        $total_passed += $subject->pass_count;
        $total_average += $subject->average_count;
        $total_failed += $subject->fail_count;
    }
}

$overall_pass_rate = $total_students > 0 ? round(($total_passed / $total_students) * 100, 1) : 0;

echo html_writer::start_div('', ['style' => 'text-align: center; padding: 16px; background: #f0f9ff; border-radius: 8px; border: 1px solid #0ea5e9;']);
echo html_writer::tag('h3', 'Overall Pass Rate', ['style' => 'margin: 0 0 8px 0; font-size: 18px; font-weight: 600; color: #0c4a6e;']);
echo html_writer::tag('h2', $overall_pass_rate . '%', ['style' => 'margin: 0; font-size: 32px; font-weight: 700; color: ' . ($overall_pass_rate >= 70 ? '#10b981' : ($overall_pass_rate >= 50 ? '#f59e0b' : '#ef4444')) . ';']);
echo html_writer::tag('p', 'Out of ' . $total_students . ' total attempts', ['style' => 'margin: 4px 0 0 0; font-size: 14px; color: #0c4a6e;']);
echo html_writer::end_div();

// Best Performing Subject
if (!empty($performance_data['exam_results'])) {
    $best_subject = null;
    $best_pass_rate = 0;
    
    foreach ($performance_data['exam_results'] as $subject) {
        $pass_rate = $subject->total_count > 0 ? round(($subject->pass_count / $subject->total_count) * 100, 1) : 0;
        if ($pass_rate > $best_pass_rate) {
            $best_pass_rate = $pass_rate;
            $best_subject = $subject;
        }
    }
    
    if ($best_subject) {
        echo html_writer::start_div('', ['style' => 'text-align: center; padding: 16px; background: #f0fdf4; border-radius: 8px; border: 1px solid #22c55e;']);
        echo html_writer::tag('h3', 'Best Performing Subject', ['style' => 'margin: 0 0 8px 0; font-size: 18px; font-weight: 600; color: #166534;']);
        echo html_writer::tag('h4', ucfirst($best_subject->module_name), ['style' => 'margin: 0 0 4px 0; font-size: 20px; font-weight: 700; color: #166534;']);
        echo html_writer::tag('p', $best_pass_rate . '% pass rate', ['style' => 'margin: 0; font-size: 14px; color: #166534;']);
        echo html_writer::end_div();
    }
}

// Areas for Improvement
if (!empty($performance_data['exam_results'])) {
    $worst_subject = null;
    $worst_pass_rate = 100;
    
    foreach ($performance_data['exam_results'] as $subject) {
        $pass_rate = $subject->total_count > 0 ? round(($subject->pass_count / $subject->total_count) * 100, 1) : 0;
        if ($pass_rate < $worst_pass_rate) {
            $worst_pass_rate = $pass_rate;
            $worst_subject = $subject;
        }
    }
    
    if ($worst_subject && $worst_pass_rate < 70) {
        echo html_writer::start_div('', ['style' => 'text-align: center; padding: 16px; background: #fef2f2; border-radius: 8px; border: 1px solid #f87171;']);
        echo html_writer::tag('h3', 'Needs Attention', ['style' => 'margin: 0 0 8px 0; font-size: 18px; font-weight: 600; color: #991b1b;']);
        echo html_writer::tag('h4', ucfirst($worst_subject->module_name), ['style' => 'margin: 0 0 4px 0; font-size: 20px; font-weight: 700; color: #991b1b;']);
        echo html_writer::tag('p', $worst_pass_rate . '% pass rate', ['style' => 'margin: 0; font-size: 14px; color: #991b1b;']);
        echo html_writer::end_div();
    }
}

echo html_writer::end_div();
echo html_writer::end_div();

echo '</div>'; // End main container

echo $OUTPUT->footer();
?>








