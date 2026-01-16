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
 * Student Questions Page
 *
 * @package   theme_remui_kids
 * @copyright 2024 WisdmLabs
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../../config.php');
require_once($CFG->dirroot . '/theme/remui_kids/lib.php');

// Check if user is logged in
require_login();

// Check if user has teacher capabilities
$context = context_system::instance();
if (!has_capability('moodle/site:config', $context) && !has_capability('moodle/course:manageactivities', $context)) {
    throw new moodle_exception('nopermissions', 'error', '', 'Access denied');
}

$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/pages/questions.php');
$PAGE->set_title('Student Questions');
$PAGE->set_heading('Student Questions');
$PAGE->set_pagelayout('base');

// Get mock questions data
$questions = [
    [
        'id' => 1,
        'title' => 'What wrong in this code',
        'content' => 'I am getting an error when trying to run this JavaScript function. Can someone help me understand what is wrong?',
        'student_name' => 'Zaki',
        'grade' => 'Grade 9',
        'course' => 'Mathematics',
        'date' => '14 Apr 2025',
        'status' => 'MENTOR REPLIED',
        'status_class' => 'mentor-replied',
        'upvotes' => 0,
        'replies' => 1
    ],
    [
        'id' => 2,
        'title' => 'What wrong in this code',
        'content' => 'I have been working on this problem for hours but cannot figure out the solution. Please help!',
        'student_name' => 'Zaki',
        'grade' => 'Grade 10',
        'course' => 'Science',
        'date' => '28 Mar 2025',
        'status' => 'MENTOR REPLIED',
        'status_class' => 'mentor-replied',
        'upvotes' => 0,
        'replies' => 1
    ],
    [
        'id' => 3,
        'title' => 'What wrong in this code',
        'content' => 'This is a follow-up question to my previous post. I still need help with the same issue.',
        'student_name' => 'Zaki',
        'grade' => 'Grade 11',
        'course' => 'English',
        'date' => '28 Mar 2025',
        'status' => 'MENTOR REPLIED',
        'status_class' => 'mentor-replied',
        'upvotes' => 0,
        'replies' => 1
    ],
    [
        'id' => 4,
        'title' => 'Some tests are not getting passed.',
        'content' => 'I have written several test cases but some of them are failing. Can you help me debug this issue?',
        'student_name' => 'Sujith',
        'grade' => 'Grade 12',
        'course' => 'Mathematics',
        'date' => '19 Sep 2024',
        'status' => 'Clarified',
        'status_class' => 'clarified',
        'upvotes' => 1,
        'replies' => 1
    ],
    [
        'id' => 5,
        'title' => 'CheckBox',
        'content' => 'I need help with implementing a checkbox functionality in my web application.',
        'student_name' => 'Daveed',
        'grade' => 'Grade 9',
        'course' => 'Science',
        'date' => '16 Dec 2023',
        'status' => 'MENTOR REPLIED',
        'status_class' => 'mentor-replied',
        'upvotes' => 1,
        'replies' => 3
    ],
    [
        'id' => 6,
        'title' => 'How to solve quadratic equations?',
        'content' => 'I am struggling with the quadratic formula. Can someone explain it step by step?',
        'student_name' => 'Emma Wilson',
        'grade' => 'Grade 10',
        'course' => 'Mathematics',
        'date' => '2 days ago',
        'status' => 'Pending',
        'status_class' => 'pending',
        'upvotes' => 0,
        'replies' => 0
    ],
    [
        'id' => 7,
        'title' => 'Physics lab experiment help',
        'content' => 'I need assistance with the pendulum experiment. The results are not matching the expected values.',
        'student_name' => 'Ryan Chen',
        'grade' => 'Grade 11',
        'course' => 'Science',
        'date' => '1 day ago',
        'status' => 'MENTOR REPLIED',
        'status_class' => 'mentor-replied',
        'upvotes' => 2,
        'replies' => 1
    ],
    [
        'id' => 8,
        'title' => 'Essay writing structure',
        'content' => 'Can someone help me understand the proper structure for a persuasive essay?',
        'student_name' => 'Sophia Martinez',
        'grade' => 'Grade 12',
        'course' => 'English',
        'date' => '3 days ago',
        'status' => 'Clarified',
        'status_class' => 'clarified',
        'upvotes' => 1,
        'replies' => 2
    ],
    [
        'id' => 9,
        'title' => 'Chemistry reaction balancing',
        'content' => 'I am having trouble balancing chemical equations. Can someone show me the step-by-step process?',
        'student_name' => 'Alex Thompson',
        'grade' => 'Grade 9',
        'course' => 'Science',
        'date' => '4 days ago',
        'status' => 'MENTOR REPLIED',
        'status_class' => 'mentor-replied',
        'upvotes' => 3,
        'replies' => 2
    ],
    [
        'id' => 10,
        'title' => 'History timeline confusion',
        'content' => 'I need help organizing the major events of World War II in chronological order.',
        'student_name' => 'Maya Patel',
        'grade' => 'Grade 11',
        'course' => 'English',
        'date' => '5 days ago',
        'status' => 'Pending',
        'status_class' => 'pending',
        'upvotes' => 0,
        'replies' => 0
    ]
];

