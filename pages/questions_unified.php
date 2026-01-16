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
 * Unified Questions Management Page
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
$PAGE->set_url('/theme/remui_kids/pages/questions_unified.php');
$PAGE->set_title('Questions Management');
$PAGE->set_heading('Questions Management');
$PAGE->set_pagelayout('base');

// Set active page for sidebar highlighting
$currentpage = ['questions' => true];

// Get active tab
$active_tab = optional_param('tab', 'questions', PARAM_ALPHA);

// Mock questions data
$questions = [
    [
        'id' => 1,
        'title' => 'What wrong in this code',
        'content' => 'I am getting an error when trying to run this JavaScript function. Can someone help me understand what is wrong?',
        'student_name' => 'Katakam koteswararao',
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
        'id' => 3,
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
    ]
];

// Mock chat data
$chat_messages = [
    [
        'id' => 1,
        'sender' => 'student',
        'sender_name' => 'Katakam koteswararao',
        'message' => 'Hi teacher, I need help with the JavaScript function I mentioned in my question.',
        'timestamp' => '2 hours ago',
        'avatar' => 'https://via.placeholder.com/40'
    ],
    [
        'id' => 2,
        'sender' => 'teacher',
        'sender_name' => 'You',
        'message' => 'Hello! I can see your question about the JavaScript function. Can you share the specific code that\'s giving you trouble?',
        'timestamp' => '2 hours ago',
        'avatar' => 'https://via.placeholder.com/40'
    ]
];

// Mock group calls data
$group_calls = [
    [
        'id' => 1,
        'title' => 'Mathematics Problem Solving Session',
        'description' => 'Group discussion for Grade 9 students struggling with algebra',
        'grade' => 'Grade 9',
        'subject' => 'Mathematics',
        'participants' => 8,
        'max_participants' => 12,
        'scheduled_time' => 'Today, 3:00 PM',
        'status' => 'scheduled',
        'duration' => '45 minutes',
        'type' => 'video'
    ],
    [
        'id' => 2,
        'title' => 'Science Lab Discussion',
        'description' => 'Review of chemistry experiments and lab reports',
        'grade' => 'Grade 10',
        'subject' => 'Science',
        'participants' => 6,
        'max_participants' => 10,
        'scheduled_time' => 'Tomorrow, 2:00 PM',
        'status' => 'scheduled',
        'duration' => '60 minutes',
        'type' => 'video'
    ]
];

echo $OUTPUT->header();
?>

