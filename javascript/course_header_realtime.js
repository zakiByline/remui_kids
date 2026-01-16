/**
 * Real-time course header data updater
 * 
 * Fetches and updates course header statistics periodically
 * 
 * @package theme_remui_kids
 * @copyright (c) 2025 Kodeit
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

(function() {
    'use strict';

    // Configuration
    const UPDATE_INTERVAL = 30000; // 30 seconds

    /**
     * Get AJAX URL
     */
    function getAjaxUrl() {
        if (typeof M !== 'undefined' && M.cfg && M.cfg.wwwroot) {
            return M.cfg.wwwroot + '/theme/remui_kids/ajax/get_course_header_data.php';
        }
        // Fallback: try to get from current page URL
        const baseUrl = window.location.origin + window.location.pathname.split('/').slice(0, -3).join('/');
        return baseUrl + '/theme/remui_kids/ajax/get_course_header_data.php';
    }

    /**
     * Get sesskey
     */
    function getSesskey() {
        if (typeof M !== 'undefined' && M.cfg && M.cfg.sesskey) {
            return M.cfg.sesskey;
        }
        // Try to get from form or input
        const sesskeyInput = document.querySelector('input[name="sesskey"]');
        if (sesskeyInput) {
            return sesskeyInput.value;
        }
        return '';
    }

    /**
     * Get course ID from the page
     */
    function getCourseId() {
        // Try to get from data attribute on course header (most reliable)
        // HTML data-course-id becomes dataset.courseId in JavaScript
        const courseHeader = document.querySelector('.course-header-banner');
        if (courseHeader) {
            // Check both camelCase (standard) and direct attribute access
            if (courseHeader.dataset.courseId) {
                const id = parseInt(courseHeader.dataset.courseId);
                if (id && id > 0) return id;
            }
            // Also try direct getAttribute as fallback
            const attrId = courseHeader.getAttribute('data-course-id');
            if (attrId) {
                const id = parseInt(attrId);
                if (id && id > 0) return id;
            }
        }

        // Try to get from URL parameter
        const urlParams = new URLSearchParams(window.location.search);
        const courseId = urlParams.get('id');

        if (courseId) {
            const id = parseInt(courseId);
            if (id && id > 0) return id;
        }

        // Try to get from page body data attribute
        const body = document.body;
        if (body) {
            if (body.dataset.courseId) {
                const id = parseInt(body.dataset.courseId);
                if (id && id > 0) return id;
            }
            const bodyAttrId = body.getAttribute('data-course-id');
            if (bodyAttrId) {
                const id = parseInt(bodyAttrId);
                if (id && id > 0) return id;
            }
        }

        // Try to get from Moodle course context
        if (typeof M !== 'undefined' && M.cfg && M.cfg.courseId) {
            const id = parseInt(M.cfg.courseId);
            if (id && id > 0) return id;
        }

        return null;
    }

    /**
     * Update header statistics
     */
    function updateHeaderData(data) {
        // Update all stat items
        const statItems = document.querySelectorAll('.course-stats-bottom .stat-item');
        statItems.forEach(item => {
            const statText = item.querySelector('.stat-text');
            if (!statText) return;

            const text = statText.textContent.trim();

            // Update enrolled students
            if (text.includes('Enrolled Students')) {
                statText.textContent = data.enrolledstudentscount + ' Enrolled Students';
            }
            // Update teachers count
            else if (text.includes('Teachers') && !text.includes('Enrolled')) {
                statText.textContent = data.teacherscount + ' Teachers';
            }
            // Update start date
            else if (text.startsWith('Start:')) {
                statText.textContent = 'Start: ' + data.startdate;
            }
            // Update end date
            else if (text.startsWith('End:')) {
                statText.textContent = 'End: ' + data.enddate;
            }
            // Update duration
            else if (text.startsWith('Duration:')) {
                statText.textContent = 'Duration: ' + data.duration;
            }
            // Update sections count
            else if (text.includes('Sections') && !text.includes('Lessons')) {
                statText.textContent = data.sectionscount + ' Sections';
            }
            // Update lessons count
            else if (text.includes('Lessons')) {
                statText.textContent = data.lessonscount + ' Lessons';
            }
        });

        // Update teachers list if needed
        const instructorInfo = document.querySelectorAll('.instructor-info');
        if (instructorInfo.length > 0 && data.teachers && data.teachers.length > 0) {
            // Clear existing instructors (except the first one which might be the container)
            const instructorContainer = document.querySelector('.course-header-main');
            if (instructorContainer) {
                // Remove all instructor-info elements
                const existingInstructors = instructorContainer.querySelectorAll('.instructor-info');
                existingInstructors.forEach(el => el.remove());

                // Add updated instructors
                data.teachers.forEach(teacher => {
                    const instructorDiv = document.createElement('div');
                    instructorDiv.className = 'instructor-info';
                    instructorDiv.innerHTML = `
                        <img src="${teacher.profileimageurl}" alt="${teacher.fullname}" class="instructor-avatar">
                        <span class="instructor-name">${teacher.fullname}</span>
                    `;
                    instructorContainer.appendChild(instructorDiv);
                });
            }
        }
    }

    /**
     * Fetch course header data from server
     */
    function fetchHeaderData(courseId) {
        if (!courseId || courseId <= 0) {
            console.warn('Course header realtime: Invalid course ID:', courseId);
            return;
        }

        const formData = new FormData();
        formData.append('courseid', courseId.toString());
        const sesskey = getSesskey();
        if (sesskey) {
            formData.append('sesskey', sesskey);
        }

        console.debug('Course header realtime: Fetching data for course ID:', courseId, 'URL:', getAjaxUrl());

        fetch(getAjaxUrl(), {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            })
            .then(response => {
                if (!response.ok) {
                    // Try to get error message from response
                    return response.text().then(text => {
                        try {
                            const data = JSON.parse(text);
                            const errorMsg = data.message || data.error || 'Network response was not ok';
                            console.error('Course header realtime: Server error:', errorMsg, data);
                            throw new Error(errorMsg);
                        } catch (parseError) {
                            // If JSON parsing fails, log the raw response
                            console.error('Course header realtime: HTTP error', response.status, '- Response is not JSON');
                            console.error('Course header realtime: Raw response:', text.substring(0, 500));
                            throw new Error('Network response was not ok (HTTP ' + response.status + ')');
                        }
                    });
                }
                return response.json();
            })
            .then(data => {
                if (data.status === 'success') {
                    updateHeaderData(data);
                } else {
                    console.error('Course header realtime: Error from server:', data.error || data.message, data);
                }
            })
            .catch(error => {
                // Handle permission errors gracefully - these are expected in some cases
                const errorMsg = error.message || '';
                if (errorMsg.includes('permissions') ||
                    errorMsg.includes('Access denied') ||
                    errorMsg.includes('do not have access')) {
                    // Permission errors are expected for users without proper access
                    // Don't log as error, just silently fail
                    console.debug('Course header realtime: Permission denied (expected for some users)');
                    return;
                }

                // Log other errors for debugging
                if (errorMsg.includes('Invalid course ID')) {
                    console.warn('Course header realtime: Invalid course ID error - this should not happen if course ID was validated');
                } else {
                    console.error('Course header realtime: Fetch error:', errorMsg || error);
                }
            });
    }

    /**
     * Initialize real-time updates
     */
    function init() {
        // Wait for DOM to be ready
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function() {
                startUpdates();
            });
        } else {
            startUpdates();
        }
    }

    /**
     * Start periodic updates
     */
    function startUpdates() {
        const courseId = getCourseId();

        if (!courseId) {
            console.warn('Course header realtime: Course ID not found, skipping real-time updates');
            console.debug('Course header realtime: Debug info:', {
                url: window.location.href,
                hasCourseHeader: !!document.querySelector('.course-header-banner'),
                courseHeader: document.querySelector('.course-header-banner')
            });
            return;
        }

        // Check if course header exists
        const courseHeader = document.querySelector('.course-header-banner');
        if (!courseHeader) {
            console.warn('Course header realtime: Course header not found on page');
            return;
        }

        console.debug('Course header realtime: Starting updates for course ID:', courseId);

        // Fetch immediately
        fetchHeaderData(courseId);

        // Set up periodic updates
        setInterval(function() {
            fetchHeaderData(courseId);
        }, UPDATE_INTERVAL);
    }

    // Initialize when script loads
    init();

})();