echo $OUTPUT->header();
?>

<div class="teacher-main-content">
    <div class="container-fluid">
        <!-- Enhanced Page Header -->
        <div class="page-header">
            <div class="header-content">
                <h1 class="page-title">
                    <i class="fa fa-question-circle"></i>
                    Student Questions
                </h1>
                <p class="page-subtitle">Manage and answer student questions with advanced filtering and communication tools</p>
            </div>
            <div class="header-actions">
                <button class="btn-primary" onclick="createNewQuestion()">
                    <i class="fa fa-plus"></i> New Question
                </button>
            </div>
        </div>

        <!-- Questions Toolbar -->
        <div class="questions-toolbar">
            <div class="filter-group">
                <label class="filter-label">Sort By:</label>
                <select id="question-sort" class="filter-select" onchange="filterQuestions()">
                    <option value="latest">Latest</option>
                    <option value="oldest">Oldest</option>
                    <option value="most_replied">Most Replied</option>
                    <option value="unanswered">Unanswered</option>
                </select>
            </div>
            <div class="filter-group">
                <label class="filter-label">Filter:</label>
                <select id="question-filter" class="filter-select" onchange="filterQuestions()">
                    <option value="all">All</option>
                    <option value="grade_9">Grade 9</option>
                    <option value="grade_10">Grade 10</option>
                    <option value="grade_11">Grade 11</option>
                    <option value="grade_12">Grade 12</option>
                    <option value="mathematics">Mathematics</option>
                    <option value="science">Science</option>
                    <option value="english">English</option>
                </select>
            </div>
            <div class="filter-group">
                <label class="filter-label">Status:</label>
                <select id="status-filter" class="filter-select" onchange="filterQuestions()">
                    <option value="all">All</option>
                    <option value="pending">Pending</option>
                    <option value="mentor_replied">Mentor Replied</option>
                    <option value="clarified">Clarified</option>
                </select>
            </div>
        </div>

        <!-- Questions List -->
        <div class="questions-list">
            <?php foreach ($questions as $question): ?>
            <div class="question-item" data-grade="<?php echo $question['grade']; ?>" data-course="<?php echo $question['course']; ?>" data-status="<?php echo $question['status_class']; ?>">
                <div class="question-header">
                    <h4 class="question-title"><?php echo htmlspecialchars($question['title']); ?></h4>
                    <div class="question-meta">
                        <span class="question-grade"><?php echo $question['grade']; ?></span>
                        <span class="question-course"><?php echo $question['course']; ?></span>
                    </div>
                </div>
                <div class="question-content">
                    <p class="question-text"><?php echo htmlspecialchars($question['content']); ?></p>
                    <div class="question-student">
                        <span class="student-name"><?php echo htmlspecialchars($question['student_name']); ?></span>
                        <span class="question-date"><?php echo $question['date']; ?></span>
                    </div>
                </div>
                <div class="question-status">
                    <span class="status-badge <?php echo $question['status_class']; ?>"><?php echo $question['status']; ?></span>
                    <div class="question-stats">
                        <span class="upvotes"><?php echo $question['upvotes']; ?> UPVOTES</span>
                        <span class="replies"><?php echo $question['replies']; ?> REPLIES</span>
                    </div>
                </div>
                <div class="question-actions">
                    <button class="btn-action" onclick="answerQuestion(<?php echo $question['id']; ?>)" title="Answer">
                        <i class="fa fa-reply"></i>
                    </button>
                    <button class="btn-action" onclick="startVideoCall(<?php echo $question['id']; ?>)" title="Video Call">
                        <i class="fa fa-video"></i>
                    </button>
                    <button class="btn-action" onclick="startAudioCall(<?php echo $question['id']; ?>)" title="Audio Call">
                        <i class="fa fa-phone"></i>
                    </button>
                    <button class="btn-action" onclick="startChat(<?php echo $question['id']; ?>)" title="Chat">
                        <i class="fa fa-comments"></i>
                    </button>
                    <button class="btn-action" onclick="groupCall(<?php echo $question['id']; ?>)" title="Group Call">
                        <i class="fa fa-users"></i>
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<style>
/* Enhanced Questions Page Styling */
.teacher-main-content {
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    min-height: 100vh;
    padding: 2rem 0;
}