<div class="teacher-css-wrapper">
    <div class="teacher-dashboard-wrapper">
        
        <?php include(__DIR__ . '/../teacher/includes/sidebar.php'); ?>
        
        <div class="teacher-main-content">
            <div class="container-fluid">
        <!-- Page Header -->
        <div class="page-header">
            <div class="header-content">
                <h1 class="page-title">Questions Management</h1>
                <p class="page-subtitle">Manage student questions, chat, and group calls</p>
            </div>
            <div class="header-actions">
                <button class="btn-primary" onclick="createNewQuestion()">
                    <i class="fa fa-plus"></i> New Question
                </button>
            </div>
        </div>

        <!-- Tab Navigation -->
        <div class="questions-tabs">
            <div class="tab-nav">
                <button class="tab-btn <?php echo $active_tab === 'questions' ? 'active' : ''; ?>" onclick="switchTab('questions')">
                    <i class="fa fa-question-circle"></i>
                    <span>All Questions</span>
                    <span class="tab-badge"><?php echo count($questions); ?></span>
                </button>
                <button class="tab-btn <?php echo $active_tab === 'create' ? 'active' : ''; ?>" onclick="switchTab('create')">
                    <i class="fa fa-plus"></i>
                    <span>Create Question</span>
                </button>
                <button class="tab-btn <?php echo $active_tab === 'chat' ? 'active' : ''; ?>" onclick="switchTab('chat')">
                    <i class="fa fa-comments"></i>
                    <span>Chat</span>
                    <span class="tab-badge">3</span>
                </button>
                <button class="tab-btn <?php echo $active_tab === 'calls' ? 'active' : ''; ?>" onclick="switchTab('calls')">
                    <i class="fa fa-users"></i>
                    <span>Group Calls</span>
                    <span class="tab-badge"><?php echo count($group_calls); ?></span>
                </button>
            </div>
        </div>

        <!-- Tab Content -->
        <div class="tab-content">
            <!-- All Questions Tab -->
            <div id="tab-questions" class="tab-pane <?php echo $active_tab === 'questions' ? 'active' : ''; ?>">
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

            <!-- Create Question Tab -->
            <div id="tab-create" class="tab-pane <?php echo $active_tab === 'create' ? 'active' : ''; ?>">
                <div class="create-question-form">
                    <div class="form-header">
                        <h3 class="form-title">
                            <i class="fa fa-plus-circle"></i>
                            Create New Question
                        </h3>
                        <p class="form-subtitle">Share your question with the community and get help from teachers and peers</p>
                    </div>
                    
                    <form id="question-form" onsubmit="submitQuestion(event)">
                        <div class="form-group">
                            <label for="question-title" class="form-label">
                                <i class="fa fa-heading"></i>
                                Question Title *
                            </label>
                            <input type="text" id="question-title" name="title" class="form-control" placeholder="Enter a clear, descriptive title for your question" required>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="question-grade" class="form-label">
                                    <i class="fa fa-graduation-cap"></i>
                                    Grade Level *
                                </label>
                                <select id="question-grade" name="grade" class="form-control" required>
                                    <option value="">Select Grade</option>
                                    <option value="Grade 9">Grade 9</option>
                                    <option value="Grade 10">Grade 10</option>
                                    <option value="Grade 11">Grade 11</option>
                                    <option value="Grade 12">Grade 12</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="question-course" class="form-label">
                                    <i class="fa fa-book"></i>
                                    Subject *
                                </label>
                                <select id="question-course" name="course" class="form-control" required>
                                    <option value="">Select Subject</option>
                                    <option value="Mathematics">Mathematics</option>
                                    <option value="Science">Science</option>
                                    <option value="English">English</option>
                                    <option value="History">History</option>
                                    <option value="Geography">Geography</option>
                                    <option value="Computer Science">Computer Science</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="question-content" class="form-label">
                                <i class="fa fa-edit"></i>
                                Question Content *
                            </label>
                            <textarea id="question-content" name="content" class="form-control" rows="6" placeholder="Provide detailed information about your question. Include any relevant context, code snippets, or examples." required></textarea>
                        </div>

                        <div class="form-group">
                            <label for="question-tags" class="form-label">
                                <i class="fa fa-tags"></i>
                                Tags (Optional)
                            </label>
                            <input type="text" id="question-tags" name="tags" class="form-control" placeholder="Enter tags separated by commas (e.g., javascript, functions, debugging)">
                            <small class="form-help">Tags help categorize your question and make it easier to find.</small>
                        </div>

                        <div class="form-actions">
                            <button type="button" class="btn-outline" onclick="saveDraft()">
                                <i class="fa fa-save"></i> Save as Draft
                            </button>
                            <button type="submit" class="btn-primary">
                                <i class="fa fa-paper-plane"></i> Post Question
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Chat Tab -->
            <div id="tab-chat" class="tab-pane <?php echo $active_tab === 'chat' ? 'active' : ''; ?>">
                <div class="chat-container">
                    <div class="chat-sidebar">
                        <div class="chat-header">
                            <h3>Active Chats</h3>
                            <button class="btn-primary" onclick="startNewChat()">
                                <i class="fa fa-plus"></i> New Chat
                            </button>
                        </div>
                        <div class="chat-list">
                            <div class="chat-item active">
                                <div class="chat-avatar">
                                    <img src="https://via.placeholder.com/40" alt="Student">
                                </div>
                                <div class="chat-info">
                                    <div class="chat-name">Katakam koteswararao</div>
                                    <div class="chat-preview">I need help with JavaScript...</div>
                                    <div class="chat-time">2 hours ago</div>
                                </div>
                                <div class="chat-status online"></div>
                            </div>
                            <div class="chat-item">
                                <div class="chat-avatar">
                                    <img src="https://via.placeholder.com/40" alt="Student">
                                </div>
                                <div class="chat-info">
                                    <div class="chat-name">Emma Wilson</div>
                                    <div class="chat-preview">Can you explain quadratic...</div>
                                    <div class="chat-time">1 day ago</div>
                                </div>
                                <div class="chat-status offline"></div>
                            </div>
                        </div>
                    </div>
                    <div class="chat-main">
                        <div class="chat-header">
                            <div class="chat-user-info">
                                <div class="user-avatar">
                                    <img src="https://via.placeholder.com/40" alt="Student Avatar">
                                </div>
                                <div class="user-details">
                                    <h3 class="user-name">Katakam koteswararao</h3>
                                    <p class="user-status">Online</p>
                                </div>
                            </div>
                            <div class="chat-actions">
                                <button class="btn-action" onclick="startVideoCall()" title="Video Call">
                                    <i class="fa fa-video"></i>
                                </button>
                                <button class="btn-action" onclick="startAudioCall()" title="Audio Call">
                                    <i class="fa fa-phone"></i>
                                </button>
                            </div>
                        </div>
                        <div class="chat-messages" id="chat-messages">
                            <?php foreach ($chat_messages as $message): ?>
                            <div class="message <?php echo $message['sender']; ?>">
                                <div class="message-avatar">
                                    <img src="<?php echo $message['avatar']; ?>" alt="<?php echo htmlspecialchars($message['sender_name']); ?>">
                                </div>
                                <div class="message-content">
                                    <div class="message-header">
                                        <span class="sender-name"><?php echo htmlspecialchars($message['sender_name']); ?></span>
                                        <span class="message-time"><?php echo $message['timestamp']; ?></span>
                                    </div>
                                    <div class="message-text">
                                        <?php echo nl2br(htmlspecialchars($message['message'])); ?>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="chat-input">
                            <div class="input-group">
                                <textarea id="message-input" placeholder="Type your message..." rows="3"></textarea>
                                <div class="input-actions">
                                    <button class="btn-attachment" onclick="attachFile()" title="Attach File">
                                        <i class="fa fa-paperclip"></i>
                                    </button>
                                    <button class="btn-emoji" onclick="insertEmoji()" title="Emoji">
                                        <i class="fa fa-smile"></i>
                                    </button>
                                    <button class="btn-send" onclick="sendMessage()" title="Send">
                                        <i class="fa fa-paper-plane"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Group Calls Tab -->
            <div id="tab-calls" class="tab-pane <?php echo $active_tab === 'calls' ? 'active' : ''; ?>">
                <!-- Enhanced Calls Header -->
                <div class="page-header">
                    <div class="header-wrapper">
                        <div class="header-content">
                            <h2 class="page-title"><i class="fa fa-users"></i> Group Calls Management</h2>
                            <p class="page-subtitle">Schedule, manage and conduct collaborative sessions with your students</p>
                        </div>
                        <div class="header-actions">
                            <button class="btn-primary" onclick="createGroupCall()"><i class="fa fa-plus"></i> Create New Meeting</button>
                        </div>
                    </div>
                </div>
                <div class="group-calls-toolbar">
                    <div class="filter-group">
                        <label class="filter-label">Filter by Grade:</label>
                        <select id="grade-filter" class="filter-select" onchange="filterGroupCalls()">
                            <option value="all">All Grades</option>
                            <option value="Grade 9">Grade 9</option>
                            <option value="Grade 10">Grade 10</option>
                            <option value="Grade 11">Grade 11</option>
                            <option value="Grade 12">Grade 12</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label class="filter-label">Filter by Status:</label>
                        <select id="status-filter" class="filter-select" onchange="filterGroupCalls()">
                            <option value="all">All Status</option>
                            <option value="scheduled">Scheduled</option>
                            <option value="live">Live</option>
                            <option value="completed">Completed</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label class="filter-label">Call Type:</label>
                        <select id="type-filter" class="filter-select" onchange="filterGroupCalls()">
                            <option value="all">All Types</option>
                            <option value="video">Video Calls</option>
                            <option value="audio">Audio Calls</option>
                        </select>
                    </div>
                </div>

                <div class="group-calls-grid">
                    <?php foreach ($group_calls as $call): ?>
                    <div class="group-call-card" data-grade="<?php echo $call['grade']; ?>" data-status="<?php echo $call['status']; ?>" data-type="<?php echo $call['type']; ?>">
                        <div class="call-header">
                            <div class="call-title">
                                <h3><?php echo htmlspecialchars($call['title']); ?></h3>
                                <div class="call-meta">
                                    <span class="call-grade"><?php echo $call['grade']; ?></span>
                                    <span class="call-subject"><?php echo $call['subject']; ?></span>
                                </div>
                            </div>
                            <div class="call-status">
                                <span class="status-badge status-<?php echo $call['status']; ?>">
                                    <?php echo ucfirst($call['status']); ?>
                                </span>
                            </div>
                        </div>
                        <div class="call-content">
                            <p class="call-description"><?php echo htmlspecialchars($call['description']); ?></p>
                            <div class="call-details">
                                <div class="detail-item">
                                    <i class="fa fa-users"></i>
                                    <span><?php echo $call['participants']; ?>/<?php echo $call['max_participants']; ?> participants</span>
                                </div>
                                <div class="detail-item">
                                    <i class="fa fa-clock"></i>
                                    <span><?php echo $call['scheduled_time']; ?></span>
                                </div>
                                <div class="detail-item">
                                    <i class="fa fa-<?php echo $call['type'] === 'video' ? 'video' : 'phone'; ?>"></i>
                                    <span><?php echo ucfirst($call['type']); ?> Call</span>
                                </div>
                                <div class="detail-item">
                                    <i class="fa fa-hourglass-half"></i>
                                    <span><?php echo $call['duration']; ?></span>
                                </div>
                            </div>
                        </div>
                        <div class="call-actions">
                            <?php if ($call['status'] === 'scheduled'): ?>
                                <button class="btn-primary" onclick="joinCall(<?php echo $call['id']; ?>)">
                                    <i class="fa fa-play"></i> Join Call
                                </button>
                                <button class="btn-outline" onclick="editCall(<?php echo $call['id']; ?>)">
                                    <i class="fa fa-edit"></i> Edit
                                </button>
                            <?php elseif ($call['status'] === 'live'): ?>
                                <button class="btn-primary" onclick="joinCall(<?php echo $call['id']; ?>)">
                                    <i class="fa fa-video"></i> Join Live
                                </button>
                                <button class="btn-outline" onclick="endCall(<?php echo $call['id']; ?>)">
                                    <i class="fa fa-stop"></i> End Call
                                </button>
                            <?php else: ?>
                                <button class="btn-outline" onclick="viewRecording(<?php echo $call['id']; ?>)">
                                    <i class="fa fa-play-circle"></i> View Recording
                                </button>
                                <button class="btn-outline" onclick="scheduleSimilar(<?php echo $call['id']; ?>)">
                                    <i class="fa fa-calendar-plus"></i> Schedule Similar
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            </div><!-- End container-fluid -->
        </div><!-- End teacher-main-content -->
    </div><!-- End teacher-dashboard-wrapper -->
