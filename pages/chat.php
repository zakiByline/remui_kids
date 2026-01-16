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
 * Chat Page
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

$question_id = optional_param('question', 0, PARAM_INT);

$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/pages/chat.php', ['question' => $question_id]);
$PAGE->set_title('Chat with Student');
$PAGE->set_heading('Chat with Student');
$PAGE->set_pagelayout('base');

// Mock chat data
$chat_messages = [
    [
        'id' => 1,
        'sender' => 'student',
        'sender_name' => 'Zaki',
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
    ],
    [
        'id' => 3,
        'sender' => 'student',
        'sender_name' => 'Zaki',
        'message' => 'Here is the code:\n\n```javascript\nfunction calculateSum(a, b) {\n    return a + b;\n}\n\nconsole.log(calculateSum(5, 3));\n```\n\nI\'m getting an error when I run this.',
        'timestamp' => '1 hour ago',
        'avatar' => 'https://via.placeholder.com/40'
    ],
    [
        'id' => 4,
        'sender' => 'teacher',
        'sender_name' => 'You',
        'message' => 'The code looks correct to me. What specific error message are you seeing? Can you copy and paste the exact error?',
        'timestamp' => '1 hour ago',
        'avatar' => 'https://via.placeholder.com/40'
    ],
    [
        'id' => 5,
        'sender' => 'student',
        'sender_name' => 'Zaki',
        'message' => 'It says "ReferenceError: calculateSum is not defined" but I can see the function is defined right there.',
        'timestamp' => '30 minutes ago',
        'avatar' => 'https://via.placeholder.com/40'
    ],
    [
        'id' => 6,
        'sender' => 'teacher',
        'sender_name' => 'You',
        'message' => 'Ah, I see the issue! You need to make sure the function is defined before you call it. Try moving the function call after the function definition, or wrap everything in a document ready function if you\'re using this in a browser.',
        'timestamp' => '25 minutes ago',
        'avatar' => 'https://via.placeholder.com/40'
    ]
];

echo $OUTPUT->header();
?>

<div class="teacher-main-content">
    <div class="container-fluid">
        <!-- Enhanced Chat Header -->
        <div class="chat-header">
            <div class="chat-user-info">
                <div class="user-avatar">
                    <img src="https://via.placeholder.com/50" alt="Student Avatar">
                    <div class="online-indicator"></div>
                </div>
                <div class="user-details">
                    <h3 class="user-name">Zaki</h3>
                    <p class="user-status">
                        <span class="status-dot"></span>
                        Online
                    </p>
                </div>
            </div>
            <div class="chat-actions">
                <button class="btn-action" onclick="startVideoCall()" title="Video Call">
                    <i class="fa fa-video"></i>
                </button>
                <button class="btn-action" onclick="startAudioCall()" title="Audio Call">
                    <i class="fa fa-phone"></i>
                </button>
                <button class="btn-action" onclick="groupChat()" title="Group Chat">
                    <i class="fa fa-users"></i>
                </button>
            </div>
        </div>

        <!-- Chat Messages -->
        <div class="chat-container">
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

            <!-- Chat Input -->
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

<style>
/* Enhanced Chat Page Styling */
.teacher-main-content {
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    min-height: 100vh;
    padding: 2rem 0;
}

.container-fluid {
    max-width: 1200px;
    margin: 0 auto;
    padding: 0 2rem;
}

/* Enhanced Chat Header */
.chat-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 2rem 2.5rem;
    background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
    border-radius: 20px 20px 0 0;
    color: white;
    box-shadow: 0 8px 32px rgba(99, 102, 241, 0.25);
    position: relative;
    overflow: hidden;
}