.container-fluid {
    max-width: 1400px;
    margin: 0 auto;
    padding: 0 2rem;
}

/* Enhanced Page Header */
.page-header {
    background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
    color: white;
    padding: 3rem 2rem;
    border-radius: 20px;
    margin-bottom: 2rem;
    box-shadow: 0 12px 40px rgba(99, 102, 241, 0.25);
    position: relative;
    overflow: hidden;
}

.page-header::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grain" width="100" height="100" patternUnits="userSpaceOnUse"><circle cx="25" cy="25" r="1" fill="white" opacity="0.1"/><circle cx="75" cy="75" r="1" fill="white" opacity="0.1"/><circle cx="50" cy="10" r="0.5" fill="white" opacity="0.1"/><circle cx="10" cy="60" r="0.5" fill="white" opacity="0.1"/><circle cx="90" cy="40" r="0.5" fill="white" opacity="0.1"/></pattern></defs><rect width="100" height="100" fill="url(%23grain)"/></svg>');
    opacity: 0.3;
}

.header-content {
    position: relative;
    z-index: 1;
}

.page-title {
    font-size: 3rem;
    font-weight: 800;
    margin: 0 0 1rem 0;
    text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    display: flex;
    align-items: center;
    gap: 1rem;
}

.page-title i {
    font-size: 2.5rem;
    color: rgba(255, 255, 255, 0.9);
}

.page-subtitle {
    font-size: 1.25rem;
    margin: 0;
    opacity: 0.9;
    font-weight: 500;
    line-height: 1.6;
}

.header-actions {
    position: relative;
    z-index: 1;
}

.btn-primary {
    background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
    color: #6366f1;
    border: 2px solid rgba(255, 255, 255, 0.2);
    padding: 1rem 2rem;
    border-radius: 16px;
    font-weight: 700;
    font-size: 1.125rem;
    box-shadow: 0 6px 25px rgba(255, 255, 255, 0.2);
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    display: inline-flex;
    align-items: center;
    gap: 0.75rem;
}

.btn-primary:hover {
    background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
    transform: translateY(-3px);
    box-shadow: 0 12px 35px rgba(255, 255, 255, 0.3);
}

/* Enhanced Questions Toolbar */
.questions-toolbar {
    display: flex;
    gap: 2rem;
    margin-bottom: 2rem;
    padding: 2rem;
    background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
    border-radius: 20px;
    border: 1px solid rgba(99, 102, 241, 0.1);
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
    flex-wrap: wrap;
    align-items: center;
}

.filter-group {
    display: flex;
    align-items: center;
    gap: 1rem;
    background: white;
    padding: 1rem 1.5rem;
    border-radius: 16px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
    border: 1px solid rgba(99, 102, 241, 0.1);
}

