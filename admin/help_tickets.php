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
 * Admin dashboard for managing help tickets
 *
 * @package    theme_remui_kids
 * @copyright  2025 Kodeit
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$PAGE->set_url(new moodle_url('/theme/remui_kids/admin/help_tickets.php'));
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title('Help Tickets Management');
$PAGE->set_heading('Help Tickets Management');

// Get filter parameters
$status = optional_param('status', '', PARAM_ALPHA);
$category = optional_param('category', '', PARAM_ALPHA);
$ticketid = optional_param('ticketid', 0, PARAM_INT);

echo $OUTPUT->header();

?>

<style>
.help-admin-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 20px;
}

.help-admin-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
    padding-bottom: 20px;
    border-bottom: 2px solid #e9ecef;
}

.help-admin-title {
    font-size: 28px;
    font-weight: 700;
    color: #1f2937;
    margin: 0;
}

.help-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.help-stat-card {
    background: white;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    border-left: 4px solid #667eea;
}

.help-stat-card.open {
    border-left-color: #3b82f6;
}

.help-stat-card.in_progress {
    border-left-color: #f59e0b;
}

.help-stat-card.resolved {
    border-left-color: #10b981;
}

.help-stat-card.closed {
    border-left-color: #6b7280;
}

.help-stat-label {
    font-size: 14px;
    color: #6b7280;
    margin-bottom: 8px;
}

.help-stat-value {
    font-size: 32px;
    font-weight: 700;
    color: #1f2937;
}

.help-filters {
    background: white;
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 20px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    display: flex;
    gap: 15px;
    align-items: center;
    flex-wrap: wrap;
}

.help-filter-group {
    display: flex;
    flex-direction: column;
    gap: 5px;
}

.help-filter-label {
    font-size: 12px;
    font-weight: 600;
    color: #6b7280;
    text-transform: uppercase;
}

.help-filter-select {
    padding: 10px 15px;
    border: 2px solid #e9ecef;
    border-radius: 8px;
    font-size: 14px;
    min-width: 180px;
}

.help-tickets-table {
    background: white;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.help-tickets-table table {
    width: 100%;
    border-collapse: collapse;
}

.help-tickets-table th {
    background: #f8f9fa;
    padding: 15px;
    text-align: left;
    font-weight: 600;
    color: #495057;
    font-size: 14px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.help-tickets-table td {
    padding: 15px;
    border-top: 1px solid #e9ecef;
    font-size: 14px;
}

.help-tickets-table tr:hover {
    background: #f8f9fa;
    cursor: pointer;
}

.help-ticket-badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
}

.help-ticket-badge.open {
    background: #e7f3ff;
    color: #0066cc;
}

.help-ticket-badge.in_progress {
    background: #fff4e6;
    color: #f59e0b;
}

.help-ticket-badge.resolved {
    background: #e6f9f0;
    color: #059669;
}

.help-ticket-badge.closed {
    background: #f3f4f6;
    color: #6b7280;
}

.help-priority-badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
}

.help-priority-badge.low {
    background: #e0e7ff;
    color: #4f46e5;
}

.help-priority-badge.normal {
    background: #dbeafe;
    color: #3b82f6;
}

.help-priority-badge.high {
    background: #fed7aa;
    color: #ea580c;
}

.help-priority-badge.urgent {
    background: #fecaca;
    color: #dc2626;
}

.help-action-btn {
    padding: 6px 12px;
    background: #667eea;
    color: white;
    border: none;
    border-radius: 6px;
    font-size: 12px;
    cursor: pointer;
    transition: all 0.2s;
}

.help-action-btn:hover {
    background: #5568d3;
    transform: translateY(-1px);
}

.help-empty-state {
    padding: 60px 20px;
    text-align: center;
    color: #9ca3af;
}

.help-ticket-detail-modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
    z-index: 10000;
    align-items: center;
    justify-content: center;
}

.help-ticket-detail-modal.active {
    display: flex;
}

.help-ticket-detail-content {
    background: white;
    border-radius: 16px;
    width: 90%;
    max-width: 1000px;
    max-height: 90vh;
    overflow-y: auto;
    padding: 30px;
}

.help-detail-actions {
    display: flex;
    gap: 10px;
    margin-top: 20px;
    padding-top: 20px;
    border-top: 2px solid #e9ecef;
}

.help-btn {
    padding: 10px 20px;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}

.help-btn-primary {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
}

.help-btn-success {
    background: #10b981;
    color: white;
}

.help-btn-danger {
    background: #ef4444;
    color: white;
}