.chat-header::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grain" width="100" height="100" patternUnits="userSpaceOnUse"><circle cx="25" cy="25" r="1" fill="white" opacity="0.1"/><circle cx="75" cy="75" r="1" fill="white" opacity="0.1"/><circle cx="50" cy="10" r="0.5" fill="white" opacity="0.1"/><circle cx="10" cy="60" r="0.5" fill="white" opacity="0.1"/><circle cx="90" cy="40" r="0.5" fill="white" opacity="0.1"/></pattern></defs><rect width="100" height="100" fill="url(%23grain)"/></svg>');
    opacity: 0.3;
}

.chat-user-info {
    display: flex;
    align-items: center;
    gap: 1.5rem;
    position: relative;
    z-index: 1;
}

.user-avatar {
    position: relative;
}

.user-avatar img {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    border: 3px solid rgba(255, 255, 255, 0.2);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.online-indicator {
    position: absolute;
    bottom: 2px;
    right: 2px;
    width: 16px;
    height: 16px;
    background: #10b981;
    border: 3px solid white;
    border-radius: 50%;
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.7; }
}

.user-name {
    margin: 0 0 0.5rem 0;
    font-size: 1.5rem;
    font-weight: 700;
    color: white;
    text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

.user-status {
    margin: 0;
    font-size: 1rem;
    color: rgba(255, 255, 255, 0.9);
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-weight: 500;
}

.status-dot {
    width: 8px;
    height: 8px;
    background: #10b981;
    border-radius: 50%;
    animation: pulse 2s infinite;
}

.chat-actions {
    display: flex;
    gap: 1rem;
    position: relative;
    z-index: 1;
}

.btn-action {
    background: linear-gradient(135deg, rgba(255, 255, 255, 0.2) 0%, rgba(255, 255, 255, 0.1) 100%);
    border: 2px solid rgba(255, 255, 255, 0.3);
    color: white;
    padding: 1rem;
    border-radius: 16px;
    cursor: pointer;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    width: 56px;
    height: 56px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
    font-weight: 600;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.btn-action:hover {
    background: linear-gradient(135deg, rgba(255, 255, 255, 0.3) 0%, rgba(255, 255, 255, 0.2) 100%);
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.2);
}

.btn-action:nth-child(1):hover {
    background: linear-gradient(135deg, #10b981, #14b8a6);
    border-color: #10b981;
}

.btn-action:nth-child(2):hover {
    background: linear-gradient(135deg, #06b6d4, #3b82f6);
    border-color: #06b6d4;
}

.btn-action:nth-child(3):hover {
    background: linear-gradient(135deg, #8b5cf6, #a855f7);
    border-color: #8b5cf6;
}

/* Enhanced Chat Container */
.chat-container {
    background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
    border-radius: 0 0 20px 20px;
    height: 700px;
    display: flex;
    flex-direction: column;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.08);
    border: 1px solid rgba(99, 102, 241, 0.1);
}

.chat-messages {
    flex: 1;
    overflow-y: auto;
    padding: 2rem;
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
}

.chat-messages::-webkit-scrollbar {
    width: 8px;
}

.chat-messages::-webkit-scrollbar-track {
    background: #f1f5f9;
    border-radius: 10px;
}

.chat-messages::-webkit-scrollbar-thumb {
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    border-radius: 10px;
}

.chat-messages::-webkit-scrollbar-thumb:hover {
    background: linear-gradient(135deg, #4f46e5, #7c3aed);
}

.message {
    display: flex;
    gap: 1rem;
    max-width: 75%;
    animation: slideIn 0.3s ease-out;
}

@keyframes slideIn {
    from { opacity: 0; transform: translateY(20px); }
    to { opacity: 1; transform: translateY(0); }
}

.message.teacher {
    align-self: flex-end;
    flex-direction: row-reverse;
}

.message.student {
    align-self: flex-start;
}

.message-avatar img {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    border: 2px solid #e2e8f0;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.message.teacher .message-avatar img {
    border-color: #6366f1;
}

.message-content {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    padding: 1rem 1.5rem;
    border-radius: 20px;
    max-width: 100%;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
    border: 1px solid #e2e8f0;
    position: relative;
}

.message.teacher .message-content {
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    color: white;
    border-color: #6366f1;
    box-shadow: 0 6px 20px rgba(99, 102, 241, 0.2);
}

.message-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.5rem;
}

.sender-name {
    font-weight: 700;
    font-size: 0.9rem;
}

.message.teacher .sender-name {
    color: rgba(255, 255, 255, 0.9);
}

.message-time {
    font-size: 0.8rem;
    opacity: 0.7;
    font-weight: 500;
}

.message-text {
    font-size: 1rem;
    line-height: 1.6;
    word-wrap: break-word;
    font-weight: 500;
}

/* Enhanced Chat Input */
.chat-input {
    padding: 2rem;
    border-top: 2px solid #e2e8f0;
    background: white;
}

.input-group {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

#message-input {
    width: 100%;
    padding: 1.25rem 1.5rem;
    border: 2px solid #e2e8f0;
    border-radius: 16px;
    resize: none;
    font-family: inherit;
    font-size: 1rem;
    background: #f8fafc;
    transition: all 0.3s ease;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
}

#message-input:focus {
    outline: none;
    border-color: #6366f1;
    box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
    background: white;
    transform: translateY(-1px);
}

.input-actions {
    display: flex;
    justify-content: flex-end;
    gap: 1rem;
}

.btn-attachment,
.btn-emoji,
.btn-send {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    border: 2px solid #e5e7eb;
    color: #6b7280;
    padding: 1rem;
    border-radius: 12px;
    cursor: pointer;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    width: 48px;
    height: 48px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.1rem;
    font-weight: 600;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
}

.btn-send {
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    color: white;
    border-color: #6366f1;
    box-shadow: 0 4px 12px rgba(99, 102, 241, 0.25);
}

.btn-attachment:hover,
.btn-emoji:hover {
    background: linear-gradient(135deg, #e9ecef 0%, #dee2e6 100%);
    color: #495057;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.btn-send:hover {
    background: linear-gradient(135deg, #4f46e5, #7c3aed);
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(99, 102, 241, 0.3);
}

/* Responsive Design */
@media (max-width: 768px) {
    .container-fluid {
        padding: 0 1rem;
    }
    
    .chat-header {
        padding: 1.5rem;
    }
    
    .user-avatar img {
        width: 50px;
        height: 50px;
    }
    
    .user-name {
        font-size: 1.25rem;
    }
    
    .chat-actions {
        gap: 0.5rem;
    }
    
    .btn-action {
        width: 48px;
        height: 48px;
        font-size: 1rem;
    }
    
    .chat-container {
        height: 600px;
    }
    
    .chat-messages {
        padding: 1.5rem;
    }
    
    .message {
        max-width: 85%;
    }
    
    .chat-input {
        padding: 1.5rem;
    }
    
    .input-actions {
        gap: 0.5rem;
    }
    
    .btn-attachment,
    .btn-emoji,
    .btn-send {
        width: 44px;
        height: 44px;
        font-size: 1rem;
    }
}
</style>

<script>
function startVideoCall() {
    alert('Video call feature will be implemented');
}

function startAudioCall() {
    alert('Audio call feature will be implemented');
}

function groupChat() {
    alert('Group chat feature will be implemented');
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
        // Add message to chat
        addMessageToChat('teacher', 'You', message, 'Just now');
        messageInput.value = '';
        
        // Scroll to bottom
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

// Auto-scroll to bottom on page load
document.addEventListener('DOMContentLoaded', function() {
    const chatMessages = document.getElementById('chat-messages');
    chatMessages.scrollTop = chatMessages.scrollHeight;
});

// Send message on Enter (but allow Shift+Enter for new lines)
document.getElementById('message-input').addEventListener('keydown', function(e) {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        sendMessage();
    }
});
</script>

<?php
echo $OUTPUT->footer();
?>