</div><!-- End teacher-css-wrapper -->

<style>
/* Page Layout with Sidebar */
.teacher-css-wrapper {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    background-color: #f8f9fa;
    min-height: 100vh;
}

.teacher-dashboard-wrapper {
    display: flex;
    min-height: 100vh;
}

.teacher-main-content {
    flex: 1;
    padding: 20px 0 0 0;
    width: 100%;
}

/* Hide Moodle's default main content area */
#region-main,
[role="main"] {
    background: transparent !important;
    box-shadow: none !important;
    border: 0 !important;
    padding: 0 !important;
    margin: 0 !important;
}

#page {
    margin: 0 !important;
    padding: 0 !important;
}

#page-content {
    padding: 0 !important;
    margin: 0 !important;
}

#region-main-box {
    padding: 0 !important;
    margin: 0 !important;
}

.drawers {
    margin: 0 !important;
    padding: 0 !important;
}

.secondary-navigation,
.tertiary-navigation,
.breadcrumb,
#page-header,
.page-context-header,
.activity-navigation,
[data-region="drawer"],
.drawer-toggles {
    display: none !important;
}

.container-fluid {
    padding: 30px;
}

/* Enhanced Tab Navigation */
.questions-tabs {
    background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
    border-radius: 16px;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.08);
    border: 1px solid rgba(99, 102, 241, 0.1);
    margin-bottom: 2rem;
    overflow: hidden;
    backdrop-filter: blur(10px);
}