.help-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}
</style>

<div class="help-admin-container">
    <div class="help-admin-header">
        <h1 class="help-admin-title">
            <i class="fa fa-life-ring"></i> Help Tickets Management
        </h1>
    </div>

    <?php
    // Get statistics
    $stats = [
        'total' => $DB->count_records('theme_remui_kids_helptickets'),
        'open' => $DB->count_records('theme_remui_kids_helptickets', ['status' => 'open']),
        'in_progress' => $DB->count_records('theme_remui_kids_helptickets', ['status' => 'in_progress']),
        'resolved' => $DB->count_records('theme_remui_kids_helptickets', ['status' => 'resolved']),
        'closed' => $DB->count_records('theme_remui_kids_helptickets', ['status' => 'closed']),
    ];
    ?>

    <div class="help-stats-grid">
        <div class="help-stat-card">
            <div class="help-stat-label">Total Tickets</div>
            <div class="help-stat-value"><?php echo $stats['total']; ?></div>
        </div>
        <div class="help-stat-card open">
            <div class="help-stat-label">Open</div>
            <div class="help-stat-value"><?php echo $stats['open']; ?></div>
        </div>
        <div class="help-stat-card in_progress">
            <div class="help-stat-label">In Progress</div>
            <div class="help-stat-value"><?php echo $stats['in_progress']; ?></div>
        </div>
        <div class="help-stat-card resolved">
            <div class="help-stat-label">Resolved</div>
            <div class="help-stat-value"><?php echo $stats['resolved']; ?></div>
        </div>
        <div class="help-stat-card closed">
            <div class="help-stat-label">Closed</div>
            <div class="help-stat-value"><?php echo $stats['closed']; ?></div>
        </div>
    </div>

    <div class="help-filters">
        <div class="help-filter-group">
            <label class="help-filter-label">Status</label>
            <select class="help-filter-select" id="statusFilter" onchange="applyFilters()">
                <option value="">All Status</option>
                <option value="open" <?php echo $status == 'open' ? 'selected' : ''; ?>>Open</option>
                <option value="in_progress" <?php echo $status == 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                <option value="resolved" <?php echo $status == 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                <option value="closed" <?php echo $status == 'closed' ? 'selected' : ''; ?>>Closed</option>
            </select>
        </div>

        <div class="help-filter-group">
            <label class="help-filter-label">Category</label>
            <select class="help-filter-select" id="categoryFilter" onchange="applyFilters()">
                <option value="">All Categories</option>
                <option value="bug" <?php echo $category == 'bug' ? 'selected' : ''; ?>>Bug Report</option>
                <option value="query" <?php echo $category == 'query' ? 'selected' : ''; ?>>Question/Query</option>
                <option value="feature" <?php echo $category == 'feature' ? 'selected' : ''; ?>>Feature Request</option>
                <option value="general" <?php echo $category == 'general' ? 'selected' : ''; ?>>General Support</option>
            </select>
        </div>

        <button class="help-action-btn" onclick="location.reload()">
            <i class="fa fa-refresh"></i> Refresh
        </button>
    </div>

    <div class="help-tickets-table">
        <?php
        // Build query
        $params = [];
        $where = [];

        if (!empty($status)) {
            $where[] = 't.status = :status';
            $params['status'] = $status;
        }

        if (!empty($category)) {
            $where[] = 't.category = :category';
            $params['category'] = $category;
        }

        $wheresql = empty($where) ? '' : 'WHERE ' . implode(' AND ', $where);

        $sql = "SELECT t.*, u.firstname, u.lastname, u.email,
                       (SELECT COUNT(*) FROM {theme_remui_kids_helpticket_msgs} m WHERE m.ticketid = t.id) as messagecount
                FROM {theme_remui_kids_helptickets} t
                JOIN {user} u ON u.id = t.userid
                $wheresql
                ORDER BY t.timecreated DESC";

        $tickets = $DB->get_records_sql($sql, $params);

        if (empty($tickets)) {
            echo '<div class="help-empty-state">';
            echo '<i class="fa fa-inbox" style="font-size: 64px; opacity: 0.3; margin-bottom: 20px;"></i>';
            echo '<p style="font-size: 18px; font-weight: 500;">No tickets found</p>';
            echo '<p style="font-size: 14px;">Tickets will appear here when users submit them</p>';
            echo '</div>';
        } else {
            echo '<table>';
            echo '<thead>';
            echo '<tr>';
            echo '<th>Ticket #</th>';
            echo '<th>Subject</th>';
            echo '<th>User</th>';
            echo '<th>Category</th>';
            echo '<th>Status</th>';
            echo '<th>Priority</th>';
            echo '<th>Created</th>';
            echo '<th>Messages</th>';
            echo '<th>Actions</th>';
            echo '</tr>';
            echo '</thead>';
            echo '<tbody>';

            foreach ($tickets as $ticket) {
                $username = fullname($ticket);
                $timeago = format_time_ago($ticket->timecreated);
                
                echo '<tr onclick="viewTicket(' . $ticket->id . ')">';
                echo '<td><strong style="color: #667eea;">#' . $ticket->ticketnumber . '</strong></td>';
                echo '<td>' . s($ticket->subject) . '</td>';
                echo '<td>' . s($username) . '<br><small style="color: #9ca3af;">' . s($ticket->email) . '</small></td>';
                echo '<td><span style="text-transform: capitalize;">' . $ticket->category . '</span></td>';
                echo '<td><span class="help-ticket-badge ' . $ticket->status . '">' . str_replace('_', ' ', $ticket->status) . '</span></td>';
                echo '<td><span class="help-priority-badge ' . $ticket->priority . '">' . $ticket->priority . '</span></td>';
                echo '<td>' . $timeago . '</td>';
                echo '<td><i class="fa fa-comments"></i> ' . $ticket->messagecount . '</td>';
                echo '<td><button class="help-action-btn" onclick="event.stopPropagation(); viewTicket(' . $ticket->id . ')">View</button></td>';
                echo '</tr>';
            }

            echo '</tbody>';
            echo '</table>';
        }
        ?>
    </div>
