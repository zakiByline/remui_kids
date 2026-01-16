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
 * Group Calls Page
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
$PAGE->set_url('/theme/remui_kids/pages/group_calls.php');
$PAGE->set_title('Group Calls');
$PAGE->set_heading('Group Calls');
$PAGE->set_pagelayout('base');

// Mock group call data
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
    ],
    [
        'id' => 3,
        'title' => 'English Essay Writing Workshop',
        'description' => 'Group session for improving essay writing skills',
        'grade' => 'Grade 11',
        'subject' => 'English',
        'participants' => 4,
        'max_participants' => 8,
        'scheduled_time' => 'Live Now',
        'status' => 'live',
        'duration' => '30 minutes',
        'type' => 'audio'
    ],
    [
        'id' => 4,
        'title' => 'History Timeline Discussion',
        'description' => 'Group study session for World War II timeline',
        'grade' => 'Grade 12',
        'subject' => 'History',
        'participants' => 5,
        'max_participants' => 15,
        'scheduled_time' => 'Completed',
        'status' => 'completed',
        'duration' => '40 minutes',
        'type' => 'video'
    ]
];

echo $OUTPUT->header();
?>

<div class="teacher-main-content">
    <div class="container-fluid">
        <!-- Enhanced Page Header -->
        <div class="page-header">
            <div class="header-wrapper">
                <div class="header-content">
                    <h1 class="page-title">
                        <i class="fa fa-users"></i>
                        Group Calls Management
                    </h1>
                    <p class="page-subtitle">Schedule, manage, and conduct group video and audio calls with students for collaborative learning sessions</p>
                </div>
                <div class="header-actions">
                    <button class="btn-create-call" onclick="createGroupCall()">
                        <i class="fa fa-plus-circle"></i> Create New Meeting
                    </button>
                </div>
            </div>
        </div>

        <!-- Group Calls Toolbar -->
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

        <!-- Group Calls Grid -->
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
</div>

<style>
/* Enhanced Group Calls Page Styling */
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
    padding: 3rem 2.5rem;
    border-radius: 24px;
    margin-bottom: 2.5rem;
    box-shadow: 0 20px 60px rgba(99, 102, 241, 0.3);
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

.header-wrapper {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 3rem;
    position: relative;
    z-index: 1;
}

.header-content {
    flex: 1;
}

.page-title {
    font-size: 3rem;
    font-weight: 900;
    margin: 0 0 1rem 0;
    text-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    display: flex;
    align-items: center;
    gap: 1.25rem;
    letter-spacing: -0.5px;
}

.page-title i {
    font-size: 3rem;
    color: rgba(255, 255, 255, 0.95);
    background: rgba(255, 255, 255, 0.15);
    padding: 0.75rem;
    border-radius: 20px;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.1);
}

.page-subtitle {
    font-size: 1.2rem;
    margin: 0;
    opacity: 0.95;
    font-weight: 500;
    line-height: 1.7;
    max-width: 600px;
}

.header-actions {
    display: flex;
    gap: 1rem;
}

.btn-create-call {
    background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
    color: #6366f1;
    border: 3px solid rgba(255, 255, 255, 0.3);
    padding: 1.25rem 2.5rem;
    border-radius: 18px;
    font-weight: 800;
    font-size: 1.15rem;
    box-shadow: 0 8px 30px rgba(255, 255, 255, 0.25);
    transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    display: inline-flex;
    align-items: center;
    gap: 0.85rem;
    cursor: pointer;
    white-space: nowrap;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.btn-create-call i {
    font-size: 1.4rem;
    animation: pulse-icon 2s ease-in-out infinite;
}

@keyframes pulse-icon {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.1); }
}

.btn-create-call:hover {
    background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
    transform: translateY(-4px) scale(1.05);
    box-shadow: 0 16px 45px rgba(255, 255, 255, 0.4);
    border-color: rgba(255, 255, 255, 0.5);
}

/* Enhanced Group Calls Toolbar */
.group-calls-toolbar {
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

/* Enhanced Group Calls Grid */
.group-calls-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
    gap: 2rem;
}