.tab-nav {
    display: flex;
    background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);
    border-bottom: 2px solid #e2e8f0;
    position: relative;
}

.tab-btn {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.75rem;
    padding: 1.25rem 1.5rem;
    background: transparent;
    border: none;
    color: #64748b;
    font-weight: 600;
    font-size: 0.95rem;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    cursor: pointer;
    position: relative;
    overflow: hidden;
}

.tab-btn::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    opacity: 0;
    transition: opacity 0.3s ease;
    z-index: 0;
}

.tab-btn:hover::before {
    opacity: 0.1;
}

.tab-btn:hover {
    color: #374151;
    transform: translateY(-1px);
}

.tab-btn.active {
    background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
    color: #6366f1;
    border-bottom: 3px solid #6366f1;
    box-shadow: 0 4px 20px rgba(99, 102, 241, 0.15);
    transform: translateY(-2px);
}

.tab-btn.active::before {
    opacity: 0;
}

.tab-btn i {
    font-size: 1.2rem;
    z-index: 1;
    position: relative;
}

.tab-badge {
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    color: white;
    padding: 0.375rem 0.75rem;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 700;
    min-width: 24px;
    text-align: center;
    box-shadow: 0 2px 8px rgba(99, 102, 241, 0.3);
    z-index: 1;
    position: relative;
}

