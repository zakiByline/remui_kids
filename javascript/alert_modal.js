/**
 * RemUI Alert Modal System
 * 
 * Replaces browser alert() and confirm() with custom styled modals.
 * Usage:
 *   RemuiAlert.show('Success!', 'Operation completed successfully', 'success');
 *   RemuiAlert.confirm('Delete?', 'Are you sure?', (confirmed) => { ... });
 * 
 * @package    theme_remui_kids
 * @copyright  2025 Kodeit
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

(function() {
    'use strict';

    // Create namespace
    window.RemuiAlert = {
        modal: null,
        overlay: null,
        icon: null,
        title: null,
        message: null,
        confirmBtn: null,
        cancelBtn: null,
        iconWrapper: null,
        resolveCallback: null,
        rejectCallback: null,

        init: function() {
            this.modal = document.getElementById('remuiAlertModal');
            if (!this.modal) {
                console.error('RemuiAlert: Modal element not found. Make sure alert_modal.php is included.');
                return;
            }
            this.overlay = this.modal.querySelector('.remui-alert-modal-overlay');
            this.icon = document.getElementById('remuiAlertIcon');
            this.title = document.getElementById('remuiAlertTitle');
            this.message = document.getElementById('remuiAlertMessage');
            this.confirmBtn = document.getElementById('remuiAlertConfirmBtn');
            this.cancelBtn = document.getElementById('remuiAlertCancelBtn');
            this.iconWrapper = this.modal.querySelector('.remui-alert-modal-icon-wrapper');

            // Close on Escape key
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && this.modal.style.display !== 'none') {
                    this.close();
                }
            });
        },

        show: function(title, message, type, onConfirm) {
            // Ensure modal is initialized
            if (!this.modal) {
                this.init();
            }
            if (!this.modal) {
                console.error('RemuiAlert: Cannot show modal - modal element not found');
                if (window.originalAlert) {
                    window.originalAlert(message || title);
                }
                return;
            }
            type = type || 'info';
            this.setType(type);
            this.title.textContent = title || '';
            this.message.textContent = message || '';
            this.confirmBtn.textContent = 'OK';
            this.cancelBtn.style.display = 'none';
            this.confirmBtn.onclick = () => {
                this.close();
                if (onConfirm) onConfirm();
            };
            this.modal.style.display = 'flex';
            setTimeout(() => this.confirmBtn.focus(), 100);
        },

        confirm: function(title, message, onConfirm, onCancel) {
            // Ensure modal is initialized
            if (!this.modal) {
                this.init();
            }
            if (!this.modal) {
                console.error('RemuiAlert: Cannot show confirm - modal element not found');
                if (window.originalConfirm) {
                    const result = window.originalConfirm(message || title);
                    if (result && onConfirm) onConfirm();
                    if (!result && onCancel) onCancel();
                }
                return;
            }
            this.setType('warning');
            this.title.textContent = title || 'Confirm';
            this.message.textContent = message || 'Are you sure?';
            this.confirmBtn.textContent = 'Confirm';
            this.cancelBtn.style.display = 'inline-block';
            this.modal.style.display = 'flex';
            
            this.confirmBtn.onclick = () => {
                this.close();
                if (onConfirm) onConfirm();
            };
            
            this.cancelBtn.onclick = () => {
                this.close();
                if (onCancel) onCancel();
            };
            
            setTimeout(() => this.cancelBtn.focus(), 100);
        },

        success: function(message, title, onConfirm) {
            this.show(title || 'Success', message, 'success', onConfirm);
        },

        error: function(message, title, onConfirm) {
            this.show(title || 'Error', message, 'error', onConfirm);
        },

        warning: function(message, title, onConfirm) {
            this.show(title || 'Warning', message, 'warning', onConfirm);
        },

        info: function(message, title, onConfirm) {
            this.show(title || 'Information', message, 'info', onConfirm);
        },

        setType: function(type) {
            const types = {
                success: { icon: 'fa-check-circle', class: 'success' },
                error: { icon: 'fa-exclamation-circle', class: 'error' },
                warning: { icon: 'fa-exclamation-triangle', class: 'warning' },
                info: { icon: 'fa-info-circle', class: 'info' }
            };

            const config = types[type] || types.info;
            this.icon.className = 'remui-alert-modal-icon fa-solid ' + config.icon;
            this.iconWrapper.className = 'remui-alert-modal-icon-wrapper ' + config.class;
        },

        close: function() {
            this.modal.style.display = 'none';
            if (this.rejectCallback) {
                this.rejectCallback();
                this.rejectCallback = null;
            }
        },

        cancel: function() {
            this.close();
        }
    };

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => RemuiAlert.init());
    } else {
        RemuiAlert.init();
    }

    // Replace browser alert() and confirm() globally
    window.originalAlert = window.alert;
    window.originalConfirm = window.confirm;

    window.alert = function(message) {
        if (typeof RemuiAlert !== 'undefined' && RemuiAlert.modal) {
            return new Promise((resolve) => {
                RemuiAlert.show('Alert', message, 'info', () => resolve(true));
            });
        } else {
            return window.originalAlert(message);
        }
    };

    window.confirm = function(message) {
        if (typeof RemuiAlert !== 'undefined' && RemuiAlert.modal) {
            return new Promise((resolve) => {
                RemuiAlert.confirm('Confirm', message, 
                    () => resolve(true),
                    () => resolve(false)
                );
            });
        } else {
            return window.originalConfirm(message);
        }
    };

})();




