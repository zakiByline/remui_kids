<?php
// Test page for exact dashboard styling

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/theme/remui_kids/lib.php');

require_login();

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/theme/remui_kids/pages/test_exact_dashboard.php'));
$PAGE->set_pagelayout('standard');
$PAGE->set_title('Test Exact Dashboard');
$PAGE->set_heading('Test Exact Dashboard');

echo $OUTPUT->header();

// Test the exact dashboard template
$test_data = [
    'student' => [
        'firstname' => 'Anika',
        'fullname' => 'Anika Test',
        'email' => 'anika@test.com'
    ],
    'overall' => ['percent' => 80],
    'overview_counts' => [
        'total_courses' => 5,
        'completed_courses' => 1,
        'hours_spent' => '112h'
    ],
    'engagement' => [
        'live_classes_percent' => 70,
        'quiz_attempts' => 20,
        'total_quizzes' => 30,
        'assignments_done' => 10,
        'total_assignments' => 15
    ],
    'upcoming_classes' => [
        [
            'title' => 'Newtonian Mechanics - Class 5',
            'instructor_name' => 'Rakesh Ahmed',
            'instructor_avatar' => '/user/pix.php/0/f1',
            'course_name' => 'Physics 1',
            'course_color' => 'red',
            'class_number' => 'Class 5',
            'date_time' => '15th Oct, 2024; 12:00PM',
            'time_remaining' => '2 min left',
            'urgency_color' => 'red'
        ]
    ],
    'courses' => [
        [
            'name' => 'Physics 1',
            'course_icon' => 'P',
            'course_icon_color' => 'orange',
            'chapters' => 5,
            'lectures' => 30,
            'progress' => 30,
            'progress_color' => 'orange',
            'overall_score' => 80,
            'status_label' => 'In progress',
            'status_class' => 'inprogress'
        ]
    ],
    'streak' => [
        'days' => 5,
        'record' => 16,
        'classes_covered' => 6,
        'assignments_completed' => 4,
        'days_list' => [
            ['day' => 'Sat', 'status' => 'active'],
            ['day' => 'Sun', 'status' => 'active'],
            ['day' => 'Mon', 'status' => 'active'],
            ['day' => 'Tue', 'status' => 'active'],
            ['day' => 'Wed', 'status' => 'active'],
            ['day' => 'Thu', 'status' => 'inactive'],
            ['day' => 'Fri', 'status' => 'inactive']
        ]
    ],
    'assignments' => [
        [
            'name' => 'Advanced problem solving math',
            'course_name' => 'H. math 1',
            'course_color' => 'green',
            'assignment_number' => 'Assignment 5',
            'due_date' => '15th Oct, 2024, 12:00PM',
            'urgency_color' => 'red'
        ]
    ],
    'quizzes' => [
        [
            'name' => 'Vector division',
            'questions' => 10,
            'duration' => 15
        ]
    ]
];

try {
    $html = $OUTPUT->render_from_template('theme_remui_kids/exact_student_dashboard', $test_data);
    echo $html;
} catch (Throwable $e) {
    echo html_writer::div('Template Error: ' . $e->getMessage(), 'alert alert-danger');
    echo html_writer::div('Stack trace: ' . $e->getTraceAsString(), 'text-muted small');
}

echo $OUTPUT->footer();
?>







