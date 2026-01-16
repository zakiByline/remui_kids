/**
 * Help Widget - JavaScript functionality
 * @package theme_remui_kids
 * @copyright 2025 Kodeit
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

(function() {
    'use strict';

    // Prevent multiple initializations
    if (window.helpWidgetInitialized) {
        return;
    }
    window.helpWidgetInitialized = true;

    // Global state
    let currentView = 'new'; // 'new', 'list', 'detail'
    let currentTicketId = null;
    let selectedFiles = [];
    let unreadCount = 0;

    // Initialize the help widget
    function initHelpWidget() {
        // Double-check to prevent duplicate initialization
        if (document.getElementById('helpFabMain')) {
            return;
        }
        createHelpButton();
        createHelpModal();
        attachEventListeners();
        checkUnreadTickets();
    }

    // Create floating help button with expandable menu
    function createHelpButton() {
        // Create backdrop
        const backdrop = document.createElement('div');
        backdrop.className = 'help-fab-backdrop';
        backdrop.id = 'helpFabBackdrop';
        document.body.appendChild(backdrop);

        // Create FAB menu
        const buttonsContainer = document.createElement('div');
        buttonsContainer.className = 'help-floating-buttons';
        buttonsContainer.innerHTML = `
            <!-- Triangle Menu with 2 buttons -->
            <div class="help-fab-actions" id="helpFabActions">
                <!-- Chatbot Button (Left) -->
                <button class="help-fab-button chatbot-btn" id="chatbotFabButton" title="AI Assistant">
                    <i class="fa fa-comments"></i>
                </button>
                
                <!-- Help/Support Button (Right) -->
                <button class="help-fab-button help-btn" id="helpFabButton" title="Help & Support">
                    <i class="fa fa-question-circle"></i>
                    <span class="help-badge" id="helpBadge" style="display: none;">0</span>
                </button>
            </div>
            
            <!-- Main FAB Toggle (Bottom) -->
            <button class="help-fab-main" id="helpFabMain" title="Quick Actions">
                <i class="fa fa-plus"></i>
            </button>
        `;
        document.body.appendChild(buttonsContainer);
    }

    // Create help modal
    function createHelpModal() {
        const modalOverlay = document.createElement('div');
        modalOverlay.className = 'help-modal-overlay';
        modalOverlay.id = 'helpModalOverlay';
        modalOverlay.innerHTML = `
            <div class="help-modal" id="helpModal">
                <div class="help-modal-header">
                    <h2 class="help-modal-title">
                        <i class="fa fa-life-ring"></i>
                        <span>Help & Support</span>
                    </h2>
                    <button class="help-close-btn" id="helpCloseBtn">
                        <i class="fa fa-times"></i>
                    </button>
                </div>
                <div class="help-modal-body">
                    <div class="help-sidebar" id="helpSidebar">
                        <button class="help-nav-btn active" data-view="new">
                            <i class="fa fa-plus-circle"></i>
                            <span>New Ticket</span>
                        </button>
                        <button class="help-nav-btn" data-view="list">
                            <i class="fa fa-list"></i>
                            <span>My Tickets</span>
                        </button>
                    </div>
                    <div class="help-main-content" id="helpMainContent">
                        <!-- Content will be dynamically loaded here -->
                    </div>
                </div>
            </div>
        `;
        document.body.appendChild(modalOverlay);
    }

    // Attach event listeners
    function attachEventListeners() {
        // Main FAB toggle
        const fabMain = document.getElementById('helpFabMain');
        const fabActions = document.getElementById('helpFabActions');
        const fabBackdrop = document.getElementById('helpFabBackdrop');
        let fabOpen = false;

        function toggleFabMenu(open) {
            fabOpen = open;
            if (fabOpen) {
                fabMain.classList.add('active');
                fabActions.classList.add('active');
                fabBackdrop.classList.add('active');
            } else {
                fabMain.classList.remove('active');
                fabActions.classList.remove('active');
                fabBackdrop.classList.remove('active');
            }
        }

        fabMain.addEventListener('click', function(e) {
            e.stopPropagation();
            toggleFabMenu(!fabOpen);
        });

        // Close FAB menu when clicking backdrop
        fabBackdrop.addEventListener('click', function() {
            toggleFabMenu(false);
        });

        // Help button - open modal and close FAB menu
        document.getElementById('helpFabButton').addEventListener('click', function(e) {
            e.stopPropagation();
            openHelpModal();
            toggleFabMenu(false);
        });

        // Chatbot button - trigger existing chatbot
        document.getElementById('chatbotFabButton').addEventListener('click', function(e) {
            e.stopPropagation();
            // Try multiple methods to open the AI chatbot

            // Method 1: Click the toggle button if it exists (even if hidden)
            const chatToggle = document.querySelector('#ai-chat-toggle, [id*="ai-chat-toggle"]');
            if (chatToggle) {
                chatToggle.click();
                toggleFabMenu(false);
                return;
            }

            // Method 2: Directly show the chatbot container
            const chatbotContainer = document.querySelector('#ai-assistant-chatbot');
            const chatWindow = document.querySelector('#ai-chat-window');

            if (chatbotContainer && chatWindow) {
                chatbotContainer.classList.remove('ai-assistant-collapsed');
                chatbotContainer.classList.add('ai-assistant-expanded');
                chatWindow.style.display = 'flex';

                // Focus input if exists
                const input = document.querySelector('#ai-input');
                if (input) {
                    setTimeout(() => input.focus(), 100);
                }
            }

            toggleFabMenu(false);
        });

        // Close modal
        document.getElementById('helpCloseBtn').addEventListener('click', closeHelpModal);
        document.getElementById('helpModalOverlay').addEventListener('click', function(e) {
            if (e.target === this) {
                closeHelpModal();
            }
        });

        // Navigation buttons
        document.querySelectorAll('.help-nav-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const view = this.getAttribute('data-view');
                switchView(view);
            });
        });

        // Close on escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeHelpModal();
                if (fabOpen) {
                    toggleFabMenu(false);
                }
            }
        });
    }

    // Open help modal
    function openHelpModal() {
        document.getElementById('helpModalOverlay').classList.add('active');
        document.body.style.overflow = 'hidden';
        switchView('new'); // Always start with new ticket view
    }

    // Close help modal
    function closeHelpModal() {
        document.getElementById('helpModalOverlay').classList.remove('active');
        document.body.style.overflow = '';
    }

    // Switch between views
    function switchView(view) {
        currentView = view;

        // Update navigation buttons
        document.querySelectorAll('.help-nav-btn').forEach(btn => {
            btn.classList.remove('active');
            if (btn.getAttribute('data-view') === view) {
                btn.classList.add('active');
            }
        });

        // Load appropriate content
        const content = document.getElementById('helpMainContent');
        switch (view) {
            case 'new':
                renderNewTicketForm(content);
                break;
            case 'list':
                loadTicketsList(content);
                break;
            case 'detail':
                loadTicketDetail(content, currentTicketId);
                break;
        }
    }

    // Render new ticket form
    function renderNewTicketForm(container) {
        container.innerHTML = `
            <div class="help-form">
                <h3 style="margin-top: 0; color: #1f2937;">Create New Support Ticket</h3>
                <p style="color: #6b7280; margin-bottom: 20px;">
                    Need help with a bug, have a question, or want to suggest a feature? We're here to help!
                </p>

                <div class="help-form-group">
                    <label class="help-form-label">
                        Category <span style="color: #ef4444;">*</span>
                    </label>
                    <select class="help-form-select" id="helpCategory" required>
                        <option value="">Select a category</option>
                        <option value="bug">Bug Report</option>
                        <option value="query">Question/Query</option>
                        <option value="feature">Feature Request</option>
                        <option value="general">General Support</option>
                    </select>
                </div>

                <div class="help-form-group">
                    <label class="help-form-label">
                        Subject <span style="color: #ef4444;">*</span>
                    </label>
                    <input 
                        type="text" 
                        class="help-form-input" 
                        id="helpSubject" 
                        placeholder="Brief summary of your issue"
                        required
                        maxlength="255"
                    />
                </div>

                <div class="help-form-group">
                    <label class="help-form-label">
                        Description <span style="color: #ef4444;">*</span>
                    </label>
                    <textarea 
                        class="help-form-textarea" 
                        id="helpDescription" 
                        placeholder="Please provide detailed information about your issue..."
                        required
                    ></textarea>
                </div>

                <div class="help-form-group">
                    <label class="help-form-label">Priority</label>
                    <select class="help-form-select" id="helpPriority">
                        <option value="low">Low</option>
                        <option value="normal" selected>Normal</option>
                        <option value="high">High</option>
                        <option value="urgent">Urgent</option>
                    </select>
                </div>

                <div class="help-form-group">
                    <label class="help-form-label">Attachments (Optional)</label>
                    <div class="help-file-upload" id="helpFileUpload">
                        <i class="fa fa-cloud-upload" style="font-size: 32px; color: #9ca3af; margin-bottom: 10px;"></i>
                        <p style="margin: 0; color: #6b7280;">
                            <strong>Click to upload</strong> or drag and drop<br>
                            <small>Screenshots, documents, or any relevant files</small>
                        </p>
                        <input 
                            type="file" 
                            class="help-file-input" 
                            id="helpFileInput" 
                            multiple
                            accept="image/*,.pdf,.doc,.docx,.txt"
                        />
                    </div>
                    <div class="help-file-list" id="helpFileList"></div>
                </div>

                <button class="help-submit-btn" id="helpSubmitBtn">
                    <i class="fa fa-paper-plane"></i> Submit Ticket
                </button>
            </div>
        `;

        // Attach form event listeners
        attachFormListeners();
    }

    // Attach form event listeners
    function attachFormListeners() {
        // File upload
        const fileUploadArea = document.getElementById('helpFileUpload');
        const fileInput = document.getElementById('helpFileInput');

        fileUploadArea.addEventListener('click', () => fileInput.click());

        fileInput.addEventListener('change', function(e) {
            handleFiles(e.target.files);
        });

        // Drag and drop
        fileUploadArea.addEventListener('dragover', function(e) {
            e.preventDefault();
            this.classList.add('dragover');
        });

        fileUploadArea.addEventListener('dragleave', function() {
            this.classList.remove('dragover');
        });

        fileUploadArea.addEventListener('drop', function(e) {
            e.preventDefault();
            this.classList.remove('dragover');
            handleFiles(e.dataTransfer.files);
        });

        // Submit button
        document.getElementById('helpSubmitBtn').addEventListener('click', submitTicket);
    }

    // Handle file selection
    function handleFiles(files) {
        Array.from(files).forEach(file => {
            // Check file size (max 10MB)
            if (file.size > 10 * 1024 * 1024) {
                alert('File ' + file.name + ' is too large. Maximum size is 10MB.');
                return;
            }

            selectedFiles.push(file);
        });

        renderFileList();
    }

    // Render file list
    function renderFileList() {
        const fileList = document.getElementById('helpFileList');
        fileList.innerHTML = '';

        selectedFiles.forEach((file, index) => {
            const fileItem = document.createElement('div');
            fileItem.className = 'help-file-item';
            fileItem.innerHTML = `
                <i class="fa fa-file"></i>
                <span>${file.name}</span>
                <button class="help-file-remove" data-index="${index}">
                    <i class="fa fa-times"></i>
                </button>
            `;
            fileList.appendChild(fileItem);
        });

        // Attach remove listeners
        document.querySelectorAll('.help-file-remove').forEach(btn => {
            btn.addEventListener('click', function() {
                const index = parseInt(this.getAttribute('data-index'));
                selectedFiles.splice(index, 1);
                renderFileList();
            });
        });
    }

    // Submit ticket
    function submitTicket() {
        const category = document.getElementById('helpCategory').value;
        const subject = document.getElementById('helpSubject').value;
        const description = document.getElementById('helpDescription').value;
        const priority = document.getElementById('helpPriority').value;

        // Validation
        if (!category || !subject || !description) {
            alert('Please fill in all required fields.');
            return;
        }

        // Disable submit button
        const submitBtn = document.getElementById('helpSubmitBtn');
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Submitting...';

        // Create FormData
        const formData = new FormData();
        formData.append('action', 'create_ticket');
        formData.append('category', category);
        formData.append('subject', subject);
        formData.append('description', description);
        formData.append('priority', priority);
        formData.append('sesskey', M.cfg.sesskey);

        // Add files
        selectedFiles.forEach((file, index) => {
            formData.append('files[]', file);
        });

        // Send AJAX request
        fetch(M.cfg.wwwroot + '/theme/remui_kids/ajax/help_tickets.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Ticket created successfully! Ticket #' + data.ticketnumber);
                    selectedFiles = [];
                    switchView('list');
                } else {
                    alert('Error: ' + (data.message || 'Failed to create ticket'));
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = '<i class="fa fa-paper-plane"></i> Submit Ticket';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred. Please try again.');
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fa fa-paper-plane"></i> Submit Ticket';
            });
    }

    // Load tickets list
    function loadTicketsList(container) {
        container.innerHTML = '<div class="help-loading"><div class="help-spinner"></div></div>';

        fetch(M.cfg.wwwroot + '/theme/remui_kids/ajax/help_tickets.php?action=list_tickets&sesskey=' + M.cfg.sesskey)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    renderTicketsList(container, data.tickets);
                } else {
                    container.innerHTML = '<div class="help-empty-state"><p>Error loading tickets</p></div>';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                container.innerHTML = '<div class="help-empty-state"><p>Error loading tickets</p></div>';
            });
    }

    // Render tickets list
    function renderTicketsList(container, tickets) {
        if (!tickets || tickets.length === 0) {
            container.innerHTML = `
                <div class="help-empty-state">
                    <div class="help-empty-icon"><i class="fa fa-inbox"></i></div>
                    <div class="help-empty-text">No tickets yet</div>
                    <div class="help-empty-subtext">Create your first support ticket to get started</div>
                </div>
            `;
            return;
        }

        let html = '<h3 style="margin-top: 0; color: #1f2937;">My Support Tickets</h3>';
        html += '<div class="help-tickets-list">';

        tickets.forEach(ticket => {
            const hasUnread = ticket.unread > 0;
            html += `
                <div class="help-ticket-card ${hasUnread ? 'unread' : ''}" data-ticket-id="${ticket.id}">
                    <div class="help-ticket-header">
                        <span class="help-ticket-number">#${ticket.ticketnumber}</span>
                        <span class="help-ticket-status ${ticket.status}">${ticket.status.replace('_', ' ')}</span>
                    </div>
                    <div class="help-ticket-subject">${escapeHtml(ticket.subject)}</div>
                    <div class="help-ticket-preview">${escapeHtml(ticket.description)}</div>
                    <div class="help-ticket-meta">
                        <span><i class="fa fa-tag"></i> ${ticket.category}</span>
                        <span><i class="fa fa-clock-o"></i> ${ticket.timeago}</span>
                        ${hasUnread ? '<span style="color: #FF6B6B;"><i class="fa fa-envelope"></i> ' + ticket.unread + ' new</span>' : ''}
                    </div>
                </div>
            `;
        });

        html += '</div>';
        container.innerHTML = html;

        // Attach click listeners
        document.querySelectorAll('.help-ticket-card').forEach(card => {
            card.addEventListener('click', function() {
                currentTicketId = this.getAttribute('data-ticket-id');
                currentView = 'detail';
                loadTicketDetail(container, currentTicketId);
            });
        });
    }

    // Load ticket detail
    function loadTicketDetail(container, ticketId) {
        container.innerHTML = '<div class="help-loading"><div class="help-spinner"></div></div>';

        fetch(M.cfg.wwwroot + '/theme/remui_kids/ajax/help_tickets.php?action=get_ticket&ticket_id=' + ticketId + '&sesskey=' + M.cfg.sesskey)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    renderTicketDetail(container, data.ticket, data.messages);
                    // Mark as read
                    markTicketAsRead(ticketId);
                } else {
                    container.innerHTML = '<div class="help-empty-state"><p>Error loading ticket</p></div>';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                container.innerHTML = '<div class="help-empty-state"><p>Error loading ticket</p></div>';
            });
    }

    // Render ticket detail
    function renderTicketDetail(container, ticket, messages) {
        let html = `
            <div class="help-ticket-detail">
                <div class="help-ticket-detail-header">
                    <button class="help-back-btn" id="helpBackBtn">
                        <i class="fa fa-arrow-left"></i> Back to Tickets
                    </button>
                    <h3 style="margin: 10px 0; color: #1f2937;">${escapeHtml(ticket.subject)}</h3>
                    <div class="help-ticket-info">
                        <div class="help-info-item">
                            <span class="help-info-label">Ticket Number</span>
                            <span class="help-info-value">#${ticket.ticketnumber}</span>
                        </div>
                        <div class="help-info-item">
                            <span class="help-info-label">Status</span>
                            <span class="help-info-value">
                                <span class="help-ticket-status ${ticket.status}">${ticket.status.replace('_', ' ')}</span>
                            </span>
                        </div>
                        <div class="help-info-item">
                            <span class="help-info-label">Category</span>
                            <span class="help-info-value">${ticket.category}</span>
                        </div>
                        <div class="help-info-item">
                            <span class="help-info-label">Created</span>
                            <span class="help-info-value">${ticket.timeago}</span>
                        </div>
                    </div>
                </div>
                
                <div class="help-messages" id="helpMessages">
        `;

        messages.forEach(msg => {
            const isAdmin = msg.isadmin == 1;
            const initials = msg.username.charAt(0).toUpperCase();

            html += `
                <div class="help-message ${isAdmin ? 'admin' : ''}">
                    <div class="help-message-avatar">${initials}</div>
                    <div class="help-message-content">
                        <div class="help-message-bubble">
                            <p class="help-message-text">${escapeHtml(msg.message)}</p>
                        </div>
            `;

            if (msg.attachments && msg.attachments.length > 0) {
                html += '<div class="help-message-attachments">';
                msg.attachments.forEach(att => {
                    html += `
                        <a href="${att.url}" class="help-attachment" target="_blank">
                            <i class="fa fa-paperclip"></i>
                            ${escapeHtml(att.filename)}
                        </a>
                    `;
                });
                html += '</div>';
            }

            html += `
                        <div class="help-message-time">${msg.timeago}</div>
                    </div>
                </div>
            `;
        });

        html += `
                </div>
                
                <div class="help-reply-form">
                    <textarea 
                        class="help-reply-input" 
                        id="helpReplyInput" 
                        placeholder="Type your reply..."
                    ></textarea>
                    <div class="help-reply-actions">
                        <div>
                            <input type="file" id="helpReplyFileInput" multiple style="display: none;">
                            <button class="help-nav-btn" onclick="document.getElementById('helpReplyFileInput').click()" style="display: inline-flex;">
                                <i class="fa fa-paperclip"></i>
                                Attach Files
                            </button>
                        </div>
                        <button class="help-reply-btn" id="helpReplyBtn">
                            <i class="fa fa-paper-plane"></i> Send Reply
                        </button>
                    </div>
                </div>
            </div>
        `;

        container.innerHTML = html;

        // Scroll to bottom of messages
        setTimeout(() => {
            const messagesDiv = document.getElementById('helpMessages');
            if (messagesDiv) {
                messagesDiv.scrollTop = messagesDiv.scrollHeight;
            }
        }, 100);

        // Attach event listeners
        document.getElementById('helpBackBtn').addEventListener('click', () => switchView('list'));
        document.getElementById('helpReplyBtn').addEventListener('click', () => sendReply(ticket.id));
    }

    // Send reply
    function sendReply(ticketId) {
        const message = document.getElementById('helpReplyInput').value.trim();

        if (!message) {
            alert('Please enter a message');
            return;
        }

        const replyBtn = document.getElementById('helpReplyBtn');
        replyBtn.disabled = true;
        replyBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Sending...';

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
                    // Reload ticket detail
                    loadTicketDetail(document.getElementById('helpMainContent'), ticketId);
                } else {
                    alert('Error: ' + (data.message || 'Failed to send reply'));
                    replyBtn.disabled = false;
                    replyBtn.innerHTML = '<i class="fa fa-paper-plane"></i> Send Reply';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred. Please try again.');
                replyBtn.disabled = false;
                replyBtn.innerHTML = '<i class="fa fa-paper-plane"></i> Send Reply';
            });
    }

    // Mark ticket as read
    function markTicketAsRead(ticketId) {
        fetch(M.cfg.wwwroot + '/theme/remui_kids/ajax/help_tickets.php?action=mark_read&ticket_id=' + ticketId + '&sesskey=' + M.cfg.sesskey)
            .then(() => {
                checkUnreadTickets();
            });
    }

    // Check unread tickets
    function checkUnreadTickets() {
        fetch(M.cfg.wwwroot + '/theme/remui_kids/ajax/help_tickets.php?action=unread_count&sesskey=' + M.cfg.sesskey)
            .then(response => response.json())
            .then(data => {
                if (data.success && data.count > 0) {
                    unreadCount = data.count;
                    const badge = document.getElementById('helpBadge');
                    if (badge) {
                        badge.textContent = unreadCount;
                        badge.style.display = 'flex';
                    }
                } else {
                    const badge = document.getElementById('helpBadge');
                    if (badge) {
                        badge.style.display = 'none';
                    }
                }
            })
            .catch(error => console.error('Error checking unread:', error));
    }

    // Utility: Escape HTML
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initHelpWidget);
    } else {
        initHelpWidget();
    }

    // Check for unread tickets periodically (every 2 minutes)
    setInterval(checkUnreadTickets, 120000);

})();