.tab-btn.active .tab-badge {
    background: linear-gradient(135deg, #4f46e5, #7c3aed);
    box-shadow: 0 4px 12px rgba(79, 70, 229, 0.4);
}

/* Enhanced Tab Content */
.tab-content {
    background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
    min-height: 700px;
    border-radius: 0 0 16px 16px;
    position: relative;
}

.tab-pane {
    display: none;
    padding: 2.5rem;
    animation: fadeIn 0.3s ease-in-out;
}

.tab-pane.active {
    display: block;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Page Header Enhancement */
.page-header {
    background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
    color: white;
    padding: 2rem;
    border-radius: 16px;
    margin-bottom: 2rem;
    box-shadow: 0 8px 32px rgba(99, 102, 241, 0.25);
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
    font-size: 2.5rem;
    font-weight: 800;
    margin: 0 0 0.5rem 0;
    text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

.page-subtitle {
    font-size: 1.125rem;
    margin: 0;
    opacity: 0.9;
    font-weight: 500;
}

.header-actions {
    position: relative;
    z-index: 1;
}

.btn-primary {
    background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
    color: #6366f1;
    border: 2px solid rgba(255, 255, 255, 0.2);
    padding: 0.875rem 1.75rem;
    border-radius: 12px;
    font-weight: 700;
    font-size: 1rem;
    box-shadow: 0 4px 20px rgba(255, 255, 255, 0.2);
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.btn-primary:hover {
    background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(255, 255, 255, 0.3);
}

/* Enhanced Questions Toolbar */
.questions-toolbar {
    display: flex;
    gap: 1.5rem;
    margin-bottom: 2rem;
    padding: 1.5rem;
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    border-radius: 16px;
    border: 1px solid rgba(99, 102, 241, 0.1);
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
    flex-wrap: wrap;
    align-items: center;
}

.filter-group {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    background: white;
    padding: 0.75rem 1rem;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
    border: 1px solid rgba(99, 102, 241, 0.1);
}

.filter-label {
    font-weight: 700;
    color: #374151;
    font-size: 0.875rem;
    white-space: nowrap;
}

.filter-select {
    padding: 0.625rem 1rem;
    border: 2px solid #e2e8f0;
    border-radius: 10px;
    background: white;
    font-size: 0.875rem;
    color: #374151;
    min-width: 140px;
    font-weight: 500;
    transition: all 0.3s ease;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
}

.filter-select:focus {
    outline: none;
    border-color: #6366f1;
    box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
    transform: translateY(-1px);
}

/* Enhanced Questions List */
.questions-list {
    max-height: 600px;
    overflow-y: auto;
    padding-right: 0.5rem;
}

.questions-list::-webkit-scrollbar {
    width: 8px;
}

.questions-list::-webkit-scrollbar-track {
    background: #f1f5f9;
    border-radius: 10px;
}

.questions-list::-webkit-scrollbar-thumb {
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    border-radius: 10px;
}

.questions-list::-webkit-scrollbar-thumb:hover {
    background: linear-gradient(135deg, #4f46e5, #7c3aed);
}

.question-item {
    background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
    border: 1px solid rgba(99, 102, 241, 0.1);
    border-radius: 16px;
    padding: 2rem;
    margin-bottom: 1.5rem;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    overflow: hidden;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
}

.question-item::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    opacity: 0;
    transition: opacity 0.3s ease;
}

.question-item:hover::before {
    opacity: 1;
}

.question-item:hover {
    box-shadow: 0 12px 40px rgba(99, 102, 241, 0.15);
    transform: translateY(-4px);
    border-color: rgba(99, 102, 241, 0.2);
}

.question-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 1.5rem;
    gap: 1.5rem;
}

.question-title {
    font-size: 1.375rem;
    font-weight: 700;
    color: #1e293b;
    margin: 0;
    flex: 1;
    line-height: 1.4;
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
}

.question-meta {
    display: flex;
    gap: 0.75rem;
    flex-shrink: 0;
}

.question-grade,
.question-course {
    padding: 0.5rem 1rem;
    border-radius: 12px;
    font-size: 0.8rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
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
    margin-bottom: 1.5rem;
}

.question-text {
    color: #4b5563;
    font-size: 1rem;
    line-height: 1.7;
    margin-bottom: 1rem;
    font-weight: 500;
}

.question-student {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 0.9rem;
    color: #6b7280;
    background: #f8fafc;
    padding: 0.75rem 1rem;
    border-radius: 10px;
    border: 1px solid #e2e8f0;
}

.student-name {
    font-weight: 700;
    color: #374151;
}

.question-date {
    color: #9ca3af;
    font-weight: 500;
}

.question-status {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.5rem;
    padding-top: 1.5rem;
    border-top: 2px solid #f1f5f9;
}

.status-badge {
    padding: 0.5rem 1rem;
    border-radius: 16px;
    font-size: 0.8rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
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
    gap: 1.5rem;
    font-size: 0.8rem;
    color: #6b7280;
    font-weight: 600;
}

.upvotes,
.replies {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 1rem;
    background: #f8fafc;
    border-radius: 10px;
    border: 1px solid #e2e8f0;
}

.question-actions {
    display: flex;
    gap: 0.75rem;
    flex-wrap: wrap;
}

.question-actions .btn-action {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    border: 2px solid #e5e7eb;
    color: #6b7280;
    padding: 0.75rem;
    border-radius: 12px;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 48px;
    height: 48px;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    font-size: 1rem;
    font-weight: 600;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
}

.question-actions .btn-action:hover {
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    color: white;
    border-color: #6366f1;
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(99, 102, 241, 0.3);
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

/* Chat Layout */
.chat-container {
    display: grid;
    grid-template-columns: 320px 1fr;
    height: 650px;
    border: 2px solid rgba(99, 102, 241, 0.1);
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.08);
    background: white;
}

.chat-sidebar {
    background: #f8fafc;
    border-right: 1px solid #e5e7eb;
    display: flex;
    flex-direction: column;
}

.chat-sidebar .chat-header {
    padding: 1rem;
    border-bottom: 1px solid #e5e7eb;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.chat-sidebar .chat-header h3 {
    margin: 0;
    font-size: 1.125rem;
    font-weight: 600;
    color: #374151;
}

.chat-list {
    flex: 1;
    overflow-y: auto;
}

.chat-item {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 1rem;
    border-bottom: 1px solid #e5e7eb;
    cursor: pointer;
    transition: background 0.2s ease;
}

.chat-item:hover {
    background: #e5e7eb;
}

.chat-item.active {
    background: #6366f1;
    color: white;
}

.chat-avatar img {
    width: 40px;
    height: 40px;
    border-radius: 50%;
}

.chat-info {
    flex: 1;
    min-width: 0;
}

.chat-name {
    font-weight: 600;
    font-size: 0.95rem;
    margin-bottom: 0.25rem;
}

.chat-preview {
    font-size: 0.875rem;
    color: #6b7280;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.chat-item.active .chat-preview {
    color: rgba(255, 255, 255, 0.8);
}

.chat-time {
    font-size: 0.75rem;
    color: #9ca3af;
    margin-top: 0.25rem;
}

.chat-item.active .chat-time {
    color: rgba(255, 255, 255, 0.7);
}

.chat-status {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    margin-left: auto;
}

.chat-status.online {
    background: #10b981;
}

.chat-status.offline {
    background: #9ca3af;
}

.chat-main {
    display: flex;
    flex-direction: column;
    background: white;
}

.chat-main .chat-header {
    padding: 1rem 1.5rem;
    border-bottom: 1px solid #e5e7eb;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.chat-user-info {
    display: flex;
    align-items: center;
    gap: 1rem;
}

.user-avatar img {
    width: 40px;
    height: 40px;
    border-radius: 50%;
}

.user-name {
    margin: 0;
    font-size: 1.125rem;
    font-weight: 600;
    color: #1e293b;
}

.user-status {
    margin: 0;
    font-size: 0.875rem;
    color: #10b981;
}

.chat-actions {
    display: flex;
    gap: 0.5rem;
}

.chat-messages {
    flex: 1;
    overflow-y: auto;
    padding: 1rem;
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.message {
    display: flex;
    gap: 0.75rem;
    max-width: 70%;
}

.message.teacher {
    align-self: flex-end;
    flex-direction: row-reverse;
}

.message.student {
    align-self: flex-start;
}

.message-avatar img {
    width: 32px;
    height: 32px;
    border-radius: 50%;
}

.message-content {
    background: #f8f9fa;
    padding: 0.75rem 1rem;
    border-radius: 12px;
    max-width: 100%;
}

.message.teacher .message-content {
    background: #6366f1;
    color: white;
}

.message-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.25rem;
}

.sender-name {
    font-weight: 600;
    font-size: 0.875rem;
}

.message-time {
    font-size: 0.75rem;
    opacity: 0.7;
}

.message-text {
    font-size: 0.95rem;
    line-height: 1.5;
    word-wrap: break-word;
}

.chat-input {
    padding: 1rem;
    border-top: 1px solid #e5e7eb;
}

.input-group {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

#message-input {
    width: 100%;
    padding: 0.75rem;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    resize: none;
    font-family: inherit;
    font-size: 0.95rem;
}

#message-input:focus {
    outline: none;
    border-color: #6366f1;
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
}

.input-actions {
    display: flex;
    justify-content: flex-end;
    gap: 0.5rem;
}

.btn-attachment,
.btn-emoji,
.btn-send {
    background: #f8f9fa;
    border: 1px solid #e5e7eb;
    color: #6b7280;
    padding: 0.5rem;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s ease;
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.btn-send {
    background: #6366f1;
    color: white;
    border-color: #6366f1;
}

.btn-attachment:hover,
.btn-emoji:hover {
    background: #e9ecef;
    color: #495057;
}

.btn-send:hover {
    background: #4f46e5;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(99, 102, 241, 0.25);
}

/* Enhanced Form Styling */
.create-question-form {
    background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
    border-radius: 20px;
    padding: 3rem;
    box-shadow: 0 12px 40px rgba(0, 0, 0, 0.08);
    border: 1px solid rgba(99, 102, 241, 0.1);
    position: relative;
    overflow: hidden;
}

.create-question-form::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
}

.form-header {
    text-align: center;
    margin-bottom: 3rem;
    padding-bottom: 2rem;
    border-bottom: 2px solid #f1f5f9;
}

.form-title {
    font-size: 2rem;
    font-weight: 800;
    color: #1e293b;
    margin: 0 0 1rem 0;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 1rem;
}

.form-title i {
    color: #6366f1;
    font-size: 1.5rem;
}

.form-subtitle {
    color: #64748b;
    font-size: 1.125rem;
    margin: 0;
    font-weight: 500;
}

.form-group {
    margin-bottom: 2rem;
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 2rem;
}

.form-label {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    font-weight: 700;
    color: #374151;
    margin-bottom: 0.75rem;
    font-size: 1rem;
}

.form-label i {
    color: #6366f1;
    font-size: 1.1rem;
    width: 20px;
}

.form-control {
    width: 100%;
    padding: 1rem 1.25rem;
    border: 2px solid #e2e8f0;
    border-radius: 12px;
    font-size: 1rem;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    font-family: inherit;
    background: white;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
}

.form-control:focus {
    outline: none;
    border-color: #6366f1;
    box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
    transform: translateY(-1px);
}

.form-control[type="text"],
.form-control[type="email"] {
    height: 56px;
}

.form-control[type="textarea"] {
    resize: vertical;
    min-height: 140px;
    font-family: inherit;
    line-height: 1.6;
}

.form-help {
    display: block;
    font-size: 0.875rem;
    color: #64748b;
    margin-top: 0.5rem;
    font-weight: 500;
}

.form-actions {
    display: flex;
    gap: 1.5rem;
    justify-content: center;
    margin-top: 3rem;
    padding-top: 2rem;
    border-top: 2px solid #f1f5f9;
}

.btn-outline,
.btn-primary {
    padding: 1rem 2rem;
    border-radius: 12px;
    font-weight: 700;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 0.75rem;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    border: none;
    cursor: pointer;
    font-size: 1rem;
    min-width: 180px;
    justify-content: center;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.btn-outline {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    color: #6366f1;
    border: 2px solid #6366f1;
}

.btn-outline:hover {
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    color: white;
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(99, 102, 241, 0.3);
}

.btn-primary {
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    color: white;
    border: 2px solid transparent;
}

.btn-primary:hover {
    background: linear-gradient(135deg, #4f46e5, #7c3aed);
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(99, 102, 241, 0.4);
}

/* Responsive Design */
@media (max-width: 768px) {
    .tab-nav {
        flex-direction: column;
    }
    
    .tab-btn {
        justify-content: flex-start;
        text-align: left;
    }
    
    .chat-container {
        grid-template-columns: 1fr;
        height: auto;
    }
    
    .chat-sidebar {
        display: none;
    }
    
    .chat-main {
        height: 500px;
    }
    
    .form-row {
        grid-template-columns: 1fr;
        gap: 1.5rem;
    }
    
    .form-actions {
        flex-direction: column;
    }
    
    .btn-outline,
    .btn-primary {
        width: 100%;
    }
    
    .create-question-form {
        padding: 2rem;
    }
}

/* Group Calls styling (unified page) */
.group-calls-toolbar {
    display: flex;
    gap: 1.5rem;
    margin-bottom: 2rem;
    padding: 1.5rem;
    background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
    border-radius: 16px;
    border: 1px solid rgba(99, 102, 241, 0.1);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.06);
    flex-wrap: wrap;
    align-items: center;
}

.group-calls-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
    gap: 1.5rem;
}

.group-call-card {
    background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
    border: 1px solid rgba(99, 102, 241, 0.1);
    border-radius: 16px;
    padding: 1.75rem;
    position: relative;
    overflow: hidden;
    box-shadow: 0 6px 22px rgba(0, 0, 0, 0.06);
    transition: all .3s ease;
}

.group-call-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 14px 42px rgba(99, 102, 241, 0.15);
}

.call-header { display:flex; justify-content:space-between; align-items:flex-start; gap:1rem; margin-bottom:1rem; }
.call-title h3 { margin:0 0 .5rem 0; font-size:1.125rem; font-weight:700; color:#1e293b; }
.call-meta { display:flex; gap:.5rem; flex-wrap:wrap; }
.call-grade, .call-subject { padding:.35rem .7rem; border-radius:10px; font-size:.75rem; font-weight:700; color:#fff; text-transform:uppercase; letter-spacing:.4px; }
.call-grade { background: linear-gradient(135deg,#6366f1,#8b5cf6); }
.call-subject { background: linear-gradient(135deg,#06b6d4,#3b82f6); }
.status-badge { padding:.35rem .8rem; border-radius:9999px; font-size:.75rem; font-weight:700; color:#fff; }
.status-scheduled { background: linear-gradient(135deg,#f59e0b,#f97316); }
.status-live { background: linear-gradient(135deg,#ef4444,#dc2626); animation:pulse 2s infinite; }
.status-completed { background: linear-gradient(135deg,#10b981,#14b8a6); }

@keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.7} }

.call-content { margin-top:.5rem; }
.call-description { color:#4b5563; margin:.5rem 0 1rem; }
.call-details { display:grid; grid-template-columns: 1fr 1fr; gap:.75rem; }
.detail-item { display:flex; align-items:center; gap:.5rem; color:#6b7280; font-size:.9rem; }
.detail-item i { width:16px; color:#6366f1; }

.call-actions { display:flex; gap:.75rem; flex-wrap:wrap; margin-top:1rem; }

@media (max-width: 768px) {
  .group-calls-grid { grid-template-columns: 1fr; }
  .call-details { grid-template-columns: 1fr; }
}
</style>

<script>
// Tab switching
function switchTab(tabName) {
    // Hide all tab panes
    document.querySelectorAll('.tab-pane').forEach(pane => {
        pane.classList.remove('active');
    });
    
    // Remove active class from all tab buttons
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.classList.remove('active');
    });
    
    // Show selected tab pane
    document.getElementById('tab-' + tabName).classList.add('active');
    
    // Add active class to clicked tab button
    event.target.closest('.tab-btn').classList.add('active');
    
    // Update URL without page reload
    const url = new URL(window.location);
    url.searchParams.set('tab', tabName);
    window.history.pushState({}, '', url);
}

// Questions functions
function filterQuestions() {
    const sortBy = document.getElementById('question-sort').value;
    const filter = document.getElementById('question-filter').value;
    const statusFilter = document.getElementById('status-filter').value;
    
    const questionItems = document.querySelectorAll('.question-item');
    questionItems.forEach(item => {
        const grade = item.dataset.grade;
        const course = item.dataset.course;
        const status = item.dataset.status;
        
        let show = true;
        
        if (filter !== 'all') {
            if (filter.startsWith('grade_')) {
                const filterGrade = filter.replace('grade_', 'Grade ');
                show = grade === filterGrade;
            } else {
                show = course.toLowerCase().includes(filter.toLowerCase());
            }
        }
        
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
    console.log('Answering question:', questionId);
    alert('Answer question feature will be implemented');
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
    switchTab('chat');
}

function groupCall(questionId) {
    console.log('Starting group call for question:', questionId);
    switchTab('calls');
}

// Create question functions
function createNewQuestion() {
    switchTab('create');
}

function submitQuestion(event) {
    event.preventDefault();
    const formData = new FormData(event.target);
    const questionData = Object.fromEntries(formData.entries());
    
    console.log('Submitting question:', questionData);
    alert('Question submitted successfully! (This is a demo)');
    
    // Switch to questions tab
    switchTab('questions');
}

function saveDraft() {
    const formData = new FormData(document.getElementById('question-form'));
    const questionData = Object.fromEntries(formData.entries());
    
    console.log('Saving draft:', questionData);
    alert('Draft saved successfully! (This is a demo)');
}

// Chat functions
function startNewChat() {
    alert('Start new chat feature will be implemented');
}

function startVideoCall() {
    alert('Video call feature will be implemented');
}

function startAudioCall() {
    alert('Audio call feature will be implemented');
}

function attachFile() {
    alert('File attachment feature will be implemented');
}

function insertEmoji() {
    alert('Emoji picker will be implemented');
}

function sendMessage() {
    const messageInput = document.getElementById('message-input');
    const message = messageInput.value.trim();
    
    if (message) {
        addMessageToChat('teacher', 'You', message, 'Just now');
        messageInput.value = '';
        
        const chatMessages = document.getElementById('chat-messages');
        chatMessages.scrollTop = chatMessages.scrollHeight;
    }
}

function addMessageToChat(sender, senderName, message, timestamp) {
    const chatMessages = document.getElementById('chat-messages');
    const messageDiv = document.createElement('div');
    messageDiv.className = `message ${sender}`;
    
    messageDiv.innerHTML = `
        <div class="message-avatar">
            <img src="https://via.placeholder.com/40" alt="${senderName}">
        </div>
        <div class="message-content">
            <div class="message-header">
                <span class="sender-name">${senderName}</span>
                <span class="message-time">${timestamp}</span>
            </div>
            <div class="message-text">${message.replace(/\n/g, '<br>')}</div>
        </div>
    `;
    
    chatMessages.appendChild(messageDiv);
}

// Group calls functions
function createGroupCall() {
    // Simple inline modal for unified page (mock)
    const exists = document.getElementById('createMeetingModal');
    if (exists) { exists.remove(); }
    const modal = document.createElement('div');
    modal.id = 'createMeetingModal';
    modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9999;display:flex;align-items:center;justify-content:center;backdrop-filter:blur(4px)';
    modal.innerHTML = `
      <div style="background:linear-gradient(135deg,#fff,#f8fafc);border-radius:20px;padding:2rem;max-width:560px;width:92%;box-shadow:0 25px 80px rgba(0,0,0,.3)">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
          <h3 style="margin:0;font-weight:800;color:#1e293b"><i class="fa fa-video-camera" style="color:#6366f1;margin-right:.5rem"></i>Create New Meeting</h3>
          <button onclick="this.closest('#createMeetingModal').remove()" style="background:none;border:none;font-size:1.25rem;color:#94a3b8;cursor:pointer"><i class="fa fa-times"></i></button>
        </div>
        <div style="display:grid;grid-template-columns:1fr;gap:.75rem">
          <input id="um_title" placeholder="Meeting title" style="padding:.75rem 1rem;border:2px solid #e2e8f0;border-radius:12px"/>
          <textarea id="um_desc" placeholder="Description" rows="3" style="padding:.75rem 1rem;border:2px solid #e2e8f0;border-radius:12px"></textarea>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem">
            <select id="um_grade" style="padding:.75rem 1rem;border:2px solid #e2e8f0;border-radius:12px">
              <option value="">Grade</option><option>Grade 9</option><option>Grade 10</option><option>Grade 11</option><option>Grade 12</option>
            </select>
            <select id="um_subject" style="padding:.75rem 1rem;border:2px solid #e2e8f0;border-radius:12px">
              <option value="">Subject</option><option>Mathematics</option><option>Science</option><option>English</option><option>History</option>
            </select>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem">
            <input id="um_datetime" type="datetime-local" style="padding:.75rem 1rem;border:2px solid #e2e8f0;border-radius:12px"/>
            <input id="um_duration" type="number" min="15" max="180" value="45" style="padding:.75rem 1rem;border:2px solid #e2e8f0;border-radius:12px"/>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem">
            <select id="um_type" style="padding:.75rem 1rem;border:2px solid #e2e8f0;border-radius:12px"><option value="video">Video</option><option value="audio">Audio</option></select>
            <input id="um_max" type="number" min="2" max="50" value="12" style="padding:.75rem 1rem;border:2px solid #e2e8f0;border-radius:12px"/>
          </div>
          <div style="display:flex;gap:.75rem;margin-top:.5rem">
            <button onclick="alert('Meeting scheduled (mock). Integrate to backend next.');document.getElementById('createMeetingModal').remove();" class="btn-primary" style="flex:1"><i class="fa fa-calendar-check"></i> Schedule</button>
            <button onclick="document.getElementById('createMeetingModal').remove();" class="btn-outline" style="flex:.5">Cancel</button>
          </div>
        </div>
      </div>`;
    document.body.appendChild(modal);
}
function filterGroupCalls() {
    const gradeFilter = document.getElementById('grade-filter').value;
    const statusFilter = document.getElementById('status-filter').value;
    const typeFilter = document.getElementById('type-filter').value;
    
    const callCards = document.querySelectorAll('.group-call-card');
    
    callCards.forEach(card => {
        const grade = card.dataset.grade;
        const status = card.dataset.status;
        const type = card.dataset.type;
        
        let show = true;
        
        if (gradeFilter !== 'all' && grade !== gradeFilter) {
            show = false;
        }
        
        if (statusFilter !== 'all' && status !== statusFilter) {
            show = false;
        }
        
        if (typeFilter !== 'all' && type !== typeFilter) {
            show = false;
        }
        
        card.style.display = show ? 'block' : 'none';
    });
}

function joinCall(callId) {
    console.log('Joining call:', callId);
    alert('Joining group call... (This is a demo)');
}

function editCall(callId) {
    console.log('Editing call:', callId);
    alert('Edit call feature will be implemented');
}

function endCall(callId) {
    console.log('Ending call:', callId);
    alert('Ending group call... (This is a demo)');
}

function viewRecording(callId) {
    console.log('Viewing recording:', callId);
    alert('View recording feature will be implemented');
}

function scheduleSimilar(callId) {
    console.log('Scheduling similar call:', callId);
    alert('Schedule similar call feature will be implemented');
}

// Initialize based on URL parameter
document.addEventListener('DOMContentLoaded', function() {
    const urlParams = new URLSearchParams(window.location.search);
    const tab = urlParams.get('tab');
    if (tab) {
        switchTab(tab);
    }
    
    // Auto-scroll chat to bottom
    const chatMessages = document.getElementById('chat-messages');
    if (chatMessages) {
        chatMessages.scrollTop = chatMessages.scrollHeight;
    }
});

// Send message on Enter (but allow Shift+Enter for new lines)
document.addEventListener('DOMContentLoaded', function() {
    const messageInput = document.getElementById('message-input');
    if (messageInput) {
        messageInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        });
    }
});
</script>

<?php
echo $OUTPUT->footer();
?>