.group-call-card {
    background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
    border: 1px solid rgba(99, 102, 241, 0.1);
    border-radius: 20px;
    padding: 2.5rem;
    transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    overflow: hidden;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
}

.group-call-card::before {
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

.group-call-card:hover::before {
    opacity: 1;
}

.group-call-card:hover {
    box-shadow: 0 20px 60px rgba(99, 102, 241, 0.15);
    transform: translateY(-6px);
    border-color: rgba(99, 102, 241, 0.2);
}

.call-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 2rem;
    gap: 2rem;
}

.call-title h3 {
    margin: 0 0 1rem 0;
    font-size: 1.375rem;
    font-weight: 800;
    color: #1e293b;
    line-height: 1.4;
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
}

.call-meta {
    display: flex;
    gap: 1rem;
    flex-wrap: wrap;
}

.call-grade,
.call-subject {
    padding: 0.75rem 1.25rem;
    border-radius: 16px;
    font-size: 0.8rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.call-grade {
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    color: white;
}

.call-subject {
    background: linear-gradient(135deg, #06b6d4, #3b82f6);
    color: white;
}

.status-badge {
    padding: 0.75rem 1.5rem;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    box-shadow: 0 6px 20px rgba(0, 0, 0, 0.1);
}

.status-scheduled {
    background: linear-gradient(135deg, #f59e0b, #f97316);
    color: white;
}

.status-live {
    background: linear-gradient(135deg, #ef4444, #dc2626);
    color: white;
    animation: pulse 2s infinite;
}

.status-completed {
    background: linear-gradient(135deg, #10b981, #14b8a6);
    color: white;
}

@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.7; }
}

.call-content {
    margin-bottom: 2rem;
}

.call-description {
    color: #4b5563;
    font-size: 1.125rem;
    line-height: 1.8;
    margin-bottom: 1.5rem;
    font-weight: 500;
}

.call-details {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    padding: 1.5rem;
    border-radius: 16px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
}

.detail-item {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    font-size: 1rem;
    color: #6b7280;
    font-weight: 600;
    padding: 0.75rem;
    background: white;
    border-radius: 12px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
}

.detail-item i {
    width: 20px;
    color: #6366f1;
    font-size: 1.1rem;
}

.call-actions {
    display: flex;
    gap: 1rem;
    flex-wrap: wrap;
}

.btn-primary,
.btn-outline {
    padding: 1rem 1.5rem;
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
    min-width: 160px;
    justify-content: center;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.btn-primary {
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    color: white;
    border: 2px solid transparent;
}

.btn-primary:hover {
    background: linear-gradient(135deg, #4f46e5, #7c3aed);
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(99, 102, 241, 0.3);
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
    box-shadow: 0 8px 25px rgba(99, 102, 241, 0.2);
}

/* Responsive Design */
@media (max-width: 1024px) {
    .header-wrapper {
        flex-direction: column;
        align-items: flex-start;
        gap: 2rem;
    }
    
    .header-actions {
        width: 100%;
    }
    
    .btn-create-call {
        width: 100%;
        justify-content: center;
    }
}

@media (max-width: 768px) {
    .container-fluid {
        padding: 0 1rem;
    }
    
    .page-header {
        padding: 2rem 1.5rem;
    }
    
    .page-title {
        font-size: 1.75rem;
        gap: 0.75rem;
    }
    
    .page-title i {
        font-size: 2rem;
        padding: 0.5rem;
    }
    
    .page-subtitle {
        font-size: 1rem;
    }
    
    .btn-create-call {
        padding: 1rem 1.5rem;
        font-size: 1rem;
    }
    
    .group-calls-toolbar {
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
    
    .group-calls-grid {
        grid-template-columns: 1fr;
    }
    
    .call-header {
        flex-direction: column;
        gap: 1rem;
    }
    
    .call-meta {
        flex-direction: column;
        gap: 0.5rem;
        width: 100%;
    }
    
    .call-details {
        grid-template-columns: 1fr;
    }
    
    .call-actions {
        flex-direction: column;
    }
    
    .btn-primary,
    .btn-outline {
        width: 100%;
        min-width: auto;
    }
}
</style>

<script>
function createGroupCall() {
    // Create a professional modal for creating meetings
    const modalHTML = `
        <div id="createMeetingModal" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0, 0, 0, 0.6); z-index: 9999; display: flex; align-items: center; justify-content: center; backdrop-filter: blur(4px);">
            <div style="background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%); border-radius: 24px; padding: 3rem; max-width: 600px; width: 90%; box-shadow: 0 25px 80px rgba(0, 0, 0, 0.3); animation: slideDown 0.3s ease;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
                    <h2 style="margin: 0; font-size: 2rem; font-weight: 800; color: #1e293b; display: flex; align-items: center; gap: 1rem;">
                        <i class="fa fa-video-camera" style="color: #6366f1;"></i>
                        Create New Meeting
                    </h2>
                    <button onclick="closeModal()" style="background: none; border: none; font-size: 1.5rem; color: #94a3b8; cursor: pointer; padding: 0.5rem; border-radius: 8px; transition: all 0.2s;">
                        <i class="fa fa-times"></i>
                    </button>
                </div>
                
                <form id="createMeetingForm" style="display: flex; flex-direction: column; gap: 1.5rem;">
                    <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                        <label style="font-weight: 700; color: #374151; font-size: 0.95rem;">Meeting Title *</label>
                        <input type="text" id="meetingTitle" required style="padding: 0.875rem 1rem; border: 2px solid #e2e8f0; border-radius: 12px; font-size: 1rem; transition: all 0.3s;" placeholder="e.g., Mathematics Problem Solving Session">
                    </div>
                    
                    <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                        <label style="font-weight: 700; color: #374151; font-size: 0.95rem;">Description *</label>
                        <textarea id="meetingDescription" required style="padding: 0.875rem 1rem; border: 2px solid #e2e8f0; border-radius: 12px; font-size: 1rem; min-height: 100px; resize: vertical; transition: all 0.3s;" placeholder="Describe the purpose of this meeting"></textarea>
                    </div>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                            <label style="font-weight: 700; color: #374151; font-size: 0.95rem;">Grade *</label>
                            <select id="meetingGrade" required style="padding: 0.875rem 1rem; border: 2px solid #e2e8f0; border-radius: 12px; font-size: 1rem; transition: all 0.3s;">
                                <option value="">Select Grade</option>
                                <option value="Grade 9">Grade 9</option>
                                <option value="Grade 10">Grade 10</option>
                                <option value="Grade 11">Grade 11</option>
                                <option value="Grade 12">Grade 12</option>
                            </select>
                        </div>
                        
                        <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                            <label style="font-weight: 700; color: #374151; font-size: 0.95rem;">Subject *</label>
                            <select id="meetingSubject" required style="padding: 0.875rem 1rem; border: 2px solid #e2e8f0; border-radius: 12px; font-size: 1rem; transition: all 0.3s;">
                                <option value="">Select Subject</option>
                                <option value="Mathematics">Mathematics</option>
                                <option value="Science">Science</option>
                                <option value="English">English</option>
                                <option value="History">History</option>
                                <option value="Geography">Geography</option>
                            </select>
                        </div>
                    </div>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                            <label style="font-weight: 700; color: #374151; font-size: 0.95rem;">Date & Time *</label>
                            <input type="datetime-local" id="meetingDateTime" required style="padding: 0.875rem 1rem; border: 2px solid #e2e8f0; border-radius: 12px; font-size: 1rem; transition: all 0.3s;">
                        </div>
                        
                        <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                            <label style="font-weight: 700; color: #374151; font-size: 0.95rem;">Duration (minutes) *</label>
                            <input type="number" id="meetingDuration" required min="15" max="180" value="45" style="padding: 0.875rem 1rem; border: 2px solid #e2e8f0; border-radius: 12px; font-size: 1rem; transition: all 0.3s;">
                        </div>
                    </div>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                            <label style="font-weight: 700; color: #374151; font-size: 0.95rem;">Call Type *</label>
                            <select id="meetingType" required style="padding: 0.875rem 1rem; border: 2px solid #e2e8f0; border-radius: 12px; font-size: 1rem; transition: all 0.3s;">
                                <option value="video">Video Call</option>
                                <option value="audio">Audio Call</option>
                            </select>
                        </div>
                        
                        <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                            <label style="font-weight: 700; color: #374151; font-size: 0.95rem;">Max Participants *</label>
                            <input type="number" id="meetingMaxParticipants" required min="2" max="50" value="12" style="padding: 0.875rem 1rem; border: 2px solid #e2e8f0; border-radius: 12px; font-size: 1rem; transition: all 0.3s;">
                        </div>
                    </div>
                    
                    <div style="display: flex; gap: 1rem; margin-top: 1rem;">
                        <button type="submit" style="flex: 1; background: linear-gradient(135deg, #6366f1, #8b5cf6); color: white; border: none; padding: 1rem 2rem; border-radius: 12px; font-weight: 700; font-size: 1rem; cursor: pointer; transition: all 0.3s; box-shadow: 0 4px 14px rgba(99, 102, 241, 0.3);">
                            <i class="fa fa-calendar-check"></i> Schedule Meeting
                        </button>
                        <button type="button" onclick="closeModal()" style="flex: 0.4; background: #f1f5f9; color: #64748b; border: 2px solid #e2e8f0; padding: 1rem 2rem; border-radius: 12px; font-weight: 700; font-size: 1rem; cursor: pointer; transition: all 0.3s;">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    `;
    
    document.body.insertAdjacentHTML('beforeend', modalHTML);
    
    // Add animation keyframes
    const style = document.createElement('style');
    style.textContent = `
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        #createMeetingForm input:focus,
        #createMeetingForm select:focus,
        #createMeetingForm textarea:focus {
            outline: none;
            border-color: #6366f1;
            box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
        }
        #createMeetingForm button[type="submit"]:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(99, 102, 241, 0.4);
        }
        #createMeetingForm button[type="button"]:hover {
            background: #e2e8f0;
            border-color: #cbd5e1;
        }
    `;
    document.head.appendChild(style);
    
    // Handle form submission
    document.getElementById('createMeetingForm').addEventListener('submit', function(e) {
        e.preventDefault();
        
        const formData = {
            title: document.getElementById('meetingTitle').value,
            description: document.getElementById('meetingDescription').value,
            grade: document.getElementById('meetingGrade').value,
            subject: document.getElementById('meetingSubject').value,
            dateTime: document.getElementById('meetingDateTime').value,
            duration: document.getElementById('meetingDuration').value,
            type: document.getElementById('meetingType').value,
            maxParticipants: document.getElementById('meetingMaxParticipants').value
        };
        
        console.log('Creating meeting with data:', formData);
        
        // Show success message
        closeModal();
        alert('Meeting scheduled successfully! This will be integrated with Moodle calendar and notification system.');
        
        // TODO: Send data to backend via AJAX
        // In production, this would create the meeting in Moodle
    });
}

function closeModal() {
    const modal = document.getElementById('createMeetingModal');
    if (modal) {
        modal.style.animation = 'fadeOut 0.2s ease';
        setTimeout(() => modal.remove(), 200);
    }
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
    // In production, this would redirect to the video call interface
    alert('🎥 Joining group call...\n\nIn production, this will launch the video conferencing interface (BigBlueButton/Zoom integration).');
}

function editCall(callId) {
    console.log('Editing call:', callId);
    // In production, this would open the edit modal with existing data
    alert('✏️ Edit meeting feature\n\nThis will allow you to modify meeting details, participants, and settings.');
}

function endCall(callId) {
    console.log('Ending call:', callId);
    if (confirm('Are you sure you want to end this live call?')) {
        alert('📴 Ending group call...\n\nThis will disconnect all participants and save the recording.');
    }
}

function viewRecording(callId) {
    console.log('Viewing recording:', callId);
    // In production, this would open the recording player
    alert('📹 View recording feature\n\nThis will open the recorded session for playback and review.');
}

function scheduleSimilar(callId) {
    console.log('Scheduling similar call:', callId);
    // In production, this would pre-fill the create form with existing data
    alert('📅 Schedule similar call\n\nThis will open the create meeting form with details copied from this call.');
}
</script>

<?php
echo $OUTPUT->footer();
?>
