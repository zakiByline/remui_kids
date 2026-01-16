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
 * Create Question Page
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
$PAGE->set_url('/theme/remui_kids/pages/create_question.php');
$PAGE->set_title('Create Question');
$PAGE->set_heading('Create Question');
$PAGE->set_pagelayout('base');

echo $OUTPUT->header();
?>

<div class="teacher-main-content">
    <div class="container-fluid">
        <!-- Enhanced Page Header -->
        <div class="page-header">
            <div class="header-content">
                <h1 class="page-title">
                    <i class="fa fa-plus-circle"></i>
                    Create New Question
                </h1>
                <p class="page-subtitle">Share your question with the community and get help from teachers and peers</p>
            </div>
            <div class="header-actions">
                <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/pages/questions.php" class="btn-outline">
                    <i class="fa fa-arrow-left"></i> Back to Questions
                </a>
            </div>
        </div>

        <!-- Enhanced Create Question Form -->
        <div class="create-question-form">
            <div class="form-header">
                <h3 class="form-title">
                    <i class="fa fa-edit"></i>
                    Question Details
                </h3>
                <p class="form-subtitle">Provide comprehensive information to get the best help from the community</p>
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

                <div class="form-row">
                    <div class="form-group">
                        <label for="question-tags" class="form-label">
                            <i class="fa fa-tags"></i>
                            Tags (Optional)
                        </label>
                        <input type="text" id="question-tags" name="tags" class="form-control" placeholder="Enter tags separated by commas (e.g., javascript, functions, debugging)">
                        <small class="form-help">Tags help categorize your question and make it easier to find.</small>
                    </div>
                    <div class="form-group">
                        <label for="question-priority" class="form-label">
                            <i class="fa fa-exclamation-triangle"></i>
                            Priority
                        </label>
                        <select id="question-priority" name="priority" class="form-control">
                            <option value="normal">Normal</option>
                            <option value="high">High</option>
                            <option value="urgent">Urgent</option>
                        </select>
                    </div>
                </div>

                <div class="form-options">
                    <div class="form-group">
                        <label class="form-checkbox">
                            <input type="checkbox" id="allow-group-discussion" name="allow_group_discussion">
                            <span class="checkbox-label">
                                <i class="fa fa-users"></i>
                                Allow group discussion for this question
                            </span>
                        </label>
                        <small class="form-help">Enable this to allow multiple students to participate in a group discussion.</small>
                    </div>

                    <div class="form-group">
                        <label class="form-checkbox">
                            <input type="checkbox" id="enable-video-call" name="enable_video_call">
                            <span class="checkbox-label">
                                <i class="fa fa-video"></i>
                                Enable video call support
                            </span>
                        </label>
                        <small class="form-help">Allow students to request video calls for this question.</small>
                    </div>
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
</div>

<style>
/* Enhanced Create Question Page Styling */
.teacher-main-content {
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    min-height: 100vh;
    padding: 2rem 0;
}