.filter-label {
    font-weight: 700;
    color: #374151;
    font-size: 1rem;
    white-space: nowrap;
}

.filter-select {
    padding: 0.75rem 1.25rem;
    border: 2px solid #e2e8f0;
    border-radius: 12px;
    background: white;
    font-size: 0.95rem;
    color: #374151;
    min-width: 160px;
    font-weight: 600;
    transition: all 0.3s ease;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
}

.filter-select:focus {
    outline: none;
    border-color: #6366f1;
    box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
    transform: translateY(-1px);
}

/* Enhanced Questions List */
.questions-list {
    max-height: 700px;
    overflow-y: auto;
    padding-right: 1rem;
}

.questions-list::-webkit-scrollbar {
    width: 10px;
}

.questions-list::-webkit-scrollbar-track {
    background: #f1f5f9;
    border-radius: 12px;
}

.questions-list::-webkit-scrollbar-thumb {
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    border-radius: 12px;
}

.questions-list::-webkit-scrollbar-thumb:hover {
    background: linear-gradient(135deg, #4f46e5, #7c3aed);
}

.question-item {
    background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
    border: 1px solid rgba(99, 102, 241, 0.1);
    border-radius: 20px;
    padding: 2.5rem;
    margin-bottom: 2rem;
    transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    overflow: hidden;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
}

.question-item::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 5px;
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    opacity: 0;
    transition: opacity 0.3s ease;
}

.question-item:hover::before {
    opacity: 1;
}

.question-item:hover {
    box-shadow: 0 20px 60px rgba(99, 102, 241, 0.15);
    transform: translateY(-6px);
    border-color: rgba(99, 102, 241, 0.2);
}

.question-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 2rem;
    gap: 2rem;
}

.question-title {
    font-size: 1.5rem;
    font-weight: 800;
    color: #1e293b;
    margin: 0;
    flex: 1;
    line-height: 1.4;
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
}

.question-meta {
    display: flex;
    gap: 1rem;
    flex-shrink: 0;
}