</div>

<!-- Ticket Detail Modal -->
<div class="help-ticket-detail-modal" id="ticketDetailModal">
    <div class="help-ticket-detail-content" id="ticketDetailContent">
        <!-- Content loaded via AJAX -->
    </div>
</div>

<script>
function applyFilters() {
    const status = document.getElementById('statusFilter').value;
    const category = document.getElementById('categoryFilter').value;
    
    let url = window.location.pathname + '?';
    if (status) url += 'status=' + status + '&';
    if (category) url += 'category=' + category;
    
    window.location.href = url;
}

function viewTicket(ticketId) {
    const modal = document.getElementById('ticketDetailModal');
    const content = document.getElementById('ticketDetailContent');
    
    content.innerHTML = '<div style="text-align: center; padding: 40px;"><i class="fa fa-spinner fa-spin" style="font-size: 32px;"></i></div>';
    modal.classList.add('active');
    
    fetch(M.cfg.wwwroot + '/theme/remui_kids/ajax/help_tickets.php?action=get_ticket&ticket_id=' + ticketId + '&sesskey=' + M.cfg.sesskey)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                renderTicketDetail(data.ticket, data.messages);
            } else {
                content.innerHTML = '<p>Error loading ticket</p>';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            content.innerHTML = '<p>Error loading ticket</p>';
        });
}