.container-fluid {
    max-width: 1000px;
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

.btn-outline {
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
    text-decoration: none;
}

.btn-outline:hover {
    background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
    transform: translateY(-3px);
    box-shadow: 0 12px 35px rgba(255, 255, 255, 0.3);
    text-decoration: none;
}

/* Enhanced Form Styling */
.create-question-form {
    background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
    border-radius: 24px;
    padding: 3rem;
    box-shadow: 0 16px 48px rgba(0, 0, 0, 0.08);
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
    height: 5px;
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
}

.form-header {
    text-align: center;
    margin-bottom: 3rem;
    padding-bottom: 2rem;
    border-bottom: 2px solid #f1f5f9;
}

.form-title {
    font-size: 2.25rem;
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
    font-size: 1.75rem;
}

.form-subtitle {
    color: #64748b;
    font-size: 1.125rem;
    margin: 0;
    font-weight: 500;
}

.form-group {
    margin-bottom: 2.5rem;
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
    padding: 1.25rem 1.5rem;
    border: 2px solid #e2e8f0;
    border-radius: 16px;
    font-size: 1rem;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    font-family: inherit;
    background: white;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
}

.form-control:focus {
    outline: none;
    border-color: #6366f1;
    box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
    transform: translateY(-2px);
}

.form-control[type="text"],
.form-control[type="email"] {
    height: 64px;
}

.form-control[type="textarea"] {
    resize: vertical;
    min-height: 160px;
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

.form-options {
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    padding: 2rem;
    border-radius: 16px;
    border: 1px solid #e2e8f0;
    margin: 2rem 0;
}

.form-checkbox {
    display: flex;
    align-items: center;
    gap: 1rem;
    cursor: pointer;
    margin-bottom: 1.5rem;
    padding: 1rem;
    background: white;
    border-radius: 12px;
    border: 1px solid #e2e8f0;
    transition: all 0.3s ease;
}

.form-checkbox:hover {
    background: #f8fafc;
    border-color: #6366f1;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(99, 102, 241, 0.1);
}

.form-checkbox input[type="checkbox"] {
    width: 20px;
    height: 20px;
    accent-color: #6366f1;
    cursor: pointer;
}

.checkbox-label {
    font-weight: 600;
    color: #374151;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    font-size: 1rem;
}

.checkbox-label i {
    color: #6366f1;
    font-size: 1.1rem;
}

.form-actions {
    display: flex;
    gap: 2rem;
    justify-content: center;
    margin-top: 3rem;
    padding-top: 2rem;
    border-top: 2px solid #f1f5f9;
}

.btn-outline,
.btn-primary {
    padding: 1.25rem 2.5rem;
    border-radius: 16px;
    font-weight: 700;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 0.75rem;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    border: none;
    cursor: pointer;
    font-size: 1.125rem;
    min-width: 200px;
    justify-content: center;
    box-shadow: 0 6px 20px rgba(0, 0, 0, 0.1);
}

.btn-outline {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    color: #6366f1;
    border: 2px solid #6366f1;
}

.btn-outline:hover {
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    color: white;
    transform: translateY(-3px);
    box-shadow: 0 12px 35px rgba(99, 102, 241, 0.3);
    text-decoration: none;
}

.btn-primary {
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    color: white;
    border: 2px solid transparent;
}

.btn-primary:hover {
    background: linear-gradient(135deg, #4f46e5, #7c3aed);
    transform: translateY(-3px);
    box-shadow: 0 12px 35px rgba(99, 102, 241, 0.4);
    text-decoration: none;
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
    
    .create-question-form {
        padding: 2rem;
    }
    
    .form-row {
        grid-template-columns: 1fr;
        gap: 1.5rem;
    }
    
    .form-actions {
        flex-direction: column;
        gap: 1rem;
    }
    
    .btn-outline,
    .btn-primary {
        width: 100%;
        min-width: auto;
    }
    
    .form-options {
        padding: 1.5rem;
    }
}
</style>

<script>
function submitQuestion(event) {
    event.preventDefault();
    
    const formData = new FormData(event.target);
    const questionData = {
        title: formData.get('title'),
        grade: formData.get('grade'),
        course: formData.get('course'),
        content: formData.get('content'),
        tags: formData.get('tags'),
        priority: formData.get('priority'),
        allow_group_discussion: formData.get('allow_group_discussion') === 'on',
        enable_video_call: formData.get('enable_video_call') === 'on'
    };
    
    console.log('Submitting question:', questionData);
    
    // Simulate form submission
    alert('Question submitted successfully! (This is a demo)');
    
    // Redirect to questions page
    window.location.href = '<?php echo $CFG->wwwroot; ?>/theme/remui_kids/pages/questions.php';
}

function saveDraft() {
    const formData = new FormData(document.getElementById('question-form'));
    const questionData = {
        title: formData.get('title'),
        grade: formData.get('grade'),
        course: formData.get('course'),
        content: formData.get('content'),
        tags: formData.get('tags'),
        priority: formData.get('priority'),
        allow_group_discussion: formData.get('allow_group_discussion') === 'on',
        enable_video_call: formData.get('enable_video_call') === 'on'
    };
    
    console.log('Saving draft:', questionData);
    alert('Draft saved successfully! (This is a demo)');
}

// Auto-save draft every 30 seconds
setInterval(function() {
    const title = document.getElementById('question-title').value;
    const content = document.getElementById('question-content').value;
    
    if (title || content) {
        console.log('Auto-saving draft...');
        // In a real implementation, this would save to localStorage or send to server
    }
}, 30000);
</script>

<?php
echo $OUTPUT->footer();
?>