.question-grade,
.question-course {
    padding: 0.75rem 1.25rem;
    border-radius: 16px;
    font-size: 0.85rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.question-grade {
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    color: white;
}

.question-course {
    background: linear-gradient(135deg, #06b6d4, #3b82f6);
    color: white;
}

.question-content {
    margin-bottom: 2rem;
}

.question-text {
    color: #4b5563;
    font-size: 1.125rem;
    line-height: 1.8;
    margin-bottom: 1.5rem;
    font-weight: 500;
}

.question-student {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 1rem;
    color: #6b7280;
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    padding: 1rem 1.5rem;
    border-radius: 12px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
}

.student-name {
    font-weight: 700;
    color: #374151;
    font-size: 1.1rem;
}

.question-date {
    color: #9ca3af;
    font-weight: 600;
}

.question-status {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 2rem;
    padding-top: 2rem;
    border-top: 2px solid #f1f5f9;
}

.status-badge {
    padding: 0.75rem 1.5rem;
    border-radius: 20px;
    font-size: 0.85rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    box-shadow: 0 6px 20px rgba(0, 0, 0, 0.1);
}

.status-badge.mentor-replied {
    background: linear-gradient(135deg, #10b981, #14b8a6);
    color: white;
}

.status-badge.pending {
    background: linear-gradient(135deg, #f59e0b, #f97316);
    color: white;
}

.status-badge.clarified {
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    color: white;
}

.question-stats {
    display: flex;
    gap: 2rem;
    font-size: 0.9rem;
    color: #6b7280;
    font-weight: 600;
}

.upvotes,
.replies {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.75rem 1.25rem;
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    border-radius: 12px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
}

.question-actions {
    display: flex;
    gap: 1rem;
    flex-wrap: wrap;
}

.question-actions .btn-action {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    border: 2px solid #e5e7eb;
    color: #6b7280;
    padding: 1rem;
    border-radius: 16px;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 56px;
    height: 56px;
    transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    font-size: 1.25rem;
    font-weight: 600;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
}

.question-actions .btn-action:hover {
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    color: white;
    border-color: #6366f1;
    transform: translateY(-3px);
    box-shadow: 0 12px 35px rgba(99, 102, 241, 0.3);
}

.question-actions .btn-action:nth-child(2):hover {
    background: linear-gradient(135deg, #10b981, #14b8a6);
    border-color: #10b981;
}

.question-actions .btn-action:nth-child(3):hover {
    background: linear-gradient(135deg, #06b6d4, #3b82f6);
    border-color: #06b6d4;
}

.question-actions .btn-action:nth-child(4):hover {
    background: linear-gradient(135deg, #8b5cf6, #a855f7);
    border-color: #8b5cf6;
}

.question-actions .btn-action:nth-child(5):hover {
    background: linear-gradient(135deg, #f59e0b, #f97316);
    border-color: #f59e0b;
}

/* Responsive Design */
@media (max-width: 768px) {
    .container-fluid {
        padding: 0 1rem;
    }
    
    .page-header {
        padding: 2rem 1.5rem;
    }
    
    .page-title {
        font-size: 2rem;
        flex-direction: column;
        align-items: flex-start;
        gap: 0.5rem;
    }
    
    .questions-toolbar {
        flex-direction: column;
        gap: 1rem;
        padding: 1.5rem;
    }
    
    .filter-group {
        flex-direction: column;
        align-items: flex-start;
        gap: 0.5rem;
        width: 100%;
    }
    
    .filter-select {
        width: 100%;
    }
    
    .question-header {
        flex-direction: column;
        gap: 1rem;
    }
    
    .question-meta {
        flex-direction: column;
        gap: 0.5rem;
        width: 100%;
    }
    
    .question-actions {
        justify-content: center;
    }
    
    .question-actions .btn-action {
        width: 48px;
        height: 48px;
        font-size: 1rem;
    }
}
</style>

<script>
// Questions System Functions
function createNewQuestion() {
    window.location.href = '<?php echo $CFG->wwwroot; ?>/theme/remui_kids/pages/create_question.php';
}

function filterQuestions() {
    const sortBy = document.getElementById('question-sort').value;
    const filter = document.getElementById('question-filter').value;
    const statusFilter = document.getElementById('status-filter').value;
    
    console.log('Filtering questions:', { sortBy, filter, statusFilter });
    
    const questionItems = document.querySelectorAll('.question-item');
    questionItems.forEach(item => {
        const grade = item.dataset.grade;
        const course = item.dataset.course;
        const status = item.dataset.status;
        
        let show = true;
        
        // Filter by grade/course
        if (filter !== 'all') {
            if (filter.startsWith('grade_')) {
                const filterGrade = filter.replace('grade_', 'Grade ');
                show = grade === filterGrade;
            } else {
                show = course.toLowerCase().includes(filter.toLowerCase());
            }
        }
        
        // Filter by status
        if (statusFilter !== 'all' && show) {
            const statusMap = {
                'pending': 'pending',
                'mentor_replied': 'mentor-replied',
                'clarified': 'clarified'
            };
            show = status === statusMap[statusFilter];
        }
        
        item.style.display = show ? 'block' : 'none';
    });
}

function answerQuestion(questionId) {
    window.location.href = `<?php echo $CFG->wwwroot; ?>/theme/remui_kids/pages/answer_question.php?id=${questionId}`;
}

function startVideoCall(questionId) {
    console.log('Starting video call for question:', questionId);
    alert('Video call feature will be implemented');
}

function startAudioCall(questionId) {
    console.log('Starting audio call for question:', questionId);
    alert('Audio call feature will be implemented');
}

function startChat(questionId) {
    console.log('Starting chat for question:', questionId);
    window.location.href = `<?php echo $CFG->wwwroot; ?>/theme/remui_kids/pages/chat.php?question=${questionId}`;
}

function groupCall(questionId) {
    console.log('Starting group call for question:', questionId);
    alert('Group call feature will be implemented');
}
</script>

<?php
echo $OUTPUT->footer();
?>