function renderTicketDetail(ticket, messages) {
    const content = document.getElementById('ticketDetailContent');
    
    let html = `
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px;">
            <div>
                <h2 style="margin: 0 0 10px 0; color: #1f2937;">${escapeHtml(ticket.subject)}</h2>
                <p style="color: #6b7280; margin: 0;">Ticket #${ticket.ticketnumber} • ${ticket.category} • Created ${ticket.timeago}</p>
            </div>
            <button onclick="closeModal()" style="background: #f3f4f6; border: none; width: 36px; height: 36px; border-radius: 50%; cursor: pointer;">
                <i class="fa fa-times"></i>
            </button>
        </div>
        
        <div style="display: flex; gap: 15px; margin-bottom: 20px;">
            <span class="help-ticket-badge ${ticket.status}">${ticket.status.replace('_', ' ')}</span>
            <span class="help-priority-badge ${ticket.priority}">${ticket.priority}</span>
        </div>
        
        <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 20px; max-height: 400px; overflow-y: auto;">
    `;
    
    messages.forEach(msg => {
        const isAdmin = msg.isadmin == 1;
        html += `
            <div style="margin-bottom: 20px; padding: 15px; background: ${isAdmin ? '#e7f3ff' : 'white'}; border-radius: 8px;">
                <div style="display: flex; justify-content: space-between; margin-bottom: 10px;">
                    <strong style="color: ${isAdmin ? '#0066cc' : '#1f2937'};">${escapeHtml(msg.username)} ${isAdmin ? '(Admin)' : ''}</strong>
                    <small style="color: #9ca3af;">${msg.timeago}</small>
                </div>
                <p style="margin: 0; color: #495057; margin-bottom: 10px;">${escapeHtml(msg.message)}</p>
        `;
        
        // Add attachments if they exist
        if (msg.attachments && msg.attachments.length > 0) {
            html += '<div style="display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px;">';
            msg.attachments.forEach(att => {
                html += `
                    <a href="${att.url}" target="_blank" style="
                        display: inline-flex;
                        align-items: center;
                        gap: 6px;
                        padding: 8px 12px;
                        background: white;
                        border: 1px solid #e9ecef;
                        border-radius: 6px;
                        text-decoration: none;
                        color: #667eea;
                        font-size: 13px;
                        transition: all 0.2s ease;
                    " onmouseover="this.style.borderColor='#667eea'; this.style.background='#f0f4ff';" 
                       onmouseout="this.style.borderColor='#e9ecef'; this.style.background='white';">
                        <i class="fa fa-paperclip"></i>
                        ${escapeHtml(att.filename)}
                    </a>
                `;
            });
            html += '</div>';
        }
        
        html += '</div>';
    });
    
    html += `
        </div>
        
        <div style="margin-bottom: 20px;">
            <label style="display: block; margin-bottom: 8px; font-weight: 600; color: #495057;">Reply to Ticket</label>
            <textarea id="adminReplyInput" style="width: 100%; padding: 12px; border: 2px solid #e9ecef; border-radius: 8px; min-height: 100px; font-family: inherit;" placeholder="Type your reply..."></textarea>
        </div>
        
        <div class="help-detail-actions">
            <button class="help-btn help-btn-primary" onclick="sendAdminReply(${ticket.id})">
                <i class="fa fa-paper-plane"></i> Send Reply
            </button>
            <button class="help-btn help-btn-success" onclick="updateTicketStatus(${ticket.id}, 'resolved')">
                <i class="fa fa-check"></i> Mark Resolved
            </button>
            <button class="help-btn help-btn-danger" onclick="updateTicketStatus(${ticket.id}, 'closed')">
                <i class="fa fa-times"></i> Close Ticket
            </button>
        </div>
    `;
    
    content.innerHTML = html;
}

function sendAdminReply(ticketId) {
    const message = document.getElementById('adminReplyInput').value.trim();
    
    if (!message) {
        alert('Please enter a message');
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'add_message');
    formData.append('ticket_id', ticketId);
    formData.append('message', message);
    formData.append('sesskey', M.cfg.sesskey);
    
    fetch(M.cfg.wwwroot + '/theme/remui_kids/ajax/help_tickets.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Reply sent successfully');
            viewTicket(ticketId);
        } else {
            alert('Error: ' + (data.message || 'Failed to send reply'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred');
    });
}

function updateTicketStatus(ticketId, status) {
    if (!confirm('Are you sure you want to change the ticket status to ' + status + '?')) {
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'update_status');
    formData.append('ticket_id', ticketId);
    formData.append('status', status);
    formData.append('sesskey', M.cfg.sesskey);
    
    fetch(M.cfg.wwwroot + '/theme/remui_kids/ajax/help_tickets.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Status updated successfully');
            closeModal();
            location.reload();
        } else {
            alert('Error: ' + (data.message || 'Failed to update status'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred');
    });
}

function closeModal() {
    document.getElementById('ticketDetailModal').classList.remove('active');
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Close modal on outside click
document.getElementById('ticketDetailModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeModal();
    }
});

// Helper function
function format_time_ago(timestamp) {
    const diff = Math.floor(Date.now() / 1000) - timestamp;
    
    if (diff < 60) return 'Just now';
    if (diff < 3600) return Math.floor(diff / 60) + ' minutes ago';
    if (diff < 86400) return Math.floor(diff / 3600) + ' hours ago';
    if (diff < 604800) return Math.floor(diff / 86400) + ' days ago';
    
    return new Date(timestamp * 1000).toLocaleDateString();
}
</script>

<?php

function format_time_ago($timestamp) {
    $diff = time() - $timestamp;

    if ($diff < 60) {
        return 'Just now';
    } else if ($diff < 3600) {
        $mins = floor($diff / 60);
        return $mins . ' minute' . ($mins > 1 ? 's' : '') . ' ago';
    } else if ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    } else if ($diff < 604800) {
        $days = floor($diff / 86400);
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    } else {
        return userdate($timestamp, '%d %B %Y');
    }
}

echo $OUTPUT->footer();

