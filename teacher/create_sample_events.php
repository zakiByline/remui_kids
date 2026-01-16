<?php
/**
 * Create sample calendar events for testing teacher schedule
 * 
 * This file helps create sample calendar events for testing the teacher dashboard schedule feature
 * 
 * @package theme_remui_kids
 * @copyright 2025 Kodeit
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../../config.php');
require_once($CFG->dirroot . '/calendar/lib.php');

require_login();

// Check if user is a teacher or admin
$isteacher = is_siteadmin() || has_capability('moodle/course:create', context_system::instance());

if (!$isteacher) {
    print_error('Access denied. You must be a teacher or administrator.');
}

$PAGE->set_context(context_system::instance());
$PAGE->set_url('/theme/remui_kids/teacher/create_sample_events.php');
$PAGE->set_title('Create Sample Calendar Events');
$PAGE->set_heading('Create Sample Calendar Events');

echo $OUTPUT->header();

// Handle form submission
$create = optional_param('create', 0, PARAM_INT);

if ($create && confirm_sesskey()) {
    // Get teacher's first course
    $courses = enrol_get_all_users_courses($USER->id, true);
    $course = !empty($courses) ? reset($courses) : null;
    
    if (!$course) {
        echo $OUTPUT->notification('You are not enrolled in any course. Please enroll in a course first.', 'error');
    } else {
        echo html_writer::tag('h3', 'Creating sample events for course: ' . $course->fullname);
        
        // Sample events to create
        $sample_events = [
            [
                'name' => 'Digital Learning Basics',
                'description' => 'Introduction to digital learning tools and platforms. Location: Room 204',
                'eventtype' => 'course',
                'courseid' => $course->id,
                'timestart' => strtotime('next Monday 9:00'),
                'timeduration' => 7200, // 2 hours
            ],
            [
                'name' => 'Assessment Design Workshop',
                'description' => 'Workshop on designing effective assessments. Room: 305',
                'eventtype' => 'course',
                'courseid' => $course->id,
                'timestart' => strtotime('next Tuesday 13:00'),
                'timeduration' => 7200,
            ],
            [
                'name' => 'Classroom Management Session',
                'description' => 'Best practices for classroom management. Building: A, Room: 102',
                'eventtype' => 'course',
                'courseid' => $course->id,
                'timestart' => strtotime('next Thursday 10:00'),
                'timeduration' => 7200,
            ],
            [
                'name' => 'Mentoring Session',
                'description' => 'One-on-one mentoring with senior faculty. Virtual: Zoom',
                'eventtype' => 'user',
                'courseid' => 0,
                'userid' => $USER->id,
                'timestart' => strtotime('next Thursday 14:00'),
                'timeduration' => 7200,
            ],
            [
                'name' => 'Advanced Digital Assessment',
                'description' => 'Advanced techniques for digital assessment. Room 204',
                'eventtype' => 'course',
                'courseid' => $course->id,
                'timestart' => strtotime('next Friday 9:00'),
                'timeduration' => 10800, // 3 hours
            ],
            [
                'name' => 'Trainer Development Workshop',
                'description' => 'Professional development for trainers. Virtual: Microsoft Teams',
                'eventtype' => 'course',
                'courseid' => $course->id,
                'timestart' => strtotime('next Saturday 11:00'),
                'timeduration' => 7200,
            ],
        ];
        
        $created_count = 0;
        foreach ($sample_events as $event_data) {
            try {
                $event = new stdClass();
                $event->name = $event_data['name'];
                $event->description = $event_data['description'];
                $event->format = FORMAT_HTML;
                $event->eventtype = $event_data['eventtype'];
                $event->courseid = isset($event_data['courseid']) ? $event_data['courseid'] : 0;
                $event->userid = isset($event_data['userid']) ? $event_data['userid'] : 0;
                $event->timestart = $event_data['timestart'];
                $event->timeduration = $event_data['timeduration'];
                $event->visible = 1;
                $event->repeatid = 0;
                $event->modulename = '';
                $event->instance = 0;
                $event->type = 0;
                
                $calendar_event = calendar_event::create($event);
                
                if ($calendar_event) {
                    echo html_writer::tag('p', '✓ Created: ' . $event_data['name'] . ' on ' . 
                        date('l, F j, Y \a\t g:i A', $event_data['timestart']), ['class' => 'alert alert-success']);
                    $created_count++;
                } else {
                    echo html_writer::tag('p', '✗ Failed to create: ' . $event_data['name'], ['class' => 'alert alert-danger']);
                }
            } catch (Exception $e) {
                echo html_writer::tag('p', '✗ Error creating ' . $event_data['name'] . ': ' . $e->getMessage(), ['class' => 'alert alert-danger']);
            }
        }
        
        echo html_writer::tag('div', 
            html_writer::tag('h4', "Successfully created {$created_count} sample events!") .
            html_writer::tag('p', 'Now go to your Teacher Dashboard to see the events in your schedule.') .
            html_writer::link(new moodle_url('/my/'), 'Go to Teacher Dashboard', ['class' => 'btn btn-primary']),
            ['class' => 'alert alert-info', 'style' => 'margin-top: 20px;']
        );
    }
} else {
    // Show form
    echo html_writer::start_div('container-fluid', ['style' => 'max-width: 800px; margin: 40px auto;']);
    
    echo html_writer::tag('h2', 'Create Sample Calendar Events for Testing');
    echo html_writer::tag('p', 'This tool will create 6 sample calendar events in your Moodle calendar to test the Teacher Dashboard schedule feature.');
    
    echo html_writer::start_div('card');
    echo html_writer::start_div('card-body');
    
    echo html_writer::tag('h4', 'Sample Events to be Created:');
    echo html_writer::start_tag('ul', ['class' => 'list-group']);
    
    $sample_list = [
        '📅 Digital Learning Basics - Monday 9:00-11:00 (Room 204)',
        '📅 Assessment Design Workshop - Tuesday 13:00-15:00 (Room 305)',
        '📅 Classroom Management Session - Thursday 10:00-12:00 (Room 102)',
        '👤 Mentoring Session - Thursday 14:00-16:00 (Virtual)',
        '📅 Advanced Digital Assessment - Friday 9:00-12:00 (Room 204)',
        '📅 Trainer Development Workshop - Saturday 11:00-13:00 (Virtual)',
    ];
    
    foreach ($sample_list as $item) {
        echo html_writer::tag('li', $item, ['class' => 'list-group-item']);
    }
    
    echo html_writer::end_tag('ul');
    
    echo html_writer::div('', '', ['style' => 'height: 20px;']);
    
    $form_url = new moodle_url('/theme/remui_kids/teacher/create_sample_events.php', [
        'create' => 1,
        'sesskey' => sesskey()
    ]);
    
    echo html_writer::link($form_url, 'Create Sample Events', ['class' => 'btn btn-primary btn-lg']);
    echo html_writer::tag('span', '  or  ', ['style' => 'margin: 0 10px;']);
    echo html_writer::link(new moodle_url('/my/'), 'Go Back to Dashboard', ['class' => 'btn btn-secondary']);
    
    echo html_writer::end_div(); // card-body
    echo html_writer::end_div(); // card
    
    echo html_writer::start_div('alert alert-info', ['style' => 'margin-top: 30px;']);
    echo html_writer::tag('h5', '📝 Note:');
    echo html_writer::tag('p', 'These events will be created in next week\'s schedule. After creating them:');
    echo html_writer::start_tag('ol');
    echo html_writer::tag('li', 'Go to your Teacher Dashboard (/my/)');
    echo html_writer::tag('li', 'Scroll down to the "Your Schedule" section');
    echo html_writer::tag('li', 'You should see the events displayed in the weekly calendar');
    echo html_writer::tag('li', 'Check the "Upcoming Sessions" section on the right');
    echo html_writer::end_tag('ol');
    echo html_writer::end_div();
    
    echo html_writer::end_div(); // container
}

echo $OUTPUT->footer();


