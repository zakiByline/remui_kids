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
 * Reusable Alert/Confirmation Modal Component
 * 
 * This component provides custom styled modals to replace browser alert() and confirm() functions.
 * It can be included in any page within the remui_kids theme.
 *
 * @package    theme_remui_kids
 * @copyright  2025 Kodeit
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();
?>

<!-- Alert/Confirmation Modal System -->
<div id="remuiAlertModal" class="remui-alert-modal" style="display: none;">
    <div class="remui-alert-modal-overlay" onclick="RemuiAlert.close()"></div>
    <div class="remui-alert-modal-content">
        <div class="remui-alert-modal-header">
            <div class="remui-alert-modal-icon-wrapper">
                <i id="remuiAlertIcon" class="remui-alert-modal-icon"></i>
            </div>
            <h3 id="remuiAlertTitle" class="remui-alert-modal-title"></h3>
            <span class="remui-alert-modal-close" onclick="RemuiAlert.close()">&times;</span>
        </div>
        <div class="remui-alert-modal-body">
            <p id="remuiAlertMessage" class="remui-alert-modal-message"></p>
        </div>
        <div class="remui-alert-modal-footer">
            <button id="remuiAlertCancelBtn" class="remui-alert-btn remui-alert-btn-secondary" onclick="RemuiAlert.cancel()" style="display: none;">Cancel</button>
            <button id="remuiAlertConfirmBtn" class="remui-alert-btn remui-alert-btn-primary" onclick="RemuiAlert.confirm()">OK</button>
        </div>
    </div>
</div>

<style>
.remui-alert-modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    z-index: 10000;
    display: flex;
    align-items: center;
    justify-content: center;
    animation: remuiAlertFadeIn 0.2s ease-out;
}

.remui-alert-modal-overlay {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    backdrop-filter: blur(2px);
}

.remui-alert-modal-content {
    position: relative;
    background: white;
    border-radius: 12px;
    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
    max-width: 450px;
    width: 90%;
    max-height: 90vh;
    overflow: hidden;
    animation: remuiAlertSlideIn 0.3s ease-out;
    z-index: 10001;
}

.remui-alert-modal-header {
    display: flex;
    align-items: center;
    padding: 20px 24px;
    border-bottom: 1px solid #e5e7eb;
    gap: 12px;
}

.remui-alert-modal-icon-wrapper {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.remui-alert-modal-icon {
    font-size: 20px;
    color: white;
}

.remui-alert-modal-icon-wrapper.success {
    background: #10b981;
}

.remui-alert-modal-icon-wrapper.error {
    background: #ef4444;
}

.remui-alert-modal-icon-wrapper.warning {
    background: #f59e0b;
}

.remui-alert-modal-icon-wrapper.info {
    background: #3b82f6;
}

.remui-alert-modal-title {
    flex: 1;
    margin: 0;
    font-size: 18px;
    font-weight: 600;
    color: #1f2937;
}

.remui-alert-modal-close {
    font-size: 24px;
    color: #9ca3af;
    cursor: pointer;
    line-height: 1;
    transition: color 0.2s;
    width: 24px;
    height: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.remui-alert-modal-close:hover {
    color: #374151;
}

.remui-alert-modal-body {
    padding: 24px;
}

.remui-alert-modal-message {
    margin: 0;
    font-size: 15px;
    line-height: 1.6;
    color: #4b5563;
    white-space: pre-wrap;
    word-wrap: break-word;
}

.remui-alert-modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    padding: 16px 24px;
    border-top: 1px solid #e5e7eb;
    background: #f9fafb;
}

.remui-alert-btn {
    padding: 10px 20px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    border: none;
    transition: all 0.2s;
    min-width: 80px;
}

.remui-alert-btn-primary {
    background: #3b82f6;
    color: white;
}

.remui-alert-btn-primary:hover {
    background: #2563eb;
}

.remui-alert-btn-primary:active {
    background: #1d4ed8;
}

.remui-alert-btn-secondary {
    background: white;
    color: #374151;
    border: 1px solid #d1d5db;
}

.remui-alert-btn-secondary:hover {
    background: #f9fafb;
    border-color: #9ca3af;
}

.remui-alert-btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

@keyframes remuiAlertFadeIn {
    from {
        opacity: 0;
    }
    to {
        opacity: 1;
    }
}

@keyframes remuiAlertSlideIn {
    from {
        transform: translateY(-20px);
        opacity: 0;
    }
    to {
        transform: translateY(0);
        opacity: 1;
    }
}

/* Responsive */
@media (max-width: 640px) {
    .remui-alert-modal-content {
        width: 95%;
        max-width: none;
    }
    
    .remui-alert-modal-header {
        padding: 16px 20px;
    }
    
    .remui-alert-modal-body {
        padding: 20px;
    }
    
    .remui-alert-modal-footer {
        padding: 12px 20px;
        flex-direction: column-reverse;
    }
    
    .remui-alert-btn {
        width: 100%;
    }
}
</style>



