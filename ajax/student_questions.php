<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * AJAX endpoint for student questions integration with Moodle messaging
 *
 * @package    theme_remui_kids
 * @copyright  2025 Kodeit
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../../config.php');
require_once($CFG->dirroot . '/theme/remui_kids/lib.php');

// Check if user is logged in
require_login();

// Set JSON header
header('Content-Type: application/json');

// Get action from request
$action = optional_param('action', '', PARAM_TEXT);

try {
    switch ($action) {
        case 'get_questions':
            $questions = theme_remui_kids_get_student_questions_integrated($USER->id);
            echo json_encode([
                'success' => true,
                'questions' => $questions
            ]);
            break;
            
        case 'send_message':
            $student_id = required_param('student_id', PARAM_INT);
            $message = required_param('message', PARAM_TEXT);
            $course_name = optional_param('course_name', '', PARAM_TEXT);
            
            $result = theme_remui_kids_send_question_notification($student_id, $USER->id, $message, $course_name);
            
            echo json_encode([
                'success' => $result,
                'message' => $result ? 'Message sent successfully' : 'Failed to send message'
            ]);
            break;
            
        case 'create_forum_discussion':
            $student_id = required_param('student_id', PARAM_INT);
            $course_id = required_param('course_id', PARAM_INT);
            $question = required_param('question', PARAM_TEXT);
            $subject = required_param('subject', PARAM_TEXT);
            
            $discussion_id = theme_remui_kids_create_question_forum_discussion($student_id, $course_id, $question, $subject);
            
            echo json_encode([
                'success' => $discussion_id !== false,
                'discussion_id' => $discussion_id,
                'message' => $discussion_id ? 'Forum discussion created successfully' : 'Failed to create forum discussion'
            ]);
            break;
            
        case 'get_messaging_questions':
            $questions = theme_remui_kids_get_questions_from_messaging($USER->id);
            echo json_encode([
                'success' => true,
                'questions' => $questions
            ]);
            break;
            
        case 'get_forum_questions':
            $questions = theme_remui_kids_get_questions_from_forums($USER->id);
            echo json_encode([
                'success' => true,
                'questions' => $questions
            ]);
            break;
            
        default:
            echo json_encode([
                'success' => false,
                'message' => 'Invalid action'
            ]);
            break;
    }
    
} catch (Exception $e) {
    error_log("Error in student_questions.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred: ' . $e->getMessage()
    ]);
}

