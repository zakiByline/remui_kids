<?php
use local_communityhub\constants;
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
 * Community & Collaboration Page
 *
 * @package   theme_remui_kids
 * @copyright 2025 Kodeit
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lang_init.php'); // Apply user's selected language
require_once($CFG->libdir . '/filelib.php');
require_once(__DIR__ . '/lib.php'); // Include theme lib.php for theme functions
require_once(__DIR__ . '/lib/highschool_common.php');

require_login();

$systemcontext = context_system::instance();
$PAGE->set_context($systemcontext);
$PAGE->set_url('/theme/remui_kids/community.php');
$PAGE->set_pagelayout('standard');
$PAGE->set_title('Community & Collaboration');
$PAGE->add_body_class('community-page');

// Check if user is a high school student
$is_highschool = remui_kids_check_highschool_student($USER, $DB);
if ($is_highschool) {
    $PAGE->add_body_class('has-student-sidebar');
    $PAGE->add_body_class('highschool-page');
}

$cancreatecommunity = has_capability('local/communityhub:create', $systemcontext);
$issuperadmin = is_siteadmin($USER->id);
error_log("issuperadmin: " . print_r($issuperadmin, true));

// Get community ID if viewing specific community
$communityid = optional_param('id', 0, PARAM_INT);

echo $OUTPUT->header();

// Render highschool sidebar if user is highschool student
if ($is_highschool) {
    $sidebar_context = remui_kids_build_highschool_sidebar_context('community', $USER);
    echo $OUTPUT->render_from_template('theme_remui_kids/highschool_sidebar', $sidebar_context);
}
?>

<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@100;200;300;500;600;700;800;900&display=swap');

* {
    font-family: "Inter", sans-serif;
}

html, body {
    height: 100% !important;
    width: 100% !important;
    margin: 0 !important;
    padding: 0 !important;
    overflow-x: hidden;
    background: #f0f4ff;
}

/* Reset Moodle's default main content area and containers */
#region-main,
[role="main"],
#page-content {
    background: transparent;
    box-shadow: none;
    border: 0;
    padding: 0 !important;
    margin: 0 !important;
    width: 100% !important;
    max-width: 100% !important;
    overflow-x: visible;
}

/* Ensure page takes full viewport */
#page {
    width: 100%;
    max-width: 100%;
    overflow-x: visible;
    padding-top: 0 !important;
    margin-top: 0 !important;
}
.footer-copyright-wrapper ,.footer-mainsection-wrapper{
        display: none !important;
    } 
    #page-footer {
        background: transparent !important;
    }
/* Break out of any container constraints - full width */
.community-page-wrapper {
    padding: 32px 48px 48px;
    padding-top: 32px;
    width: 100vw;
    max-width: 100vw;
    min-height: 100vh;
    height: auto;
    background-color: #ffffff;
    box-sizing: border-box;
    position: relative;
    margin: 0;
    margin-left: calc(50% - 50vw);
    margin-right: calc(50% - 50vw);
    border-radius: 0;
    box-shadow: none;
}

/* ========== HIGHSCHOOL SIDEBAR STYLES - ONLY FOR HIGHSCHOOL USERS ========== */

/* Adjust layout when highschool sidebar is present - ONLY for highschool users */
body.has-student-sidebar.highschool-page .community-page-wrapper {
    width: calc(100vw - 280px);
    max-width: calc(100vw - 280px);
    margin-left: 10px;  /* Push content to the right to make space for sidebar */
    margin-right: 0;
    padding-left: 32px;
    padding-right: 32px;
}

/* Container adjustment for highschool users only */
body.has-student-sidebar.highschool-page .container {
    margin-left: 0px !important;
}

/* Page header spacing for highschool users only */
body.has-student-sidebar.highschool-page #page-header {
    margin-bottom: 0px; 
}

/* Ensure sidebar is properly positioned */
body.has-student-sidebar.highschool-page .student-sidebar {
    position: fixed;
    left: 0;
    top: 0;
    height: 100vh;
    width: 280px;
    z-index: 1000;
    overflow-y: auto;
}

::-webkit-scrollbar {
    display: none;
}

.community-top-actions {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
}

.back-button {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    background: #fcfcfc;
    color: rgb(102, 102, 102) !important;
    padding: 10px 20px;
    border-radius: 999px;
    font-weight: 600;
    text-decoration: none;
    border: 1px solid rgb(102, 102, 102);
    transition: all 0.2s ease;
}

.back-button i {
    font-size: 0.9rem;
}

.back-button:hover {
    background: rgb(102, 102, 102);
    color: #ffffff !important;
}

/* Community Header */
.community-header {
    margin-bottom: 32px;
}

.community-header-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 24px;
    flex-wrap: wrap;
}

.community-title-block h1 {
    font-size: 2rem;
    font-weight: 700;
    color: #0f172a;
    margin: 0 0 8px 0;
}

.community-title-block p {
    color: #6b7280;
    margin: 0;
}

.community-switcher {
    margin-left: auto;
    display: flex;
    flex-direction: row;
    gap: 6px;
    min-width: 220px;
}

.community-switcher label {
    font-size: 0.8rem;
    font-weight: 600;
    color: #475569;
    margin-right: 10px;
}

.community-switcher select {
    border: 1px solid #cbd5f5;
    border-radius: 999px;
    padding: 10px 16px;
    font-size: 0.9rem;
    background: #f8faff;
    color: #0f172a;
    min-width: 220px;
    cursor: pointer;
    box-shadow: inset 0 1px 2px rgba(15, 23, 42, 0.08);
}

.community-switcher select:focus {
    outline: none;
    border-color: #2563eb;
    box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.2);
    background: #ffffff;
}

.community-header-actions {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    justify-content: flex-end;
    align-items: center;
    margin-left: auto;
    width: auto;
    margin-top: 0;
}

.community-detail-actions {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
}

.community-empty-state {
    display: none;
    background: linear-gradient(135deg, #eff6ff, #faf5ff);
    border: 1px solid #dbeafe;
    border-radius: 16px;
    padding: 32px;
    margin-bottom: 24px;
    text-align: center;
    color: #0f172a;
    box-shadow: 0 12px 24px rgba(15, 23, 42, 0.08);
}

.community-empty-state h3 {
    margin: 0 0 12px 0;
    font-size: 1.5rem;
}

.community-empty-state p {
    margin: 0;
    color: #475569;
}

.community-list-section {
    display: none;
    margin-bottom: 24px;
    border:0.5px solid #e2e8f0;
    border-radius: 16px;
    padding: 18px 20px;
    background: #fff;
}

.community-list-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
}

.community-list-header h2 {
    margin: 0;
    font-size: 1.5rem;
    color: #0f172a;
}

.community-list-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
    gap: 20px;
}

.community-card {
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    padding: 14px 16px;
    width: 100%;
    min-width: 0;
    box-shadow: 0 6px 16px rgba(15, 23, 42, 0.07);
    cursor: pointer;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
    background: #fff;
}

.community-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 16px 30px rgba(15, 23, 42, 0.12);
}

.community-card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
}

.community-card h5 {
    margin: 0;
    font-size: 1rem;
    color: #0f172a;
}

.community-card-members {
    background: #eef2ff;
    color: #1d4ed8;
    font-size: 0.75rem;
    font-weight: 600;
    padding: 6px 10px;
    border-radius: 999px;
    white-space: nowrap;
    box-shadow: inset 0 1px 1px rgba(255,255,255,0.6);
    border: none;
    outline: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    cursor: pointer;
}

.community-card p {
    margin: 6px 0 6px 0;
    color: #475569;
    font-size: 0.85rem;
}

.community-card-divider {
    height: 1px;
    background: #edf2f7;
    margin-bottom: 12px;
}

.community-card-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
}

.community-card-header-actions {
    display: flex;
    align-items: center;
    gap: 10px;
}

.community-card-chat-icon {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    background: #eef2ff;
    color: #2563eb;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.9rem;
    text-decoration: none;
    transition: background 0.2s ease, color 0.2s ease, transform 0.2s ease;
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.6);
}

.community-card-chat-icon:hover {
    background: #dbeafe;
    color: #1e3a8a;
    transform: translateY(-1px);
}

.community-card-posts {
    font-size: 0.8rem;
    color: #475569;
    display: flex;
    align-items: center;
    gap: 6px;
}

.community-card-owner {
    display: flex;
    align-items: center;
    gap: 8px;
}

.community-card-owner-avatar {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    object-fit: cover;
    background: #e2e8f0;
    border: 1px solid #fff;
    box-shadow: 0 1px 3px rgba(15, 23, 42, 0.2);
}

.community-card-owner-info {
    display: flex;
    flex-direction: column;
    line-height: 1.2;
}

.community-card-owner-name {
    font-size: 0.8rem;
    font-weight: 600;
    color: #0f172a;
    margin: 6px 0px 0px 0px !important;
}

.community-card-owner-date {
    font-size: 0.7rem !important;
    color: #94a3b8;
    margin: 0;
    align-items: right;
    justify-content: right;
    text-align: right;
}

.btn {
    padding: 10px 20px;
    border-radius: 8px;
    font-size: 0.875rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    border: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
}

.btn-primary {
    background: #2563eb !important;
    color: white !important;
}

.btn-primary:hover {
    background: #1d4ed8;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
}

.btn-secondary {
    background: white;
    color: #374151;
    border: 1px solid #d1d5db;
}

.btn-secondary:hover {
    background: #f9fafb;
    border-color: #9ca3af;
}

/* Main Content Grid */
@media (max-width: 1400px) {
    .community-content-grid {
        gap: 18px;
    }
}

@media (max-width: 1200px) {
    .community-content-grid {
        grid-template-columns: 1fr !important;
    }
    
    .community-sidebar-left,
    .community-sidebar-right {
        display: none;
    }
}

/* Mobile responsive for highschool sidebar */
@media (max-width: 768px) {
    body.has-student-sidebar.highschool-page .community-page-wrapper {
        width: 100vw;
        max-width: 100vw;
        margin-left: 0;
        padding-left: 1rem;
        padding-right: 1rem;
    }
    
    body.has-student-sidebar.highschool-page .student-sidebar {
        transform: translateX(-100%);
        transition: transform 0.3s ease;
    }
    
    body.has-student-sidebar.highschool-page .student-sidebar.show {
        transform: translateX(0);
    }
}

/* Sidebar Toggle Button */
.sidebar-toggle-btn {
    position: absolute;
    top: 12px;
    right: -16px;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: white;
    border: 1px solid #e5e7eb;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 10;
    transition: all 0.2s;
    /* Remove all default button styling */
    padding: 0;
    margin: 0;
    outline: none;
    -webkit-appearance: none;
    -moz-appearance: none;
    appearance: none;
    box-sizing: border-box;
}

.sidebar-toggle-btn:focus {
    outline: none;
    border: 1px solid #e5e7eb;
}

.sidebar-toggle-btn:active {
    outline: none;
    border: 1px solid #e5e7eb;
}

.sidebar-toggle-btn:hover {
    background: #f9fafb;
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
}

.community-sidebar-right .sidebar-toggle-btn {
    left: -16px;
    right: auto;
}

.sidebar-toggle-btn i {
    font-size: 12px;
    color: #2563eb;
}

/* When collapsed, position button inside sidebar - keep same beautiful styling */
.community-sidebar-left.collapsed .sidebar-toggle-btn {
    position: relative;
    top: 0;
    right: auto;
    left: auto;
    margin: 0 auto;
    width: 32px;
    height: 32px;
    background: white;
    border: 1px solid #e5e7eb;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

.community-sidebar-left.collapsed .sidebar-toggle-btn:hover {
    background: #f9fafb;
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
}

.community-sidebar-left.collapsed .sidebar-toggle-btn i {
    color: #2563eb;
    font-size: 12px;
    /* When left sidebar is collapsed, show right arrow to expand */
}

.community-sidebar-right.collapsed .sidebar-toggle-btn {
    position: relative;
    top: 0;
    left: auto;
    right: auto;
    margin: 0 auto;
    width: 32px;
    height: 32px;
    background: white;
    border: 1px solid #e5e7eb;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

.community-sidebar-right.collapsed .sidebar-toggle-btn:hover {
    background: #f9fafb;
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
}

.community-sidebar-right.collapsed .sidebar-toggle-btn i {
    color: #2563eb;
    font-size: 12px;
    /* When right sidebar is collapsed, show left arrow to expand */
}

/* Left Sidebar - Community Spaces */
.community-sidebar-left,
.community-sidebar-right {
    position: relative;
    display: flex;
    flex-direction: column;
    gap: 24px;
    transition: all 0.3s ease;
    overflow: hidden;
}

.community-sidebar-left.collapsed,
.community-sidebar-right.collapsed {
    width: 48px;
    min-width: 48px;
    max-width: 48px;
    display: flex;
    align-items: flex-start;
    justify-content: center;
    padding-top: 8px;
}

.community-sidebar-right.collapsed {
    border-right: none;
    border-left: 2px solid #e2e8f0;
}

.community-sidebar-left.collapsed .sidebar-content,
.community-sidebar-right.collapsed .sidebar-content {
    display: none;
}

/* Dynamic Grid Columns */
.community-content-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 24px;
    width: 100%;
    align-items: flex-start;
    transition: grid-template-columns 0.3s ease;
}

.community-content-grid.left-collapsed {
    grid-template-columns: 48px 1fr minmax(0, 1fr);
}

.community-content-grid.right-collapsed {
    grid-template-columns: minmax(0, 1fr) 1fr 48px;
}

.community-content-grid.both-collapsed {
    grid-template-columns: 48px 1fr 48px;
}

.community-content-grid.left-collapsed.right-collapsed {
    grid-template-columns: 48px 1fr 48px;
}

/* Featured Space Card */
.featured-space-card {
    background: linear-gradient(135deg, #2563eb 0%, #1e40af 100%);
    border-radius: 16px;
    padding: 24px;
    color: white;
    box-shadow: 0 10px 25px rgba(37, 99, 235, 0.2);
}

.featured-space-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 16px;
}

.featured-space-icon {
    width: 48px;
    height: 48px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
}

.featured-space-badge {
    background: rgba(255, 255, 255, 0.3);
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 600;
}

.featured-space-title {
    font-size: 1.25rem;
    font-weight: 700;
    margin: 0 0 8px 0;
}

.featured-space-description {
    color: rgba(255, 255, 255, 0.9);
    font-size: 0.875rem;
    margin: 0 0 16px 0;
    line-height: 1.5;
}

.featured-space-members {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 16px;
}

.member-avatars {
    display: flex;
    gap: -8px;
}

.member-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    border: 2px solid rgba(255, 255, 255, 0.3);
    object-fit: cover;
}

.member-avatar:not(:first-child) {
    margin-left: -8px;
}

.featured-space-join-btn {
    width: 100%;
    background: white;
    color: #2563eb;
    font-weight: 600;
    padding: 12px;
    border-radius: 8px;
    border: none;
    cursor: pointer;
    transition: all 0.3s ease;
}

.featured-space-join-btn:hover {
    background: #f0f9ff;
    transform: translateY(-1px);
}

/* Spaces List */
.spaces-list-card {
    background: white;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    border: 1px solid #e5e7eb;
}

.spaces-list-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
}

.spaces-list-header h3 {
    font-size: 1.125rem;
    font-weight: 700;
    color: #0f172a;
    margin: 0;
}

.spaces-list-header a {
    color: #2563eb;
    font-size: 0.875rem;
    font-weight: 600;
    text-decoration: none;
}

.spaces-list-header a:hover {
    text-decoration: underline;
}

.space-item {
    display: flex;
    align-items: center;
    padding: 12px;
    border-radius: 8px;
    border: 1px solid #e5e7eb;
    margin-bottom: 12px;
    cursor: pointer;
    transition: all 0.2s ease;
}

.space-item:hover {
    background: #f9fafb;
    border-color: #d1d5db;
}

.icon-picker-item:hover {
    transform: scale(1.1);
    box-shadow: 0 2px 8px rgba(0,0,0,0.15);
}

.icon-picker-item.selected {
    transform: scale(1.05);
    box-shadow: 0 2px 8px rgba(0,0,0,0.2);
}

.space-item-icon {
    width: 40px;
    height: 40px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 12px;
    font-size: 18px;
}

.space-item-content {
    flex: 1;
}

.space-item-title {
    font-weight: 600;
    color: #0f172a;
    margin: 0 0 4px 0;
    font-size: 0.875rem;
}

.space-item-meta {
    font-size: 0.75rem;
    color: #6b7280;
    margin: 0;
}

.space-item-badge {
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 600;
    background: #dcfce7;
    color: #166534;
}

.space-members-btn {
    background: #e0e7ff;
    border: 1px solid #c7d2fe;
    color: #1d4ed8;
    padding: 6px 10px;
    border-radius: 6px;
    cursor: pointer;
    font-size: 0.75rem;
    transition: all 0.2s ease;
}

.space-members-btn:hover {
    background: #c7d2fe;
    color: #1e3a8a;
}

.space-chat-btn {
    background: #f0f9ff;
    border: 1px solid #bae6fd;
    color: #0284c7;
    padding: 6px 10px;
    border-radius: 6px;
    cursor: pointer;
    font-size: 0.75rem;
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

.space-chat-btn:hover {
    background: #e0f2fe;
    color: #0369a1;
}

.create-space-btn {
    width: 100%;
    margin-top: 16px;
    border: 1px solid #2563eb;
    color: #2563eb;
    background: white;
}

.create-space-btn:hover {
    background: #eff6ff;
}

/* Events Card */
.events-card {
    background: white;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    border: 1px solid #e5e7eb;
}

.events-card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    margin-bottom: 16px;
}

.events-card-header h3 {
    font-size: 1.125rem;
    font-weight: 700;
    color: #0f172a;
    margin: 0;
    white-space: nowrap;
    flex-shrink: 0;
}

.events-card-header .btn {
    padding: 6px 12px;
    font-size: 0.75rem;
    white-space: nowrap;
    flex-shrink: 0;
}

.events-card h3 {
    font-size: 1.125rem;
    font-weight: 700;
    color: #0f172a;
    margin: 0 0 16px 0;
}

.event-item {
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 12px;
    cursor: pointer;
    transition: all 0.2s ease;
}

.event-item:hover {
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.event-date {
    width: 64px;
    height: 64px;
    border-radius: 8px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    margin-right: 12px;
    font-weight: 600;
    text-align: center;
}

.event-date-month {
    font-size: 0.75rem;
    text-transform: uppercase;
}

.event-date-day {
    font-size: 1.5rem;
}

.event-content {
    flex: 1;
}

.event-title {
    font-weight: 600;
    color: #0f172a;
    margin: 0 0 4px 0;
    font-size: 0.875rem;
}

.event-meta {
    font-size: 0.75rem;
    color: #6b7280;
    margin: 0 0 8px 0;
}

.event-badges {
    display: flex;
    gap: 8px;
    align-items: center;
}

.event-badge {
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 600;
}

.event-badge.event-badge-past {
    background: #fee2e2;
    color: #b91c1c;
}

.view-all-events-btn {
    width: 100%;
    margin-top: 16px;
    border: 1px solid #d1d5db;
    color: #374151;
}

/* Middle Column - Posts Feed */
.community-feed {
    display: flex;
    flex-direction: column;
    gap: 24px;
}

/* Create Post Card */
.create-post-card {
    background: white;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    border: 1px solid #e5e7eb;
}

.create-post-header {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    margin-bottom: 16px;
}

.user-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    object-fit: cover;
    background: #e5e7eb;
    display: block;
    flex-shrink: 0;
    border: 2px solid #fff;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.create-post-input {
    flex: 1;
    background: #f9fafb;
    border-radius: 8px;
    padding: 12px 16px;
    border: none;
    font-size: 0.875rem;
    color: #374151;
    resize: none;
    min-height: 80px;
}

.create-post-input:focus {
    outline: none;
    background: white;
    box-shadow: 0 0 0 2px #2563eb;
}

.create-post-actions {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.create-post-media {
    display: flex;
    gap: 16px;
}

.media-btn {
    display: flex;
    align-items: center;
    gap: 6px;
    color: #6b7280;
    font-size: 0.875rem;
    background: none;
    border: none;
    cursor: pointer;
    padding: 8px;
    border-radius: 6px;
    transition: all 0.2s ease;
}

.media-btn:hover {
    background: #f3f4f6;
    color: #2563eb;
}

.create-post-submit {
    background: #2563eb;
    color: white;
    width: 100%;
    padding: 10px 20px;
    border-radius: 8px;
    border: none;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
}

.create-post-submit:hover {
    background: #1d4ed8;
    transform: translateY(-1px);
}

/* Posts Feed Container */
.posts-feed-container {
    max-height: calc(100vh - 100px);
    overflow-y: auto;
    overflow-x: hidden;
    padding-right: 8px;
}

.posts-feed-container::-webkit-scrollbar {
    width: 6px;
}

.posts-feed-container::-webkit-scrollbar-track {
    background: #f1f5f9;
    border-radius: 10px;
}

.posts-feed-container::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 10px;
}

.posts-feed-container::-webkit-scrollbar-thumb:hover {
    background: #94a3b8;
}

/* Post Card */
.post-card {
    background: white;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    border: 1px solid #e5e7eb;
    margin-bottom: 16px;
    cursor: pointer;
    transition: all 0.2s;
}

.post-card:hover {
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    border-color: #cbd5e1;
}

.post-card .post-actions,
.post-card .post-menu-btn,
.post-card .comments-section {
    cursor: default;
}

.post-card .post-actions *,
.post-card .post-menu-btn * {
    cursor: pointer;
}

.post-card:last-child {
    margin-bottom: 0;
}

.post-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 16px;
}

.post-author {
    display: flex;
    align-items: flex-start;
    gap: 12px;
}

.post-author-info h4 {
    font-weight: 600;
    color: #0f172a;
    margin: 0 4px 0 0;
    display: inline;
    font-size: 0.875rem;
}

.post-author-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 600;
    margin-left: 8px;
}

.post-meta {
    font-size: 0.75rem;
    color: #6b7280;
    margin: 4px 0 0 0;
}

.post-edited-indicator {
    color: #9ca3af;
    font-style: italic;
    margin-left: 4px;
}

.post-space-link {
    color: #2563eb;
    text-decoration: none;
}

.post-space-link:hover {
    text-decoration: underline;
}

.post-menu-btn {
    background: none;
    border: none;
    color: #9ca3af;
    cursor: pointer;
    padding: 4px;
    border-radius: 4px;
}

.post-menu-btn:hover {
    background: #f3f4f6;
    color: #374151;
}

.post-menu-dropdown {
    position: absolute;
    top: 100%;
    right: 0;
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    z-index: 100;
    min-width: 160px;
    margin-top: 4px;
}

.post-menu-item {
    width: 100%;
    padding: 10px 16px;
    text-align: left;
    background: none;
    border: none;
    color: #ef4444;
    font-size: 0.875rem;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 8px;
    transition: background 0.2s;
}

.post-menu-item:hover {
    background: #fee2e2;
}

.post-menu-item i {
    width: 16px;
}

.post-action-btn.saved {
    color: #2563eb;
}

.post-action-btn.saved i {
    color: #2563eb;
}

.post-content {
    margin-bottom: 16px;
}

.post-title {
    font-size: 1.125rem;
    font-weight: 600;
    color: #0f172a;
    margin: 0 0 8px 0;
}

.post-message {
    color: #374151;
    line-height: 1.6;
    margin: 0 0 12px 0;
    white-space: pre-wrap;
    word-wrap: break-word;
}

.post-media-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 8px;
    margin: 12px 0;
}

.post-media-item {
    border-radius: 8px;
    overflow: hidden;
    max-height: 300px;
}

.post-media-item img,
.post-media-item video {
    width: 100%;
    height: 100%;
    object-fit: cover;
    cursor: pointer;
    transition: opacity 0.2s ease;
}

.post-media-item img:hover {
    opacity: 0.9;
}

.post-document-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin: 12px 0;
}

.post-doc-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 14px;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    background: #f8fafc;
    gap: 12px;
}

.post-doc-icon {
    width: 42px;
    height: 42px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
    color: #2563eb;
    background: #e0edff;
}

.post-doc-info {
    flex: 1;
    min-width: 0;
}

.post-doc-info p {
    margin: 0;
    font-weight: 600;
    color: #0f172a;
    font-size: 0.95rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.post-doc-info span {
    color: #64748b;
    font-size: 0.8rem;
}

.post-doc-actions a {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    border-radius: 999px;
    border: 1px solid #c7d7fe;
    color: #1d4ed8;
    font-weight: 600;
    font-size: 0.85rem;
    text-decoration: none;
    background: #fff;
}

.post-doc-actions a:hover {
    background: #e0edff;
}

.post-actions {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding-top: 16px;
    border-top: 1px solid #e5e7eb;
}

.post-action-group {
    display: flex;
    gap: 24px;
}

.post-action-btn {
    display: flex;
    align-items: center;
    gap: 6px;
    color: #6b7280;
    font-size: 0.875rem;
    background: none;
    border: none;
    cursor: pointer;
    padding: 8px;
    border-radius: 6px;
    transition: all 0.2s ease;
}

.post-action-btn:hover {
    background: #f3f4f6;
    color: #2563eb;
}

.post-action-btn.liked {
    color: #2563eb;
}

.post-action-btn.liked i {
    color: #ef4444;
}

/* Comments Section */
.comments-section {
    margin-top: 16px;
    padding-top: 16px;
    border-top: 1px solid #e5e7eb;
}

.comment-submit-btn {
    background: #2563eb;
    color: #ffffff;
    border: none;
    border-radius: 8px;
    padding: 8px 16px;
    font-size: 0.85rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
}

.comment-submit-btn:hover {
    background: #1d4ed8;
}

.comment-submit-btn.small {
    padding: 6px 12px;
    font-size: 0.8rem;
}

.comment-item {
    display: flex;
    gap: 12px;
    margin-bottom: 16px;
}

.comment-item.nested {
    margin-left: 48px;
    margin-top: 12px;
    padding-left: 12px;
    border-left: 2px solid #e5e7eb;
}

.nested-replies {
    margin-top: 12px;
}

.comment-view-replies {
    margin-top: 8px;
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    color: #1d4ed8;
    border-radius: 999px;
    padding: 6px 12px;
    font-size: 0.8rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.comment-view-replies:hover {
    background: #dbeafe;
    border-color: #93c5fd;
    color: #1e3a8a;
}

.comment-action-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: #6b7280;
    font-size: 0.8rem;
}

.comment-action-link.liked {
    color: #2563eb;
    font-weight: 600;
}

.comment-action-link.liked i {
    color: #2563eb;
}

.comment-content {
    flex: 1;
    background: #f9fafb;
    border-radius: 8px;
    padding: 12px;
}

.comment-author {
    font-weight: 600;
    color: #0f172a;
    font-size: 0.875rem;
    margin: 0 0 4px 0;
}

.comment-text {
    color: #374151;
    font-size: 0.875rem;
    margin: 0 0 8px 0;
    line-height: 1.5;
}

.comment-actions {
    display: flex;
    gap: 16px;
}

.comment-action-link {
    font-size: 0.75rem;
    color: #6b7280;
    text-decoration: none;
    cursor: pointer;
}

.comment-action-link:hover {
    color: #2563eb;
}

.comment-input {
    display: flex;
    gap: 12px;
    margin-top: 12px;
}

.comment-input-field {
    flex: 1;
    background: #f9fafb;
    border-radius: 8px;
    padding: 10px 12px;
    border: none;
    font-size: 0.875rem;
}

.comment-input-field:focus {
    outline: none;
    background: white;
    box-shadow: 0 0 0 2px #2563eb;
}

/* Right Sidebar */
.community-sidebar-right {
    display: flex;
    flex-direction: column;
    gap: 24px;
}

/* Stats Card */
.stats-card {
    background: #ffffff;
    border-radius: 16px;
    padding: 24px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 20px 40px rgba(15, 23, 42, 0.08);
}

.stats-card h3 {
    font-size: 1.2rem;
    font-weight: 700;
    color: #0f172a;
    margin: 0 0 20px 0;
}

.insights-metrics {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
    margin-bottom: 20px;
}

.insights-metric {
    border-radius: 14px;
    padding: 18px 12px;
    text-align: center;
    color: #0f172a;
    min-width: 0;
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.3);
}

.metric-blue {
    background: linear-gradient(135deg, #eef2ff, #e0ecff);
}

.metric-green {
    background: linear-gradient(135deg, #ecfdf5, #d8f5e8);
}

.metric-purple {
    background: linear-gradient(135deg, #f5f3ff, #ede9fe);
}

.metric-amber {
    background: linear-gradient(135deg, #fff7ed, #fef3c7);
}

.insights-metric-number {
    font-size: 2rem;
    font-weight: 700;
    margin: 0 0 6px 0;
}

.metric-blue .insights-metric-number { color: #2563eb; }
.metric-green .insights-metric-number { color: #059669; }
.metric-purple .insights-metric-number { color: #7c3aed; }
.metric-amber .insights-metric-number { color: #d97706; }

.insights-metric-label {
    font-size: 0.875rem;
    color: #475569;
    margin: 0;
}

.engagement-chart {
    background: #f8fafc;
    border-radius: 12px;
    padding: 16px;
}

.engagement-chart-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
}

.engagement-chart-header p {
    margin: 0;
    font-size: 0.95rem;
    font-weight: 600;
    color: #0f172a;
}

.engagement-bars {
    display: grid;
    grid-template-columns: repeat(7, minmax(0, 1fr));
    gap: 8px;
    margin-bottom: 8px;
}

.engagement-bar {
    text-align: center;
}

.engagement-day {
    font-size: 0.7rem;
    color: #94a3b8;
    margin-bottom: 6px;
    display: block;
}

.engagement-bar-track {
    width: 70%;
    height: 64px;
    margin: 0 auto;
    background: #e2e8f0;
    border-radius: 6px;
    position: relative;
    overflow: hidden;
}

.engagement-bar-fill {
    position: absolute;
    bottom: 0;
    left: 0;
    width: 100%;
    background: linear-gradient(135deg, #2563eb, #3b82f6);
    border-radius: 6px;
}

.engagement-peak {
    font-size: 0.75rem;
    color: #475569;
    text-align: center;
    margin: 0;
}

/* Top Contributors */
.contributors-card {
    background: white;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    border: 1px solid #e5e7eb;
}

.contributors-card h3 {
    font-size: 1.125rem;
    font-weight: 700;
    color: #0f172a;
    margin: 0 0 16px 0;
}

.contributor-item {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 16px;
}

.contributor-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    object-fit: cover;
    position: relative;
}

.contributor-badge {
    position: absolute;
    bottom: -2px;
    right: -2px;
    width: 20px;
    height: 20px;
    border-radius: 50%;
    border: 2px solid white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 10px;
}

.contributor-info {
    flex: 1;
}

.contributor-name {
    font-weight: 600;
    color: #0f172a;
    font-size: 0.875rem;
    margin: 0;
}

.contributor-points {
    font-size: 0.875rem;
    font-weight: 600;
    color: #2563eb;
}

.contributor-progress {
    width: 100%;
    height: 6px;
    background: #e5e7eb;
    border-radius: 3px;
    margin-top: 4px;
    overflow: hidden;
}

.contributor-progress-bar {
    height: 100%;
    background: #2563eb;
    border-radius: 3px;
    transition: width 0.3s ease;
}

/* Resource Library */
.resources-card {
    background: white;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    border: 1px solid #e5e7eb;
}

.resources-card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
}

.resources-card-header h3 {
    font-size: 1.125rem;
    font-weight: 700;
    color: #0f172a;
    margin: 0;
}

.resources-card-header a {
    color: #2563eb;
    font-size: 0.875rem;
    font-weight: 600;
    text-decoration: none;
}

.resource-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px;
    background: #f9fafb;
    border-radius: 8px;
    margin-bottom: 12px;
    cursor: pointer;
    transition: all 0.2s ease;
}

.resource-item:hover {
    background: #f3f4f6;
}

.resource-icon {
    width: 40px;
    height: 40px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
}

.resource-info {
    flex: 1;
}

.resource-title {
    font-weight: 600;
    color: #0f172a;
    font-size: 0.875rem;
    margin: 0 0 4px 0;
}

.resource-meta {
    font-size: 0.75rem;
    color: #6b7280;
    margin: 0;
}

.resource-download-btn {
    color: #9ca3af;
    background: none;
    border: none;
    cursor: pointer;
    padding: 8px;
    border-radius: 6px;
}

.resource-download-btn:hover {
    background: #e5e7eb;
    color: #2563eb;
}

.modal {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.55);
    z-index: 2000;
    padding: 20px;
    overflow-y: auto;
}

/* Nested modal stacking - modals opened on top of other modals get higher z-index */
.modal.modal-layer-1 {
    z-index: 2100;
}

.modal.modal-layer-2 {
    z-index: 2200;
}

.modal.modal-layer-3 {
    z-index: 2300;
}

.modal-content {
    background: white;
    border-radius: 16px;
    margin: 40px auto;
    width: 100%;
    max-width: 600px;
    box-shadow: 0 20px 50px rgba(15, 23, 42, 0.2);
}

.modal-header {
    padding: 20px 24px;
    border-bottom: 1px solid #e5e7eb;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-header h3 {
    margin: 0;
    font-size: 1.25rem;
}

.modal-body {
    padding: 20px 24px;
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.modal-footer {
    padding: 16px 24px 24px;
    border-top: 1px solid #e5e7eb;
    display: flex;
    justify-content: flex-end;
    gap: 12px;
}

.modal .form-group {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.modal .form-group label {
    font-size: 0.875rem;
    font-weight: 600;
    color: #0f172a;
}

.modal .form-group input,
.modal .form-group select,
.modal .form-group textarea {
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 10px 12px;
    font-size: 0.9rem;
}

/* Loading States */
.loading {
    text-align: center;
    padding: 40px;
    color: #6b7280;
}

.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: #6b7280;
}

.empty-state-icon {
    font-size: 48px;
    margin-bottom: 16px;
    opacity: 0.5;
}

.empty-state-text {
    font-size: 1rem;
    margin: 0;
}

/* Post Detail Modal */
.post-detail-modal-content {
    max-width: 1400px;
    width: 95%;
    max-height: 90vh;
    display: flex;
    flex-direction: column;
}

.post-detail-body {
    padding: 0;
    overflow-y: auto;
    max-height: calc(90vh - 80px);
}

#postDetailContent {
    padding: 24px;
}

.post-detail-post {
    background: white;
    border-radius: 12px;
    padding: 24px;
    margin-bottom: 24px;
    border: 1px solid #e5e7eb;
}

.post-detail-replies {
    padding-top: 24px;
    border-top: 1px solid #e5e7eb;
}

.post-detail-replies h4 {
    font-size: 1.125rem;
    font-weight: 600;
    color: #0f172a;
    margin-bottom: 16px;
}

.post-detail-comment-input {
    margin-top: 24px;
    padding-top: 24px;
    border-top: 1px solid #e5e7eb;
}

/* Member List Styles */
.member-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 16px;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    margin-bottom: 8px;
    background: #ffffff;
    transition: all 0.2s;
}

.member-item:hover {
    background: #f9fafb;
    border-color: #d1d5db;
}

.member-item-info {
    display: flex;
    align-items: center;
    gap: 12px;
    flex: 1;
}

.member-item-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid #e5e7eb;
}

.member-item-details {
    flex: 1;
}

.member-item-name {
    font-weight: 600;
    color: #0f172a;
    font-size: 0.9rem;
    margin: 0 0 2px 0;
}

.member-item-email {
    font-size: 0.8rem;
    color: #64748b;
    margin: 0;
}

.member-item-role {
    display: flex;
    align-items: center;
    gap: 8px;
}

.member-role-badge {
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 600;
    white-space: nowrap;
}

.member-role-badge.admin {
    background: #dbeafe;
    color: #1e40af;
}

.member-role-badge.moderator {
    background: #fef3c7;
    color: #b45309;
}

.member-role-badge.member {
    background: #f3f4f6;
    color: #4b5563;
}

.member-item-actions {
    display: flex;
    align-items: center;
    gap: 8px;
}

.member-action-btn {
    padding: 6px 12px;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    background: white;
    color: #64748b;
    font-size: 0.8rem;
    cursor: pointer;
    transition: all 0.2s;
}

.member-action-btn:hover {
    background: #f9fafb;
    border-color: #d1d5db;
    color: #0f172a;
}

.member-action-btn.danger:hover {
    background: #fee2e2;
    border-color: #fca5a5;
    color: #dc2626;
}

.user-select-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 12px;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    margin-bottom: 8px;
    background: white;
    cursor: pointer;
    transition: all 0.2s;
}

.user-select-item:hover {
    background: #f9fafb;
    border-color: #2563eb;
}

.user-select-item.selected {
    background: #eff6ff;
    border-color: #2563eb;
}

.user-select-item-info {
    display: flex;
    align-items: center;
    gap: 10px;
}

.user-select-item-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    object-fit: cover;
}

.user-select-item-name {
    font-weight: 500;
    color: #0f172a;
    font-size: 0.875rem;
}

.selected-user-tag {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 10px;
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    border-radius: 16px;
    font-size: 0.8rem;
    color: #1e40af;
}

.selected-user-tag-remove {
    cursor: pointer;
    color: #3b82f6;
    font-weight: bold;
}

.selected-user-tag-remove:hover {
    color: #1e40af;
}
.close{
    cursor:pointer;
}
.secondary-button {
    width: 100% !important;
    justify-content: center !important;
    border: 1px solid rgb(72, 100, 155) !important;
}
#viewMembersButton {
    background: #eef2ff;
    color: #1d4ed8;
    padding: 6px 10px;
    border-radius: 6px;
    cursor: pointer;
    font-size: 0.75rem;
    transition: all 0.2s ease;
}

.create-event-btn, 
.view-all-resources-btn,
.view-all-spaces-btn {
    background: #eef2ff;
    border: 1px solid rgb(209, 209, 209);
}

/* Inline Filter Bar */
.community-filter-bar {
    display: none;
    width: 100%;
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 16px 20px;
    margin-top: 16px;
    box-shadow: 0 2px 8px rgba(15, 23, 42, 0.06);
}

.community-filter-bar.show {
    display: flex;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
}

.filter-item {
    display: flex;
    flex-direction: column;
    gap: 6px;
    flex-shrink: 0;
}

.filter-item label {
    font-size: 0.75rem;
    font-weight: 600;
    color: #475569;
    margin: 0;
}

.filter-item select,
.filter-item input {
    padding: 8px 12px;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    font-size: 0.875rem;
    background: white;
    color: #0f172a;
    cursor: pointer;
    min-width: 140px;
}

.filter-item select:focus,
.filter-item input:focus {
    outline: none;
    border-color: #2563eb;
    box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.1);
}

.filter-item.filter-space {
    min-width: 160px;
}

.filter-item.filter-posted-by {
    min-width: 180px;
}

.filter-item.filter-cohort {
    min-width: 160px;
}

.filter-item.filter-date {
    min-width: 150px;
}

.filter-item.filter-sort {
    min-width: 140px;
}

.filter-item.filter-checkbox {
    display: flex;
    flex-direction: row;
    align-items: center;
    gap: 8px;
    min-width: auto;
    padding-top: 20px;
}

.filter-item.filter-checkbox label {
    margin: 0;
    cursor: pointer;
    font-weight: 500;
    display: flex;
    align-items: center;
    gap: 8px;
}

.filter-item.filter-checkbox input[type="checkbox"] {
    width: 18px;
    height: 18px;
    cursor: pointer;
    margin: 0;
    padding: 0;
    min-width: 18px;
    border: 2px solid #cbd5e1;
    border-radius: 4px;
    appearance: none;
    -webkit-appearance: none;
    -moz-appearance: none;
    background: white;
    position: relative;
    flex-shrink: 0;
}

.filter-item.filter-checkbox input[type="checkbox"]:checked {
    background: #2563eb;
    border-color: #2563eb;
}

.filter-item.filter-checkbox input[type="checkbox"]:checked::after {
    content: '✓';
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    color: white;
    font-size: 12px;
    font-weight: bold;
}

.filter-item.filter-checkbox input[type="checkbox"]:focus {
    outline: none;
    box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.2);
}

.filter-actions {
    display: flex;
    gap: 8px;
    align-items: flex-end;
    margin-left: auto;
}

.filter-actions .btn {
    padding: 8px 16px;
    font-size: 0.875rem;
    white-space: nowrap;
}

/* All Spaces Modal Styles */
#allSpacesList {
    display: flex;
    flex-direction: column;
    gap: 12px;
    max-height: 500px;
    overflow-y: auto;
    padding: 8px;
}

#allSpacesList .space-item {
    cursor: pointer;
}

#allSpacesList .space-item:hover {
    background: #f9fafb;
    border-color: #2563eb;
}

/* All Events Modal Styles */
#allEventsList {
    display: flex;
    flex-direction: column;
    gap: 12px;
    max-height: 500px;
    overflow-y: auto;
    padding: 8px;
}

#allEventsList .event-item {
    cursor: pointer;
}

#allEventsList .event-item:hover {
    background: #f9fafb;
    border-color: #2563eb;
}

/* All Resources Modal Styles */
#allResourcesList {
    display: flex;
    flex-direction: column;
    gap: 12px;
    max-height: 500px;
    overflow-y: auto;
    padding: 8px;
}

#allResourcesList .resource-item {
    cursor: pointer;
}

#allResourcesList .resource-item:hover {
    background: #f9fafb;
    border-color: #2563eb;
}

/* Full Image Modal Styles */
#fullImageModal .modal-content {
    background: rgba(0, 0, 0, 0.95) !important;
    min-height: 550px !important;
}

#fullImageModal .close:hover {
    background: rgba(0, 0, 0, 0.8) !important;
}

#fullImageContent {
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.5);
    min-height: 550px;
    width: auto;
    height: auto;
}

/* Pagination Styles */
.pagination-controls {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 8px;
    margin-top: 20px;
    padding-top: 20px;
    border-top: 1px solid #e5e7eb;
}

.pagination-info {
    color: #64748b;
    font-size: 0.875rem;
    margin: 0 12px;
}

.pagination-btn {
    padding: 8px 16px;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    background: white;
    color: #475569;
    font-size: 0.875rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
}

.pagination-btn:hover:not(:disabled) {
    background: #f1f5f9;
    border-color: #2563eb;
    color: #2563eb;
}

.pagination-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.pagination-btn.active {
    background: #2563eb;
    border-color: #2563eb;
    color: white;
}
</style>

<div class="community-page-wrapper">
    <div class="community-top-actions">
        <?php if ($communityid > 0): ?>
            <a class="back-button" href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/community.php">
                <i class="fa-solid fa-arrow-left"></i>
                Back to Communities
            </a>
        <?php else: ?>
            <a class="back-button" href="<?php echo $CFG->wwwroot; ?>/my/">
                <i class="fa-solid fa-arrow-left"></i>
                Back to Dashboard
            </a>
        <?php endif; ?>
    </div>
    <!-- Community Header -->
    <div class="community-header">
        <div class="community-header-top">
            <div class="community-title-block">
                <h1>Community & Collaboration</h1>
                <p>Connect, share, and grow with the teaching community</p>
            </div>
            <div class="community-header-actions" id="communityHeaderActions">
                <button type="button" class="btn btn-secondary" id="communityChatButton">
                    <i class="fa-solid fa-comments"></i> Community Chat
                </button>
                <?php if ($cancreatecommunity): ?>
                    <button class="btn btn-primary create-community-btn" id="createCommunityAction" onclick="openCreateCommunityModal()">
                        <i class="fa-solid fa-plus"></i> Create Community
                    </button>
                <?php endif; ?>
                <div class="community-detail-actions" id="communityDetailActions">
                    <button class="btn btn-primary" onclick="openCreatePostModal()">
                        <i class="fa-solid fa-plus"></i> Create Post
                    </button>
                    <button class="btn btn-secondary" onclick="toggleFilterBar()">
                        <i class="fa-solid fa-filter"></i> Filter
                    </button>
                    <button class="btn btn-secondary" id="viewMembersButton" onclick="openMembersModal(currentCommunityId, currentCommunityName)">
                        <i class="fa-solid fa-users"></i> 
                    </button>
                    <button class="btn btn-secondary" id="moderationButton" onclick="openModerationPanel()" style="display: none; background: #fef2f2; color: #dc2626; border-color: #fecaca;">
                        <i class="fa-solid fa-shield-halved"></i> Moderation
                    </button>
                </div>
            </div>
            <!-- Inline Filter Bar -->
            <div class="community-filter-bar" id="communityFilterBar">
                <div class="filter-item filter-space">
                    <label>Space</label>
                    <select id="inlineFilterSpaceSelect">
                        <option value="0">All Spaces</option>
                    </select>
                </div>
                <div class="filter-item filter-posted-by">
                    <label>Posted By</label>
                    <select id="inlineFilterPostedBy">
                        <option value="">All Users</option>
                    </select>
                </div>
                <div class="filter-item filter-cohort">
                    <label>Cohort</label>
                    <select id="inlineFilterCohorts">
                        <option value="">All Cohorts</option>
                    </select>
                </div>
                <div class="filter-item filter-date">
                    <label>From Date</label>
                    <input type="date" id="inlineFilterFromDate">
                </div>
                <div class="filter-item filter-date">
                    <label>To Date</label>
                    <input type="date" id="inlineFilterToDate">
                </div>
                <div class="filter-item filter-sort">
                    <label>Sort By</label>
                    <select id="inlineFilterSortBy">
                        <option value="newest">Newest First</option>
                        <option value="oldest">Oldest First</option>
                        <option value="most_liked">Most Liked</option>
                        <option value="most_commented">Most Commented</option>
                    </select>
                </div>
                <div class="filter-item filter-checkbox">
                    <input type="checkbox" id="inlineFilterLikedOnly" onchange="applyInlineFilters()">
                    <label for="inlineFilterLikedOnly">Liked Posts Only</label>
                </div>
                <div class="filter-item filter-checkbox">
                    <input type="checkbox" id="inlineFilterSavedOnly" onchange="applyInlineFilters()">
                    <label for="inlineFilterSavedOnly">Saved Posts Only</label>
                </div>
                <div class="filter-actions">
                    <button class="btn btn-secondary" onclick="clearInlineFilters()" style="padding: 8px 12px;">
                        Clear
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="community-list-section" id="communityListSection">
        <div class="community-list-header">
            <h2>Your Communities</h2>
            <p style="color: #475569;">Select a community to view posts and resources</p>
        </div>
        <div class="community-list-grid" id="communityListGrid"></div>
    </div>

    <div class="community-empty-state" id="communityEmptyState">
        <h3>
        You are not part of any Community yet!
        </h3>
        <p>
            <?php if ($cancreatecommunity): ?>
                Use the Create Community button above to start a community for your school, or wait for an administrator to add you.
            <?php else: ?>
                Once an administrator or teacher adds you to a community, you will see it here.
            <?php endif; ?>
        </p>
    </div>

    <!-- Main Content Grid -->
    <div class="community-content-grid" id="communityContentGrid">
        <!-- Left Sidebar -->
        <div class="community-sidebar-left" id="leftSidebar">
            <button class="sidebar-toggle-btn" id="leftSidebarToggle" onclick="toggleSidebar('left')" title="Collapse sidebar">
                <i class="fa-solid fa-chevron-left"></i>
            </button>
            <div class="sidebar-content">
            <!-- Featured Space (will be populated dynamically) -->
            <div id="featuredSpaceCard" class="featured-space-card" style="display: none;">
                <div class="featured-space-header">
                    <div class="featured-space-icon">
                        <i class="fa-solid fa-graduation-cap"></i>
                    </div>
                    <span class="featured-space-badge">Featured</span>
                </div>
                <h3 class="featured-space-title" id="featuredSpaceTitle"></h3>
                <p class="featured-space-description" id="featuredSpaceDescription"></p>
                <div class="featured-space-members">
                    <div class="member-avatars" id="featuredSpaceMembers"></div>
                    <span id="featuredSpaceMemberCount"></span>
                </div>
                <button class="featured-space-join-btn" onclick="joinFeaturedSpace()">Join Space</button>
            </div>

            <!-- Community Spaces List -->
            <div class="spaces-list-card">
                <div class="spaces-list-header">
                    <h3>Community Spaces</h3>
                    <button class="btn view-all-spaces-btn" onclick="viewAllSpaces()">View All</button>
                </div>
                <div id="spacesList"></div>
                <button class="btn create-space-btn secondary-button" onclick="openCreateSpaceModal()">
                    <i class="fa-solid fa-plus"></i> Create New Space
                </button>
            </div>

            <!-- Upcoming Events -->
            <div class="events-card">
                <div class="events-card-header">
                    <h3>Upcoming Events</h3>
                    <button class="btn create-event-btn" onclick="openCreateEventModal()">
                        <i class="fa-solid fa-plus"></i> Add Event
                    </button>
                </div>
                <div id="eventsList"></div>
                <button class="btn view-all-events-btn secondary-button" onclick="viewAllEvents(); return false;">
                    <i class="fa-regular fa-calendar"></i> View All Events
                </button>
            </div>
            </div>
        </div>

        <!-- Middle Column - Posts Feed -->
        <div class="community-feed">
            <!-- Create Post Card -->
            <div class="create-post-card">
                <div class="create-post-header">
                    <?php 
                    // Get user picture URL properly
                    $userpicture = $OUTPUT->user_picture($USER, ['size' => 40, 'link' => false, 'class' => 'user-avatar']);
                    $userpictureurl = $CFG->wwwroot . '/user/pix.php/' . $USER->id . '/f1.jpg';
                    ?>
                    <img src="<?php echo $userpictureurl; ?>" alt="Your profile" class="user-avatar" id="currentUserAvatar" onerror="this.src='<?php echo $CFG->wwwroot; ?>/pix/u/f1.png'">
                    <textarea class="create-post-input" id="createPostInput" placeholder="Share your thoughts, ideas or questions..."></textarea>
                </div>
                <div class="create-post-actions">
                    <button class="create-post-submit" onclick="openCreatePostModal()">Post</button>
                </div>
                <div id="selectedMediaPreview" style="display: none; margin-top: 12px;"></div>
            </div>

            <!-- Posts Feed -->
            <div id="postsFeed" class="posts-feed-container">
                <div class="loading">Loading posts...</div>
            </div>
        </div>

        <!-- Right Sidebar -->
        <div class="community-sidebar-right" id="rightSidebar">
            <button class="sidebar-toggle-btn" id="rightSidebarToggle" onclick="toggleSidebar('right')" title="Collapse sidebar">
                <i class="fa-solid fa-chevron-right"></i>
            </button>
            <div class="sidebar-content">
            <!-- Community Stats -->
            <div class="stats-card">
                <h3>Community Insights</h3>
                <div class="insights-metrics">
                    <div class="insights-metric metric-blue">
                        <p class="insights-metric-number" id="statActiveMembers">0</p>
                        <p class="insights-metric-label">Active Members</p>
                    </div>
                    <div class="insights-metric metric-green">
                        <p class="insights-metric-number" id="statPosts">0</p>
                        <p class="insights-metric-label">Posts This Month</p>
                    </div>
                    <div class="insights-metric metric-purple">
                        <p class="insights-metric-number" id="statSpaces">0</p>
                        <p class="insights-metric-label">Active Spaces</p>
                    </div>
                    <div class="insights-metric metric-amber">
                        <p class="insights-metric-number" id="statEngagement">0%</p>
                        <p class="insights-metric-label">Engagement Rate</p>
                    </div>
                </div>
                <div class="engagement-chart">
                    <div class="engagement-chart-header">
                        <p>Top Engagement Times</p>
                        <i class="fa-regular fa-clock" style="color: #94a3b8;"></i>
                    </div>
                    <div class="engagement-bars">
                        <div class="engagement-bar">
                            <span class="engagement-day">Mon</span>
                            <div class="engagement-bar-track">
                                <div class="engagement-bar-fill" style="height:70%"></div>
                            </div>
                        </div>
                        <div class="engagement-bar">
                            <span class="engagement-day">Tue</span>
                            <div class="engagement-bar-track">
                                <div class="engagement-bar-fill" style="height:85%"></div>
                            </div>
                        </div>
                        <div class="engagement-bar">
                            <span class="engagement-day">Wed</span>
                            <div class="engagement-bar-track">
                                <div class="engagement-bar-fill" style="height:60%"></div>
                            </div>
                        </div>
                        <div class="engagement-bar">
                            <span class="engagement-day">Thu</span>
                            <div class="engagement-bar-track">
                                <div class="engagement-bar-fill" style="height:90%"></div>
                            </div>
                        </div>
                        <div class="engagement-bar">
                            <span class="engagement-day">Fri</span>
                            <div class="engagement-bar-track">
                                <div class="engagement-bar-fill" style="height:50%"></div>
                            </div>
                        </div>
                        <div class="engagement-bar">
                            <span class="engagement-day">Sat</span>
                            <div class="engagement-bar-track">
                                <div class="engagement-bar-fill" style="height:30%"></div>
                            </div>
                        </div>
                        <div class="engagement-bar">
                            <span class="engagement-day">Sun</span>
                            <div class="engagement-bar-track">
                                <div class="engagement-bar-fill" style="height:20%"></div>
                            </div>
                        </div>
                    </div>
                    <p class="engagement-peak">Peak activity: Thursdays 3-5 PM</p>
                </div>
            </div>

            <!-- Resource Library -->
            <div class="resources-card">
                <div class="resources-card-header">
                    <h3>Resource Library</h3>
                    <button class="btn view-all-resources-btn" onclick="viewAllResources()">View All</button>
                </div>
                <div id="resourcesList"></div>
                <button class="btn share-resource-btn secondary-button" onclick="openShareResourceModal()">
                    <i class="fa-solid fa-upload"></i> Share a Resource
                </button>
            </div>
            </div>
        </div>
    </div>
</div>

<!-- Create Community Modal -->
<div id="createCommunityModal" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 600px;">
        <div class="modal-header">
            <h3>Create New Community</h3>
            <span class="close" onclick="closeModal('createCommunityModal')" style="cursor: pointer;">&times;</span>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label>Community Name *</label>
                <input type="text" id="communityName" class="form-control" placeholder="e.g., Teachers Network, Student Support Group" required>
            </div>
            <div class="form-group">
                <label>Description</label>
                <textarea id="communityDescription" class="form-control" rows="4" placeholder="Describe what this community is about..."></textarea>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('createCommunityModal')">Cancel</button>
            <button class="btn btn-primary" onclick="submitCreateCommunity()">Create Community</button>
        </div>
    </div>
</div>

<!-- Modals will be added here -->
<?php
// Include RemUI Alert Modal System HTML
require_once($CFG->dirroot . '/theme/remui_kids/components/alert_modal.php');
?>
<div id="createPostModal" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 600px;">
        <div class="modal-header">
            <h3>Create Post</h3>
            <span class="close" onclick="closeModal('createPostModal')">&times;</span>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label>Select Space (Optional)</label>
                <select id="postSpaceSelect" class="form-control">
                    <option value="0">Post in Community (General)</option>
                </select>
                <small style="color: #6b7280; font-size: 0.75rem; margin-top: 4px; display: block;">
                    Spaces are sub-groups within a Community for organizing topics
                </small>
            </div>
            <div class="form-group">
                <label>Subject (Optional)</label>
                <input type="text" id="postSubject" class="form-control" placeholder="Post subject...">
            </div>
            <div class="form-group">
                <label>Message</label>
                <textarea id="postMessage" class="form-control" rows="6" placeholder="Share your thoughts..."></textarea>
            </div>
            <div class="form-group">
                <label>Attach Media</label>
                <input type="file" id="postMediaFiles" multiple accept="image/*,video/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.zip" class="form-control" onchange="validateFileSize(this, 30)">
                <small style="color: #6b7280; font-size: 0.75rem; margin-top: 4px; display: block;">Maximum file size: 30MB per file</small>
                <div id="mediaPreview" style="margin-top: 12px;"></div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('createPostModal')">Cancel</button>
            <button class="btn btn-primary" onclick="submitPost()">Post</button>
        </div>
    </div>
</div>

<!-- Report Post Modal -->
<div id="reportPostModal" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header">
            <h3><i class="fa-solid fa-flag" style="color: #f59e0b; margin-right: 8px;"></i> Report Post</h3>
            <span class="close" onclick="closeModal('reportPostModal')">&times;</span>
        </div>
        <div class="modal-body">
            <p style="color: #64748b; margin-bottom: 16px;">Help us keep the community safe. Please let us know why you're reporting this post.</p>
            <div class="form-group">
                <label>Reason (Optional)</label>
                <textarea id="reportPostReason" class="form-control" rows="4" placeholder="e.g., Contains inappropriate content, spam, harassment, etc." style="resize: vertical;"></textarea>
                <small style="color: #6b7280; font-size: 0.75rem; margin-top: 4px; display: block;">
                    Your report will be reviewed by moderators. False reports may result in action against your account.
                </small>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('reportPostModal')">Cancel</button>
            <button class="btn btn-primary" onclick="submitReportPost()" style="background-color: #f59e0b; border-color: #f59e0b;">
                <i class="fa-solid fa-flag"></i> Submit Report
            </button>
        </div>
    </div>
</div>

<div id="createSpaceModal" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 520px;">
        <div class="modal-header">
            <h3>Create Community Space</h3>
            <span class="close" onclick="closeModal('createSpaceModal')">&times;</span>
        </div>
        <div class="modal-body">
            <!-- Live Preview -->
            <div class="form-group">
                <label>Preview</label>
                <div id="createSpacePreview" style="display: flex; align-items: flex-start; gap: 12px; padding: 16px; background: #f8fafc; border-radius: 12px; border: 1px solid #e2e8f0;">
                    <div id="createSpacePreviewIcon" style="width: 48px; height: 48px; min-width: 48px; border-radius: 10px; display: flex; align-items: center; justify-content: center; background: #2563eb; color: white; font-size: 1.25rem;">
                        <i class="fa-solid fa-users"></i>
                    </div>
                    <div style="flex: 1; min-width: 0;">
                        <h4 id="createSpacePreviewName" style="margin: 0; font-weight: 600; color: #1e293b;">Space Name</h4>
                        <p id="createSpacePreviewDesc" style="margin: 4px 0 0 0; font-size: 0.8rem; color: #64748b; display: none;"></p>
                        <p style="margin: 4px 0 0 0; font-size: 0.75rem; color: #94a3b8;">0 posts • 1 members</p>
                    </div>
                </div>
            </div>
            <div class="form-group">
                <label>Space Name <span style="color:#ef4444">*</span></label>
                <input type="text" id="spaceNameInput" placeholder="e.g. Grade 5 Science Club" oninput="updateCreateSpacePreview()">
            </div>
            <div class="form-group">
                <label>Description</label>
                <textarea id="spaceDescriptionInput" rows="3" placeholder="Describe the purpose of this space..." oninput="updateCreateSpacePreview()"></textarea>
            </div>
            <div class="form-group">
                <label>Icon & Color</label>
                <div style="display: flex; gap: 12px; align-items: stretch;">
                    <div id="createIconGrid" class="icon-picker-grid" style="flex: 1; display: grid; grid-template-columns: repeat(5, 1fr); gap: 8px; max-height: 150px; overflow-y: auto; padding: 8px; background: #f8fafc; border-radius: 8px; border: 1px solid #e2e8f0;">
            </div>
                    <div style="display: flex; flex-direction: column; gap: 8px;">
                        <label style="font-size: 0.75rem; color: #64748b; margin: 0;">Color</label>
                        <input type="color" id="spaceColorInput" value="#2563eb" onchange="updateCreateSpacePreview(); updateIconGrid('create')" oninput="updateCreateSpacePreview(); updateIconGrid('create')" style="width: 60px; height: 60px; cursor: pointer; border: none; border-radius: 8px;">
            </div>
                </div>
                <input type="hidden" id="spaceIconSelect" value="fa-solid fa-users">
            </div>
            <div class="info-box" style="background: #fef3c7; border-left: 4px solid #f59e0b; padding: 12px; border-radius: 4px; margin-top: 16px;">
                <p style="margin: 0; font-size: 0.875rem; color: #92400e;">
                    <strong>What is a Space?</strong><br>
                    A Space is a sub-group within a Community. Use Spaces to organize posts by topic, 
                    grade level, subject, or any other category. Posts can be assigned to specific Spaces.
                </p>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('createSpaceModal')">Cancel</button>
            <button class="btn btn-primary" onclick="submitCreateSpace()">Create Space</button>
        </div>
    </div>
</div>

<!-- Edit Space Modal -->
<div id="editSpaceModal" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 520px;">
        <div class="modal-header">
            <h3><i class="fa-solid fa-pen" style="margin-right: 8px;"></i>Edit Space</h3>
            <span class="close" onclick="closeModal('editSpaceModal')">&times;</span>
        </div>
        <div class="modal-body">
            <input type="hidden" id="editSpaceId">
            <!-- Live Preview -->
            <div class="form-group">
                <label>Preview</label>
                <div id="editSpacePreview" style="display: flex; align-items: flex-start; gap: 12px; padding: 16px; background: #f8fafc; border-radius: 12px; border: 1px solid #e2e8f0;">
                    <div id="editSpacePreviewIcon" style="width: 48px; height: 48px; min-width: 48px; border-radius: 10px; display: flex; align-items: center; justify-content: center; background: #2563eb; color: white; font-size: 1.25rem;">
                        <i class="fa-solid fa-users"></i>
                    </div>
                    <div style="flex: 1; min-width: 0;">
                        <h4 id="editSpacePreviewName" style="margin: 0; font-weight: 600; color: #1e293b;">Space Name</h4>
                        <p id="editSpacePreviewDesc" style="margin: 4px 0 0 0; font-size: 0.8rem; color: #64748b; display: none;"></p>
                        <p style="margin: 4px 0 0 0; font-size: 0.75rem; color: #94a3b8;">Posts • Members</p>
                    </div>
                </div>
            </div>
            <div class="form-group">
                <label>Space Name <span style="color:#ef4444">*</span></label>
                <input type="text" id="editSpaceNameInput" placeholder="e.g. Grade 5 Science Club" oninput="updateEditSpacePreview()">
            </div>
            <div class="form-group">
                <label>Description</label>
                <textarea id="editSpaceDescriptionInput" rows="3" placeholder="Describe the purpose of this space..." oninput="updateEditSpacePreview()"></textarea>
            </div>
            <div class="form-group">
                <label>Icon & Color</label>
                <div style="display: flex; gap: 12px; align-items: stretch;">
                    <div id="editIconGrid" class="icon-picker-grid" style="flex: 1; display: grid; grid-template-columns: repeat(5, 1fr); gap: 8px; max-height: 150px; overflow-y: auto; padding: 8px; background: #f8fafc; border-radius: 8px; border: 1px solid #e2e8f0;">
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 8px;">
                        <label style="font-size: 0.75rem; color: #64748b; margin: 0;">Color</label>
                        <input type="color" id="editSpaceColorInput" value="#2563eb" onchange="updateEditSpacePreview(); updateIconGrid('edit')" oninput="updateEditSpacePreview(); updateIconGrid('edit')" style="width: 60px; height: 60px; cursor: pointer; border: none; border-radius: 8px;">
                    </div>
                </div>
                <input type="hidden" id="editSpaceIconSelect" value="fa-solid fa-users">
            </div>
            <div style="border-top: 1px solid #e2e8f0; margin-top: 16px; padding-top: 16px;">
                <button type="button" onclick="confirmDeleteSpace()" style="background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; padding: 10px 16px; border-radius: 6px; cursor: pointer; width: 100%;">
                    <i class="fa-solid fa-trash" style="margin-right: 6px;"></i>Delete Space
                </button>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('editSpaceModal')">Cancel</button>
            <button class="btn btn-primary" onclick="saveSpaceEdit()">Save Changes</button>
        </div>
    </div>
</div>

<!-- Member Management Modal -->
<div id="membersModal" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 700px;">
        <div class="modal-header">
            <h3 id="membersModalTitle">Community Members</h3>
            <span class="close" onclick="closeModal('membersModal')">&times;</span>
        </div>
        <div class="modal-body">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <div id="membersCount" style="color: #64748b; font-size: 0.875rem;"></div>
                <button class="btn btn-primary" id="addMembersBtn" onclick="openAddMembersModal()" style="display: none;">
                    <i class="fa-solid fa-plus" style="margin-right: 6px;"></i>Add Members
                </button>
            </div>
            <div id="membersList" style="max-height: 400px; overflow-y: auto;">
                <div style="text-align: center; padding: 40px; color: #64748b;">
                    <i class="fa-solid fa-spinner fa-spin" style="font-size: 24px; margin-bottom: 12px;"></i>
                    <p>Loading members...</p>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('membersModal')">Close</button>
        </div>
    </div>
</div>

<!-- Add Members Modal -->
<div id="addMembersModal" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 600px;">
        <div class="modal-header">
            <h3>Add Members</h3>
            <span class="close" onclick="closeModal('addMembersModal')">&times;</span>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label>Filter by Role</label>
                <select id="roleFilterSelect" onchange="handleRoleFilterChange()" style="width: 100%; padding: 10px; border: 1px solid #e2e8f0; border-radius: 6px;">
                    <option value="all">All Users</option>
                    <option value="students">Students Only</option>
                    <option value="teachers">Teachers Only</option>
                    <option value="parents">Parents Only</option>
                    <?php if ($issuperadmin): ?>
                    <option value="schooladmins">School Admins Only</option>
                    <?php endif; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Search Users</label>
                <input type="text" id="userSearchInput" placeholder="Type to search users..." onkeyup="searchUsers()" style="width: 100%; padding: 10px; border: 1px solid #e2e8f0; border-radius: 6px;">
            </div>
            <div id="availableUsersList" style="max-height: 300px; overflow-y: auto; margin-top: 16px;">
                <div style="text-align: center; padding: 20px; color: #64748b;">
                    <p>Start typing to search for users...</p>
                </div>
            </div>
            <div id="selectedUsersList" style="margin-top: 16px; padding-top: 16px; border-top: 1px solid #e2e8f0;">
                <label style="font-weight: 600; margin-bottom: 8px; display: block;">Selected Users:</label>
                <div id="selectedUsersTags" style="display: flex; flex-wrap: wrap; gap: 8px;">
                    <p style="color: #64748b; font-size: 0.875rem;">No users selected</p>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('addMembersModal')">Cancel</button>
            <button class="btn btn-primary" onclick="submitAddMembers()">Add Members</button>
        </div>
    </div>
</div>

<!-- Post Detail Modal -->
<div id="postDetailModal" class="modal" style="display: none;">
    <div class="modal-content post-detail-modal-content">
        <div class="modal-header">
            <h3>Post Details</h3>
            <span class="close" onclick="closeModal('postDetailModal')">&times;</span>
        </div>
        <div class="modal-body post-detail-body">
            <div id="postDetailContent">
                <div style="text-align: center; padding: 40px;">
                    <div class="spinner-border" role="status">
                        <span class="sr-only">Loading...</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Edit Post Modal -->
<div id="editPostModal" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 600px;">
        <div class="modal-header">
            <h3><i class="fa-solid fa-pen" style="margin-right: 8px;"></i>Edit Post</h3>
            <span class="close" onclick="closeModal('editPostModal')">&times;</span>
        </div>
        <div class="modal-body">
            <input type="hidden" id="editPostId">
            <div class="form-group" style="margin-bottom: 16px;">
                <label for="editPostSubject" style="display: block; font-weight: 500; margin-bottom: 6px; color: #374151;">Title (optional)</label>
                <input type="text" id="editPostSubject" class="form-control" placeholder="Post title..." style="width: 100%; padding: 10px 12px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 0.95rem;">
            </div>
            <div class="form-group" style="margin-bottom: 16px;">
                <label for="editPostMessage" style="display: block; font-weight: 500; margin-bottom: 6px; color: #374151;">Message <span style="color: #ef4444;">*</span></label>
                <textarea id="editPostMessage" class="form-control" rows="5" placeholder="Write your message..." style="width: 100%; padding: 10px 12px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 0.95rem; resize: vertical;"></textarea>
            </div>
            <div class="form-group" style="margin-bottom: 16px;">
                <label style="display: block; font-weight: 500; margin-bottom: 6px; color: #374151;">Attached Files</label>
                <div id="editPostFilesContainer" style="max-height: 200px; overflow-y: auto;">
                    <!-- Files will be rendered here -->
                </div>
            </div>
            <div class="form-group" style="margin-bottom: 16px;">
                <label for="editPostNewFiles" style="display: block; font-weight: 500; margin-bottom: 6px; color: #374151;">Add New Files</label>
                <input type="file" id="editPostNewFiles" multiple onchange="validateFileSize(this, 30); previewEditPostNewFiles()" style="width: 100%; padding: 10px; border: 1px dashed #cbd5e1; border-radius: 8px; background: #f8fafc;">
                <small style="color: #6b7280; font-size: 0.75rem; margin-top: 4px; display: block;">Maximum file size: 30MB per file</small>
                <div id="editPostNewFilesPreview" style="margin-top: 8px;">
                    <!-- New files preview will be rendered here -->
                </div>
            </div>
        </div>
        <div class="modal-footer" style="display: flex; justify-content: flex-end; gap: 12px; padding: 16px 24px; border-top: 1px solid #e2e8f0;">
            <button type="button" class="btn btn-secondary" onclick="closeModal('editPostModal')" style="padding: 10px 20px; border-radius: 8px; background: #f1f5f9; color: #475569; border: none; cursor: pointer;">Cancel</button>
            <button type="button" class="btn btn-primary modal-submit-btn" onclick="savePostEdit()" style="padding: 10px 20px; border-radius: 8px; background: #2563eb; color: white; border: none; cursor: pointer;">Save Changes</button>
        </div>
    </div>
</div>

<!-- Space Members Modal -->
<div id="spaceMembersModal" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 700px;">
        <div class="modal-header">
            <h3 id="spaceMembersModalTitle">Space Members</h3>
            <span class="close" onclick="closeModal('spaceMembersModal')">&times;</span>
        </div>
        <div class="modal-body">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <div id="spaceMembersCount" style="color: #64748b; font-size: 0.875rem;"></div>
                <button class="btn btn-primary" id="addSpaceMembersBtn" onclick="openAddSpaceMembersModal()" style="display: none;">
                    <i class="fa-solid fa-plus" style="margin-right: 6px;"></i>Add Members
                </button>
            </div>
            <div id="spaceMembersList" style="max-height: 400px; overflow-y: auto;">
                <div style="text-align: center; padding: 40px; color: #64748b;">
                    <i class="fa-solid fa-spinner fa-spin" style="font-size: 24px; margin-bottom: 12px;"></i>
                    <p>Loading members...</p>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('spaceMembersModal')">Close</button>
        </div>
    </div>
</div>

<!-- Add Space Members Modal -->
<div id="addSpaceMembersModal" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 600px;">
        <div class="modal-header">
            <h3>Add Members to Space</h3>
            <span class="close" onclick="closeModal('addSpaceMembersModal')">&times;</span>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label>Filter by Role</label>
                <select id="spaceRoleFilterSelect" onchange="handleSpaceRoleFilterChange()" style="width: 100%; padding: 10px; border: 1px solid #e2e8f0; border-radius: 6px;">
                    <option value="all">All Users</option>
                    <option value="students">Students Only</option>
                    <option value="teachers">Teachers Only</option>
                    <option value="parents">Parents Only</option>
                    <?php if ($issuperadmin): ?>
                    <option value="schooladmins">School Admins Only</option>
                    <?php endif; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Search Users</label>
                <input type="text" id="spaceUserSearchInput" placeholder="Type to search users..." onkeyup="searchSpaceUsers()" style="width: 100%; padding: 10px; border: 1px solid #e2e8f0; border-radius: 6px;">
            </div>
            <div id="availableSpaceUsersList" style="max-height: 300px; overflow-y: auto; margin-top: 16px;">
                <div style="text-align: center; padding: 20px; color: #64748b;">
                    <p>Start typing to search for users...</p>
                </div>
            </div>
            <div id="selectedSpaceUsersList" style="margin-top: 16px; padding-top: 16px; border-top: 1px solid #e2e8f0;">
                <label style="font-weight: 600; margin-bottom: 8px; display: block;">Selected Users:</label>
                <div id="selectedSpaceUsersTags" style="display: flex; flex-wrap: wrap; gap: 8px;">
                    <p style="color: #64748b; font-size: 0.875rem;">No users selected</p>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('addSpaceMembersModal')">Cancel</button>
            <button class="btn btn-primary" onclick="submitAddSpaceMembers()">Add Members</button>
        </div>
    </div>
</div>

<!-- Share Resource Modal -->
<div id="shareResourceModal" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 560px;">
        <div class="modal-header">
            <h3>Share a Resource</h3>
            <span class="close" onclick="closeModal('shareResourceModal')">&times;</span>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label>Title</label>
                <input type="text" id="resourceTitleInput" placeholder="e.g. Grade 4 Math Plan">
            </div>
            <div class="form-group">
                <label>Description (Optional)</label>
                <textarea id="resourceDescriptionInput" rows="3" placeholder="Add helpful context for your teammates..."></textarea>
            </div>
            <div class="form-group">
                <label>Share with Space (Optional)</label>
                <select id="resourceSpaceSelect">
                    <option value="0">Entire Community</option>
                </select>
            </div>
            <div class="form-group">
                <label>Upload File *</label>
                <input type="file" id="resourceFileInput" accept="*/*" onchange="validateFileSize(this, 30)">
                <small style="color:#6b7280;">Maximum file size: 30MB per file. Accepted: images, docs, slides, zips, more.</small>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('shareResourceModal')">Cancel</button>
            <button class="btn btn-primary" onclick="submitShareResource()">Upload Resource</button>
        </div>
    </div>
</div>

<!-- Create Event Modal -->
<div id="createEventModal" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 620px;">
        <div class="modal-header">
            <h3>Create Community Event</h3>
            <span class="close" onclick="closeModal('createEventModal')">&times;</span>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label>Event Title *</label>
                <input type="text" id="eventTitleInput" placeholder="e.g. Parent Teacher Meeting">
            </div>
            <div class="form-group">
                <label>Description (Optional)</label>
                <textarea id="eventDescriptionInput" rows="3" placeholder="Add agenda, links or preparation notes..."></textarea>
            </div>
            <div class="form-group" style="display:flex; gap:12px; flex-wrap:wrap;">
                <div style="flex:1; min-width:180px;">
                    <label>Date *</label>
                    <input type="date" id="eventDateInput">
                </div>
                <div style="flex:1; min-width:160px;">
                    <label>Start Time</label>
                    <input type="time" id="eventStartTimeInput">
                </div>
                <div style="flex:1; min-width:160px;">
                    <label>End Time (Optional)</label>
                    <input type="time" id="eventEndTimeInput">
                </div>
            </div>
            <div class="form-group">
                <label>Event Type</label>
                <select id="eventTypeSelect"></select>
            </div>
            <div class="form-group">
                <label>Location / Link</label>
                <input type="text" id="eventLocationInput" placeholder="Room 201 or Zoom link">
            </div>
            <div class="form-group">
                <label>Associate to a Space (Optional)</label>
                <select id="eventSpaceSelect">
                    <option value="0">Entire Community</option>
                </select>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('createEventModal')">Cancel</button>
            <button class="btn btn-primary" onclick="submitCreateEvent()">Create Event</button>
        </div>
    </div>
</div>

<!-- Edit Event Modal -->
<div id="editEventModal" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 620px;">
        <div class="modal-header">
            <h3><i class="fa-solid fa-pen" style="margin-right: 8px;"></i>Edit Event</h3>
            <span class="close" onclick="closeModal('editEventModal')">&times;</span>
        </div>
        <div class="modal-body">
            <input type="hidden" id="editEventId">
            <div class="form-group">
                <label>Event Title *</label>
                <input type="text" id="editEventTitleInput" placeholder="e.g. Parent Teacher Meeting">
            </div>
            <div class="form-group">
                <label>Description (Optional)</label>
                <textarea id="editEventDescriptionInput" rows="3" placeholder="Add agenda, links or preparation notes..."></textarea>
            </div>
            <div class="form-group" style="display:flex; gap:12px; flex-wrap:wrap;">
                <div style="flex:1; min-width:180px;">
                    <label>Date *</label>
                    <input type="date" id="editEventDateInput">
                </div>
                <div style="flex:1; min-width:140px;">
                    <label>Start Time</label>
                    <input type="time" id="editEventStartTimeInput">
                </div>
                <div style="flex:1; min-width:140px;">
                    <label>End Time</label>
                    <input type="time" id="editEventEndTimeInput">
                </div>
            </div>
            <div class="form-group">
                <label>Event Type</label>
                <select id="editEventTypeSelect"></select>
            </div>
            <div class="form-group">
                <label>Location / Link</label>
                <input type="text" id="editEventLocationInput" placeholder="Room 201 or Zoom link">
            </div>
            <div style="border-top: 1px solid #e2e8f0; margin-top: 16px; padding-top: 16px;">
                <button type="button" onclick="confirmDeleteEvent()" style="background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; padding: 10px 16px; border-radius: 6px; cursor: pointer; width: 100%;">
                    <i class="fa-solid fa-trash" style="margin-right: 6px;"></i>Delete Event
                </button>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('editEventModal')">Cancel</button>
            <button class="btn btn-primary" onclick="saveEventEdit()">Save Changes</button>
        </div>
    </div>
</div>

<!-- Quick Moderation Modal -->
<div id="quickModerationModal" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header">
            <h3><i class="fa-solid fa-shield-halved" style="margin-right: 8px;"></i>Quick Moderation</h3>
            <span class="close" onclick="closeModal('quickModerationModal')">&times;</span>
        </div>
        <div class="modal-body">
            <input type="hidden" id="quickModPostId">
            <div style="margin-bottom: 16px;">
                <p style="margin: 0 0 8px 0; font-weight: 600; color: #374151;">Post Content:</p>
                <div style="padding: 12px; background: #f3f4f6; border-radius: 6px; margin-bottom: 12px;">
                    <p id="quickModPostTitle" style="margin: 0 0 8px 0; font-weight: 600;"></p>
                    <p id="quickModPostMessage" style="margin: 0; color: #6b7280; white-space: pre-wrap;"></p>
                </div>
            </div>
            <div style="padding: 12px; background: #fef2f2; border-left: 4px solid #dc2626; border-radius: 6px; margin-bottom: 16px;">
                <p style="margin: 0 0 4px 0; color: #991b1b; font-weight: 600;">Flag Reason:</p>
                <p id="quickModFlagReason" style="margin: 0; color: #7f1d1d; font-size: 0.875rem;"><strong>Flag Reason:</strong> <span id="quickModFlagReasonText"></span></p>
            </div>
        </div>
        <div class="modal-footer" style="display: flex; gap: 8px; justify-content: flex-end;">
            <button class="btn btn-secondary" onclick="closeModal('quickModerationModal')" style="width: 100px; display: flex; align-items: center; justify-content: center;">Cancel</button>
            <button class="btn" onclick="denyFlaggedPost(document.getElementById('quickModPostId').value)" style="width: 120px; background: #3b82f6; color: white; display: flex; align-items: center; justify-content: center; gap: 6px;">
                <i class="fa-solid fa-times"></i> <span>Deny</span>
            </button>
            <button class="btn btn-primary" onclick="approveFlaggedPost(document.getElementById('quickModPostId').value)" style="width: 120px; background: #ef4444; color: white; display: flex; align-items: center; justify-content: center; gap: 6px;">
                <i class="fa-solid fa-check"></i> <span>Approve</span>
            </button>
            <button class="btn" onclick="deletePostFromModeration(document.getElementById('quickModPostId').value)" style="width: 120px; background: #dc2626; color: white; display: flex; align-items: center; justify-content: center; gap: 6px;">
                <i class="fa-solid fa-trash"></i> <span>Delete</span>
            </button>
        </div>
    </div>
</div>

<!-- Moderation Panel Modal -->
<div id="moderationPanelModal" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 900px; max-height: 90vh; overflow-y: auto;">
        <div class="modal-header">
            <h3><i class="fa-solid fa-shield-halved" style="margin-right: 8px;"></i>Content Moderation</h3>
            <span class="close" onclick="closeModal('moderationPanelModal')">&times;</span>
        </div>
        <div class="modal-body" style="background: #ffffff; padding: 0;">
            <!-- Tab Navigation -->
            <div style="display: flex; border-bottom: 1px solid #e5e7eb; background: #ffffff;">
                <button id="moderationTabFlagged" class="moderation-tab-btn active" onclick="switchModerationTab('flagged')" style="flex: 1; padding: 16px; border: none; background: transparent; cursor: pointer; border-bottom: 2px solid #3b82f6; font-weight: 600; color: #3b82f6;">
                    <i class="fa-solid fa-robot"></i> AI Flagged Posts
                </button>
                <button id="moderationTabReported" class="moderation-tab-btn" onclick="switchModerationTab('reported')" style="flex: 1; padding: 16px; border: none; background: transparent; cursor: pointer; border-bottom: 2px solid transparent; font-weight: 500; color: #64748b;">
                    <i class="fa-solid fa-flag"></i> Reported Posts
                </button>
            </div>
            
            <!-- Tab Content -->
            <div id="moderationTabContent" style="padding: 24px; background: #ffffff;">
                <div id="flaggedPostsList" style="min-height: 200px;">
                    <div class="loading">Loading flagged posts...</div>
                </div>
                <div id="reportedPostsList" style="min-height: 200px; display: none;">
                    <div class="loading">Loading reported posts...</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- View All Spaces Modal -->
<div id="viewAllSpacesModal" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 900px;">
        <div class="modal-header">
            <h3>All Community Spaces</h3>
            <span class="close" onclick="closeModal('viewAllSpacesModal')">&times;</span>
        </div>
        <div class="modal-body">
            <div id="allSpacesList" style="min-height: 300px;">
                <div style="text-align: center; padding: 40px; color: #64748b;">
                    <i class="fa-solid fa-spinner fa-spin" style="font-size: 24px; margin-bottom: 12px;"></i>
                    <p>Loading spaces...</p>
                </div>
            </div>
            <div id="allSpacesPagination" style="display: flex; justify-content: center; align-items: center; gap: 12px; margin-top: 20px; padding-top: 20px; border-top: 1px solid #e5e7eb;">
                <!-- Pagination controls will be added here -->
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('viewAllSpacesModal')">Close</button>
        </div>
    </div>
</div>

<!-- View All Events Modal -->
<div id="viewAllEventsModal" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 900px;">
        <div class="modal-header">
            <h3>All Upcoming Events</h3>
            <span class="close" onclick="closeModal('viewAllEventsModal')">&times;</span>
        </div>
        <div class="modal-body">
            <div id="allEventsList" style="min-height: 300px;">
                <div style="text-align: center; padding: 40px; color: #64748b;">
                    <i class="fa-solid fa-spinner fa-spin" style="font-size: 24px; margin-bottom: 12px;"></i>
                    <p>Loading events...</p>
                </div>
            </div>
            <div id="allEventsPagination" style="display: flex; justify-content: center; align-items: center; gap: 12px; margin-top: 20px; padding-top: 20px; border-top: 1px solid #e5e7eb;">
                <!-- Pagination controls will be added here -->
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('viewAllEventsModal')">Close</button>
        </div>
    </div>
</div>

<!-- View All Resources Modal -->
<div id="viewAllResourcesModal" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 900px;">
        <div class="modal-header">
            <h3>All Resources</h3>
            <span class="close" onclick="closeModal('viewAllResourcesModal')">&times;</span>
        </div>
        <div class="modal-body">
            <div id="allResourcesList" style="min-height: 300px;">
                <div style="text-align: center; padding: 40px; color: #64748b;">
                    <i class="fa-solid fa-spinner fa-spin" style="font-size: 24px; margin-bottom: 12px;"></i>
                    <p>Loading resources...</p>
                </div>
            </div>
            <div id="allResourcesPagination" style="display: flex; justify-content: center; align-items: center; gap: 12px; margin-top: 20px; padding-top: 20px; border-top: 1px solid #e5e7eb;">
                <!-- Pagination controls will be added here -->
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('viewAllResourcesModal')">Close</button>
        </div>
    </div>
</div>

<!-- Full Image Modal -->
<div id="fullImageModal" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 95vw; max-height: 95vh; min-height: 550px; width: auto; margin: 2.5vh auto; background: rgba(0, 0, 0, 0.95); border: none; border-radius: 8px; padding: 0;">
        <div style="position: relative; width: 100%; min-height: 550px; display: flex; align-items: center; justify-content: center; padding: 60px 20px 80px;">
            <span class="close" onclick="closeModal('fullImageModal')" style="position: absolute; top: 20px; right: 20px; color: white; font-size: 36px; font-weight: bold; cursor: pointer; z-index: 10; background: rgba(0, 0, 0, 0.5); border-radius: 50%; width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; transition: background 0.2s ease;">&times;</span>
            <img id="fullImageContent" src="" alt="" style="max-width: 100%; max-height: calc(95vh - 140px); min-height: 550px; width: auto; height: auto; object-fit: contain; border-radius: 4px;">
            <div style="position: absolute; bottom: 20px; left: 50%; transform: translateX(-50%); display: flex; gap: 12px;">
                <button class="btn btn-primary" onclick="downloadFullImage()" style="background: rgba(37, 99, 235, 0.9); border: none; padding: 12px 24px; border-radius: 8px; color: white; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 8px; transition: background 0.2s ease;">
                    <i class="fa-solid fa-download"></i> Download
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Filter Posts Modal -->
<div id="filterModal" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 700px;">
        <div class="modal-header">
            <h3>Filter Posts</h3>
            <span class="close" onclick="closeFilterModal()">&times;</span>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label>Select Spaces</label>
                <select id="filterSpaceSelect" multiple style="width: 100%; padding: 10px; border: 1px solid #e2e8f0; border-radius: 6px; min-height: 100px;">
                    <option value="0">Entire Community</option>
                </select>
                <small style="color: #64748b; font-size: 0.75rem;">Hold Ctrl/Cmd to select multiple spaces</small>
            </div>
            
            <div class="form-group" style="display: flex; gap: 12px;">
                <div style="flex: 1;">
                    <label>From Date</label>
                    <input type="date" id="filterFromDate" style="width: 100%; padding: 10px; border: 1px solid #e2e8f0; border-radius: 6px;">
                </div>
                <div style="flex: 1;">
                    <label>To Date</label>
                    <input type="date" id="filterToDate" style="width: 100%; padding: 10px; border: 1px solid #e2e8f0; border-radius: 6px;">
                </div>
            </div>
            
            <div class="form-group">
                <label>Posted By</label>
                <select id="filterPostedBy" style="width: 100%; padding: 10px; border: 1px solid #e2e8f0; border-radius: 6px;">
                    <option value="">All Users</option>
                </select>
            </div>
            
            <div class="form-group">
                <label>User Roles</label>
                <select id="filterRoles" multiple style="width: 100%; padding: 10px; border: 1px solid #e2e8f0; border-radius: 6px; min-height: 80px;">
                </select>
                <small style="color: #64748b; font-size: 0.75rem;">Hold Ctrl/Cmd to select multiple roles</small>
            </div>
            
            <div class="form-group">
                <label>Cohorts</label>
                <select id="filterCohorts" multiple style="width: 100%; padding: 10px; border: 1px solid #e2e8f0; border-radius: 6px; min-height: 80px;">
                </select>
                <small style="color: #64748b; font-size: 0.75rem;">Hold Ctrl/Cmd to select multiple cohorts</small>
            </div>
            
            <div class="form-group">
                <label>Sort By</label>
                <select id="filterSortBy" style="width: 100%; padding: 10px; border: 1px solid #e2e8f0; border-radius: 6px;">
                    <option value="newest">Newest First</option>
                    <option value="oldest">Oldest First</option>
                    <option value="most_liked">Most Liked</option>
                    <option value="most_commented">Most Commented</option>
                </select>
            </div>
        </div>
        <div class="modal-footer" style="display: flex; justify-content: space-between;">
            <button class="btn btn-secondary" onclick="clearFilters()">Clear All</button>
            <div style="display: flex; gap: 8px;">
                <button class="btn btn-secondary" onclick="closeFilterModal()">Cancel</button>
                <button class="btn btn-primary" onclick="applyFilters()">Apply Filters</button>
            </div>
        </div>
    </div>
</div>

<script>
// Global variables
let currentCommunityId = <?php echo $communityid; ?>;
let currentCommunityName = '';
let currentCommunityMemberCount = 0;
let currentCommunityDescription = '';
let currentUserId = <?php echo $USER->id; ?>;
let currentFilters = {};
let filterOptionsLoaded = false;
let selectedMediaFiles = [];
let selectedSpaceId = 0;
let availableCommunities = [];
let currentPostPage = 0;
let totalPosts = 0;
let postsPerPage = 20;
let isLoadingPosts = false;
let currentSpaces = [];
let canModerate = false; // Whether current user can moderate posts (edit/delete any post)
const eventTypeOptions = <?php echo json_encode(constants::event_type_labels(), JSON_UNESCAPED_UNICODE); ?>;
const communityContentGrid = document.getElementById('communityContentGrid');
const communityEmptyState = document.getElementById('communityEmptyState');
const communityHeaderActions = document.getElementById('communityHeaderActions');
const communityDetailActions = document.getElementById('communityDetailActions');
const communityListSection = document.getElementById('communityListSection');
const communityListGrid = document.getElementById('communityListGrid');
const communitySwitcher = document.querySelector('.community-switcher');
const canCreateCommunity = <?php echo $cancreatecommunity ? 'true' : 'false'; ?>;
const spaceChatPageBaseUrl = <?php echo json_encode($CFG->wwwroot . '/theme/remui_kids/community_chat.php?spaceid='); ?>;
const communityChatPageBaseUrl = <?php echo json_encode($CFG->wwwroot . '/theme/remui_kids/community_chat.php?communityid='); ?>;
const communityChatBaseUrl = <?php echo json_encode($CFG->wwwroot . '/theme/remui_kids/community_chat.php'); ?>;
const communityChatButton = document.getElementById('communityChatButton');
const communityPageTitle = document.querySelector('.community-title-block h1');
const communityPageSubtitle = document.querySelector('.community-title-block p');
const defaultCommunityPageTitle = communityPageTitle ? communityPageTitle.textContent.trim() : 'Community & Collaboration';
const defaultCommunityPageSubtitle = communityPageSubtitle ? communityPageSubtitle.textContent.trim() : 'Connect, share, and grow with the teaching community';

function updateMemberButtonLabel() {
    const btn = document.getElementById('viewMembersButton');
    if (!btn) {
        return;
    }
    const count = currentCommunityMemberCount || 0;
    const label = count === 1 ? '1 Member' : `${count}`;
    btn.innerHTML = `<i class="fa-solid fa-users"></i> ${label}`;
}

function updateCommunityChatButtonTarget() {
    if (!communityChatButton) {
        return;
    }
    let target = communityChatBaseUrl;
    if (currentCommunityId) {
        target = `${communityChatPageBaseUrl}${encodeURIComponent(currentCommunityId)}`;
    }
    communityChatButton.dataset.target = target;
}

// Initialize page
document.addEventListener('DOMContentLoaded', function() {
    const communitySelect = document.getElementById('communitySelect');
    if (communitySelect) {
        communitySelect.addEventListener('change', handleCommunityChange);
    }
    loadDefaultCommunity();
    // Load sidebar states from localStorage
    loadSidebarStates();
    updateCommunityChatButtonTarget();
    if (communityChatButton) {
        communityChatButton.addEventListener('click', () => {
            const target = communityChatButton.dataset.target || communityChatBaseUrl;
            window.location.href = target;
        });
    }
    
    // Check if moderation panel should be reopened after reload
    const reopenPanel = sessionStorage.getItem('reopenModerationPanel');
    const moderationTab = sessionStorage.getItem('moderationTab');
    const moderationSuccess = sessionStorage.getItem('moderationSuccess');
    
    if (reopenPanel === 'true') {
        sessionStorage.removeItem('reopenModerationPanel');
        sessionStorage.removeItem('moderationTab');
        // Wait a bit for page to fully load, then reopen panel
        setTimeout(() => {
            if (moderationTab) {
                openModerationPanel();
                switchModerationTab(moderationTab);
            } else {
                openModerationPanel();
            }
            
            // Show success message after panel is opened
            if (moderationSuccess) {
                sessionStorage.removeItem('moderationSuccess');
                setTimeout(() => {
                    if (typeof RemuiAlert !== 'undefined') {
                        RemuiAlert.success(moderationSuccess);
                    }
                }, 300);
            }
        }, 1000);
    } else if (moderationSuccess) {
        // If panel wasn't open, just show the success message
        sessionStorage.removeItem('moderationSuccess');
        setTimeout(() => {
            if (typeof RemuiAlert !== 'undefined') {
                RemuiAlert.success(moderationSuccess);
            }
        }, 500);
    }
});

// Toggle sidebar collapse/expand
function toggleSidebar(side) {
    const sidebar = document.getElementById(side === 'left' ? 'leftSidebar' : 'rightSidebar');
    const toggleBtn = document.getElementById(side === 'left' ? 'leftSidebarToggle' : 'rightSidebarToggle');
    const grid = document.getElementById('communityContentGrid');
    
    if (!sidebar || !grid) return;
    
    const isCollapsed = sidebar.classList.contains('collapsed');
    
    if (isCollapsed) {
        sidebar.classList.remove('collapsed');
        toggleBtn.title = 'Collapse sidebar';
        const icon = toggleBtn.querySelector('i');
        if (side === 'left') {
            icon.classList.remove('fa-chevron-right');
            icon.classList.add('fa-chevron-left');
        } else {
            icon.classList.remove('fa-chevron-left');
            icon.classList.add('fa-chevron-right');
        }
    } else {
        sidebar.classList.add('collapsed');
        toggleBtn.title = 'Expand sidebar';
        const icon = toggleBtn.querySelector('i');
        if (side === 'left') {
            icon.classList.remove('fa-chevron-left');
            icon.classList.add('fa-chevron-right');
        } else {
            icon.classList.remove('fa-chevron-right');
            icon.classList.add('fa-chevron-left');
        }
    }
    
    // Update grid classes
    updateGridColumns();
    
    // Save state to localStorage
    saveSidebarStates();
}

// Update grid columns based on collapsed state
function updateGridColumns() {
    const grid = document.getElementById('communityContentGrid');
    const leftSidebar = document.getElementById('leftSidebar');
    const rightSidebar = document.getElementById('rightSidebar');
    
    if (!grid || !leftSidebar || !rightSidebar) return;
    
    // Remove all collapse classes
    grid.classList.remove('left-collapsed', 'right-collapsed', 'both-collapsed');
    
    const leftCollapsed = leftSidebar.classList.contains('collapsed');
    const rightCollapsed = rightSidebar.classList.contains('collapsed');
    
    if (leftCollapsed && rightCollapsed) {
        grid.classList.add('both-collapsed');
    } else if (leftCollapsed) {
        grid.classList.add('left-collapsed');
    } else if (rightCollapsed) {
        grid.classList.add('right-collapsed');
    }
}

// Save sidebar states to localStorage
function saveSidebarStates() {
    const leftSidebar = document.getElementById('leftSidebar');
    const rightSidebar = document.getElementById('rightSidebar');
    
    if (leftSidebar && rightSidebar) {
        localStorage.setItem('communityLeftSidebarCollapsed', leftSidebar.classList.contains('collapsed'));
        localStorage.setItem('communityRightSidebarCollapsed', rightSidebar.classList.contains('collapsed'));
    }
}

// Load sidebar states from localStorage
function loadSidebarStates() {
    const leftCollapsed = localStorage.getItem('communityLeftSidebarCollapsed') === 'true';
    const rightCollapsed = localStorage.getItem('communityRightSidebarCollapsed') === 'true';
    
    const leftSidebar = document.getElementById('leftSidebar');
    const rightSidebar = document.getElementById('rightSidebar');
    const leftToggle = document.getElementById('leftSidebarToggle');
    const rightToggle = document.getElementById('rightSidebarToggle');
    
    if (leftSidebar && leftCollapsed) {
        leftSidebar.classList.add('collapsed');
        if (leftToggle) {
            const icon = leftToggle.querySelector('i');
            if (icon) {
                icon.classList.remove('fa-chevron-left');
                icon.classList.add('fa-chevron-right');
            }
            leftToggle.title = 'Expand sidebar';
        }
    }
    
    if (rightSidebar && rightCollapsed) {
        rightSidebar.classList.add('collapsed');
        if (rightToggle) {
            const icon = rightToggle.querySelector('i');
            if (icon) {
                icon.classList.remove('fa-chevron-right');
                icon.classList.add('fa-chevron-left');
            }
            rightToggle.title = 'Expand sidebar';
        }
    }
    
    updateGridColumns();
}

// Load default community list
// Helper function to extract JSON from response that might have HTML error output
function extractJSONFromResponse(text) {
    // Find the first occurrence of '{' which should be the start of JSON
    const firstBrace = text.indexOf('{');
    if (firstBrace === -1) {
        throw new Error('No JSON found in response');
    }
    // Extract from first brace to end
    const jsonText = text.substring(firstBrace);
    // Try to find the last '}' that properly closes the JSON
    // Count braces to find the matching closing brace
    let braceCount = 0;
    let lastBraceIndex = -1;
    for (let i = 0; i < jsonText.length; i++) {
        if (jsonText[i] === '{') {
            braceCount++;
        } else if (jsonText[i] === '}') {
            braceCount--;
            if (braceCount === 0) {
                lastBraceIndex = i;
            }
        }
    }
    if (lastBraceIndex === -1) {
        throw new Error('Invalid JSON structure');
    }
    // Extract the JSON part
    const cleanJSON = jsonText.substring(0, lastBraceIndex + 1);
    return JSON.parse(cleanJSON);
}

// Helper function to fetch and parse JSON from communityhub/ajax.php (handles HTML error output)
async function fetchCommunityHubJSON(url, options = {}) {
    try {
        const response = await fetch(url, options);
        if (!response.ok) {
            throw new Error('Network response was not ok');
        }
        const text = await response.text();
        return extractJSONFromResponse(text);
    } catch (error) {
        console.error('Error fetching from communityhub:', error);
        throw error;
    }
}

function loadDefaultCommunity() {
    // Show loading state - hide empty state initially
    setViewMode('loading');
    
    fetch('<?php echo $CFG->wwwroot; ?>/local/communityhub/ajax.php?action=list&page=0&perpage=100&sesskey=<?php echo sesskey(); ?>')
        .then(response => {
            // Check if response is ok
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            // Get response as text first to handle HTML error output
            return response.text();
        })
        .then(text => {
            // Extract JSON from response (might have HTML prefix)
            try {
                const data = extractJSONFromResponse(text);
                // Validate response structure properly
                if (data && data.success !== undefined) {
                    // Check if data.data exists and has records
                    if (data.success && data.data && Array.isArray(data.data.records)) {
                        availableCommunities = data.data.records;
                        populateCommunitySelect();
                        handleLandingState();
                    } else if (data.success && data.data && (!data.data.records || data.data.records.length === 0)) {
                        // Only show empty state if we got a successful response with no records
                        showNoCommunitiesState();
                    } else {
                        // Response structure is unexpected, log and show error
                        console.error('Unexpected response structure:', data);
                        showNoCommunitiesState();
                    }
                } else {
                    // Invalid response format
                    console.error('Invalid response format:', data);
                    showNoCommunitiesState();
                }
            } catch (parseError) {
                console.error('Error parsing JSON:', parseError);
                showNoCommunitiesState();
            }
        })
        .catch(error => {
            console.error('Error loading communities:', error);
            // Only show empty state on actual error, not on network issues
            // Retry once after a short delay
            setTimeout(() => {
                fetch('<?php echo $CFG->wwwroot; ?>/local/communityhub/ajax.php?action=list&page=0&perpage=100&sesskey=<?php echo sesskey(); ?>')
                    .then(response => {
                        if (!response.ok) {
                            throw new Error('Network response was not ok');
                        }
                        return response.text();
                    })
                    .then(text => {
                        try {
                            const data = extractJSONFromResponse(text);
                            if (data && data.success && data.data && Array.isArray(data.data.records)) {
                                availableCommunities = data.data.records;
                                populateCommunitySelect();
                                handleLandingState();
                            } else {
                                showNoCommunitiesState();
                            }
                        } catch (parseError) {
                            console.error('Error parsing JSON on retry:', parseError);
                            showNoCommunitiesState();
                        }
                    })
                    .catch(retryError => {
                        console.error('Retry failed:', retryError);
                        showNoCommunitiesState();
                    });
            }, 500);
        });
}

function showNoCommunitiesState() {
    // Only show empty state after confirming no communities exist
    const postsFeed = document.getElementById('postsFeed');
    if (postsFeed) {
        postsFeed.innerHTML = '<div class="empty-state"><div class="empty-state-icon"><i class="fa-solid fa-users"></i></div><p class="empty-state-text">No communities yet. Create one to get started!</p></div>';
    }
    populateCommunitySelect();
    setViewMode('empty');
}

function handleLandingState() {
    // Validate that we have properly loaded communities data
    if (!Array.isArray(availableCommunities)) {
        console.error('availableCommunities is not an array:', availableCommunities);
        showNoCommunitiesState();
        return;
    }
    
    if (availableCommunities.length === 0) {
        // Only show empty state if we confirmed there are no communities
        showNoCommunitiesState();
        return;
    }

    if (!currentCommunityId) {
        renderCommunityList();
        setViewMode('list');
        return;
    }

    // Ensure both are treated as numbers for comparison
    const targetId = parseInt(currentCommunityId, 10);
    const hasAccess = availableCommunities.some(comm => {
        const commId = parseInt(comm.id, 10);
        return commId === targetId;
    });
    
    if (!hasAccess) {
        console.warn('Community access check failed. Current ID:', currentCommunityId, 'Available IDs:', availableCommunities.map(c => c.id));
        currentCommunityId = 0;
        renderCommunityList();
        setViewMode('list');
        return;
    }

    loadCommunityDetail(targetId);
}

function populateCommunitySelect() {
    const select = document.getElementById('communitySelect');
    if (!select) return;

    select.innerHTML = '';
    if (!availableCommunities.length) {
        const option = document.createElement('option');
        option.value = '';
        option.textContent = 'No communities available';
        select.appendChild(option);
        select.disabled = true;
        return;
    }

    availableCommunities.forEach(comm => {
        const option = document.createElement('option');
        option.value = comm.id;
        option.textContent = comm.name;
        if (parseInt(comm.id, 10) === parseInt(currentCommunityId || 0, 10)) {
            option.selected = true;
        }
        select.appendChild(option);
    });
    select.disabled = false;
}

function handleCommunityChange(event) {
    const newId = parseInt(event.target.value, 10);
    if (!newId || newId === parseInt(currentCommunityId || 0, 10)) {
        return;
    }
    currentCommunityId = newId;
    loadCommunityDetail(currentCommunityId);
}

// Load community detail
function loadCommunityDetail(communityId) {
    if (!communityId) {
        handleLandingState();
        return Promise.resolve();
    }
    // Reset pagination
    currentPostPage = 0;
    totalPosts = 0;
    
    return fetch('<?php echo $CFG->wwwroot; ?>/local/communityhub/ajax.php?action=detail&communityid=' + communityId + '&sesskey=<?php echo sesskey(); ?>')
        .then(async response => {
            // Get response as text first to handle HTML error output
            const text = await response.text();
            let payload;
            try {
                payload = extractJSONFromResponse(text);
            } catch (parseError) {
                console.error('Error parsing JSON in loadCommunityDetail:', parseError);
                payload = { success: false };
            }
            if (!response.ok || !payload.success) {
                handleCommunityAccessDenied(payload.error);
                return null;
            }
            return payload.data;
        })
        .then(data => {
            if (!data) {
                return Promise.resolve();
            }
            // Don't render posts here - we'll load them separately with pagination
            renderCommunityDetail(data, false);
            populateCommunitySelect();
            setViewMode('detail');
            // Load filter options (but don't show filter bar yet - user must toggle it)
            filterOptionsLoaded = false;
            
            // Check if user can moderate this community
            fetchCommunityHubJSON(`<?php echo $CFG->wwwroot; ?>/local/communityhub/ajax.php?action=can_moderate&communityid=${communityId}&sesskey=<?php echo sesskey(); ?>`)
                .then(modData => {
                    canModerate = modData.success && modData.data && modData.data.can_moderate;
                    // Show moderation button if user can moderate
                    const modBtn = document.getElementById('moderationButton');
                    if (modBtn) {
                        modBtn.style.display = canModerate ? 'inline-flex' : 'none';
                    }
                    // Re-render events and spaces with edit buttons if user can moderate
                    if (canModerate && currentEvents.length > 0) {
                        renderEvents(currentEvents);
                    }
                    if (canModerate && currentSpaces.length > 0) {
                        renderSpaces(currentSpaces);
                    }
                    // Re-render posts with moderation buttons if user can moderate
                    if (canModerate && currentPostsData.length > 0) {
                        renderPosts(currentPostsData, true);
                    }
                })
                .catch(() => { canModerate = false; });
            
            // Load initial posts and return promise
            return loadPosts(communityId, 0, true);
        })
        .catch(error => {
            console.error('Error loading community:', error);
            document.getElementById('postsFeed').innerHTML = '<div class="empty-state"><div class="empty-state-icon"><i class="fa-solid fa-exclamation-triangle"></i></div><p class="empty-state-text">Error loading community</p></div>';
            handleCommunityAccessDenied();
            return Promise.reject(error);
        });
}

function setViewMode(mode) {
    if (communityContentGrid) {
        communityContentGrid.style.display = mode === 'detail' ? 'grid' : 'none';
    }
    if (communityEmptyState) {
        // Only show empty state if explicitly set to 'empty', hide for 'loading' and other modes
        communityEmptyState.style.display = mode === 'empty' ? 'block' : 'none';
    }
    if (communityHeaderActions) {
        if (mode === 'detail' || (mode !== 'loading' && canCreateCommunity)) {
            communityHeaderActions.style.display = 'flex';
        } else {
            communityHeaderActions.style.display = 'none';
        }
    }
    // Hide "Create Community" button when in detail mode or loading
    const createCommunityBtn = document.getElementById('createCommunityAction');
    if (createCommunityBtn) {
        createCommunityBtn.style.display = (mode === 'detail' || mode === 'loading') ? 'none' : (canCreateCommunity ? 'block' : 'none');
    }
    if (communityDetailActions) {
        communityDetailActions.style.display = mode === 'detail' ? 'flex' : 'none';
    }
    if (communityListSection) {
        communityListSection.style.display = mode === 'list' ? 'block' : 'none';
    }
    if (communitySwitcher) {
        communitySwitcher.style.display = mode === 'detail' ? 'flex' : 'none';
    }
    
    // Hide filter bar when not in detail mode
    const filterBar = document.getElementById('communityFilterBar');
    if (filterBar) {
        if (mode === 'detail') {
            // Filter bar is available but initially hidden (controlled by toggle)
            // Reset filters when switching modes
            if (!filterBar.classList.contains('show')) {
                clearInlineFilters();
            }
        } else {
            // Hide and reset filter bar when not in detail mode
            filterBar.classList.remove('show');
            clearInlineFilters();
        }
    }
    
    if (communityPageTitle) {
        if (mode === 'detail' && currentCommunityName) {
            communityPageTitle.textContent = currentCommunityName;
            if (communityPageSubtitle) {
                communityPageSubtitle.textContent = currentCommunityDescription || defaultCommunityPageSubtitle;
            }
        } else {
            communityPageTitle.textContent = defaultCommunityPageTitle;
            if (communityPageSubtitle) {
                communityPageSubtitle.textContent = defaultCommunityPageSubtitle;
            }
        }
    }
    
    updateCommunityChatButtonTarget();
    
    // Show loading state in posts feed if loading
    const postsFeed = document.getElementById('postsFeed');
    if (postsFeed && mode === 'loading') {
        postsFeed.innerHTML = '<div class="loading">Loading communities...</div>';
    }
}

function renderCommunityList() {
    if (!communityListGrid) {
        return;
    }
    if (!availableCommunities.length) {
        communityListGrid.innerHTML = '<p style="color:#475569;">You are not assigned to any communities yet.</p>';
        return;
    }
    const cards = availableCommunities.map(comm => {
        const members = comm.membercount || 0;
        const posts = comm.postcount || 0;
        const name = truncateWords(comm.name || 'Community', 4);
        const rawDescription = stripHtml(comm.description || '');
        const description = truncateWords(rawDescription || 'No description provided.', 6);
        const memberlabel = `${members}`;
        const ownerName = comm.creatorname || 'Unknown';
        const ownerId = comm.createdby || 0;
        const createdDate = comm.timecreated ? formatDate(comm.timecreated) : '';
        const ownerAvatarUrl = '<?php echo $CFG->wwwroot; ?>/user/pix.php/' + ownerId + '/f1.jpg';
        return `
            <div class="community-card" data-community-id="${comm.id}" data-community-name="${escapeHtml(comm.name || '')}">
                <div class="community-card-header">
                    <h5>${escapeHtml(name)}</h5>
                    <div class="community-card-header-actions">
                        <button type="button" class="community-card-members" data-community-id="${comm.id}" data-community-name="${escapeHtml(comm.name || '')}">
                            <i class="fa-solid fa-users"></i> ${escapeHtml(memberlabel)}
                        </button>
                        <a href="${communityChatPageBaseUrl}${encodeURIComponent(comm.id)}"
                           class="community-card-chat-icon"
                           title="Open Chat"
                           onclick="event.stopPropagation();">
                            <i class="fa-solid fa-comments"></i>
                        </a>
                    </div>
                </div>
                <p>${escapeHtml(description)}</p>
                <div class="community-card-divider"></div>
                <div class="community-card-footer">
                    <div class="community-card-posts">
                        <i class="fa-regular fa-message"></i>
                        <span>${posts} posts</span>
                    </div>
                    <div class="community-card-owner">
                        <img src="${ownerAvatarUrl}" alt="${escapeHtml(ownerName)}" class="community-card-owner-avatar" onerror="this.src='<?php echo $CFG->wwwroot; ?>/pix/u/f1.png'">
                        <div class="community-card-owner-info">
                            <p class="community-card-owner-name">${escapeHtml(ownerName)}</p>
                            <p class="community-card-owner-date">${createdDate}</p>
                        </div>
                    </div>
                </div>
            </div>
        `;
    }).join('');
    communityListGrid.innerHTML = cards;
    attachCommunityCardHandlers();
}

function attachCommunityCardHandlers() {
    const cards = document.querySelectorAll('.community-card');
    cards.forEach(card => {
        card.style.cursor = 'pointer';
        card.addEventListener('click', () => {
            const id = card.dataset.communityId;
            if (id) {
                window.location.href = `${window.location.pathname}?id=${id}`;
            }
        });
    });

    const memberButtons = document.querySelectorAll('.community-card-members');
    memberButtons.forEach(button => {
        button.addEventListener('click', function(event) {
            event.stopPropagation();
            const id = this.dataset.communityId;
            const name = this.dataset.communityName || 'Community';
            openMembersModal(id, name);
        });
    });

}

function handleCommunityAccessDenied(message) {
    currentCommunityId = 0;
    renderCommunityList();
    setViewMode(availableCommunities.length ? 'list' : 'empty');
    if (message) {
        console.warn('Community access denied:', message);
    }
}

// Render community detail
function renderCommunityDetail(data, renderPostsData = true) {
    currentCommunityName = data.name || 'Community';
    currentCommunityDescription = stripHtml(data.description || '').trim();
    currentCommunityMemberCount = (data.members || []).length;
    updateMemberButtonLabel();

    // Render spaces
    renderSpaces(data.spaces || []);
    
    // Render events
    renderEvents(data.events || []);
    
    // Render posts (only if renderPostsData is true)
    if (renderPostsData) {
        renderPosts(data.posts || []);
    } else {
        // Clear posts feed and show loading
        const postsFeedEl = document.getElementById('postsFeed');
        if (postsFeedEl) {
            postsFeedEl.innerHTML = '<div class="loading">Loading posts...</div>';
        }
    }
    
    // Render resources
    renderResources(data.resources || []);
    
    // Update stats
    updateStats(data);
    
    if (currentCommunityId) {
        loadInsights(currentCommunityId);
    }
}

// Render spaces (show only first 5 on main page)
function renderSpaces(spaces) {
    const spacesListEl = document.getElementById('spacesList');
    
    currentSpaces = spaces;
    populateSpaceSelectOptions('resourceSpaceSelect');
    populateSpaceSelectOptions('eventSpaceSelect');

    if (spaces.length === 0) {
        spacesListEl.innerHTML = '<div class="empty-state"><p class="empty-state-text">No spaces yet</p></div>';
        return;
    }
    
    // Show only first 5 spaces on main page
    const spacesToShow = spaces.slice(0, 5);
    
    let html = '';
    spacesToShow.forEach(space => {
        html += `
            <div class="space-item" onclick="filterBySpace(${space.id})">
                <div class="space-item-icon" style="background: ${space.color || '#e5e7eb'}; color: white;">
                    <i class="${space.icon || 'fa-solid fa-users'}"></i>
                </div>
                <div class="space-item-content">
                    <h4 class="space-item-title">${escapeHtml(space.name)}</h4>
                    <p class="space-item-meta">${space.postcount || 0} posts • ${space.membercount || 0} members</p>
                </div>
                <div style="display: flex; gap: 8px; align-items: center;">
                    ${canModerate ? `
                    <button class="space-edit-btn" onclick="event.stopPropagation(); openEditSpaceModal(${space.id})" title="Edit Space" style="width: 32px; height: 32px; border-radius: 6px; border: none; background: #fef3c7; color: #b45309; cursor: pointer;">
                        <i class="fa-solid fa-pen"></i>
                    </button>
                    ` : ''}
                    <button class="space-members-btn" onclick="event.stopPropagation(); openSpaceMembersModal(${space.id}, '${escapeHtml(space.name)}')" title="Manage Members">
                        <i class="fa-solid fa-users"></i>
                    </button>
                    <button class="space-chat-btn" onclick="event.stopPropagation(); openSpaceChatPage(${space.id})" title="Open Space Chat">
                        <i class="fa-solid fa-comments"></i>
                    </button>
                </div>
            </div>
        `;
    });
    
    spacesListEl.innerHTML = html;
    
    // Populate space select in create post modal
    const spaceSelect = document.getElementById('postSpaceSelect');
    spaceSelect.innerHTML = '<option value="0">Post in Community</option>';
    spaces.forEach(space => {
        const option = document.createElement('option');
        option.value = space.id;
        option.textContent = space.name;
        spaceSelect.appendChild(option);
    });
}

function populateSpaceSelectOptions(selectId) {
    const select = document.getElementById(selectId);
    if (!select) {
        return;
    }

    select.innerHTML = '<option value="0">Entire Community</option>';
    currentSpaces.forEach(space => {
        const option = document.createElement('option');
        option.value = space.id;
        option.textContent = space.name;
        select.appendChild(option);
    });
}

// Store events for editing
let currentEvents = [];

// Store posts for quick moderation
let currentPostsData = [];

// Render events (show only first 5 on main page)
function renderEvents(events) {
    const eventsListEl = document.getElementById('eventsList');
    currentEvents = events; // Store for editing
    
    if (events.length === 0) {
        eventsListEl.innerHTML = '<div class="empty-state"><p class="empty-state-text">No upcoming events</p></div>';
        return;
    }
    
    // Show only first 5 events on main page
    const eventsToShow = events.slice(0, 5);
    
    let html = '';
    const nowSeconds = Date.now() / 1000;
    eventsToShow.forEach(event => {
        const startDate = new Date(event.starttime * 1000);
        const month = startDate.toLocaleString('default', { month: 'short' }).toUpperCase();
        const day = startDate.getDate();
        const timeStr = formatTime(event.starttime);
        const endTimeStr = event.endtime ? ' - ' + formatTime(event.endtime) : '';
        const description = stripHtml(event.description || '');
        const isPast = event.starttime < nowSeconds;
        const statusBadge = isPast ? '<span class="event-badge event-badge-past">Past</span>' : '';
        
        html += `
            <div class="event-item" style="position: relative;">
                <div style="display: flex;">
                    <div class="event-date" style="background: #dbeafe; color: #1e40af;">
                        <div class="event-date-month">${month}</div>
                        <div class="event-date-day">${day}</div>
                    </div>
                    <div class="event-content" style="flex: 1;">
                        <h4 class="event-title">${escapeHtml(event.title)}</h4> 
                        <p class="event-meta">${timeStr}${endTimeStr} • ${event.location || 'Virtual'}</p>
                        ${description ? `<p class="event-meta" style="white-space: pre-wrap;">${escapeHtml(description)}</p>` : ''}
                        <div class="event-badges">
                            <span class="event-badge" style="background: #dbeafe; color: #1e40af;">${event.eventtypelabel}</span>
                            ${statusBadge}
                        </div>
                    </div>
                    ${canModerate ? `
                    <button class="event-edit-btn" onclick="openEditEventModal(${event.id})" title="Edit Event" style="position: absolute; top: 8px; right: 8px; width: 28px; height: 28px; border-radius: 6px; border: none; background: #fef3c7; color: #b45309; cursor: pointer; display: flex; align-items: center; justify-content: center;">
                        <i class="fa-solid fa-pen" style="font-size: 0.75rem;"></i>
                    </button>
                    ` : ''}
                </div>
            </div>
        `;
    });
    
    eventsListEl.innerHTML = html;
}

// Load posts with pagination
function loadPosts(communityId, page, isInitial = false) {
    // Don't load posts if communityId is invalid
    if (!communityId || communityId <= 0) {
        const postsFeedEl = document.getElementById('postsFeed');
        if (postsFeedEl && isInitial) {
            postsFeedEl.innerHTML = '<div class="empty-state"><div class="empty-state-icon"><i class="fa-solid fa-comments"></i></div><p class="empty-state-text">Select a community to view posts</p></div>';
        }
        return Promise.resolve();
    }
    
    if (isLoadingPosts) return Promise.resolve();
    
    isLoadingPosts = true;
    const postsFeedEl = document.getElementById('postsFeed');
    
    if (isInitial) {
        postsFeedEl.innerHTML = '<div class="loading">Loading posts...</div>';
    }
    
    // Build query parameters with filters
    const params = new URLSearchParams({
        action: 'get_posts',
        communityid: communityId,
        page: page,
        perpage: postsPerPage,
        sesskey: '<?php echo sesskey(); ?>'
    });
    
    // Add filters if they exist
    if (currentFilters) {
        if (currentFilters.spaceids && currentFilters.spaceids.length > 0) {
            params.append('spaceids[]', currentFilters.spaceids[0]); // Single space selection
            // When a specific space is selected, exclude community-level posts (spaceid = 0)
            params.append('exclude_community_posts', '1');
        }
        if (currentFilters.postedby) {
            params.append('postedby', currentFilters.postedby);
        }
        if (currentFilters.cohorts && currentFilters.cohorts.length > 0) {
            params.append('cohorts[]', currentFilters.cohorts[0]); // Single cohort selection
        }
        if (currentFilters.fromdate) {
            params.append('fromdate', currentFilters.fromdate);
        }
        if (currentFilters.todate) {
            params.append('todate', currentFilters.todate);
        }
        if (currentFilters.sortby) {
            params.append('sortby', currentFilters.sortby);
        }
        if (currentFilters.likedonly) {
            params.append('likedonly', '1');
        }
        if (currentFilters.savedonly) {
            params.append('savedonly', '1');
        }
    }
    
    return fetchCommunityHubJSON(`<?php echo $CFG->wwwroot; ?>/local/communityhub/ajax.php?${params.toString()}`)
        .then(data => {
            isLoadingPosts = false;
            if (data.success && data.data) {
                const posts = data.data.posts || [];
                const pagination = data.data.pagination || {};
                
                totalPosts = pagination.total || 0;
                currentPostPage = page;
                
                if (isInitial && posts.length === 0) {
                    postsFeedEl.innerHTML = '<div class="empty-state"><div class="empty-state-icon"><i class="fa-solid fa-comments"></i></div><p class="empty-state-text">No posts yet. Be the first to share!</p></div>';
                    return Promise.resolve();
                }
                
                if (posts.length > 0) {
                    renderPosts(posts, isInitial);
                    updateLoadMoreButton(pagination.hasnext);
                } else {
                    updateLoadMoreButton(false);
                }
            } else {
                if (isInitial) {
                    postsFeedEl.innerHTML = '<div class="empty-state"><div class="empty-state-icon"><i class="fa-solid fa-exclamation-triangle"></i></div><p class="empty-state-text">Error loading posts</p></div>';
                }
                updateLoadMoreButton(false);
            }
            return data;
        })
        .catch(error => {
            isLoadingPosts = false;
            console.error('Error loading posts:', error);
            if (isInitial) {
                postsFeedEl.innerHTML = '<div class="empty-state"><div class="empty-state-icon"><i class="fa-solid fa-exclamation-triangle"></i></div><p class="empty-state-text">Error loading posts</p></div>';
            }
            updateLoadMoreButton(false);
            throw error;
        });
}

// Load more posts
function loadMorePosts() {
    if (isLoadingPosts) return;
    const nextPage = currentPostPage + 1;
    loadPosts(currentCommunityId, nextPage, false);
}

// Update Load More button visibility
function updateLoadMoreButton(hasMore) {
    let loadMoreBtn = document.getElementById('loadMorePostsBtn');
    if (!loadMoreBtn) {
        const postsFeedEl = document.getElementById('postsFeed');
        if (postsFeedEl && hasMore) {
            loadMoreBtn = document.createElement('button');
            loadMoreBtn.id = 'loadMorePostsBtn';
            loadMoreBtn.className = 'btn btn-secondary secondary-button';
            loadMoreBtn.style.cssText = 'width: 100%; margin-top: 16px; padding: 12px;';
            loadMoreBtn.innerHTML = '<i class="fa-solid fa-arrow-down" style="margin-right: 6px;"></i>Load More';
            loadMoreBtn.onclick = loadMorePosts;
            postsFeedEl.appendChild(loadMoreBtn);
        }
    } else {
        loadMoreBtn.style.display = hasMore ? 'block' : 'none';
    }
}

// Render posts
function renderPosts(posts, replace = false) {
    // Store posts data for quick moderation
    if (replace) {
        currentPostsData = posts;
    } else {
        currentPostsData = [...currentPostsData, ...posts];
    }
    const postsFeedEl = document.getElementById('postsFeed');
    
    // Remove loading state and empty state if replacing
    if (replace) {
        postsFeedEl.innerHTML = '';
    }
    
    // Remove Load More button temporarily
    const loadMoreBtn = document.getElementById('loadMorePostsBtn');
    if (loadMoreBtn) {
        loadMoreBtn.remove();
    }
    
    let html = '';
    posts.forEach(post => {
        const timeAgo = formatTimeAgo(post.timecreated);
        const spaceBadge = post.spacename ? `<span class="post-space-link" onclick="filterBySpace(${post.spaceid})">${escapeHtml(post.spacename)}</span>` : '';
        const likedClass = post.liked ? ' liked' : '';
        const savedClass = post.saved ? ' saved' : '';
        const isAuthor = parseInt(post.userid) === parseInt(currentUserId);
        const isEdited = post.timemodified && parseInt(post.timemodified) > parseInt(post.timecreated);
        const editedIndicator = isEdited ? ' <span class="post-edited-indicator" title="Edited"><i class="fa-solid fa-pen" style="font-size: 0.7em;"></i> edited</span>' : '';
        
        let mediaGridItems = '';
        let documentItems = '';
        if (post.media && post.media.length > 0) {
            post.media.forEach(media => {
                const fileUrl = media.fileurl || media.downloadurl || '';
                if (media.filetype === 'image') {
                    const safeUrl = fileUrl.replace(/'/g, "\\'").replace(/"/g, '\\"');
                    const safeFilename = escapeHtml(media.filename).replace(/'/g, "\\'").replace(/"/g, '\\"');
                    mediaGridItems += `<div class="post-media-item" onclick="event.stopPropagation(); openImageModal('${safeUrl}', '${safeFilename}')"><img src="${fileUrl}" alt="${escapeHtml(media.filename)}"></div>`;
                } else if (media.filetype === 'video') {
                    mediaGridItems += `<div class="post-media-item"><video controls><source src="${fileUrl}"></video></div>`;
                } else {
                    const iconClass = getDocumentIconClass(media.filetype, media.filename);
                    const label = getDocumentLabel(media.filetype, media.filename);
                    const sizeLabel = media.filesize ? ` • ${formatFileSize(media.filesize)}` : '';
                    documentItems += `
                        <div class="post-doc-item">
                            <div class="post-doc-icon"><i class="${iconClass}"></i></div>
                            <div class="post-doc-info">
                                <p>${escapeHtml(media.filename)}</p>
                                <span>${label}${sizeLabel}</span>
                            </div>
                            <div class="post-doc-actions">
                                <a href="${fileUrl}" target="_blank" rel="noopener">
                                    <i class="fa-solid fa-arrow-up-right-from-square"></i> View
                                </a>
                                <a href="${fileUrl}" download>
                                    <i class="fa-solid fa-download"></i> Download
                                </a>
                            </div>
                        </div>
                    `;
                }
            });
        }
        let mediaHtml = '';
        if (mediaGridItems) {
            mediaHtml += `<div class="post-media-grid">${mediaGridItems}</div>`;
        }
        if (documentItems) {
            mediaHtml += `<div class="post-document-list">${documentItems}</div>`;
        }
        
        html += `
            <div class="post-card" id="post-${post.id}" onclick="openPostDetail(${post.id}, event)">
                <div class="post-header">
                    <div class="post-author">
                        <img src="<?php echo $CFG->wwwroot; ?>/user/pix.php/${post.userid}/f1.jpg" alt="User" class="user-avatar" onerror="this.src='<?php echo $CFG->wwwroot; ?>/pix/u/f1.png'">
                        <div>
                            <h4 class="post-author-info">
                                ${escapeHtml(post.authorname)}
                            </h4>
                            <p class="post-meta">Posted in ${spaceBadge || 'Community'} • ${timeAgo}${editedIndicator}</p>
                        </div>
                    </div>
                    <div style="position: relative;">
                        <button class="post-menu-btn" onclick="event.stopPropagation(); togglePostMenu(${post.id})" id="postMenuBtn-${post.id}">
                            <i class="fa-solid fa-ellipsis"></i>
                        </button>
                        <div class="post-menu-dropdown" id="postMenu-${post.id}" style="display: none;">
                            ${(isAuthor || canModerate) ? `
                            <button class="post-menu-item" onclick="event.stopPropagation(); openEditPostModal(${post.id})">
                                <i class="fa-solid fa-pen"></i> Edit Post
                            </button>
                            <button class="post-menu-item" onclick="event.stopPropagation(); deletePost(${post.id})" style="color: #ef4444;">
                                <i class="fa-solid fa-trash"></i> Delete Post
                            </button>
                            ` : ''}
                            ${!isAuthor ? `
                            <button class="post-menu-item" onclick="event.stopPropagation(); reportPost(${post.id})" style="color: #f59e0b;">
                                <i class="fa-solid fa-flag"></i> Report Post
                            </button>
                            ` : ''}
                        </div>
                    </div>
                </div>
                <div class="post-content">
                    ${post.subject ? `<h3 class="post-title">${escapeHtml(post.subject)}${post.flagged && post.flag_status !== 'approved' ? ' <i class="fa-solid fa-shield-halved" style="color: #dc2626; margin-left: 8px;" title="This post has been flagged for review"></i>' : ''}</h3>` : ''}
                    ${!post.subject && post.flagged && post.flag_status !== 'approved' ? '<div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;"><i class="fa-solid fa-shield-halved" style="color: #dc2626;"></i><span style="color: #dc2626; font-weight: 600;">Flagged for Review</span></div>' : ''}
                    <div class="post-message">${post.message}</div>
                    ${mediaHtml}
                    ${post.flagged && post.flag_status !== 'approved' ? `
                    <div style="margin-top: 12px; padding: 12px; background: #fef2f2; border-left: 4px solid #dc2626; border-radius: 6px;">
                        <div style="display: flex; align-items: start; gap: 8px;">
                            <i class="fa-solid fa-triangle-exclamation" style="color: #dc2626; margin-top: 2px;"></i>
                            <div style="flex: 1;">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                                    <p style="margin: 0; color: #991b1b; font-weight: 600;">Content Flagged</p>
                                    ${canModerate ? `
                                    <button onclick="event.stopPropagation(); openQuickModeration(${post.id})" style="background: #dc2626; color: white; border: none; padding: 6px 12px; border-radius: 6px; cursor: pointer; display: flex; align-items: center; gap: 6px; font-size: 0.875rem; font-weight: 600;" title="Moderate Post">
                                        <i class="fa-solid fa-shield-halved"></i> Moderate
                                    </button>
                                    ` : ''}
                                </div>
                                <p style="margin: 0 0 8px 0; color: #7f1d1d; font-size: 0.875rem;"><strong>Flag Reason:</strong> ${escapeHtml(post.flag_reason && post.flag_reason.trim() ? post.flag_reason : 'This post has been flagged for containing inappropriate content.')}</p>
                                ${post.report_count > 0 ? `<p style="margin: 0 0 8px 0; color: #7f1d1d; font-size: 0.875rem;"><i class="fa-solid fa-flag"></i> Reported by ${post.report_count} user${post.report_count !== 1 ? 's' : ''}</p>` : ''}
                                ${isAuthor ? '<p style="margin: 0 0 8px 0; color: #991b1b; font-size: 0.875rem; font-weight: 600;"><i class="fa-solid fa-exclamation-circle"></i> Warning: Repeated violations may result in being banned from this community.</p>' : ''}
                            </div>
                        </div>
                    </div>
                    ` : ''}
                </div>
                <div class="post-actions" onclick="event.stopPropagation();">
                    <div class="post-action-group">
                        <button class="post-action-btn${likedClass}" onclick="toggleLike(${post.id}, 0)" id="likeBtn-${post.id}">
                            <i class="fa-regular fa-thumbs-up"></i> <span id="likeCount-${post.id}">${post.likecount || 0}</span> Likes
                        </button>
                        <button class="post-action-btn" onclick="toggleReply(${post.id})">
                            <i class="fa-regular fa-comment"></i> <span id="replyCount-${post.id}">${post.replycount || 0}</span> Comments
                        </button>
                    </div>
                    <button class="post-action-btn${savedClass}" onclick="toggleSavePost(${post.id})" id="saveBtn-${post.id}">
                        <i class="${post.saved ? 'fa-solid' : 'fa-regular'} fa-bookmark"></i> ${post.saved ? 'Saved' : 'Save'}
                    </button>
                </div>
                <div class="comments-section" id="comments-${post.id}" style="display: none;" onclick="event.stopPropagation();">
                    <div id="replies-${post.id}"></div>
                    <div class="comment-input">
                        <?php 
                        $userpictureurl = $CFG->wwwroot . '/user/pix.php/' . $USER->id . '/f1.jpg';
                        ?>
                        <img src="<?php echo $userpictureurl; ?>" alt="You" class="user-avatar" style="width: 32px; height: 32px;" onerror="this.src='<?php echo $CFG->wwwroot; ?>/pix/u/f1.png'">
                        <input type="text" class="comment-input-field" placeholder="Add a comment..." onkeypress="if(event.key==='Enter') createReply(${post.id}, this.value)">
                    </div>
                </div>
            </div>
        `;
    });
    
    // Append or replace HTML
    if (replace) {
        postsFeedEl.innerHTML = html;
    } else {
        const tempDiv = document.createElement('div');
        tempDiv.innerHTML = html;
        while (tempDiv.firstChild) {
            postsFeedEl.appendChild(tempDiv.firstChild);
        }
    }
}

// Render resources
// Render resources (show only first 5 on main page)
function renderResources(resources) {
    const resourcesListEl = document.getElementById('resourcesList');
    
    if (resources.length === 0) {
        resourcesListEl.innerHTML = '<div class="empty-state"><p class="empty-state-text">No resources yet</p></div>';
        return;
    }
    
    // Show only first 5 resources on main page
    const resourcesToShow = resources.slice(0, 5);
    
    let html = '';
    resourcesToShow.forEach(resource => {
        const iconClass = getFileIcon(resource.filetype);
        const fileSize = formatFileSize(resource.filesize);
        const typeLabel = (resource.filetype || '').toUpperCase();
        const cleanDescription = stripHtml(resource.description || '');
        const createdLabel = resource.timecreated ? formatDate(resource.timecreated) : '';
        
        html += `
            <div class="resource-item" onclick="downloadResource(${resource.id})">
                <div class="resource-icon" style="background: #dbeafe;">
                    <i class="${iconClass}" style="color: #1e40af;"></i>
                </div>
                <div class="resource-info">
                    <h4 class="resource-title">${escapeHtml(resource.title)}</h4>
                    <p class="resource-meta">${typeLabel} • ${fileSize} • <span id="resource-download-${resource.id}">${resource.downloadcount}</span> downloads</p>
                    ${cleanDescription ? `<p class="resource-meta">${escapeHtml(cleanDescription)}</p>` : ''}
                    <p class="resource-meta">Shared by ${escapeHtml(resource.creatorsname)} ${createdLabel ? `• ${createdLabel}` : ''}</p>
                </div>
                <button class="resource-download-btn" onclick="event.stopPropagation(); downloadResource(${resource.id})">
                    <i class="fa-solid fa-download"></i>
                </button>
            </div>
        `;
    });
    
    resourcesListEl.innerHTML = html;
}

// Update stats
function updateStats(data) {
    // This would be calculated from actual data
    document.getElementById('statActiveMembers').textContent = data.members?.length || 0;
    document.getElementById('statPosts').textContent = data.totalposts || 0;
    document.getElementById('statSpaces').textContent = data.spaces?.length || 0;
    document.getElementById('statEngagement').textContent = '0%'; // Placeholder
}

function loadInsights(communityId) {
    if (!communityId) {
        return;
    }

    fetchCommunityHubJSON(`<?php echo $CFG->wwwroot; ?>/local/communityhub/ajax.php?action=get_insights&communityid=${communityId}&sesskey=<?php echo sesskey(); ?>`)
        .then(data => {
            if (data.success && data.data) {
                updateInsights(data.data);
            }
        })
        .catch(error => console.error('Error loading insights:', error));
}

function updateInsights(insights) {
    document.getElementById('statActiveMembers').textContent = insights.activemembers ?? 0;
    document.getElementById('statPosts').textContent = insights.poststhismonth ?? 0;
    document.getElementById('statSpaces').textContent = insights.activespaces ?? 0;
    document.getElementById('statEngagement').textContent = `${insights.engagementrate ?? 0}%`;
    renderEngagementChart(insights.dayactivity || [], insights.peaktime || 'No activity yet');
}

function renderEngagementChart(dayActivity, peakTime) {
    const dayNames = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    const barsContainer = document.querySelector('.engagement-bars');
    const peakText = document.querySelector('.engagement-peak');

    if (!barsContainer) {
        return;
    }

    barsContainer.innerHTML = '';
    const values = dayActivity.length ? dayActivity : new Array(7).fill(0);

    values.forEach((percentage, index) => {
        const bar = document.createElement('div');
        bar.className = 'engagement-bar';
        bar.innerHTML = `
            <span class="engagement-day">${dayNames[index]}</span>
            <div class="engagement-bar-track">
                <div class="engagement-bar-fill" style="height:${percentage}%"></div>
            </div>
        `;
        barsContainer.appendChild(bar);
    });

    if (peakText) {
        peakText.textContent = peakTime ? `Peak activity: ${peakTime}` : 'Peak activity: No data yet';
    }
}

// Create post
function createPost() {
    const message = document.getElementById('createPostInput').value.trim();
    if (!message) {
        RemuiAlert.warning('Please enter a message');
        return;
    }
    
    if (!currentCommunityId) {
        RemuiAlert.warning('Please select a community first');
        return;
    }
    
    // Get Post button and disable it, show loader
    const postBtn = document.querySelector('.create-post-submit');
    const originalBtnText = postBtn ? postBtn.innerHTML : '';
    if (postBtn) {
        postBtn.disabled = true;
        postBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Posting...';
        postBtn.style.cursor = 'not-allowed';
        postBtn.style.opacity = '0.7';
    }
    
    const spaceId = selectedSpaceId || 0;
    const subject = ''; // Can be added later
    
    const formData = new FormData();
    formData.append('action', 'create_post');
    formData.append('sesskey', '<?php echo sesskey(); ?>');
    formData.append('communityid', currentCommunityId);
    formData.append('spaceid', spaceId);
    formData.append('subject', subject);
    formData.append('message', message);
    
    // Add media files if any
    if (selectedMediaFiles.length > 0) {
        // Double-check file sizes before upload (safety check)
        const maxSizeBytes = 30 * 1024 * 1024; // 30MB
        const oversizedFiles = selectedMediaFiles.filter(file => file.size > maxSizeBytes);
        if (oversizedFiles.length > 0) {
            RemuiAlert.error('Some files exceed the 30MB limit. Please remove them and try again.', 'File Too Large');
            return;
        }
        selectedMediaFiles.forEach(file => {
            formData.append('media[]', file);
        });
    }
    
    fetch('<?php echo $CFG->wwwroot; ?>/local/communityhub/ajax.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        // Re-enable button
        if (postBtn) {
            postBtn.disabled = false;
            postBtn.innerHTML = originalBtnText;
            postBtn.style.cursor = 'pointer';
            postBtn.style.opacity = '1';
        }
        
        if (data.success) {
            const postId = data.data.id;
            
            // Clear input
            document.getElementById('createPostInput').value = '';
            selectedMediaFiles = [];
            document.getElementById('selectedMediaPreview').style.display = 'none';
            
            // Reload posts from beginning (new post will appear at top)
            currentPostPage = -1;
            loadPosts(currentCommunityId, 0, true).then(() => {
                // After posts are loaded, trigger async moderation check
                checkPostModerationAsync(postId);
            });
        } else {
            alert('Error: ' + (data.error || 'Failed to create post'));
        }
    })
    .catch(error => {
        // Re-enable button on error
        if (postBtn) {
            postBtn.disabled = false;
            postBtn.innerHTML = originalBtnText;
            postBtn.style.cursor = 'pointer';
            postBtn.style.opacity = '1';
        }
        console.error('Error creating post:', error);
        alert('Failed to create post. Please try again.');
    });
}

// Async moderation check (runs in background after post is created)
function checkPostModerationAsync(postId) {
    // Wait a moment for post to be fully rendered
    setTimeout(() => {
        fetch('<?php echo $CFG->wwwroot; ?>/local/communityhub/ajax.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=check_post_moderation&postid=${postId}&sesskey=<?php echo sesskey(); ?>`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success && data.data.flagged) {
                // Post was flagged, refresh to show flag status
                loadPosts(currentCommunityId, currentPostPage, true);
            }
        })
        .catch(error => {
            // Silently fail - moderation check shouldn't break the UX
            console.error('Moderation check failed:', error);
        });
    }, 500); // Small delay to ensure post is rendered
}

// Toggle like
function toggleLike(postId, replyId) {
    fetch('<?php echo $CFG->wwwroot; ?>/local/communityhub/ajax.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=toggle_like&postid=${postId}&replyid=${replyId}&sesskey=<?php echo sesskey(); ?>`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            if (replyId && replyId > 0) {
                updateReplyLikeUI(replyId, data.data.liked, data.data.likecount);
            } else {
                const btn = document.getElementById(`likeBtn-${postId}`);
                const countEl = document.getElementById(`likeCount-${postId}`);
                
                if (btn && countEl) {
                    if (data.data.liked) {
                        btn.classList.add('liked');
                    } else {
                        btn.classList.remove('liked');
                    }
                    countEl.textContent = data.data.likecount;
                }
                
                // Update modal if open
                const detailBtn = document.getElementById(`detailLikeBtn-${postId}`);
                const detailCountEl = document.getElementById(`detailLikeCount-${postId}`);
                if (detailBtn && detailCountEl) {
                    if (data.data.liked) {
                        detailBtn.classList.add('liked');
                    } else {
                        detailBtn.classList.remove('liked');
                    }
                    detailCountEl.textContent = data.data.likecount;
                }
            }
        }
    });
}

function updateReplyLikeUI(replyId, liked, likeCount) {
    const links = document.querySelectorAll(`[data-reply-like="${replyId}"]`);
    links.forEach(link => {
        if (!link) {
            return;
        }
        link.classList.toggle('liked', liked);
        const label = link.querySelector('.comment-like-count');
        if (label) {
            label.textContent = formatLikeLabel(likeCount);
        }
    });
}

// Delete post
function deletePost(postId) {
    RemuiAlert.confirm(
        'Delete Post',
        'Are you sure you want to delete this post? This action cannot be undone.',
        () => {
            // Continue with deletion
            deletePostConfirmed(postId);
        }
    );
}

function deletePostConfirmed(postId) {

    fetch('<?php echo $CFG->wwwroot; ?>/local/communityhub/ajax.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=delete_post&postid=${postId}&sesskey=<?php echo sesskey(); ?>`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Remove post from feed
            const postCard = document.getElementById(`post-${postId}`);
            if (postCard) {
                postCard.style.transition = 'opacity 0.3s';
                postCard.style.opacity = '0';
                setTimeout(() => {
                    postCard.remove();
                    // Reload posts if we're on the first page
                    if (currentPostPage === 0) {
                        loadPosts(currentCommunityId, 0, true);
                    }
                }, 300);
            }
            // Close modal if open
            const modal = document.getElementById('postDetailModal');
            if (modal && modal.style.display !== 'none') {
                closeModal('postDetailModal');
            }
        } else {
            RemuiAlert.error(data.error || 'Failed to delete post');
        }
    })
    .catch(error => {
        console.error('Error deleting post:', error);
        RemuiAlert.error('Failed to delete post. Please try again.');
    });
}

// Report post - opens custom modal
let currentReportPostId = null;

function reportPost(postId) {
    currentReportPostId = postId;
    // Clear previous reason
    const reasonInput = document.getElementById('reportPostReason');
    if (reasonInput) {
        reasonInput.value = '';
    }
    // Close the post menu
    const menu = document.getElementById(`postMenu-${postId}`);
    if (menu) {
        menu.style.display = 'none';
    }
    // Open the report modal
    openModal('reportPostModal');
    // Focus on the textarea
    setTimeout(() => {
        if (reasonInput) {
            reasonInput.focus();
        }
    }, 100);
}

// Submit report post
function submitReportPost() {
    if (!currentReportPostId) {
        return;
    }
    
    const reasonInput = document.getElementById('reportPostReason');
    const reason = reasonInput ? reasonInput.value.trim() : '';
    
    // Get submit button and disable it, show loader
    const submitBtn = document.querySelector('#reportPostModal .btn-primary');
    const originalBtnText = submitBtn ? submitBtn.innerHTML : '';
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Submitting...';
        submitBtn.style.cursor = 'not-allowed';
        submitBtn.style.opacity = '0.7';
    }
    
    fetch('<?php echo $CFG->wwwroot; ?>/local/communityhub/ajax.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=report_post&postid=${currentReportPostId}&reason=${encodeURIComponent(reason || '')}&sesskey=<?php echo sesskey(); ?>`
    })
    .then(response => response.json())
    .then(data => {
        // Re-enable button
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalBtnText;
            submitBtn.style.cursor = 'pointer';
            submitBtn.style.opacity = '1';
        }
        
        if (data.success) {
            // Close modal
            closeModal('reportPostModal');
            // Show success message
            RemuiAlert.success('Thank you for reporting this post. Our moderators will review it.');
            currentReportPostId = null;
        } else {
            if (data.error && data.error.includes('already')) {
                RemuiAlert.info('You have already reported this post.');
                closeModal('reportPostModal');
                currentReportPostId = null;
            } else {
                RemuiAlert.error(data.error || 'Failed to report post');
            }
        }
    })
    .catch(error => {
        // Re-enable button on error
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalBtnText;
            submitBtn.style.cursor = 'pointer';
            submitBtn.style.opacity = '1';
        }
        console.error('Error reporting post:', error);
        RemuiAlert.error('Failed to report post. Please try again.');
    });
}

// Edit post - store the post data for editing
let currentEditPostId = null;
let currentEditPostFiles = [];

// Open the edit post modal
function openEditPostModal(postId) {
    // Hide any open post menus
    document.querySelectorAll('.post-menu-dropdown').forEach(menu => menu.style.display = 'none');
    
    currentEditPostId = postId;
    
    // Fetch post details and files
    Promise.all([
        fetch(`<?php echo $CFG->wwwroot; ?>/local/communityhub/ajax.php?action=get_post_detail&postid=${postId}&sesskey=<?php echo sesskey(); ?>`).then(r => r.json()),
        fetch(`<?php echo $CFG->wwwroot; ?>/local/communityhub/ajax.php?action=get_post_files&postid=${postId}&sesskey=<?php echo sesskey(); ?>`).then(r => r.json())
    ])
    .then(([postData, filesData]) => {
        if (!postData.success || !postData.data) {
            RemuiAlert.error('Failed to load post data');
            return;
        }
        
        const post = postData.data;
        currentEditPostFiles = filesData.success ? filesData.data : [];
        
        // Populate the modal
        document.getElementById('editPostId').value = postId;
        document.getElementById('editPostSubject').value = post.subject || '';
        
        // Strip HTML tags for textarea (get plain text from message)
        const tempDiv = document.createElement('div');
        tempDiv.innerHTML = post.message || '';
        document.getElementById('editPostMessage').value = tempDiv.textContent || tempDiv.innerText || '';
        
        // Show existing files
        renderEditPostFiles();
        
        // Clear new files
        document.getElementById('editPostNewFiles').value = '';
        document.getElementById('editPostNewFilesPreview').innerHTML = '';
        
        // Open modal
        openModal('editPostModal');
    })
    .catch(error => {
        console.error('Error loading post:', error);
        RemuiAlert.error('Failed to load post. Please try again.');
    });
}

// Render files in the edit modal
function renderEditPostFiles() {
    const container = document.getElementById('editPostFilesContainer');
    if (!currentEditPostFiles || currentEditPostFiles.length === 0) {
        container.innerHTML = '<p style="color: #64748b; font-size: 0.875rem;">No files attached</p>';
        return;
    }
    
    container.innerHTML = currentEditPostFiles.map(file => `
        <div class="edit-file-item" id="edit-file-${file.id}" style="display: flex; align-items: center; justify-content: space-between; padding: 8px 12px; background: #f1f5f9; border-radius: 6px; margin-bottom: 8px;">
            <div style="display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-file" style="color: #64748b;"></i>
                <span style="font-size: 0.875rem;">${escapeHtml(file.filename)}</span>
                <span style="font-size: 0.75rem; color: #94a3b8;">(${formatFileSize(file.filesize)})</span>
            </div>
            <button type="button" onclick="removeEditPostFile(${file.id})" style="background: none; border: none; color: #ef4444; cursor: pointer; padding: 4px;">
                <i class="fa-solid fa-times"></i>
            </button>
        </div>
    `).join('');
}

// Format file size
function formatFileSize(bytes) {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

// Validate file size before upload (30MB limit)
function validateFileSize(fileInput, maxSizeMB) {
    if (!fileInput || !fileInput.files) {
        return true;
    }
    
    const maxSizeBytes = maxSizeMB * 1024 * 1024; // Convert MB to bytes
    const files = Array.from(fileInput.files);
    const oversizedFiles = [];
    
    files.forEach(file => {
        if (file.size > maxSizeBytes) {
            oversizedFiles.push({
                name: file.name,
                size: formatFileSize(file.size),
                maxSize: maxSizeMB + 'MB'
            });
        }
    });
    
    if (oversizedFiles.length > 0) {
        // Clear the input
        fileInput.value = '';
        
        // Show error message
        let errorMsg = 'The following file(s) exceed the maximum size limit of ' + maxSizeMB + 'MB:\n\n';
        oversizedFiles.forEach(file => {
            errorMsg += `• ${file.name} (${file.size})\n`;
        });
        errorMsg += '\nPlease select smaller files.';
        
        RemuiAlert.error(errorMsg, 'File Too Large');
        return false;
    }
    
    return true;
}

// Remove a file from the post
function removeEditPostFile(fileId) {
    RemuiAlert.confirm(
        'Remove File',
        'Remove this file from the post?',
        () => {
            removePostFileConfirmed(fileId);
        }
    );
}

function removePostFileConfirmed(fileId) {
    fetch('<?php echo $CFG->wwwroot; ?>/local/communityhub/ajax.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=remove_post_file&postid=${currentEditPostId}&fileid=${fileId}&sesskey=<?php echo sesskey(); ?>`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Remove from local array and re-render
            currentEditPostFiles = currentEditPostFiles.filter(f => f.id !== fileId);
            renderEditPostFiles();
        } else {
            RemuiAlert.error(data.error || 'Failed to remove file');
        }
    })
    .catch(error => {
        console.error('Error removing file:', error);
        RemuiAlert.error('Failed to remove file. Please try again.');
    });
}

// Preview new files being added
function previewEditPostNewFiles() {
    const input = document.getElementById('editPostNewFiles');
    const preview = document.getElementById('editPostNewFilesPreview');
    preview.innerHTML = '';
    
    if (input.files && input.files.length > 0) {
        Array.from(input.files).forEach((file, index) => {
            preview.innerHTML += `
                <div style="display: flex; align-items: center; gap: 8px; padding: 8px 12px; background: #ecfdf5; border-radius: 6px; margin-bottom: 8px;">
                    <i class="fa-solid fa-plus" style="color: #10b981;"></i>
                    <span style="font-size: 0.875rem;">${escapeHtml(file.name)}</span>
                    <span style="font-size: 0.75rem; color: #94a3b8;">(${formatFileSize(file.size)})</span>
                </div>
            `;
        });
    }
}

// Save the edited post
function savePostEdit() {
    const postId = document.getElementById('editPostId').value;
    const subject = document.getElementById('editPostSubject').value.trim();
    const message = document.getElementById('editPostMessage').value.trim();
    const newFiles = document.getElementById('editPostNewFiles').files;
    
    if (!message) {
        RemuiAlert.warning('Post message cannot be empty');
        return;
    }
    
    // Validate file sizes before upload
    if (newFiles && newFiles.length > 0) {
        if (!validateFileSize(document.getElementById('editPostNewFiles'), 30)) {
            return;
        }
    }
    
    // Create form data for file upload support
    const formData = new FormData();
    formData.append('action', 'update_post');
    formData.append('postid', postId);
    formData.append('subject', subject);
    formData.append('message', message);
    formData.append('sesskey', '<?php echo sesskey(); ?>');
    
    // Add new files
    if (newFiles && newFiles.length > 0) {
        Array.from(newFiles).forEach(file => {
            formData.append('media[]', file);
        });
    }
    
    // Show loading state
    const saveBtn = document.querySelector('#editPostModal .modal-submit-btn');
    const originalText = saveBtn.textContent;
    saveBtn.textContent = 'Saving...';
    saveBtn.disabled = true;
    
    fetch('<?php echo $CFG->wwwroot; ?>/local/communityhub/ajax.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        saveBtn.textContent = originalText;
        saveBtn.disabled = false;
        
        if (data.success) {
            // Close modal and reload posts
            closeModal('editPostModal');
            loadPosts(currentCommunityId, 0, true);
            
            // Also refresh the detail modal if open
            const detailModal = document.getElementById('postDetailModal');
            if (detailModal && detailModal.style.display !== 'none') {
                openPostDetail(postId);
            }
        } else {
            // Check if it's a file size error
            if (data.error && (data.error.includes('too large') || data.error.includes('30MB') || data.error.includes('filetoobig'))) {
                RemuiAlert.error(data.error, 'File Too Large');
            } else {
                RemuiAlert.error(data.error || 'Failed to update post');
            }
        }
    })
    .catch(error => {
        saveBtn.textContent = originalText;
        saveBtn.disabled = false;
        console.error('Error updating post:', error);
        RemuiAlert.error('Failed to update post. Please try again.');
    });
}

// Toggle save post
function toggleSavePost(postId) {
    fetch('<?php echo $CFG->wwwroot; ?>/local/communityhub/ajax.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=toggle_save_post&postid=${postId}&sesskey=<?php echo sesskey(); ?>`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const btn = document.getElementById(`saveBtn-${postId}`);
            if (btn) {
                const icon = btn.querySelector('i');
                if (data.data.saved) {
                    btn.classList.add('saved');
                    btn.innerHTML = '<i class="fa-solid fa-bookmark"></i> Saved';
                } else {
                    btn.classList.remove('saved');
                    btn.innerHTML = '<i class="fa-regular fa-bookmark"></i> Save';
                }
            }
            // Update modal if open
            const detailBtn = document.getElementById(`detailSaveBtn-${postId}`);
            if (detailBtn) {
                const icon = detailBtn.querySelector('i');
                if (data.data.saved) {
                    detailBtn.classList.add('saved');
                    detailBtn.innerHTML = '<i class="fa-solid fa-bookmark"></i> Saved';
                } else {
                    detailBtn.classList.remove('saved');
                    detailBtn.innerHTML = '<i class="fa-regular fa-bookmark"></i> Save';
                }
            }
        }
    })
    .catch(error => {
        console.error('Error toggling save:', error);
    });
}

// Toggle post menu dropdown
function togglePostMenu(postId) {
    const menu = document.getElementById(`postMenu-${postId}`) || document.getElementById(`detailPostMenu-${postId}`);
    if (!menu) return;

    // Close all other menus
    document.querySelectorAll('.post-menu-dropdown').forEach(m => {
        if (m.id !== menu.id) {
            m.style.display = 'none';
        }
    });

    // Toggle current menu
    menu.style.display = menu.style.display === 'none' ? 'block' : 'none';
}

// Close menus when clicking outside
document.addEventListener('click', function(event) {
    if (!event.target.closest('.post-menu-btn') && !event.target.closest('.post-menu-dropdown')) {
        document.querySelectorAll('.post-menu-dropdown').forEach(menu => {
            menu.style.display = 'none';
        });
    }
});

// Toggle reply section
function toggleReply(postId) {
    const commentsSection = document.getElementById(`comments-${postId}`);
    commentsSection.style.display = commentsSection.style.display === 'none' ? 'block' : 'none';
    
    if (commentsSection.style.display === 'block') {
        loadReplies(postId);
    }
}

// Load replies
function loadReplies(postId) {
    fetch('<?php echo $CFG->wwwroot; ?>/local/communityhub/ajax.php?action=get_replies&postid=' + postId + '&sesskey=<?php echo sesskey(); ?>')
        .then(response => response.json())
        .then(data => {
            if (data.success && Array.isArray(data.data)) {
                renderReplies(postId, data.data);
            } else {
                renderReplies(postId, []);
            }
        });
}

// Render replies (with nested support)
function renderReplies(postId, replies) {
    const repliesEl = document.getElementById(`replies-${postId}`);
    if (!repliesEl) return;
    
    if (replies.length === 0) {
        repliesEl.innerHTML = '<p style="color: #6b7280; font-size: 0.875rem; padding: 12px;">No replies yet</p>';
        return;
    }
    
    let html = '';
    replies.forEach(reply => {
        const timeAgo = formatTimeAgo(reply.timecreated);
        const likedClass = reply.liked ? ' liked' : '';
        const likeCount = reply.likecount || 0;
        const nestedReplies = reply.replies || [];
        const totalNestedCount = reply.replycount || nestedReplies.length || 0;
        const likeLabel = formatLikeLabel(likeCount);
        const replyLabel = totalNestedCount > 0 ? `${totalNestedCount} repl${totalNestedCount === 1 ? 'y' : 'ies'}` : '';
        
        html += `
            <div class="comment-item" id="reply-${reply.id}">
                <img src="<?php echo $CFG->wwwroot; ?>/user/pix.php/${reply.userid}/f1.jpg" alt="User" class="user-avatar" style="width: 32px; height: 32px;" onerror="this.src='<?php echo $CFG->wwwroot; ?>/pix/u/f1.png'">
                <div class="comment-content" style="flex: 1;">
                    <h5 class="comment-author">${escapeHtml(reply.authorname)} <span style="font-size: 0.75rem; color: #6b7280; font-weight: normal;">${timeAgo}</span></h5>
                    <p class="comment-text">${reply.message}</p>
                    <div class="comment-actions" style="gap: 12px; flex-wrap: wrap;">
                        <a href="#" class="comment-action-link${likedClass}" data-reply-like="${reply.id}" onclick="toggleLike(0, ${reply.id}); return false;">
                            <i class="fa-regular fa-thumbs-up"></i> <span class="comment-like-count">${likeLabel}</span>
                        </a>
                        <a href="#" class="comment-action-link" onclick="toggleReplyToReply(${postId}, ${reply.id}); return false;">Reply</a>
                        ${replyLabel ? `<span style="font-size:0.75rem; color:#94a3b8;">${replyLabel}</span>` : ''}
                    </div>
                    ${totalNestedCount > 0 ? `<button class="comment-view-replies" type="button" onclick="openRepliesModal(${postId}, ${reply.id}); return false;"><i class="fa-regular fa-comments"></i> View replies (${totalNestedCount})</button>` : ''}
                    <div class="reply-to-reply-input" id="reply-input-${reply.id}" style="display: none; margin-top: 12px;">
                        <div style="display: flex; gap: 8px; align-items: center;">
                            <img src="<?php echo $CFG->wwwroot; ?>/user/pix.php/${currentUserId}/f1.jpg" alt="You" class="user-avatar" style="width: 28px; height: 28px;" onerror="this.src='<?php echo $CFG->wwwroot; ?>/pix/u/f1.png'">
                            <input type="text" class="comment-input-field" id="feed-nested-reply-input-${postId}-${reply.id}" placeholder="Write a reply..." style="flex: 1; font-size: 0.875rem;" onkeypress="if(event.key==='Enter') { submitNestedReply(${postId}, ${reply.id}, 'feed'); }">
                            <button class="comment-submit-btn small" type="button" onclick="submitNestedReply(${postId}, ${reply.id}, 'feed'); return false;">Submit</button>
                        </div>
                    </div>
                </div>
            </div>
        `;
    });
    
    repliesEl.innerHTML = html;
}

function buildDetailReplyHtml(reply, postId, isNested = false) {
    const timeAgo = formatTimeAgo(reply.timecreated);
    const likedClass = reply.liked ? ' liked' : '';
    const likeCount = reply.likecount || 0;
    const likeLabel = formatLikeLabel(likeCount);
    const childReplies = reply.replies || [];
    const nestedClass = isNested ? ' nested' : '';
    const viewBtnId = `detail-view-btn-${reply.id}`;
    const childContainerId = `detail-child-container-${reply.id}`;
    const inputId = `detail-nested-reply-input-${postId}-${reply.id}`;
    const replyLabel = childReplies.length > 0 ? `${childReplies.length} repl${childReplies.length === 1 ? 'y' : 'ies'}` : '';
    let childrenHtml = '';

    if (childReplies.length > 0) {
        childrenHtml += `
            <button class="comment-view-replies" id="${viewBtnId}" data-count="${childReplies.length}" type="button" onclick="toggleNestedReplies('detail', ${reply.id}); return false;">
                <i class="fa-regular fa-comments"></i> View replies (${childReplies.length})
            </button>
            <div class="nested-replies" id="${childContainerId}" style="display: none;">
                ${childReplies.map(child => buildDetailReplyHtml(child, postId, true)).join('')}
            </div>
        `;
    }

    return `
        <div class="comment-item${nestedClass}" id="detail-reply-${reply.id}">
            <img src="<?php echo $CFG->wwwroot; ?>/user/pix.php/${reply.userid}/f1.jpg" alt="User" class="user-avatar" style="width: 32px; height: 32px;" onerror="this.src='<?php echo $CFG->wwwroot; ?>/pix/u/f1.png'">
            <div class="comment-content" style="flex: 1;">
                <h5 class="comment-author">${escapeHtml(reply.authorname)} <span style="font-size: 0.75rem; color: #6b7280; font-weight: normal;">${timeAgo}</span></h5>
                <p class="comment-text">${reply.message}</p>
                <div class="comment-actions" style="gap: 12px; flex-wrap: wrap;">
                    <a href="#" class="comment-action-link${likedClass}" data-reply-like="${reply.id}" onclick="toggleLike(0, ${reply.id}); return false;">
                        <i class="fa-regular fa-thumbs-up"></i> <span class="comment-like-count">${likeLabel}</span>
                    </a>
                    <a href="#" class="comment-action-link" onclick="toggleReplyToReply(${postId}, ${reply.id}); return false;">Reply</a>
                    ${replyLabel ? `<span style="font-size:0.75rem; color:#94a3b8;">${replyLabel}</span>` : ''}
                </div>
                ${childrenHtml}
                <div class="reply-to-reply-input" id="detail-reply-input-${reply.id}" style="display: none; margin-top: 12px;">
                    <div style="display: flex; gap: 8px; align-items: center;">
                        <img src="<?php echo $CFG->wwwroot; ?>/user/pix.php/${currentUserId}/f1.jpg" alt="You" class="user-avatar" style="width: 28px; height: 28px;" onerror="this.src='<?php echo $CFG->wwwroot; ?>/pix/u/f1.png'">
                        <input type="text" class="comment-input-field" id="${inputId}" placeholder="Write a reply..." style="flex: 1; font-size: 0.875rem;" onkeypress="if(event.key==='Enter') submitNestedReply(${postId}, ${reply.id}, 'detail');">
                        <button class="comment-submit-btn small" type="button" onclick="submitNestedReply(${postId}, ${reply.id}, 'detail'); return false;">Reply</button>
                    </div>
                </div>
            </div>
        </div>
    `;
}

function toggleNestedReplies(context, replyId) {
    const container = document.getElementById(`${context}-child-container-${replyId}`);
    if (!container) {
        return;
    }
    const isHidden = container.style.display === 'none' || container.style.display === '';
    container.style.display = isHidden ? 'block' : 'none';

    const btn = document.getElementById(`${context}-view-btn-${replyId}`);
    if (btn) {
        const count = btn.getAttribute('data-count');
        btn.innerHTML = isHidden
            ? '<i class="fa-regular fa-comments"></i> Hide replies'
            : `<i class="fa-regular fa-comments"></i> View replies (${count})`;
    }
}

// Create reply
function createReply(postId, message, parentReplyId = 0) {
    if (!message.trim()) return;
    
    const body = `action=create_reply&postid=${postId}&message=${encodeURIComponent(message)}&sesskey=<?php echo sesskey(); ?>`;
    const fullBody = parentReplyId > 0 ? `${body}&parent_replyid=${parentReplyId}` : body;
    
    fetch('<?php echo $CFG->wwwroot; ?>/local/communityhub/ajax.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: fullBody
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            loadReplies(postId);
            // Update reply count
            const countEl = document.getElementById(`replyCount-${postId}`);
            if (countEl) {
                countEl.textContent = parseInt(countEl.textContent) + 1;
            }
            // If post detail modal is open, reload it
            const modal = document.getElementById('postDetailModal');
            if (modal && modal.style.display !== 'none') {
                loadPostDetail(postId);
            }
            // Clear nested reply input if it was used
            if (parentReplyId > 0) {
                const feedInput = document.getElementById(`feed-nested-reply-input-${postId}-${parentReplyId}`);
                const detailInput = document.getElementById(`detail-nested-reply-input-${postId}-${parentReplyId}`);
                const input = detailInput || feedInput;
                
                if (input) {
                    input.value = '';
                }
                const replyInputDiv = document.getElementById(`detail-reply-input-${parentReplyId}`) || 
                                      document.getElementById(`reply-input-${parentReplyId}`);
                if (replyInputDiv) {
                    replyInputDiv.style.display = 'none';
                }
            }
        }
    });
}

function submitPostDetailComment(postId) {
    const input = document.getElementById('postDetailCommentInput');
    if (!input) {
        return;
    }
    const message = input.value.trim();
    if (!message) {
        return;
    }
    createReply(postId, message);
    input.value = '';
}

// Toggle reply to reply input
function toggleReplyToReply(postId, parentReplyId) {
    // Prefer modal input when it exists so clicking Reply inside modal doesn't open the feed input
    const detailReplyInputDiv = document.getElementById(`detail-reply-input-${parentReplyId}`);
    const replyInputDiv = document.getElementById(`reply-input-${parentReplyId}`);

    const inputDiv = detailReplyInputDiv || replyInputDiv;
    if (inputDiv) {
        const isVisible = inputDiv.style.display !== 'none';
        inputDiv.style.display = isVisible ? 'none' : 'block';
        if (!isVisible) {
            const input = document.getElementById(`detail-nested-reply-input-${postId}-${parentReplyId}`) ||
                          document.getElementById(`feed-nested-reply-input-${postId}-${parentReplyId}`);
            if (input) {
                input.focus();
            }
        }
    }
}

// Create nested reply
function createNestedReply(postId, parentReplyId, message) {
    if (!message.trim()) return;
    createReply(postId, message, parentReplyId);
}

function submitNestedReply(postId, parentReplyId, context = 'auto') {
    let input = null;
    if (context === 'detail') {
        input = document.getElementById(`detail-nested-reply-input-${postId}-${parentReplyId}`);
    } else if (context === 'feed') {
        input = document.getElementById(`feed-nested-reply-input-${postId}-${parentReplyId}`);
    } else {
        input = document.getElementById(`detail-nested-reply-input-${postId}-${parentReplyId}`) ||
                document.getElementById(`feed-nested-reply-input-${postId}-${parentReplyId}`);
    }
    if (!input) {
        return;
    }
    const message = input.value.trim();
    if (!message) {
        return;
    }
    createNestedReply(postId, parentReplyId, message);
    input.value = '';
}

// Open post detail modal
function openPostDetail(postId, event) {
    // Stop event propagation if clicked on action buttons
    if (event) {
        const target = event.target;
        if (target.closest('.post-actions') || target.closest('.post-menu-btn') || target.closest('.comments-section')) {
            return;
        }
    }
    
    openModal('postDetailModal');
    loadPostDetail(postId);
}

function openRepliesModal(postId, replyId = 0) {
    openPostDetail(postId);
}

// Load post detail with all replies
function loadPostDetail(postId) {
    const contentEl = document.getElementById('postDetailContent');
    if (!contentEl) return;
    
    // Show loading state
    contentEl.innerHTML = '<div style="text-align: center; padding: 40px;"><div class="spinner-border" role="status"><span class="sr-only">Loading...</span></div></div>';
    
    // Fetch post details and replies
    Promise.all([
        fetch('<?php echo $CFG->wwwroot; ?>/local/communityhub/ajax.php?action=get_post_detail&postid=' + postId + '&sesskey=<?php echo sesskey(); ?>').then(r => r.json()),
        fetch('<?php echo $CFG->wwwroot; ?>/local/communityhub/ajax.php?action=get_replies&postid=' + postId + '&sesskey=<?php echo sesskey(); ?>').then(r => r.json())
    ])
    .then(([postData, repliesData]) => {
        if (postData.success && repliesData.success) {
            renderPostDetail(postData.data, repliesData.data || []);
        } else {
            contentEl.innerHTML = '<div style="text-align: center; padding: 40px; color: #ef4444;">Error loading post details</div>';
        }
    })
    .catch(error => {
        contentEl.innerHTML = '<div style="text-align: center; padding: 40px; color: #ef4444;">Error loading post details</div>';
    });
}

// Render post detail in modal
function renderPostDetail(post, replies) {
    const contentEl = document.getElementById('postDetailContent');
    if (!contentEl) return;
    
    const timeAgo = formatTimeAgo(post.timecreated);
    const spaceBadge = post.spacename ? `<span class="post-space-link">${escapeHtml(post.spacename)}</span>` : '';
    const likedClass = post.liked ? ' liked' : '';
    const savedClass = post.saved ? ' saved' : '';
    const isAuthor = parseInt(post.userid) === parseInt(currentUserId);
    const isEdited = post.timemodified && parseInt(post.timemodified) > parseInt(post.timecreated);
    const editedIndicator = isEdited ? ' <span class="post-edited-indicator" title="Edited"><i class="fa-solid fa-pen" style="font-size: 0.7em;"></i> edited</span>' : '';
    
    // Build media HTML
    let mediaGridItems = '';
    let documentItems = '';
    if (post.media && post.media.length > 0) {
        post.media.forEach(media => {
            const fileUrl = media.fileurl || media.downloadurl || '';
            if (media.filetype === 'image') {
                const safeUrl = fileUrl.replace(/'/g, "\\'").replace(/"/g, '\\"');
                const safeFilename = escapeHtml(media.filename).replace(/'/g, "\\'").replace(/"/g, '\\"');
                mediaGridItems += `<div class="post-media-item" onclick="event.stopPropagation(); openImageModal('${safeUrl}', '${safeFilename}')"><img src="${fileUrl}" alt="${escapeHtml(media.filename)}"></div>`;
            } else if (media.filetype === 'video') {
                mediaGridItems += `<div class="post-media-item"><video controls><source src="${fileUrl}"></video></div>`;
            } else {
                const iconClass = getDocumentIconClass(media.filetype, media.filename);
                const label = getDocumentLabel(media.filetype, media.filename);
                const sizeLabel = media.filesize ? ` • ${formatFileSize(media.filesize)}` : '';
                documentItems += `
                    <div class="post-doc-item">
                        <div class="post-doc-icon"><i class="${iconClass}"></i></div>
                        <div class="post-doc-info">
                            <p>${escapeHtml(media.filename)}</p>
                            <span>${label}${sizeLabel}</span>
                        </div>
                        <div class="post-doc-actions">
                            <a href="${fileUrl}" target="_blank" rel="noopener">
                                <i class="fa-solid fa-arrow-up-right-from-square"></i> View
                            </a>
                            <a href="${fileUrl}" download>
                                <i class="fa-solid fa-download"></i> Download
                            </a>
                        </div>
                    </div>
                `;
            }
        });
    }
    let mediaHtml = '';
    if (mediaGridItems) {
        mediaHtml += `<div class="post-media-grid">${mediaGridItems}</div>`;
    }
    if (documentItems) {
        mediaHtml += `<div class="post-document-list">${documentItems}</div>`;
    }
    
    // Build replies HTML with nested support
    let repliesHtml = '';
    if (replies.length > 0) {
        repliesHtml = replies.map(reply => buildDetailReplyHtml(reply, post.id)).join('');
    } else {
        repliesHtml = '<p style="color: #6b7280; font-size: 0.875rem; padding: 12px;">No replies yet</p>';
    }
    
    <?php 
    $userpictureurl = $CFG->wwwroot . '/user/pix.php/' . $USER->id . '/f1.jpg';
    ?>
    
    contentEl.innerHTML = `
        <div class="post-detail-post">
            <div class="post-header">
                <div class="post-author">
                    <img src="<?php echo $CFG->wwwroot; ?>/user/pix.php/${post.userid}/f1.jpg" alt="User" class="user-avatar" onerror="this.src='<?php echo $CFG->wwwroot; ?>/pix/u/f1.png'">
                    <div>
                        <h4 class="post-author-info">
                            ${escapeHtml(post.authorname)}
                        </h4>
                        <p class="post-meta">Posted in ${spaceBadge || 'Community'} • ${timeAgo}${editedIndicator}</p>
                    </div>
                </div>
                ${(isAuthor || canModerate) ? `
                <div style="position: relative;">
                    <button class="post-menu-btn" onclick="event.stopPropagation(); togglePostMenu(${post.id})" id="detailPostMenuBtn-${post.id}">
                        <i class="fa-solid fa-ellipsis"></i>
                    </button>
                    <div class="post-menu-dropdown" id="detailPostMenu-${post.id}" style="display: none;">
                        <button class="post-menu-item" onclick="event.stopPropagation(); openEditPostModal(${post.id})">
                            <i class="fa-solid fa-pen"></i> Edit Post
                        </button>
                        <button class="post-menu-item" onclick="event.stopPropagation(); deletePost(${post.id})" style="color: #ef4444;">
                            <i class="fa-solid fa-trash"></i> Delete Post
                        </button>
                    </div>
                </div>
                ` : ''}
            </div>
            <div class="post-content">
                ${post.subject ? `<h3 class="post-title">${escapeHtml(post.subject)}${post.flagged && post.flag_status !== 'approved' ? ' <i class="fa-solid fa-shield-halved" style="color: #dc2626; margin-left: 8px;" title="This post has been flagged for review"></i>' : ''}</h3>` : ''}
                ${!post.subject && post.flagged && post.flag_status !== 'approved' ? '<div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;"><i class="fa-solid fa-shield-halved" style="color: #dc2626;"></i><span style="color: #dc2626; font-weight: 600;">Flagged for Review</span></div>' : ''}
                <div class="post-message">${post.message}</div>
                ${mediaHtml}
                ${post.flagged && post.flag_status !== 'approved' ? `
                <div style="margin-top: 12px; padding: 12px; background: #fef2f2; border-left: 4px solid #dc2626; border-radius: 6px;">
                    <div style="display: flex; align-items: start; gap: 8px;">
                        <i class="fa-solid fa-triangle-exclamation" style="color: #dc2626; margin-top: 2px;"></i>
                        <div style="flex: 1;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                                <p style="margin: 0; color: #991b1b; font-weight: 600;">Content Flagged</p>
                                ${canModerate ? `
                                <button onclick="event.stopPropagation(); openQuickModeration(${post.id})" style="background: #dc2626; color: white; border: none; padding: 6px 12px; border-radius: 6px; cursor: pointer; display: flex; align-items: center; gap: 6px; font-size: 0.875rem; font-weight: 600;" title="Moderate Post">
                                    <i class="fa-solid fa-shield-halved"></i> Moderate
                                </button>
                                ` : ''}
                            </div>
                            <p style="margin: 0 0 8px 0; color: #7f1d1d; font-size: 0.875rem;"><strong>Flag Reason:</strong> ${escapeHtml(post.flag_reason && post.flag_reason.trim() ? post.flag_reason : 'This post has been flagged for containing inappropriate content.')}</p>
                            ${post.report_count > 0 ? `<p style="margin: 0 0 8px 0; color: #7f1d1d; font-size: 0.875rem;"><i class="fa-solid fa-flag"></i> Reported by ${post.report_count} user${post.report_count !== 1 ? 's' : ''}</p>` : ''}
                            ${parseInt(post.userid) === parseInt(currentUserId) ? '<p style="margin: 0 0 8px 0; color: #991b1b; font-size: 0.875rem; font-weight: 600;"><i class="fa-solid fa-exclamation-circle"></i> Warning: Repeated violations may result in being banned from this community.</p>' : ''}
                        </div>
                    </div>
                </div>
                ` : ''}
            </div>
            <div class="post-actions">
                <div class="post-action-group">
                    <button class="post-action-btn${likedClass}" onclick="toggleLike(${post.id}, 0)" id="detailLikeBtn-${post.id}">
                        <i class="fa-regular fa-thumbs-up"></i> <span id="detailLikeCount-${post.id}">${post.likecount || 0}</span> Likes
                    </button>
                    <button class="post-action-btn">
                        <i class="fa-regular fa-comment"></i> <span>${replies.length}</span> Comments
                    </button>
                </div>
                <button class="post-action-btn${savedClass}" onclick="toggleSavePost(${post.id})" id="detailSaveBtn-${post.id}">
                    <i class="${post.saved ? 'fa-solid' : 'fa-regular'} fa-bookmark"></i> ${post.saved ? 'Saved' : 'Save'}
                </button>
            </div>
        </div>
        <div class="post-detail-replies">
            <h4>Comments (${replies.length})</h4>
            <div id="postDetailReplies">
                ${repliesHtml}
            </div>
            <div class="post-detail-comment-input">
                <div class="comment-input" style="display: flex; gap: 12px; align-items: center;">
                    <img src="<?php echo $userpictureurl; ?>" alt="You" class="user-avatar" style="width: 32px; height: 32px;" onerror="this.src='<?php echo $CFG->wwwroot; ?>/pix/u/f1.png'">
                    <input type="text" class="comment-input-field" id="postDetailCommentInput" placeholder="Add a comment..." onkeypress="if(event.key==='Enter') { submitPostDetailComment(${post.id}); }">
                    <button class="comment-submit-btn" type="button" onclick="submitPostDetailComment(${post.id}); return false;">Post</button>
                </div>
            </div>
        </div>
    `;
}

// Utility functions
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function truncateWords(text, limit) {
    if (!text) {
        return '';
    }
    const words = text.trim().split(/\s+/);
    if (words.length <= limit) {
        return text.trim();
    }
    return words.slice(0, limit).join(' ') + '...';
}

function stripHtml(text) {
    if (!text) {
        return '';
    }
    const div = document.createElement('div');
    div.innerHTML = text;
    return (div.textContent || div.innerText || '').trim();
}

function formatTimeAgo(timestamp) {
    const now = new Date();
    const postDate = new Date(timestamp * 1000);
    const seconds = Math.floor((now.getTime() - postDate.getTime()) / 1000);
    
    // Show relative time for today only
    if (seconds < 60) return 'just now';
    if (seconds < 3600) return Math.floor(seconds / 60) + ' minutes ago';
    if (seconds < 86400) {
        const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
        const postDay = new Date(postDate.getFullYear(), postDate.getMonth(), postDate.getDate());
        
        // If same day, show hours ago
        if (today.getTime() === postDay.getTime()) {
            return Math.floor(seconds / 3600) + ' hours ago';
        }
    }
    
    // For past days, show date
    const day = postDate.getDate();
    const month = postDate.toLocaleString('en-US', { month: 'short' });
    const year = postDate.getFullYear();
    const currentYear = now.getFullYear();
    
    // If same year, show "1 Jan" format
    if (year === currentYear) {
        return `${day} ${month}`;
    }
    
    // If previous year, show "31 Dec 2024" format
    return `${day} ${month} ${year}`;
}

function formatTime(timestamp) {
    const date = new Date(timestamp * 1000);
    return date.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
}

function formatDate(timestamp) {
    const date = new Date(timestamp * 1000);
    const day = date.getDate();
    const month = date.toLocaleString('en-US', { month: 'short' });
    const year = date.getFullYear();
    return `${day} ${month} ${year}`;
}

function formatFileSize(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / 1048576).toFixed(1) + ' MB';
}

function formatLikeLabel(count) {
    const safeCount = count || 0;
    return `${safeCount} ${safeCount === 1 ? 'Like' : 'Likes'}`;
}

function getFileIcon(filetype) {
    const icons = {
        'pdf': 'fa-regular fa-file-pdf',
        'excel': 'fa-regular fa-file-excel',
        'powerpoint': 'fa-regular fa-file-powerpoint',
        'document': 'fa-regular fa-file-lines'
    };
    return icons[filetype] || 'fa-regular fa-file';
}

function resolveDocumentType(filetype, filename) {
    const normalized = (filetype || '').toLowerCase();
    if (['pdf', 'excel', 'powerpoint', 'document'].includes(normalized)) {
        return normalized;
    }
    const extension = (filename || '').split('.').pop().toLowerCase();
    if (extension === 'pdf') return 'pdf';
    if (['ppt', 'pptx'].includes(extension)) return 'powerpoint';
    if (['xls', 'xlsx', 'csv'].includes(extension)) return 'excel';
    return 'document';
}

function getDocumentIconClass(filetype, filename) {
    const resolved = resolveDocumentType(filetype, filename);
    return getFileIcon(resolved);
}

function getDocumentLabel(filetype, filename) {
    const resolved = resolveDocumentType(filetype, filename);
    const labels = {
        'pdf': 'PDF Document',
        'excel': 'Spreadsheet',
        'powerpoint': 'Presentation',
        'document': 'Document'
    };
    if (labels[resolved]) {
        return labels[resolved];
    }
    const extension = (filename || '').split('.').pop();
    return extension ? `${extension.toUpperCase()} File` : 'File';
}

// Modal functions
function openCreateCommunityModal() {
    openModal('createCommunityModal');
    // Clear form
    document.getElementById('communityName').value = '';
    document.getElementById('communityDescription').value = '';
    document.getElementById('communityCoverImage').value = '';
}

function openCreatePostModal() {
    const quickInput = document.getElementById('createPostInput');
    const messageField = document.getElementById('postMessage');

    if (messageField) {
        const prefilledText = quickInput ? quickInput.value.trim() : '';
        messageField.value = prefilledText;
        setTimeout(() => {
            messageField.focus();
            const length = messageField.value.length;
            messageField.setSelectionRange(length, length);
        }, 0);
    }

    openModal('createPostModal');
}

// Modal stacking management
let openModals = [];
const BASE_Z_INDEX = 2000;
const Z_INDEX_INCREMENT = 100;

function getNextModalLayer() {
    return openModals.length;
}

function openModal(modalId) {
    const modal = document.getElementById(modalId);
    if (!modal) return;
    
    // Calculate z-index based on current open modals
    const layer = getNextModalLayer();
    const zIndex = BASE_Z_INDEX + (layer * Z_INDEX_INCREMENT);
    
    // Remove previous layer classes
    modal.classList.remove('modal-layer-1', 'modal-layer-2', 'modal-layer-3');
    
    // Add appropriate layer class if needed
    if (layer > 0) {
        modal.classList.add(`modal-layer-${Math.min(layer, 3)}`);
    }
    
    // Set z-index directly for layers beyond 3
    if (layer > 3) {
        modal.style.zIndex = zIndex;
    } else {
        modal.style.zIndex = '';
    }
    
    modal.style.display = 'block';
    openModals.push(modalId);
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (!modal) return;
    
    modal.style.display = 'none';
    
    // Remove from open modals array
    const index = openModals.indexOf(modalId);
    if (index > -1) {
        openModals.splice(index, 1);
    }
    
    // Reset z-index styling
    modal.style.zIndex = '';
    modal.classList.remove('modal-layer-1', 'modal-layer-2', 'modal-layer-3');
}

function submitCreateCommunity() {
    const nameInput = document.getElementById('communityName');
    const descriptionInput = document.getElementById('communityDescription');
    const coverimageInput = document.getElementById('communityCoverImage');
    
    if (!nameInput) {
        RemuiAlert.error('Community name field not found');
        return;
    }
    
    const name = nameInput.value.trim();
    const description = descriptionInput ? descriptionInput.value.trim() : '';
    const coverimage = coverimageInput ? coverimageInput.value.trim() : '';
    
    if (!name) {
        alert('Please enter a community name');
        return;
    }
    
    // Get the submit button from the modal
    const submitBtn = document.querySelector('#createCommunityModal .btn-primary');
    if (!submitBtn) {
        RemuiAlert.error('Submit button not found');
        return;
    }
    
    const originalText = submitBtn.textContent;
    submitBtn.disabled = true;
    submitBtn.textContent = 'Creating...';
    
    const formData = new FormData();
    formData.append('action', 'create');
    formData.append('sesskey', '<?php echo sesskey(); ?>');
    formData.append('name', name);
    formData.append('description', description);
    if (coverimage) {
        formData.append('coverimage', coverimage);
    }
    
    fetch('<?php echo $CFG->wwwroot; ?>/local/communityhub/ajax.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            closeModal('createCommunityModal');
            // Reload to show the new community
            window.location.reload();
        } else {
            alert('Error: ' + (data.error || 'Failed to create community'));
            submitBtn.disabled = false;
            submitBtn.textContent = originalText;
        }
    })
    .catch(error => {
        console.error('Error creating community:', error);
        RemuiAlert.error('Failed to create community. Please try again.');
        submitBtn.disabled = false;
        submitBtn.textContent = originalText;
    });
}

// Available icons for space picker
const spaceIconOptions = [
    { value: 'fa-solid fa-users', label: 'Users' },
    { value: 'fa-solid fa-graduation-cap', label: 'Graduation' },
    { value: 'fa-solid fa-book-open', label: 'Book' },
    { value: 'fa-solid fa-chalkboard-user', label: 'Teacher' },
    { value: 'fa-solid fa-flask', label: 'Science' },
    { value: 'fa-solid fa-calculator', label: 'Math' },
    { value: 'fa-solid fa-globe', label: 'Geography' },
    { value: 'fa-solid fa-palette', label: 'Art' },
    { value: 'fa-solid fa-music', label: 'Music' },
    { value: 'fa-solid fa-dumbbell', label: 'Sports' },
    { value: 'fa-solid fa-laptop-code', label: 'Computer' },
    { value: 'fa-solid fa-language', label: 'Language' },
    { value: 'fa-solid fa-heart', label: 'Health' },
    { value: 'fa-solid fa-star', label: 'Star' },
    { value: 'fa-solid fa-lightbulb', label: 'Ideas' },
    { value: 'fa-solid fa-comments', label: 'Chat' },
    { value: 'fa-solid fa-trophy', label: 'Trophy' },
    { value: 'fa-solid fa-puzzle-piece', label: 'Puzzle' },
    { value: 'fa-solid fa-rocket', label: 'Rocket' },
    { value: 'fa-solid fa-leaf', label: 'Nature' },
];

function openCreateSpaceModal() {
    if (!currentCommunityId) {
        alert('Please select a community first.');
        return;
    }
    document.getElementById('spaceNameInput').value = '';
    document.getElementById('spaceDescriptionInput').value = '';
    document.getElementById('spaceIconSelect').value = 'fa-solid fa-users';
    document.getElementById('spaceColorInput').value = '#2563eb';
    updateIconGrid('create');
    updateCreateSpacePreview();
    openModal('createSpaceModal');
}

// Build icon grid for picker
function updateIconGrid(mode) {
    const prefix = mode === 'create' ? '' : 'edit';
    const gridId = mode === 'create' ? 'createIconGrid' : 'editIconGrid';
    const inputId = mode === 'create' ? 'spaceIconSelect' : 'editSpaceIconSelect';
    const colorInputId = mode === 'create' ? 'spaceColorInput' : 'editSpaceColorInput';
    
    const grid = document.getElementById(gridId);
    const selectedIcon = document.getElementById(inputId).value;
    const color = document.getElementById(colorInputId).value;
    
    grid.innerHTML = spaceIconOptions.map(opt => {
        const isSelected = opt.value === selectedIcon;
        return `
            <div class="icon-picker-item ${isSelected ? 'selected' : ''}" 
                 onclick="selectSpaceIcon('${mode}', '${opt.value}')"
                 title="${opt.label}"
                 style="width: 40px; height: 40px; border-radius: 8px; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all 0.2s; background: ${isSelected ? color : '#e2e8f0'}; color: ${isSelected ? 'white' : '#64748b'}; border: 2px solid ${isSelected ? color : 'transparent'};">
                <i class="${opt.value}"></i>
            </div>
        `;
    }).join('');
}

// Select icon from grid
function selectSpaceIcon(mode, iconValue) {
    const inputId = mode === 'create' ? 'spaceIconSelect' : 'editSpaceIconSelect';
    document.getElementById(inputId).value = iconValue;
    updateIconGrid(mode);
    if (mode === 'create') {
        updateCreateSpacePreview();
    } else {
        updateEditSpacePreview();
    }
}

// Update Create Space Preview
function updateCreateSpacePreview() {
    const name = document.getElementById('spaceNameInput').value.trim() || 'Space Name';
    const description = document.getElementById('spaceDescriptionInput').value.trim();
    const icon = document.getElementById('spaceIconSelect').value;
    const color = document.getElementById('spaceColorInput').value;
    
    document.getElementById('createSpacePreviewName').textContent = name;
    document.getElementById('createSpacePreviewIcon').style.background = color;
    document.getElementById('createSpacePreviewIcon').innerHTML = `<i class="${icon}"></i>`;
    
    const descEl = document.getElementById('createSpacePreviewDesc');
    if (description) {
        descEl.textContent = description;
        descEl.style.display = 'block';
    } else {
        descEl.style.display = 'none';
    }
}

// Update Edit Space Preview
function updateEditSpacePreview() {
    const name = document.getElementById('editSpaceNameInput').value.trim() || 'Space Name';
    const description = document.getElementById('editSpaceDescriptionInput').value.trim();
    const icon = document.getElementById('editSpaceIconSelect').value;
    const color = document.getElementById('editSpaceColorInput').value;
    
    document.getElementById('editSpacePreviewName').textContent = name;
    document.getElementById('editSpacePreviewIcon').style.background = color;
    document.getElementById('editSpacePreviewIcon').innerHTML = `<i class="${icon}"></i>`;
    
    const descEl = document.getElementById('editSpacePreviewDesc');
    if (description) {
        descEl.textContent = description;
        descEl.style.display = 'block';
    } else {
        descEl.style.display = 'none';
    }
}

function submitCreateSpace() {
    const name = document.getElementById('spaceNameInput').value.trim();
    const description = document.getElementById('spaceDescriptionInput').value.trim();
    const icon = document.getElementById('spaceIconSelect').value;
    const color = document.getElementById('spaceColorInput').value;
    
    if (!name) {
        RemuiAlert.warning('Please enter a name for the space.');
        return;
    }
    
    const submitBtn = document.querySelector('#createSpaceModal .btn.btn-primary');
    submitBtn.disabled = true;
    submitBtn.textContent = 'Creating...';
    
    fetch('<?php echo $CFG->wwwroot; ?>/local/communityhub/ajax.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=create_space&communityid=${currentCommunityId}&name=${encodeURIComponent(name)}&description=${encodeURIComponent(description)}&icon=${encodeURIComponent(icon)}&color=${encodeURIComponent(color)}&sesskey=<?php echo sesskey(); ?>`
    })
    .then(response => response.json())
    .then(data => {
        submitBtn.disabled = false;
        submitBtn.textContent = 'Create Space';
        if (data.success) {
            closeModal('createSpaceModal');
            loadCommunityDetail(currentCommunityId);
        } else {
            RemuiAlert.error(data.error || 'Failed to create space');
        }
    })
    .catch(() => {
        submitBtn.disabled = false;
        submitBtn.textContent = 'Create Space';
        RemuiAlert.error('Failed to create space. Please try again.');
    });
}

// Edit Space Functions
let currentEditSpaceId = null;

function openEditSpaceModal(spaceId) {
    currentEditSpaceId = spaceId;
    
    // Find the space in currentSpaces array
    const space = currentSpaces.find(s => s.id === spaceId);
    if (!space) {
        RemuiAlert.error('Space not found');
        return;
    }
    
    // Populate the form
    document.getElementById('editSpaceId').value = spaceId;
    document.getElementById('editSpaceNameInput').value = space.name || '';
    document.getElementById('editSpaceDescriptionInput').value = space.description || '';
    document.getElementById('editSpaceIconSelect').value = space.icon || 'fa-solid fa-users';
    document.getElementById('editSpaceColorInput').value = space.color || '#2563eb';
    
    // Build icon grid and update the preview
    updateIconGrid('edit');
    updateEditSpacePreview();
    
    openModal('editSpaceModal');
}

function saveSpaceEdit() {
    const spaceId = document.getElementById('editSpaceId').value;
    const name = document.getElementById('editSpaceNameInput').value.trim();
    const description = document.getElementById('editSpaceDescriptionInput').value.trim();
    const icon = document.getElementById('editSpaceIconSelect').value;
    const color = document.getElementById('editSpaceColorInput').value;
    
    if (!name) {
        RemuiAlert.warning('Space name cannot be empty');
        return;
    }
    
    const saveBtn = document.querySelector('#editSpaceModal .btn.btn-primary');
    const originalText = saveBtn.textContent;
    saveBtn.disabled = true;
    saveBtn.textContent = 'Saving...';
    
    fetch('<?php echo $CFG->wwwroot; ?>/local/communityhub/ajax.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=update_space&spaceid=${spaceId}&name=${encodeURIComponent(name)}&description=${encodeURIComponent(description)}&icon=${encodeURIComponent(icon)}&color=${encodeURIComponent(color)}&sesskey=<?php echo sesskey(); ?>`
    })
    .then(response => response.json())
    .then(data => {
        saveBtn.disabled = false;
        saveBtn.textContent = originalText;
        
        if (data.success) {
            closeModal('editSpaceModal');
            // Reload the community to refresh the spaces list
            loadCommunityDetail(currentCommunityId);
        } else {
            RemuiAlert.error(data.error || 'Failed to update space');
        }
    })
    .catch(error => {
        saveBtn.disabled = false;
        saveBtn.textContent = originalText;
        console.error('Error updating space:', error);
        RemuiAlert.error('Failed to update space. Please try again.');
    });
}

function confirmDeleteSpace() {
    const spaceId = document.getElementById('editSpaceId').value;
    const spaceName = document.getElementById('editSpaceNameInput').value;
    
    if (!confirm(`Are you sure you want to delete "${spaceName}"? This will hide the space and all its posts. This action cannot be undone.`)) {
        return;
    }
    
    fetch('<?php echo $CFG->wwwroot; ?>/local/communityhub/ajax.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=delete_space&spaceid=${spaceId}&sesskey=<?php echo sesskey(); ?>`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            closeModal('editSpaceModal');
            loadCommunityDetail(currentCommunityId);
        } else {
            alert('Error: ' + (data.error || 'Failed to delete space'));
        }
    })
    .catch(error => {
        console.error('Error deleting space:', error);
        alert('Failed to delete space. Please try again.');
    });
}

function downloadResource(resourceId) {
    fetch('<?php echo $CFG->wwwroot; ?>/local/communityhub/ajax.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=download_resource&resourceid=${resourceId}&sesskey=<?php echo sesskey(); ?>`
    })
        .then(response => response.json())
        .then(data => {
            if (data.success && data.data && data.data.fileurl) {
                const counter = document.getElementById(`resource-download-${resourceId}`);
                if (counter) {
                    const currentCount = parseInt(counter.textContent, 10) || 0;
                    counter.textContent = currentCount + 1;
                }
                window.location.href = data.data.fileurl;
            } else {
                alert('Unable to download resource right now.');
            }
        });
}

function resetResourceForm() {
    const titleInput = document.getElementById('resourceTitleInput');
    const descInput = document.getElementById('resourceDescriptionInput');
    const fileInput = document.getElementById('resourceFileInput');
    const spaceSelect = document.getElementById('resourceSpaceSelect');

    if (titleInput) titleInput.value = '';
    if (descInput) descInput.value = '';
    if (fileInput) fileInput.value = '';
    if (spaceSelect) spaceSelect.value = '0';
}

function openShareResourceModal() {
    if (!currentCommunityId) {
        alert('Please select a community first.');
        return;
    }
    populateSpaceSelectOptions('resourceSpaceSelect');
    resetResourceForm();
    openModal('shareResourceModal');
}

function submitShareResource() {
    if (!currentCommunityId) {
        alert('Please select a community first.');
        return;
    }

    const titleInput = document.getElementById('resourceTitleInput');
    const descriptionInput = document.getElementById('resourceDescriptionInput');
    const fileInput = document.getElementById('resourceFileInput');
    const spaceSelect = document.getElementById('resourceSpaceSelect');

    if (!fileInput || !fileInput.files.length) {
        RemuiAlert.warning('Please choose a file to upload.');
        return;
    }
    
    // Validate file size before upload
    if (!validateFileSize(fileInput, 30)) {
        return;
    }

    const formData = new FormData();
    formData.append('action', 'create_resource');
    formData.append('sesskey', '<?php echo sesskey(); ?>');
    formData.append('communityid', currentCommunityId);
    formData.append('title', titleInput ? titleInput.value.trim() : '');
    formData.append('description', descriptionInput ? descriptionInput.value.trim() : '');
    formData.append('spaceid', spaceSelect ? (spaceSelect.value || 0) : 0);
    formData.append('resourcefile', fileInput.files[0]);

    const submitBtn = document.querySelector('#shareResourceModal .btn.btn-primary');
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = 'Uploading...';
    }

    fetch('<?php echo $CFG->wwwroot; ?>/local/communityhub/ajax.php', {
        method: 'POST',
        body: formData
    })
        .then(response => response.json())
        .then(data => {
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Upload Resource';
            }
            if (!data.success) {
                throw new Error(data.error || 'Unable to upload resource');
            }
            RemuiAlert.success('Resource uploaded successfully');
            closeModal('shareResourceModal');
            resetResourceForm();
            loadCommunityDetail(currentCommunityId);
        })
        .catch(error => {
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Upload Resource';
            }
            console.error('Error uploading resource:', error);
            RemuiAlert.error(error.message || 'Failed to upload resource. Please try again.');
        });
}

function resetEventForm() {
    ['eventTitleInput', 'eventDescriptionInput', 'eventLocationInput'].forEach(id => {
        const element = document.getElementById(id);
        if (element) {
            element.value = '';
        }
    });
    const dateInput = document.getElementById('eventDateInput');
    if (dateInput) {
        const today = new Date();
        dateInput.value = today.toISOString().split('T')[0];
    }
    const startInput = document.getElementById('eventStartTimeInput');
    const endInput = document.getElementById('eventEndTimeInput');
    if (startInput) startInput.value = '';
    if (endInput) endInput.value = '';
    const typeSelect = document.getElementById('eventTypeSelect');
    if (typeSelect) typeSelect.value = Object.keys(eventTypeOptions)[0] || '';
    const spaceSelect = document.getElementById('eventSpaceSelect');
    if (spaceSelect) spaceSelect.value = '0';
}

function populateEventTypeSelect() {
    const select = document.getElementById('eventTypeSelect');
    if (!select) {
        return;
    }

    select.innerHTML = '';
    Object.keys(eventTypeOptions).forEach(value => {
        const option = document.createElement('option');
        option.value = value;
        option.textContent = eventTypeOptions[value];
        select.appendChild(option);
    });
}

function openCreateEventModal() {
    if (!currentCommunityId) {
        alert('Please select a community first.');
        return;
    }
    populateSpaceSelectOptions('eventSpaceSelect');
    populateEventTypeSelect();
    resetEventForm();
    openModal('createEventModal');
}

function submitCreateEvent() {
    if (!currentCommunityId) {
        alert('Please select a community first.');
        return;
    }

    const title = document.getElementById('eventTitleInput').value.trim();
    const description = document.getElementById('eventDescriptionInput').value.trim();
    const date = document.getElementById('eventDateInput').value;
    const startTime = document.getElementById('eventStartTimeInput').value;
    const endTime = document.getElementById('eventEndTimeInput').value;
    const eventType = document.getElementById('eventTypeSelect').value || Object.keys(eventTypeOptions)[0];
    const location = document.getElementById('eventLocationInput').value.trim();
    const spaceId = document.getElementById('eventSpaceSelect').value || 0;

    if (!title) {
        alert('Please enter a title for the event.');
        return;
    }

    if (!date) {
        alert('Please select a date for the event.');
        return;
    }

    const startTimestamp = Math.floor(new Date(`${date}T${startTime || '00:00'}`).getTime() / 1000);
    let endTimestamp = 0;
    if (endTime) {
        endTimestamp = Math.floor(new Date(`${date}T${endTime}`).getTime() / 1000);
    }

    const payload = new URLSearchParams();
    payload.append('action', 'create_event');
    payload.append('sesskey', '<?php echo sesskey(); ?>');
    payload.append('communityid', currentCommunityId);
    payload.append('title', title);
    payload.append('description', description);
    payload.append('eventtype', eventType);
    payload.append('starttime', startTimestamp);
    if (endTimestamp && endTimestamp > startTimestamp) {
        payload.append('endtime', endTimestamp);
    }
    payload.append('location', location);
    payload.append('spaceid', spaceId);

    const submitBtn = document.querySelector('#createEventModal .btn.btn-primary');
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = 'Creating...';
    }

    fetch('<?php echo $CFG->wwwroot; ?>/local/communityhub/ajax.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: payload.toString()
    })
        .then(response => response.json())
        .then(data => {
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Create Event';
            }
            if (!data.success) {
                throw new Error(data.error || 'Unable to create event');
            }
            closeModal('createEventModal');
            resetEventForm();
            loadCommunityDetail(currentCommunityId);
        })
        .catch(error => {
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Create Event';
            }
            console.error('Error creating event:', error);
            alert('Failed to create event. Please try again.');
        });
}

// Edit Event Functions
let currentEditEventId = null;

function populateEditEventTypeSelect() {
    const select = document.getElementById('editEventTypeSelect');
    if (!select) return;

    select.innerHTML = '';
    Object.keys(eventTypeOptions).forEach(value => {
        const option = document.createElement('option');
        option.value = value;
        option.textContent = eventTypeOptions[value];
        select.appendChild(option);
    });
}

function openEditEventModal(eventId) {
    currentEditEventId = eventId;
    
    // Find the event in currentEvents array
    const event = currentEvents.find(e => e.id === eventId);
    if (!event) {
        alert('Event not found');
        return;
    }
    
    // Populate the edit event type select
    populateEditEventTypeSelect();
    
    // Parse starttime to date and time
    const startDate = new Date(event.starttime * 1000);
    const dateStr = startDate.toISOString().split('T')[0];
    const startTimeStr = startDate.toTimeString().slice(0, 5);
    
    let endTimeStr = '';
    if (event.endtime && event.endtime > 0) {
        const endDate = new Date(event.endtime * 1000);
        endTimeStr = endDate.toTimeString().slice(0, 5);
    }
    
    // Populate the form
    document.getElementById('editEventId').value = eventId;
    document.getElementById('editEventTitleInput').value = event.title || '';
    
    // Strip HTML from description for textarea
    const tempDiv = document.createElement('div');
    tempDiv.innerHTML = event.description || '';
    document.getElementById('editEventDescriptionInput').value = tempDiv.textContent || tempDiv.innerText || '';
    
    document.getElementById('editEventDateInput').value = dateStr;
    document.getElementById('editEventStartTimeInput').value = startTimeStr;
    document.getElementById('editEventEndTimeInput').value = endTimeStr;
    document.getElementById('editEventTypeSelect').value = event.eventtype || Object.keys(eventTypeOptions)[0];
    document.getElementById('editEventLocationInput').value = event.location || '';
    
    openModal('editEventModal');
}

function saveEventEdit() {
    const eventId = document.getElementById('editEventId').value;
    const title = document.getElementById('editEventTitleInput').value.trim();
    const description = document.getElementById('editEventDescriptionInput').value.trim();
    const date = document.getElementById('editEventDateInput').value;
    const startTime = document.getElementById('editEventStartTimeInput').value;
    const endTime = document.getElementById('editEventEndTimeInput').value;
    const eventType = document.getElementById('editEventTypeSelect').value;
    const location = document.getElementById('editEventLocationInput').value.trim();
    
    if (!title) {
        alert('Event title cannot be empty');
        return;
    }
    
    if (!date) {
        alert('Please select a date for the event');
        return;
    }
    
    const startTimestamp = Math.floor(new Date(`${date}T${startTime || '00:00'}`).getTime() / 1000);
    let endTimestamp = 0;
    if (endTime) {
        endTimestamp = Math.floor(new Date(`${date}T${endTime}`).getTime() / 1000);
    }
    
    const saveBtn = document.querySelector('#editEventModal .btn.btn-primary');
    const originalText = saveBtn.textContent;
    saveBtn.disabled = true;
    saveBtn.textContent = 'Saving...';
    
    const payload = new URLSearchParams();
    payload.append('action', 'update_event');
    payload.append('sesskey', '<?php echo sesskey(); ?>');
    payload.append('eventid', eventId);
    payload.append('title', title);
    payload.append('description', description);
    payload.append('eventtype', eventType);
    payload.append('starttime', startTimestamp);
    payload.append('endtime', endTimestamp);
    payload.append('location', location);
    
    fetch('<?php echo $CFG->wwwroot; ?>/local/communityhub/ajax.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: payload.toString()
    })
    .then(response => response.json())
    .then(data => {
        saveBtn.disabled = false;
        saveBtn.textContent = originalText;
        
        if (data.success) {
            closeModal('editEventModal');
            loadCommunityDetail(currentCommunityId);
        } else {
            alert('Error: ' + (data.error || 'Failed to update event'));
        }
    })
    .catch(error => {
        saveBtn.disabled = false;
        saveBtn.textContent = originalText;
        console.error('Error updating event:', error);
        alert('Failed to update event. Please try again.');
    });
}

function confirmDeleteEvent() {
    const eventId = document.getElementById('editEventId').value;
    const eventTitle = document.getElementById('editEventTitleInput').value;
    
    if (!confirm(`Are you sure you want to delete "${eventTitle}"? This action cannot be undone.`)) {
        return;
    }
    
    fetch('<?php echo $CFG->wwwroot; ?>/local/communityhub/ajax.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=delete_event&eventid=${eventId}&sesskey=<?php echo sesskey(); ?>`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            closeModal('editEventModal');
            loadCommunityDetail(currentCommunityId);
        } else {
            alert('Error: ' + (data.error || 'Failed to delete event'));
        }
    })
    .catch(error => {
        console.error('Error deleting event:', error);
        alert('Failed to delete event. Please try again.');
        });
}

function openMediaPicker(type) {
    if (type === 'image' || type === 'document') {
        const input = document.createElement('input');
        input.type = 'file';
        input.multiple = true;
        if (type === 'image') {
            input.accept = 'image/*,video/*';
        } else {
            input.accept = '.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.zip';
        }
        input.onchange = function(e) {
            const files = Array.from(e.target.files);
            // Validate file sizes
            const maxSizeBytes = 30 * 1024 * 1024; // 30MB
            const oversizedFiles = files.filter(file => file.size > maxSizeBytes);
            
            if (oversizedFiles.length > 0) {
                // Clear the input
                e.target.value = '';
                selectedMediaFiles = [];
                showMediaPreview();
                
                // Show error
                let errorMsg = 'The following file(s) exceed the maximum size limit of 30MB:\n\n';
                oversizedFiles.forEach(file => {
                    errorMsg += `• ${file.name} (${formatFileSize(file.size)})\n`;
                });
                errorMsg += '\nPlease select smaller files.';
                RemuiAlert.error(errorMsg, 'File Too Large');
                return;
            }
            
            selectedMediaFiles = files;
            showMediaPreview();
        };
        input.click();
    } else if (type === 'link') {
        const url = prompt('Enter link URL:');
        if (url) {
            // Handle link attachment
            console.log('Link:', url);
        }
    }
}

function showMediaPreview() {
    const previewEl = document.getElementById('selectedMediaPreview');
    if (selectedMediaFiles.length === 0) {
        previewEl.style.display = 'none';
        return;
    }
    
    let html = '<div style="display: flex; gap: 8px; flex-wrap: wrap;">';
    selectedMediaFiles.forEach((file, index) => {
        html += `
            <div style="position: relative; display: inline-block;">
                <span style="background: #e5e7eb; padding: 4px 8px; border-radius: 4px; font-size: 0.75rem;">
                    ${escapeHtml(file.name)}
                </span>
                <button onclick="removeMediaFile(${index})" style="margin-left: 4px; background: #ef4444; color: white; border: none; border-radius: 50%; width: 20px; height: 20px; cursor: pointer; font-size: 12px;">×</button>
            </div>
        `;
    });
    html += '</div>';
    
    previewEl.innerHTML = html;
    previewEl.style.display = 'block';
}

function removeMediaFile(index) {
    selectedMediaFiles.splice(index, 1);
    showMediaPreview();
}

function submitPost() {
    const subject = document.getElementById('postSubject').value.trim();
    const message = document.getElementById('postMessage').value.trim();
    const spaceId = parseInt(document.getElementById('postSpaceSelect').value) || 0;
    
    if (!message) {
        alert('Please enter a message');
        return;
    }
    
    // Get Post button and disable it, show loader
    const postBtn = document.querySelector('#createPostModal .btn-primary');
    const originalBtnText = postBtn ? postBtn.innerHTML : '';
    if (postBtn) {
        postBtn.disabled = true;
        postBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Posting...';
        postBtn.style.cursor = 'not-allowed';
        postBtn.style.opacity = '0.7';
    }
    
    const formData = new FormData();
    formData.append('action', 'create_post');
    formData.append('sesskey', '<?php echo sesskey(); ?>');
    formData.append('communityid', currentCommunityId);
    formData.append('spaceid', spaceId);
    formData.append('subject', subject);
    formData.append('message', message);
    
    const mediaFilesInput = document.getElementById('postMediaFiles');
    const mediaFiles = mediaFilesInput ? mediaFilesInput.files : null;
    if (mediaFiles && mediaFiles.length > 0) {
        // Validate file sizes before upload
        if (!validateFileSize(mediaFilesInput, 30)) {
            return;
        }
        Array.from(mediaFiles).forEach(file => {
            formData.append('media[]', file);
        });
    }
    
    fetch('<?php echo $CFG->wwwroot; ?>/local/communityhub/ajax.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        // Re-enable button
        if (postBtn) {
            postBtn.disabled = false;
            postBtn.innerHTML = originalBtnText;
            postBtn.style.cursor = 'pointer';
            postBtn.style.opacity = '1';
        }
        
        if (data.success) {
            const postId = data.data.id;
            
            closeModal('createPostModal');
            document.getElementById('postMessage').value = '';
            document.getElementById('postSubject').value = '';
            document.getElementById('postMediaFiles').value = '';
            document.getElementById('mediaPreview').innerHTML = '';
            
            // Reload community detail (which includes posts)
            loadCommunityDetail(currentCommunityId).then(() => {
                // After posts are loaded, trigger async moderation check
                checkPostModerationAsync(postId);
            });
        } else {
            alert('Error: ' + (data.error || 'Failed to create post'));
        }
    })
    .catch(error => {
        // Re-enable button on error
        if (postBtn) {
            postBtn.disabled = false;
            postBtn.innerHTML = originalBtnText;
            postBtn.style.cursor = 'pointer';
            postBtn.style.opacity = '1';
        }
        console.error('Error creating post:', error);
        alert('Failed to create post. Please try again.');
    });
}

function filterBySpace(spaceId) {
    selectedSpaceId = spaceId;
    // Reload posts filtered by space
    loadCommunityDetail(currentCommunityId);
}

// Member Management Functions
let currentMembersModalCommunityId = 0;
let currentMembersModalCommunityName = '';
let canManageMembers = false;
let selectedUsersForAdd = [];
let availableUsersList = [];
let searchTimeout = null;
let selectedRoleFilter = 'all';
let isSuperAdmin = <?php echo $issuperadmin ? 'true' : 'false'; ?>;

function openMembersModal(communityId, communityName) {
    currentMembersModalCommunityId = communityId;
    currentMembersModalCommunityName = communityName || currentCommunityName || 'Community';
    document.getElementById('membersModalTitle').textContent = `Members - ${currentMembersModalCommunityName}`;
    openModal('membersModal');
    loadMembers(communityId);
}

function loadMembers(communityId) {
    const membersList = document.getElementById('membersList');
    membersList.innerHTML = '<div style="text-align: center; padding: 40px; color: #64748b;"><i class="fa-solid fa-spinner fa-spin" style="font-size: 24px; margin-bottom: 12px;"></i><p>Loading members...</p></div>';

    fetch(`<?php echo $CFG->wwwroot; ?>/local/communityhub/ajax.php?action=get_members&communityid=${communityId}&sesskey=<?php echo sesskey(); ?>`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.data) {
                renderMembersList(data.data, communityId);
                checkManagePermission(communityId);
            } else {
                membersList.innerHTML = '<div style="text-align: center; padding: 40px; color: #ef4444;"><p>Error loading members: ' + (data.error || 'Unknown error') + '</p></div>';
            }
        })
        .catch(error => {
            console.error('Error loading members:', error);
            membersList.innerHTML = '<div style="text-align: center; padding: 40px; color: #ef4444;"><p>Error loading members. Please try again.</p></div>';
        });
}

function renderMembersList(members, communityId) {
    const membersList = document.getElementById('membersList');
    const membersCount = document.getElementById('membersCount');
    
    membersCount.textContent = `${members.length} ${members.length === 1 ? 'member' : 'members'}`;
    currentCommunityMemberCount = members.length;
    updateMemberButtonLabel();

    if (!members.length) {
        membersList.innerHTML = '<div style="text-align: center; padding: 40px; color: #64748b;"><p>No members yet.</p></div>';
        return;
    }

    // Check if current user is an admin by looking at the members list
    const currentUserMember = members.find(m => parseInt(m.id) === parseInt(currentUserId));
    const userCanManage = currentUserMember && currentUserMember.role === 'admin';
    canManageMembers = userCanManage; // Update the global variable
    document.getElementById('addMembersBtn').style.display = userCanManage ? 'block' : 'none';

    const html = members.map(member => {
        const avatarUrl = `<?php echo $CFG->wwwroot; ?>/user/pix.php/${member.id}/f1.jpg`;
        const isCurrentUser = parseInt(member.id) === parseInt(currentUserId);
        const roleClass = member.role === 'admin' ? 'admin' : (member.role === 'moderator' ? 'moderator' : 'member');
        
        let actionsHtml = '';
        if (userCanManage && !isCurrentUser) {
            actionsHtml = `
                <div class="member-item-actions">
                    <select class="member-role-select" onchange="updateMemberRole(${communityId}, ${member.id}, this.value)" style="padding: 6px 10px; border-radius: 6px; border: 1px solid #e2e8f0; font-size: 0.85rem; cursor: pointer;">
                        <option value="admin" ${member.role === 'admin' ? 'selected' : ''}>Admin</option>
                        <option value="moderator" ${member.role === 'moderator' ? 'selected' : ''}>Moderator</option>
                        <option value="member" ${member.role === 'member' ? 'selected' : ''}>Member</option>
                    </select>
                    <button class="member-action-btn danger" onclick="removeMember(${communityId}, ${member.id})">Remove</button>
                </div>
            `;
        }

        return `
            <div class="member-item">
                <div class="member-item-info">
                    <img src="${avatarUrl}" alt="${escapeHtml(member.name)}" class="member-item-avatar" onerror="this.src='<?php echo $CFG->wwwroot; ?>/pix/u/f1.png'">
                    <div class="member-item-details">
                        <p class="member-item-name">${escapeHtml(member.name)}${isCurrentUser ? ' (You)' : ''}</p>
                        <p class="member-item-email">${escapeHtml(member.email || '')}</p>
                    </div>
                </div>
                <div class="member-item-role">
                    <span class="member-role-badge ${roleClass}">${escapeHtml(member.rolelabel || member.role)}</span>
                    ${actionsHtml}
                </div>
            </div>
        `;
    }).join('');

    membersList.innerHTML = html;
}

function checkManagePermission(communityId) {
    // Check if user can manage members (is admin or has manage capability)
    // For now, we'll check if user is admin of the community
    fetch(`<?php echo $CFG->wwwroot; ?>/local/communityhub/ajax.php?action=get_members&communityid=${communityId}&sesskey=<?php echo sesskey(); ?>`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.data) {
                const currentUserMember = data.data.find(m => parseInt(m.id) === parseInt(currentUserId));
                canManageMembers = currentUserMember && currentUserMember.role === 'admin';
                document.getElementById('addMembersBtn').style.display = canManageMembers ? 'block' : 'none';
            }
        })
        .catch(error => {
            console.error('Error checking permission:', error);
            canManageMembers = false;
            document.getElementById('addMembersBtn').style.display = 'none';
        });
}

function removeMember(communityId, memberId) {
    if (!confirm('Are you sure you want to remove this member from the community?')) {
        return;
    }

    fetch(`<?php echo $CFG->wwwroot; ?>/local/communityhub/ajax.php?action=remove_member&communityid=${communityId}&memberid=${memberId}&sesskey=<?php echo sesskey(); ?>`, {
        method: 'POST'
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                loadMembers(communityId);
                // Refresh community list if on list view
                if (availableCommunities.length) {
                    loadDefaultCommunity();
                }
            } else {
                alert('Error: ' + (data.error || 'Failed to remove member'));
            }
        })
        .catch(error => {
            console.error('Error removing member:', error);
            alert('Error removing member. Please try again.');
        });
}

function updateMemberRole(communityId, memberId, role) {
    const roleLabels = { admin: 'Admin', moderator: 'Moderator', member: 'Member' };
    if (!confirm(`Change this member's role to ${roleLabels[role] || role}?`)) {
        // Reset the select to its previous value
        loadMembers(communityId);
        return;
    }

    fetch(`<?php echo $CFG->wwwroot; ?>/local/communityhub/ajax.php?action=update_member_role&communityid=${communityId}&memberid=${memberId}&role=${role}&sesskey=<?php echo sesskey(); ?>`, {
        method: 'POST'
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                loadMembers(communityId);
            } else {
                alert('Error: ' + (data.error || 'Failed to update member role'));
            }
        })
        .catch(error => {
            console.error('Error updating member role:', error);
            alert('Error updating member role. Please try again.');
        });
}

function openAddMembersModal() {
    selectedUsersForAdd = [];
    availableUsersList = [];
    selectedRoleFilter = 'all';
    document.getElementById('roleFilterSelect').value = 'all';
    document.getElementById('userSearchInput').value = '';
    document.getElementById('availableUsersList').innerHTML = '<div style="text-align: center; padding: 20px; color: #64748b;"><i class="fa-solid fa-spinner fa-spin" style="font-size: 24px; margin-bottom: 12px;"></i><p>Loading users...</p></div>';
    document.getElementById('selectedUsersTags').innerHTML = '<p style="color: #64748b; font-size: 0.875rem;">No users selected</p>';
    openModal('addMembersModal');
    loadInitialUsers();
}

function handleRoleFilterChange() {
    const select = document.getElementById('roleFilterSelect');
    selectedRoleFilter = select.value;
    // If there's a search term, trigger search again
    const searchTerm = document.getElementById('userSearchInput').value.trim();
    if (searchTerm.length >= 2) {
        searchUsers();
    } else {
        loadInitialUsers();
    }
}

function loadInitialUsers() {
    document.getElementById('availableUsersList').innerHTML = '<div style="text-align: center; padding: 20px; color: #64748b;"><i class="fa-solid fa-spinner fa-spin" style="font-size: 24px; margin-bottom: 12px;"></i><p>Loading users...</p></div>';
    
    // Get current members to exclude them
    fetch(`<?php echo $CFG->wwwroot; ?>/local/communityhub/ajax.php?action=get_members&communityid=${currentMembersModalCommunityId}&sesskey=<?php echo sesskey(); ?>`)
        .then(response => response.json())
        .then(membersData => {
            const currentMemberIds = membersData.success && membersData.data ? membersData.data.map(m => parseInt(m.id)) : [];
            
            // Get available users with role filter
            const roleTypeParam = selectedRoleFilter !== 'all' ? `&roletype=${selectedRoleFilter}` : '';
            fetch(`<?php echo $CFG->wwwroot; ?>/local/communityhub/ajax.php?action=get_users${roleTypeParam}&sesskey=<?php echo sesskey(); ?>`)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.data) {
                        // Filter to exclude current members and limit to 10
                        availableUsersList = data.data.filter(user => {
                            const userId = parseInt(user.id);
                            return !currentMemberIds.includes(userId);
                        }).slice(0, 10);
                        renderAvailableUsers();
                    } else {
                        document.getElementById('availableUsersList').innerHTML = '<div style="text-align: center; padding: 20px; color: #ef4444;"><p>Error loading users</p></div>';
                    }
                })
                .catch(error => {
                    console.error('Error loading users:', error);
                    document.getElementById('availableUsersList').innerHTML = '<div style="text-align: center; padding: 20px; color: #ef4444;"><p>Error loading users</p></div>';
                });
        })
        .catch(error => {
            console.error('Error getting current members:', error);
        });
}

function searchUsers() {
    const searchTerm = document.getElementById('userSearchInput').value.trim();
    
    if (searchTimeout) {
        clearTimeout(searchTimeout);
    }

    if (searchTerm.length < 2) {
        loadInitialUsers();
        return;
    }

    searchTimeout = setTimeout(() => {
        // Get current members to exclude them
        fetch(`<?php echo $CFG->wwwroot; ?>/local/communityhub/ajax.php?action=get_members&communityid=${currentMembersModalCommunityId}&sesskey=<?php echo sesskey(); ?>`)
            .then(response => response.json())
            .then(membersData => {
                const currentMemberIds = membersData.success && membersData.data ? membersData.data.map(m => parseInt(m.id)) : [];
                
                // Get available users with role filter
                const roleTypeParam = selectedRoleFilter !== 'all' ? `&roletype=${selectedRoleFilter}` : '';
                fetch(`<?php echo $CFG->wwwroot; ?>/local/communityhub/ajax.php?action=get_users${roleTypeParam}&sesskey=<?php echo sesskey(); ?>`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success && data.data) {
                            availableUsersList = data.data.filter(user => {
                                const userId = parseInt(user.id);
                                const fullName = (user.name || `${user.firstname || ''} ${user.lastname || ''}`.trim() || '').toLowerCase();
                                const email = (user.email || '').toLowerCase();
                                const searchLower = searchTerm.toLowerCase();
                                return !currentMemberIds.includes(userId) && 
                                       (fullName.includes(searchLower) || email.includes(searchLower));
                            }).slice(0, 10);
                            renderAvailableUsers();
                        } else {
                            document.getElementById('availableUsersList').innerHTML = '<div style="text-align: center; padding: 20px; color: #ef4444;"><p>Error loading users</p></div>';
                        }
                    })
                    .catch(error => {
                        console.error('Error searching users:', error);
                        document.getElementById('availableUsersList').innerHTML = '<div style="text-align: center; padding: 20px; color: #ef4444;"><p>Error searching users</p></div>';
                    });
            })
            .catch(error => {
                console.error('Error getting current members:', error);
            });
    }, 300);
}

function renderAvailableUsers() {
    const container = document.getElementById('availableUsersList');
    
    if (!availableUsersList.length) {
        container.innerHTML = '<div style="text-align: center; padding: 20px; color: #64748b;"><p>No users found</p></div>';
        return;
    }

    const html = availableUsersList.map(user => {
        const avatarUrl = `<?php echo $CFG->wwwroot; ?>/user/pix.php/${user.id}/f1.jpg`;
        const isSelected = selectedUsersForAdd.some(su => parseInt(su.id) === parseInt(user.id));
        const fullName = user.name || `${user.firstname || ''} ${user.lastname || ''}`.trim() || 'Unknown User';
        
        return `
            <div class="user-select-item ${isSelected ? 'selected' : ''}" onclick="toggleUserSelection(${user.id}, '${escapeHtml(fullName)}', '${escapeHtml(user.email || '')}')">
                <div class="user-select-item-info">
                    <img src="${avatarUrl}" alt="${escapeHtml(fullName)}" class="user-select-item-avatar" onerror="this.src='<?php echo $CFG->wwwroot; ?>/pix/u/f1.png'">
                    <div>
                        <p class="user-select-item-name">${escapeHtml(fullName)}</p>
                        <p style="font-size: 0.75rem; color: #64748b; margin: 0;">${escapeHtml(user.email || '')}</p>
                    </div>
                </div>
                ${isSelected ? '<i class="fa-solid fa-check" style="color: #2563eb;"></i>' : ''}
            </div>
        `;
    }).join('');

    container.innerHTML = html;
    renderSelectedUsersTags();
}

function toggleUserSelection(userId, userName, userEmail) {
    const user = availableUsersList.find(u => parseInt(u.id) === parseInt(userId));
    if (!user) return;

    const index = selectedUsersForAdd.findIndex(su => parseInt(su.id) === parseInt(userId));
    if (index > -1) {
        selectedUsersForAdd.splice(index, 1);
    } else {
        selectedUsersForAdd.push({
            id: userId,
            name: userName,
            email: userEmail
        });
    }
    renderAvailableUsers();
}

function renderSelectedUsersTags() {
    const container = document.getElementById('selectedUsersTags');
    
    if (!selectedUsersForAdd.length) {
        container.innerHTML = '<p style="color: #64748b; font-size: 0.875rem;">No users selected</p>';
        return;
    }

    const html = selectedUsersForAdd.map(user => `
        <span class="selected-user-tag">
            ${escapeHtml(user.name)}
            <span class="selected-user-tag-remove" onclick="event.stopPropagation(); removeSelectedUser(${user.id})">×</span>
        </span>
    `).join('');

    container.innerHTML = html;
}

function removeSelectedUser(userId) {
    selectedUsersForAdd = selectedUsersForAdd.filter(u => parseInt(u.id) !== parseInt(userId));
    renderAvailableUsers();
}

function submitAddMembers() {
    if (!selectedUsersForAdd.length) {
        alert('Please select at least one user to add');
        return;
    }

    const memberIds = selectedUsersForAdd.map(u => u.id);
    const submitBtn = event.target;
    const originalText = submitBtn.textContent;
    submitBtn.disabled = true;
    submitBtn.textContent = 'Adding...';

    const formData = new FormData();
    formData.append('action', 'add_members');
    formData.append('communityid', currentMembersModalCommunityId);
    formData.append('sesskey', '<?php echo sesskey(); ?>');
    memberIds.forEach(id => {
        formData.append('memberids[]', id);
    });

    fetch(`<?php echo $CFG->wwwroot; ?>/local/communityhub/ajax.php`, {
        method: 'POST',
        body: formData
    })
        .then(response => response.json())
        .then(data => {
            submitBtn.disabled = false;
            submitBtn.textContent = originalText;
            
            if (data.success) {
                closeModal('addMembersModal');
                loadMembers(currentMembersModalCommunityId);
                // Refresh community list if on list view
                if (availableCommunities.length) {
                    loadDefaultCommunity();
                }
            } else {
                alert('Error: ' + (data.error || 'Failed to add members'));
            }
        })
        .catch(error => {
            console.error('Error adding members:', error);
            submitBtn.disabled = false;
            submitBtn.textContent = originalText;
            alert('Error adding members. Please try again.');
        });
}

// ========== SPACE MEMBER MANAGEMENT ==========
let currentSpaceMembersModalSpaceId = 0;
let currentSpaceMembersModalSpaceName = '';
let canManageSpaceMembers = false;
let selectedSpaceUsersForAdd = [];
let availableSpaceUsersList = [];
let searchSpaceTimeout = null;
let selectedSpaceRoleFilter = 'all';

function openSpaceMembersModal(spaceId, spaceName) {
    currentSpaceMembersModalSpaceId = spaceId;
    currentSpaceMembersModalSpaceName = spaceName;
    document.getElementById('spaceMembersModalTitle').textContent = `Members - ${spaceName}`;
    openModal('spaceMembersModal');
    loadSpaceMembers(spaceId);
}

function openSpaceChatPage(spaceId) {
    if (!spaceId || !spaceChatPageBaseUrl) {
        return;
    }
    const targetUrl = spaceChatPageBaseUrl + encodeURIComponent(String(spaceId));
    window.location.href = targetUrl;
}

function loadSpaceMembers(spaceId) {
    const membersList = document.getElementById('spaceMembersList');
    membersList.innerHTML = '<div style="text-align: center; padding: 40px; color: #64748b;"><i class="fa-solid fa-spinner fa-spin" style="font-size: 24px; margin-bottom: 12px;"></i><p>Loading members...</p></div>';

    fetch(`<?php echo $CFG->wwwroot; ?>/local/communityhub/ajax.php?action=get_space_members&spaceid=${spaceId}&sesskey=<?php echo sesskey(); ?>`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.data) {
                renderSpaceMembersList(data.data, spaceId);
                checkSpaceManagePermission(spaceId);
            } else {
                membersList.innerHTML = '<div style="text-align: center; padding: 40px; color: #ef4444;"><p>Error loading members: ' + (data.error || 'Unknown error') + '</p></div>';
            }
        })
        .catch(error => {
            console.error('Error loading space members:', error);
            membersList.innerHTML = '<div style="text-align: center; padding: 40px; color: #ef4444;"><p>Error loading members. Please try again.</p></div>';
        });
}

function renderSpaceMembersList(members, spaceId) {
    const membersList = document.getElementById('spaceMembersList');
    const membersCount = document.getElementById('spaceMembersCount');
    
    membersCount.textContent = `${members.length} ${members.length === 1 ? 'member' : 'members'}`;

    if (!members.length) {
        membersList.innerHTML = '<div style="text-align: center; padding: 40px; color: #64748b;"><p>No members yet.</p></div>';
        return;
    }

    // Check if current user is an admin by looking at the members list
    const currentUserMember = members.find(m => parseInt(m.id) === parseInt(currentUserId));
    const userCanManage = currentUserMember && currentUserMember.role === 'admin';
    canManageSpaceMembers = userCanManage;
    document.getElementById('addSpaceMembersBtn').style.display = userCanManage ? 'block' : 'none';

    const html = members.map(member => {
        const avatarUrl = `<?php echo $CFG->wwwroot; ?>/user/pix.php/${member.id}/f1.jpg`;
        const isCurrentUser = parseInt(member.id) === parseInt(currentUserId);
        const roleClass = member.role === 'admin' ? 'admin' : 'member';
        
        let actionsHtml = '';
        if (userCanManage && !isCurrentUser) {
            actionsHtml = `
                <div class="member-item-actions">
                    ${member.role === 'admin' 
                        ? `<button class="member-action-btn" onclick="updateSpaceMemberRole(${spaceId}, ${member.id}, 'member')">Remove Admin</button>`
                        : `<button class="member-action-btn" onclick="updateSpaceMemberRole(${spaceId}, ${member.id}, 'admin')">Make Admin</button>`
                    }
                    <button class="member-action-btn danger" onclick="removeSpaceMember(${spaceId}, ${member.id})">Remove</button>
                </div>
            `;
        }

        return `
            <div class="member-item">
                <div class="member-item-info">
                    <img src="${avatarUrl}" alt="${escapeHtml(member.name)}" class="member-item-avatar" onerror="this.src='<?php echo $CFG->wwwroot; ?>/pix/u/f1.png'">
                    <div class="member-item-details">
                        <p class="member-item-name">${escapeHtml(member.name)}${isCurrentUser ? ' (You)' : ''}</p>
                        <p class="member-item-email">${escapeHtml(member.email || '')}</p>
                    </div>
                </div>
                <div class="member-item-role">
                    <span class="member-role-badge ${roleClass}">${escapeHtml(member.rolelabel || member.role)}</span>
                    ${actionsHtml}
                </div>
            </div>
        `;
    }).join('');

    membersList.innerHTML = html;
}

function checkSpaceManagePermission(spaceId) {
    fetch(`<?php echo $CFG->wwwroot; ?>/local/communityhub/ajax.php?action=get_space_members&spaceid=${spaceId}&sesskey=<?php echo sesskey(); ?>`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.data) {
                const currentUserMember = data.data.find(m => parseInt(m.id) === parseInt(currentUserId));
                canManageSpaceMembers = currentUserMember && currentUserMember.role === 'admin';
                document.getElementById('addSpaceMembersBtn').style.display = canManageSpaceMembers ? 'block' : 'none';
            }
        })
        .catch(error => {
            console.error('Error checking permission:', error);
            canManageSpaceMembers = false;
            document.getElementById('addSpaceMembersBtn').style.display = 'none';
        });
}

function removeSpaceMember(spaceId, memberId) {
    if (!confirm('Are you sure you want to remove this member from the space?')) {
        return;
    }

    fetch(`<?php echo $CFG->wwwroot; ?>/local/communityhub/ajax.php?action=remove_space_member&spaceid=${spaceId}&memberid=${memberId}&sesskey=<?php echo sesskey(); ?>`, {
        method: 'POST'
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                loadSpaceMembers(spaceId);
            } else {
                alert('Error: ' + (data.error || 'Failed to remove member'));
            }
        })
        .catch(error => {
            console.error('Error removing space member:', error);
            alert('Error removing member. Please try again.');
        });
}

function updateSpaceMemberRole(spaceId, memberId, role) {
    const action = role === 'admin' ? 'make admin' : 'remove admin';
    if (!confirm(`Are you sure you want to ${action} this member?`)) {
        return;
    }

    fetch(`<?php echo $CFG->wwwroot; ?>/local/communityhub/ajax.php?action=update_space_member_role&spaceid=${spaceId}&memberid=${memberId}&role=${role}&sesskey=<?php echo sesskey(); ?>`, {
        method: 'POST'
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                loadSpaceMembers(spaceId);
            } else {
                alert('Error: ' + (data.error || 'Failed to update member role'));
            }
        })
        .catch(error => {
            console.error('Error updating space member role:', error);
            alert('Error updating member role. Please try again.');
        });
}

function openAddSpaceMembersModal() {
    selectedSpaceUsersForAdd = [];
    availableSpaceUsersList = [];
    selectedSpaceRoleFilter = 'all';
    document.getElementById('spaceRoleFilterSelect').value = 'all';
    document.getElementById('spaceUserSearchInput').value = '';
    document.getElementById('availableSpaceUsersList').innerHTML = '<div style="text-align: center; padding: 20px; color: #64748b;"><i class="fa-solid fa-spinner fa-spin" style="font-size: 24px; margin-bottom: 12px;"></i><p>Loading users...</p></div>';
    document.getElementById('selectedSpaceUsersTags').innerHTML = '<p style="color: #64748b; font-size: 0.875rem;">No users selected</p>';
    openModal('addSpaceMembersModal');
    loadInitialSpaceUsers();
}

function handleSpaceRoleFilterChange() {
    const select = document.getElementById('spaceRoleFilterSelect');
    selectedSpaceRoleFilter = select.value;
    const searchTerm = document.getElementById('spaceUserSearchInput').value.trim();
    if (searchTerm.length >= 2) {
        searchSpaceUsers();
    } else {
        loadInitialSpaceUsers();
    }
}

function loadInitialSpaceUsers() {
    document.getElementById('availableSpaceUsersList').innerHTML = '<div style="text-align: center; padding: 20px; color: #64748b;"><i class="fa-solid fa-spinner fa-spin" style="font-size: 24px; margin-bottom: 12px;"></i><p>Loading users...</p></div>';
    
    fetch(`<?php echo $CFG->wwwroot; ?>/local/communityhub/ajax.php?action=get_space_members&spaceid=${currentSpaceMembersModalSpaceId}&sesskey=<?php echo sesskey(); ?>`)
        .then(response => response.json())
        .then(membersData => {
            const currentMemberIds = membersData.success && membersData.data ? membersData.data.map(m => parseInt(m.id)) : [];
            
            // Find the space to get its community ID
            const space = currentSpaces.find(s => parseInt(s.id) === parseInt(currentSpaceMembersModalSpaceId));
            if (!space || !space.communityid) {
                document.getElementById('availableSpaceUsersList').innerHTML = '<div style="text-align: center; padding: 20px; color: #ef4444;"><p>Unable to find space details</p></div>';
                return;
            }
            
            const communityId = parseInt(space.communityid);
            
            // Get all community members first
            fetch(`<?php echo $CFG->wwwroot; ?>/local/communityhub/ajax.php?action=get_members&communityid=${communityId}&sesskey=<?php echo sesskey(); ?>`)
                .then(response => response.json())
                .then(communityMembersData => {
                    const communityMemberIds = communityMembersData.success && communityMembersData.data 
                        ? communityMembersData.data.map(m => parseInt(m.userid || m.id)) 
                        : [];
                    
                    const roleTypeParam = selectedSpaceRoleFilter !== 'all' ? `&roletype=${selectedSpaceRoleFilter}` : '';
                    fetch(`<?php echo $CFG->wwwroot; ?>/local/communityhub/ajax.php?action=get_users${roleTypeParam}&sesskey=<?php echo sesskey(); ?>`)
                        .then(response => response.json())
                        .then(data => {
                            if (data.success && data.data) {
                                // Filter to only community members, exclude current space members, and limit to 10
                                availableSpaceUsersList = data.data.filter(user => {
                                    const userId = parseInt(user.id);
                                    return communityMemberIds.includes(userId) && !currentMemberIds.includes(userId);
                                }).slice(0, 10);
                                renderAvailableSpaceUsers();
                            } else {
                                document.getElementById('availableSpaceUsersList').innerHTML = '<div style="text-align: center; padding: 20px; color: #ef4444;"><p>Error loading users</p></div>';
                            }
                        })
                        .catch(error => {
                            console.error('Error loading users:', error);
                            document.getElementById('availableSpaceUsersList').innerHTML = '<div style="text-align: center; padding: 20px; color: #ef4444;"><p>Error loading users</p></div>';
                        });
                })
                .catch(error => {
                    console.error('Error getting community members:', error);
                    document.getElementById('availableSpaceUsersList').innerHTML = '<div style="text-align: center; padding: 20px; color: #ef4444;"><p>Error loading community members</p></div>';
                });
        })
        .catch(error => {
            console.error('Error getting current space members:', error);
        });
}

function searchSpaceUsers() {
    const searchTerm = document.getElementById('spaceUserSearchInput').value.trim();
    
    if (searchSpaceTimeout) {
        clearTimeout(searchSpaceTimeout);
    }

    if (searchTerm.length < 2) {
        loadInitialSpaceUsers();
        return;
    }

    searchSpaceTimeout = setTimeout(() => {
        fetch(`<?php echo $CFG->wwwroot; ?>/local/communityhub/ajax.php?action=get_space_members&spaceid=${currentSpaceMembersModalSpaceId}&sesskey=<?php echo sesskey(); ?>`)
            .then(response => response.json())
            .then(membersData => {
                const currentMemberIds = membersData.success && membersData.data ? membersData.data.map(m => parseInt(m.id)) : [];
                
                // Find the space to get its community ID
                const space = currentSpaces.find(s => parseInt(s.id) === parseInt(currentSpaceMembersModalSpaceId));
                if (!space || !space.communityid) {
                    document.getElementById('availableSpaceUsersList').innerHTML = '<div style="text-align: center; padding: 20px; color: #ef4444;"><p>Unable to find space details</p></div>';
                    return;
                }
                
                const communityId = parseInt(space.communityid);
                
                // Get all community members first
                fetch(`<?php echo $CFG->wwwroot; ?>/local/communityhub/ajax.php?action=get_members&communityid=${communityId}&sesskey=<?php echo sesskey(); ?>`)
                    .then(response => response.json())
                    .then(communityMembersData => {
                        const communityMemberIds = communityMembersData.success && communityMembersData.data 
                            ? communityMembersData.data.map(m => parseInt(m.userid || m.id)) 
                            : [];
                        
                        const roleTypeParam = selectedSpaceRoleFilter !== 'all' ? `&roletype=${selectedSpaceRoleFilter}` : '';
                        fetch(`<?php echo $CFG->wwwroot; ?>/local/communityhub/ajax.php?action=get_users${roleTypeParam}&sesskey=<?php echo sesskey(); ?>`)
                            .then(response => response.json())
                            .then(data => {
                                if (data.success && data.data) {
                                    availableSpaceUsersList = data.data.filter(user => {
                                        const userId = parseInt(user.id);
                                        const fullName = (user.name || `${user.firstname || ''} ${user.lastname || ''}`.trim() || '').toLowerCase();
                                        const email = (user.email || '').toLowerCase();
                                        const searchLower = searchTerm.toLowerCase();
                                        // Only show users who are community members and not already space members
                                        return communityMemberIds.includes(userId) &&
                                               !currentMemberIds.includes(userId) && 
                                               (fullName.includes(searchLower) || email.includes(searchLower));
                                    }).slice(0, 10);
                                    renderAvailableSpaceUsers();
                                } else {
                                    document.getElementById('availableSpaceUsersList').innerHTML = '<div style="text-align: center; padding: 20px; color: #ef4444;"><p>Error loading users</p></div>';
                                }
                            })
                            .catch(error => {
                                console.error('Error searching users:', error);
                                document.getElementById('availableSpaceUsersList').innerHTML = '<div style="text-align: center; padding: 20px; color: #ef4444;"><p>Error searching users</p></div>';
                            });
                    })
                    .catch(error => {
                        console.error('Error getting community members:', error);
                        document.getElementById('availableSpaceUsersList').innerHTML = '<div style="text-align: center; padding: 20px; color: #ef4444;"><p>Error loading community members</p></div>';
                    });
            })
            .catch(error => {
                console.error('Error getting current space members:', error);
            });
    }, 300);
}

function renderAvailableSpaceUsers() {
    const container = document.getElementById('availableSpaceUsersList');
    
    if (!availableSpaceUsersList.length) {
        container.innerHTML = '<div style="text-align: center; padding: 20px; color: #64748b;"><p>No users found</p></div>';
        return;
    }

    const html = availableSpaceUsersList.map(user => {
        const avatarUrl = `<?php echo $CFG->wwwroot; ?>/user/pix.php/${user.id}/f1.jpg`;
        const isSelected = selectedSpaceUsersForAdd.some(su => parseInt(su.id) === parseInt(user.id));
        const fullName = user.name || `${user.firstname || ''} ${user.lastname || ''}`.trim() || 'Unknown User';
        
        return `
            <div class="user-select-item ${isSelected ? 'selected' : ''}" onclick="toggleSpaceUserSelection(${user.id}, '${escapeHtml(fullName)}', '${escapeHtml(user.email || '')}')">
                <div class="user-select-item-info">
                    <img src="${avatarUrl}" alt="${escapeHtml(fullName)}" class="user-select-item-avatar" onerror="this.src='<?php echo $CFG->wwwroot; ?>/pix/u/f1.png'">
                    <div>
                        <p class="user-select-item-name">${escapeHtml(fullName)}</p>
                        <p style="font-size: 0.75rem; color: #64748b; margin: 0;">${escapeHtml(user.email || '')}</p>
                    </div>
                </div>
                ${isSelected ? '<i class="fa-solid fa-check" style="color: #2563eb;"></i>' : ''}
            </div>
        `;
    }).join('');

    container.innerHTML = html;
    renderSelectedSpaceUsersTags();
}

function toggleSpaceUserSelection(userId, userName, userEmail) {
    const user = availableSpaceUsersList.find(u => parseInt(u.id) === parseInt(userId));
    if (!user) return;

    const index = selectedSpaceUsersForAdd.findIndex(su => parseInt(su.id) === parseInt(userId));
    if (index > -1) {
        selectedSpaceUsersForAdd.splice(index, 1);
    } else {
        selectedSpaceUsersForAdd.push({
            id: userId,
            name: userName,
            email: userEmail
        });
    }
    renderAvailableSpaceUsers();
}

function renderSelectedSpaceUsersTags() {
    const container = document.getElementById('selectedSpaceUsersTags');
    
    if (!selectedSpaceUsersForAdd.length) {
        container.innerHTML = '<p style="color: #64748b; font-size: 0.875rem;">No users selected</p>';
        return;
    }

    const html = selectedSpaceUsersForAdd.map(user => `
        <span class="selected-user-tag">
            ${escapeHtml(user.name)}
            <span class="selected-user-tag-remove" onclick="event.stopPropagation(); removeSelectedSpaceUser(${user.id})">×</span>
        </span>
    `).join('');

    container.innerHTML = html;
}

function removeSelectedSpaceUser(userId) {
    selectedSpaceUsersForAdd = selectedSpaceUsersForAdd.filter(u => parseInt(u.id) !== parseInt(userId));
    renderAvailableSpaceUsers();
}

function submitAddSpaceMembers() {
    if (!selectedSpaceUsersForAdd.length) {
        alert('Please select at least one user to add');
        return;
    }

    const memberIds = selectedSpaceUsersForAdd.map(u => u.id);
    const submitBtn = event.target;
    const originalText = submitBtn.textContent;
    submitBtn.disabled = true;
    submitBtn.textContent = 'Adding...';

    const formData = new FormData();
    formData.append('action', 'add_space_members');
    formData.append('spaceid', currentSpaceMembersModalSpaceId);
    formData.append('sesskey', '<?php echo sesskey(); ?>');
    memberIds.forEach(id => {
        formData.append('memberids[]', id);
    });

    fetch(`<?php echo $CFG->wwwroot; ?>/local/communityhub/ajax.php`, {
        method: 'POST',
        body: formData
    })
        .then(response => response.json())
        .then(data => {
            submitBtn.disabled = false;
            submitBtn.textContent = originalText;
            
            if (data.success) {
                closeModal('addSpaceMembersModal');
                loadSpaceMembers(currentSpaceMembersModalSpaceId);
            } else {
                alert('Error: ' + (data.error || 'Failed to add members'));
            }
        })
        .catch(error => {
            console.error('Error adding space members:', error);
            submitBtn.disabled = false;
            submitBtn.textContent = originalText;
            alert('Error adding members. Please try again.');
        });
}

// Close modal when clicking outside
window.onclick = function(event) {
    const modals = document.querySelectorAll('.modal');
    modals.forEach(modal => {
        if (event.target === modal) {
            modal.style.display = 'none';
        }
    });
}
// ========== FILTER FUNCTIONS ==========

function toggleFilterBar() {
    const filterBar = document.getElementById('communityFilterBar');
    if (!filterBar) return;
    
    const isVisible = filterBar.classList.contains('show');
    
    if (!isVisible) {
        // Show filter bar
        filterBar.classList.add('show');
        
        // Load filter options if not loaded
        if (!filterOptionsLoaded && currentCommunityId) {
            loadFilterOptions();
        } else {
            populateInlineFilterOptions();
        }
    } else {
        // Hide filter bar
        filterBar.classList.remove('show');
    }
}

function loadFilterOptions() {
    if (!currentCommunityId) return;
    
    // Get filter options (backend now filters cohorts automatically)
    fetch(`<?php echo $CFG->wwwroot; ?>/local/communityhub/ajax.php?action=get_filter_options&communityid=${currentCommunityId}&sesskey=<?php echo sesskey(); ?>`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.data) {
                window.filterOptionsData = data.data;
                
                // Filter spaces to only show spaces where current user is a member
                if (window.filterOptionsData.spaces && currentUserId) {
                    filterSpacesByUserMembership();
                }
                
                // Cohorts are now filtered by backend - only cohorts where at least one community member is enrolled
                // No need for client-side filtering
                
                filterOptionsLoaded = true;
                populateInlineFilterOptions();
            }
        })
        .catch(error => {
            console.error('Error loading filter options:', error);
        });
}

// Filter spaces to only show spaces where current user is a member
function filterSpacesByUserMembership() {
    if (!window.filterOptionsData.spaces || !currentUserId) {
        return;
    }
    
    // Use currentSpaces which contains spaces the user is enrolled in
    // currentSpaces is populated when renderCommunityDetail is called
    if (currentSpaces && Array.isArray(currentSpaces) && currentSpaces.length > 0) {
        // Filter spaces from filterOptionsData to only include those in currentSpaces
        const allSpaces = window.filterOptionsData.spaces;
        const userSpaces = allSpaces.filter(space => {
            return currentSpaces.some(cs => parseInt(cs.id) === parseInt(space.id));
        });
        
        // Only update if we found matching spaces (user must be enrolled in at least one)
        if (userSpaces.length > 0) {
            window.filterOptionsData.spaces = userSpaces;
        } else {
            // User is not enrolled in any spaces, clear the list
            window.filterOptionsData.spaces = [];
        }
    } else {
        // If currentSpaces is not available, we can't filter - this means user might not be enrolled in any spaces
        // In this case, we should still show spaces but ideally the backend should filter this
        // For now, keep all spaces as fallback
        console.warn('currentSpaces not available, cannot filter spaces by user membership');
    }
}


function populateInlineFilterOptions() {
    if (!window.filterOptionsData) {
        return;
    }
    
    const data = window.filterOptionsData;
    
    // Populate spaces (only spaces where user is a member)
    const spaceSelect = document.getElementById('inlineFilterSpaceSelect');
    if (spaceSelect) {
        spaceSelect.innerHTML = '<option value="0">All Spaces</option>';
        if (data.spaces && Array.isArray(data.spaces)) {
            // Use filtered spaces (already filtered by user membership)
            data.spaces.forEach(space => {
                const option = document.createElement('option');
                option.value = space.id;
                option.textContent = space.name;
                if (currentFilters.spaceids && currentFilters.spaceids.includes(space.id)) {
                    option.selected = true;
                }
                spaceSelect.appendChild(option);
            });
        }
        spaceSelect.onchange = applyInlineFilters;
    }
    
    // Populate users
    const userSelect = document.getElementById('inlineFilterPostedBy');
    if (userSelect) {
        userSelect.innerHTML = '<option value="">All Users</option>';
        if (data.users) {
            data.users.forEach(user => {
                const option = document.createElement('option');
                option.value = user.id;
                option.textContent = user.name;
                if (currentFilters.postedby && parseInt(currentFilters.postedby) === user.id) {
                    option.selected = true;
                }
                userSelect.appendChild(option);
            });
        }
        userSelect.onchange = applyInlineFilters;
    }
    
    // Populate cohorts (filtered by member enrollment)
    const cohortSelect = document.getElementById('inlineFilterCohorts');
    if (cohortSelect) {
        cohortSelect.innerHTML = '<option value="">All Cohorts</option>';
        if (data.cohorts && Array.isArray(data.cohorts)) {
            data.cohorts.forEach(cohort => {
                const option = document.createElement('option');
                option.value = cohort.id;
                option.textContent = cohort.name;
                if (currentFilters.cohorts && currentFilters.cohorts.includes(cohort.id)) {
                    option.selected = true;
                }
                cohortSelect.appendChild(option);
            });
        }
        cohortSelect.onchange = applyInlineFilters;
    }
    
    // Set date filters
    const fromDateInput = document.getElementById('inlineFilterFromDate');
    if (fromDateInput) {
        if (currentFilters.fromdate) {
            fromDateInput.value = currentFilters.fromdate;
        }
        fromDateInput.onchange = applyInlineFilters;
    }
    
    const toDateInput = document.getElementById('inlineFilterToDate');
    if (toDateInput) {
        if (currentFilters.todate) {
            toDateInput.value = currentFilters.todate;
        }
        toDateInput.onchange = applyInlineFilters;
    }
    
    // Set sort by
    const sortSelect = document.getElementById('inlineFilterSortBy');
    if (sortSelect) {
        if (currentFilters.sortby) {
            sortSelect.value = currentFilters.sortby;
        }
        sortSelect.onchange = applyInlineFilters;
    }
    
    // Set liked only checkbox
    const likedOnlyCheckbox = document.getElementById('inlineFilterLikedOnly');
    if (likedOnlyCheckbox) {
        likedOnlyCheckbox.checked = currentFilters.likedonly || false;
        likedOnlyCheckbox.onchange = applyInlineFilters;
    }
    
    // Set saved only checkbox
    const savedOnlyCheckbox = document.getElementById('inlineFilterSavedOnly');
    if (savedOnlyCheckbox) {
        savedOnlyCheckbox.checked = currentFilters.savedonly || false;
        savedOnlyCheckbox.onchange = applyInlineFilters;
    }
}

function applyInlineFilters() {
    const filters = {};
    
    // Get space filter (single select)
    const spaceSelect = document.getElementById('inlineFilterSpaceSelect');
    if (spaceSelect && spaceSelect.value && spaceSelect.value !== '0') {
        filters.spaceids = [parseInt(spaceSelect.value)];
        // When a space is selected, exclude community-level posts (spaceid = 0)
        filters.exclude_community_posts = true;
    } else {
        // When "All Spaces" is selected, show all posts including community-level
        filters.exclude_community_posts = false;
    }
    
    // Get date filters
    const fromDate = document.getElementById('inlineFilterFromDate');
    if (fromDate && fromDate.value) {
        filters.fromdate = fromDate.value;
    }
    
    const toDate = document.getElementById('inlineFilterToDate');
    if (toDate && toDate.value) {
        filters.todate = toDate.value;
    }
    
    // Get posted by (single select)
    const postedBy = document.getElementById('inlineFilterPostedBy');
    if (postedBy && postedBy.value) {
        filters.postedby = parseInt(postedBy.value);
    }
    
    // Get cohort (single select)
    const cohortSelect = document.getElementById('inlineFilterCohorts');
    if (cohortSelect && cohortSelect.value) {
        filters.cohorts = [parseInt(cohortSelect.value)];
    }
    
    // Get sort by
    const sortBy = document.getElementById('inlineFilterSortBy');
    if (sortBy && sortBy.value) {
        filters.sortby = sortBy.value;
    }
    
    // Get liked only checkbox
    const likedOnlyCheckbox = document.getElementById('inlineFilterLikedOnly');
    if (likedOnlyCheckbox && likedOnlyCheckbox.checked) {
        filters.likedonly = true;
    }
    
    // Get saved only checkbox
    const savedOnlyCheckbox = document.getElementById('inlineFilterSavedOnly');
    if (savedOnlyCheckbox && savedOnlyCheckbox.checked) {
        filters.savedonly = true;
    }
    
    currentFilters = filters;
    
    // Reset pagination and reload posts
    currentPostPage = 0;
    loadPosts(currentCommunityId, 0, true);
}

function clearInlineFilters() {
    currentFilters = {};
    
    const spaceSelect = document.getElementById('inlineFilterSpaceSelect');
    if (spaceSelect) spaceSelect.value = '0';
    
    const fromDate = document.getElementById('inlineFilterFromDate');
    if (fromDate) fromDate.value = '';
    
    const toDate = document.getElementById('inlineFilterToDate');
    if (toDate) toDate.value = '';
    
    const postedBy = document.getElementById('inlineFilterPostedBy');
    if (postedBy) postedBy.value = '';
    
    const cohortSelect = document.getElementById('inlineFilterCohorts');
    if (cohortSelect) cohortSelect.value = '';
    
    const sortSelect = document.getElementById('inlineFilterSortBy');
    if (sortSelect) sortSelect.value = 'newest';
    
    const likedOnlyCheckbox = document.getElementById('inlineFilterLikedOnly');
    if (likedOnlyCheckbox) likedOnlyCheckbox.checked = false;
    
    const savedOnlyCheckbox = document.getElementById('inlineFilterSavedOnly');
    if (savedOnlyCheckbox) savedOnlyCheckbox.checked = false;
    
    // Reload posts without filters
    currentPostPage = 0;
    loadPosts(currentCommunityId, 0, true);
}

// Legacy functions for modal (keep for backward compatibility)
function applyFilters() {
    applyInlineFilters();
}

function clearFilters() {
    clearInlineFilters();
}

// ========== VIEW ALL SPACES MODAL FUNCTIONS ==========
let currentSpacesPage = 0;
let totalSpaces = 0;
let spacesPerPage = 5;

function viewAllSpaces() {
    if (!currentCommunityId) {
        alert('Please select a community first.');
        return;
    }
    
    currentSpacesPage = 0;
    openModal('viewAllSpacesModal');
    loadAllSpaces(currentCommunityId, 0);
}

function loadAllSpaces(communityId, page) {
    if (!communityId) return;
    
    const spacesListEl = document.getElementById('allSpacesList');
    if (spacesListEl) {
        spacesListEl.innerHTML = '<div style="text-align: center; padding: 40px; color: #64748b;"><i class="fa-solid fa-spinner fa-spin" style="font-size: 24px; margin-bottom: 12px;"></i><p>Loading spaces...</p></div>';
    }
    
    fetchCommunityHubJSON(`<?php echo $CFG->wwwroot; ?>/local/communityhub/ajax.php?action=get_spaces&communityid=${communityId}&page=${page}&perpage=${spacesPerPage}&sesskey=<?php echo sesskey(); ?>`)
        .then(data => {
            if (data.success && data.data) {
                const spaces = data.data.spaces || [];
                const pagination = data.data.pagination || {};
                
                totalSpaces = pagination.total || 0;
                currentSpacesPage = page;
                
                if (spaces.length === 0) {
                    spacesListEl.innerHTML = '<div class="empty-state"><p class="empty-state-text">No spaces found</p></div>';
                    renderAllSpacesPagination(pagination);
                    return;
                }
                
                renderAllSpacesInModal(spaces);
                renderAllSpacesPagination(pagination);
            } else {
                spacesListEl.innerHTML = '<div class="empty-state"><p class="empty-state-text">Error loading spaces</p></div>';
            }
        })
        .catch(error => {
            console.error('Error loading spaces:', error);
            spacesListEl.innerHTML = '<div class="empty-state"><p class="empty-state-text">Error loading spaces. Please try again.</p></div>';
        });
}

function renderAllSpacesInModal(spaces) {
    const spacesListEl = document.getElementById('allSpacesList');
    if (!spacesListEl) return;
    
    if (spaces.length === 0) {
        spacesListEl.innerHTML = '<div class="empty-state"><p class="empty-state-text">No spaces found</p></div>';
        return;
    }
    
    let html = '';
    spaces.forEach(space => {
        html += `
            <div class="space-item" onclick="filterBySpace(${space.id}); closeModal('viewAllSpacesModal');">
                <div class="space-item-icon" style="background: ${space.color || '#e5e7eb'}; color: white;">
                    <i class="${space.icon || 'fa-solid fa-users'}"></i>
                </div>
                <div class="space-item-content">
                    <h4 class="space-item-title">${escapeHtml(space.name)}</h4>
                    <p class="space-item-meta">${space.postcount || 0} posts • ${space.membercount || 0} members</p>
                </div>
                <div style="display: flex; gap: 8px; align-items: center;">
                    <button class="space-members-btn" onclick="event.stopPropagation(); openSpaceMembersModal(${space.id}, '${escapeHtml(space.name)}')" title="Manage Members">
                        <i class="fa-solid fa-users"></i>
                    </button>
                    <button class="space-chat-btn" onclick="event.stopPropagation(); openSpaceChatPage(${space.id})" title="Open Space Chat">
                        <i class="fa-solid fa-comments"></i>
                    </button>
                </div>
            </div>
        `;
    });
    
    spacesListEl.innerHTML = html;
}

function renderAllSpacesPagination(pagination) {
    const paginationEl = document.getElementById('allSpacesPagination');
    if (!paginationEl) return;
    
    if (!pagination || pagination.totalpages <= 1) {
        paginationEl.innerHTML = '';
        return;
    }
    
    const totalpages = pagination.totalpages || 1;
    const currentPage = pagination.page || 0;
    const hasNext = pagination.hasnext || false;
    const hasPrev = pagination.hasprev || false;
    
    let html = '';
    
    // Previous button
    html += `
        <button class="pagination-btn" ${!hasPrev ? 'disabled' : ''} onclick="changeAllSpacesPage(${currentPage - 1})">
            <i class="fa-solid fa-chevron-left"></i> Previous
        </button>
    `;
    
    // Page numbers (show up to 5 page numbers)
    const maxVisiblePages = 5;
    let startPage = Math.max(0, currentPage - Math.floor(maxVisiblePages / 2));
    let endPage = Math.min(totalpages - 1, startPage + maxVisiblePages - 1);
    
    if (endPage - startPage < maxVisiblePages - 1) {
        startPage = Math.max(0, endPage - maxVisiblePages + 1);
    }
    
    if (startPage > 0) {
        html += `<button class="pagination-btn" onclick="changeAllSpacesPage(0)">1</button>`;
        if (startPage > 1) {
            html += `<span class="pagination-info">...</span>`;
        }
    }
    
    for (let i = startPage; i <= endPage; i++) {
        html += `
            <button class="pagination-btn ${i === currentPage ? 'active' : ''}" onclick="changeAllSpacesPage(${i})">
                ${i + 1}
            </button>
        `;
    }
    
    if (endPage < totalpages - 1) {
        if (endPage < totalpages - 2) {
            html += `<span class="pagination-info">...</span>`;
        }
        html += `<button class="pagination-btn" onclick="changeAllSpacesPage(${totalpages - 1})">${totalpages}</button>`;
    }
    
    // Next button
    html += `
        <button class="pagination-btn" ${!hasNext ? 'disabled' : ''} onclick="changeAllSpacesPage(${currentPage + 1})">
            Next <i class="fa-solid fa-chevron-right"></i>
        </button>
    `;
    
    // Page info
    const startItem = currentPage * spacesPerPage + 1;
    const endItem = Math.min((currentPage + 1) * spacesPerPage, totalSpaces);
    html += `<span class="pagination-info">Showing ${startItem}-${endItem} of ${totalSpaces} spaces</span>`;
    
    paginationEl.innerHTML = html;
}

function changeAllSpacesPage(page) {
    if (page < 0 || !currentCommunityId) return;
    loadAllSpaces(currentCommunityId, page);
    // Scroll to top of modal content
    const spacesListEl = document.getElementById('allSpacesList');
    if (spacesListEl) {
        spacesListEl.scrollTop = 0;
    }
}

// ========== VIEW ALL EVENTS MODAL FUNCTIONS ==========
let currentEventsPage = 0;
let totalEvents = 0;
let eventsPerPage = 5;

function viewAllEvents() {
    if (!currentCommunityId) {
        alert('Please select a community first.');
        return;
    }
    
    currentEventsPage = 0;
    openModal('viewAllEventsModal');
    loadAllEvents(currentCommunityId, 0);
}

function loadAllEvents(communityId, page) {
    if (!communityId) return;
    
    const eventsListEl = document.getElementById('allEventsList');
    if (eventsListEl) {
        eventsListEl.innerHTML = '<div style="text-align: center; padding: 40px; color: #64748b;"><i class="fa-solid fa-spinner fa-spin" style="font-size: 24px; margin-bottom: 12px;"></i><p>Loading events...</p></div>';
    }
    
    fetchCommunityHubJSON(`<?php echo $CFG->wwwroot; ?>/local/communityhub/ajax.php?action=get_events&communityid=${communityId}&page=${page}&perpage=${eventsPerPage}&sesskey=<?php echo sesskey(); ?>`)
        .then(data => {
            if (data.success && data.data) {
                const events = data.data.events || [];
                const pagination = data.data.pagination || {};
                
                totalEvents = pagination.total || 0;
                currentEventsPage = page;
                
                if (events.length === 0) {
                    eventsListEl.innerHTML = '<div class="empty-state"><p class="empty-state-text">No events found</p></div>';
                    renderAllEventsPagination(pagination);
                    return;
                }
                
                renderAllEventsInModal(events);
                renderAllEventsPagination(pagination);
            } else {
                eventsListEl.innerHTML = '<div class="empty-state"><p class="empty-state-text">Error loading events</p></div>';
            }
        })
        .catch(error => {
            console.error('Error loading events:', error);
            eventsListEl.innerHTML = '<div class="empty-state"><p class="empty-state-text">Error loading events. Please try again.</p></div>';
        });
}

// Moderation Panel Functions
let currentModerationPage = 0;
const moderationPerPage = 10;

let currentModerationTab = 'flagged';

function openModerationPanel() {
    currentModerationPage = 0;
    currentModerationTab = 'flagged';
    openModal('moderationPanelModal');
    switchModerationTab('flagged');
}

function switchModerationTab(tab) {
    currentModerationTab = tab;
    
    // Update tab buttons
    const flaggedBtn = document.getElementById('moderationTabFlagged');
    const reportedBtn = document.getElementById('moderationTabReported');
    const flaggedList = document.getElementById('flaggedPostsList');
    const reportedList = document.getElementById('reportedPostsList');
    
    if (tab === 'flagged') {
        flaggedBtn.classList.add('active');
        flaggedBtn.style.borderBottomColor = '#3b82f6';
        flaggedBtn.style.fontWeight = '600';
        flaggedBtn.style.color = '#3b82f6';
        reportedBtn.classList.remove('active');
        reportedBtn.style.borderBottomColor = 'transparent';
        reportedBtn.style.fontWeight = '500';
        reportedBtn.style.color = '#64748b';
        
        flaggedList.style.display = 'block';
        reportedList.style.display = 'none';
        
        if (flaggedList.innerHTML.includes('Loading') || flaggedList.innerHTML.trim() === '') {
            loadFlaggedPosts(0);
        }
    } else {
        reportedBtn.classList.add('active');
        reportedBtn.style.borderBottomColor = '#3b82f6';
        reportedBtn.style.fontWeight = '600';
        reportedBtn.style.color = '#3b82f6';
        flaggedBtn.classList.remove('active');
        flaggedBtn.style.borderBottomColor = 'transparent';
        flaggedBtn.style.fontWeight = '500';
        flaggedBtn.style.color = '#64748b';
        
        flaggedList.style.display = 'none';
        reportedList.style.display = 'block';
        
        if (reportedList.innerHTML.includes('Loading') || reportedList.innerHTML.trim() === '') {
            loadReportedPosts(0);
        }
    }
}

function loadFlaggedPosts(page) {
    const flaggedListEl = document.getElementById('flaggedPostsList');
    if (flaggedListEl) {
        flaggedListEl.innerHTML = '<div style="text-align: center; padding: 40px; color: #64748b;"><i class="fa-solid fa-spinner fa-spin" style="font-size: 24px; margin-bottom: 12px;"></i><p>Loading flagged posts...</p></div>';
    }
    
    fetchCommunityHubJSON(`<?php echo $CFG->wwwroot; ?>/local/communityhub/ajax.php?action=get_flagged_posts&page=${page}&perpage=${moderationPerPage}&sesskey=<?php echo sesskey(); ?>`)
        .then(data => {
            if (data.success && data.data) {
                const posts = data.data.posts || [];
                const total = data.data.total || 0;
                
                currentModerationPage = page;
                
                if (posts.length === 0) {
                    flaggedListEl.innerHTML = '<div class="empty-state"><p class="empty-state-text">No flagged posts pending review</p></div>';
                    return;
                }
                
                renderFlaggedPosts(posts, total);
            } else {
                flaggedListEl.innerHTML = '<div class="empty-state"><p class="empty-state-text">Error loading flagged posts</p></div>';
            }
        })
        .catch(error => {
            console.error('Error loading flagged posts:', error);
            flaggedListEl.innerHTML = '<div class="empty-state"><p class="empty-state-text">Error loading flagged posts. Please try again.</p></div>';
        });
}

function renderFlaggedPosts(posts, total) {
    const flaggedListEl = document.getElementById('flaggedPostsList');
    if (!flaggedListEl) return;
    
    let html = `<div style="margin-bottom: 20px; padding: 12px 16px; background: #f8f9fa; border-radius: 8px; border: 1px solid #e5e7eb;">
        <strong style="color: #374151; font-size: 14px;">${total} AI-flagged post${total !== 1 ? 's' : ''} pending review</strong>
    </div>`;
    
    if (posts.length === 0) {
        html = '<div class="empty-state"><p class="empty-state-text">No AI-flagged posts pending review</p></div>';
        flaggedListEl.innerHTML = html;
        return;
    }
    
    posts.forEach(post => {
        const date = new Date(post.timecreated * 1000);
        const dateStr = date.toLocaleDateString() + ' ' + date.toLocaleTimeString();
        
        html += `
            <div class="post-card" style="margin-bottom: 20px; border: 1px solid #e5e7eb; background: #ffffff; border-radius: 8px; overflow: hidden;">
                <div class="post-header" style="padding: 16px; border-bottom: 1px solid #f3f4f6;">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <img src="${post.authoravatar}" alt="${escapeHtml(post.author)}" class="user-avatar" onerror="this.src='<?php echo $CFG->wwwroot; ?>/pix/u/f1.png'" style="width: 40px; height: 40px; border-radius: 50%;">
                        <div>
                            <h4 style="margin: 0; font-size: 15px; font-weight: 600; color: #111827;">${escapeHtml(post.author)}</h4>
                            <p style="margin: 0; font-size: 0.875rem; color: #6b7280;">${dateStr} • ${escapeHtml(post.communityname)}</p>
                        </div>
                    </div>
                </div>
                <div class="post-content" style="padding: 16px;">
                    ${post.subject ? `<h3 class="post-title" style="margin: 0 0 12px 0; font-size: 16px; font-weight: 600; color: #111827;">${escapeHtml(post.subject)}</h3>` : ''}
                    <div class="post-message" style="color: #374151; line-height: 1.6;">${post.message}</div>
                </div>
                <div style="padding: 12px 16px; background: #f9fafb; border-top: 1px solid #e5e7eb;">
                    <p style="margin: 0 0 8px 0; color: #374151; font-size: 13px;"><strong style="color: #6b7280;">Flag Reason:</strong> ${escapeHtml(post.flag_reason || 'Content flagged by AI moderation system')}</p>
                    <p style="margin: 0; color: #374151; font-size: 13px;"><strong style="color: #6b7280;">User Reports:</strong> ${post.report_count || 0}</p>
                </div>
                <div style="padding: 12px 16px; display: flex; gap: 8px; border-top: 1px solid #e5e7eb; background: #ffffff; justify-content: flex-end;">
                    <button class="btn btn-primary" onclick="approveFlaggedPost(${post.id})" style="width: 120px; background: #ef4444; color: white; border: none; padding: 10px; border-radius: 6px; cursor: pointer; font-weight: 500; display: flex; align-items: center; justify-content: center; gap: 6px;">
                        <i class="fa-solid fa-check"></i> <span>Approve</span>
                    </button>
                    <button class="btn" onclick="denyFlaggedPost(${post.id})" style="width: 120px; background: #3b82f6; color: white; border: none; padding: 10px; border-radius: 6px; cursor: pointer; font-weight: 500; display: flex; align-items: center; justify-content: center; gap: 6px;">
                        <i class="fa-solid fa-times"></i> <span>Deny</span>
                    </button>
                    <button class="btn" onclick="deletePostFromModeration(${post.id})" style="width: 120px; background: #dc2626; color: white; border: none; padding: 10px; border-radius: 6px; cursor: pointer; font-weight: 500; display: flex; align-items: center; justify-content: center; gap: 6px;">
                        <i class="fa-solid fa-trash"></i> <span>Delete</span>
                    </button>
                </div>
            </div>
        `;
    });
    
    // Pagination
    const totalPages = Math.ceil(total / moderationPerPage);
    if (totalPages > 1) {
        html += `<div style="display: flex; justify-content: center; gap: 8px; margin-top: 20px; padding-top: 20px; border-top: 1px solid #e5e7eb;">`;
        if (currentModerationPage > 0) {
            html += `<button class="btn btn-secondary" onclick="loadFlaggedPosts(${currentModerationPage - 1})" style="background: #ffffff; border: 1px solid #d1d5db; color: #374151; padding: 8px 16px; border-radius: 6px; cursor: pointer;">Previous</button>`;
        }
        html += `<span style="padding: 8px 16px; display: flex; align-items: center; color: #6b7280; font-size: 14px;">Page ${currentModerationPage + 1} of ${totalPages}</span>`;
        if (currentModerationPage < totalPages - 1) {
            html += `<button class="btn btn-secondary" onclick="loadFlaggedPosts(${currentModerationPage + 1})" style="background: #ffffff; border: 1px solid #d1d5db; color: #374151; padding: 8px 16px; border-radius: 6px; cursor: pointer;">Next</button>`;
        }
        html += `</div>`;
    }
    
    flaggedListEl.innerHTML = html;
}

// Load reported posts (posts with report_count > 0)
let currentReportedPage = 0;

function loadReportedPosts(page) {
    const reportedListEl = document.getElementById('reportedPostsList');
    if (reportedListEl) {
        reportedListEl.innerHTML = '<div style="text-align: center; padding: 40px; color: #64748b;"><i class="fa-solid fa-spinner fa-spin" style="font-size: 24px; margin-bottom: 12px;"></i><p>Loading reported posts...</p></div>';
    }
    
    fetchCommunityHubJSON(`<?php echo $CFG->wwwroot; ?>/local/communityhub/ajax.php?action=get_reported_posts&page=${page}&perpage=${moderationPerPage}&sesskey=<?php echo sesskey(); ?>`)
        .then(data => {
            if (data.success && data.data) {
                const posts = data.data.posts || [];
                const total = data.data.total || 0;
                
                currentReportedPage = page;
                
                if (posts.length === 0) {
                    reportedListEl.innerHTML = '<div class="empty-state"><p class="empty-state-text">No reported posts pending review</p></div>';
                    return;
                }
                
                renderReportedPosts(posts, total);
            } else {
                reportedListEl.innerHTML = '<div class="empty-state"><p class="empty-state-text">Error loading reported posts</p></div>';
            }
        })
        .catch(error => {
            console.error('Error loading reported posts:', error);
            reportedListEl.innerHTML = '<div class="empty-state"><p class="empty-state-text">Error loading reported posts. Please try again.</p></div>';
        });
}

function renderReportedPosts(posts, total) {
    const reportedListEl = document.getElementById('reportedPostsList');
    if (!reportedListEl) return;
    
    let html = `<div style="margin-bottom: 20px; padding: 12px 16px; background: #f8f9fa; border-radius: 8px; border: 1px solid #e5e7eb;">
        <strong style="color: #374151; font-size: 14px;">${total} reported post${total !== 1 ? 's' : ''} pending review</strong>
    </div>`;
    
    posts.forEach(post => {
        const date = new Date(post.timecreated * 1000);
        const dateStr = date.toLocaleDateString() + ' ' + date.toLocaleTimeString();
        
        html += `
            <div class="post-card" style="margin-bottom: 20px; border: 1px solid #e5e7eb; background: #ffffff; border-radius: 8px; overflow: hidden;">
                <div class="post-header" style="padding: 16px; border-bottom: 1px solid #f3f4f6;">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <img src="${post.authoravatar}" alt="${escapeHtml(post.author)}" class="user-avatar" onerror="this.src='<?php echo $CFG->wwwroot; ?>/pix/u/f1.png'" style="width: 40px; height: 40px; border-radius: 50%;">
                        <div>
                            <h4 style="margin: 0; font-size: 15px; font-weight: 600; color: #111827;">${escapeHtml(post.author)}</h4>
                            <p style="margin: 0; font-size: 0.875rem; color: #6b7280;">${dateStr} • ${escapeHtml(post.communityname)}</p>
                        </div>
                    </div>
                </div>
                <div class="post-content" style="padding: 16px;">
                    ${post.subject ? `<h3 class="post-title" style="margin: 0 0 12px 0; font-size: 16px; font-weight: 600; color: #111827;">${escapeHtml(post.subject)}</h3>` : ''}
                    <div class="post-message" style="color: #374151; line-height: 1.6;">${post.message}</div>
                </div>
                <div style="padding: 12px 16px; background: #f9fafb; border-top: 1px solid #e5e7eb;">
                    <p style="margin: 0 0 12px 0; color: #374151; font-size: 13px;"><strong style="color: #6b7280;">User Reports:</strong> ${post.report_count || 0}</p>
                    ${post.reports && post.reports.length > 0 ? `
                        <div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #e5e7eb;">
                            <strong style="color: #6b7280; font-size: 13px; display: block; margin-bottom: 8px;">Report Details:</strong>
                            ${post.reports.map((report, idx) => {
                                const reportDate = new Date(report.timecreated * 1000);
                                const reportDateStr = reportDate.toLocaleDateString() + ' ' + reportDate.toLocaleTimeString();
                                return `
                                    <div style="margin-bottom: ${idx < post.reports.length - 1 ? '12px' : '0'}; padding: 10px; background: #ffffff; border: 1px solid #e5e7eb; border-radius: 6px;">
                                        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 6px;">
                                            <strong style="color: #374151; font-size: 12px;">${escapeHtml(report.reporter)}</strong>
                                            <span style="color: #9ca3af; font-size: 11px;">${reportDateStr}</span>
                                        </div>
                                        <p style="margin: 0; color: #6b7280; font-size: 12px; line-height: 1.5;">${escapeHtml(report.reason || 'No reason provided')}</p>
                                    </div>
                                `;
                            }).join('')}
                        </div>
                    ` : ''}
                </div>
                <div style="padding: 12px 16px; display: flex; gap: 8px; border-top: 1px solid #e5e7eb; background: #ffffff; justify-content: flex-end;">
                    <button class="btn" onclick="flagReportedPost(${post.id})" style="width: 120px; background: #ef4444; color: white; border: none; padding: 10px; border-radius: 6px; cursor: pointer; font-weight: 500; display: flex; align-items: center; justify-content: center; gap: 6px;">
                        <i class="fa-solid fa-flag"></i> <span>Flag</span>
                    </button>
                    <button class="btn btn-primary" onclick="denyReports(${post.id})" style="width: 140px; background: #3b82f6; color: white; border: none; padding: 10px; border-radius: 6px; cursor: pointer; font-weight: 500; display: flex; align-items: center; justify-content: center; gap: 6px;">
                        <i class="fa-solid fa-xmark"></i> <span>Deny Reports</span>
                    </button>
                    <button class="btn" onclick="deletePostFromModeration(${post.id})" style="width: 120px; background: #dc2626; color: white; border: none; padding: 10px; border-radius: 6px; cursor: pointer; font-weight: 500; display: flex; align-items: center; justify-content: center; gap: 6px;">
                        <i class="fa-solid fa-trash"></i> <span>Delete</span>
                    </button>
                </div>
            </div>
        `;
    });
    
    // Pagination
    const totalPages = Math.ceil(total / moderationPerPage);
    if (totalPages > 1) {
        html += `<div style="display: flex; justify-content: center; gap: 8px; margin-top: 20px; padding-top: 20px; border-top: 1px solid #e5e7eb;">`;
        if (currentReportedPage > 0) {
            html += `<button class="btn btn-secondary" onclick="loadReportedPosts(${currentReportedPage - 1})" style="background: #ffffff; border: 1px solid #d1d5db; color: #374151; padding: 8px 16px; border-radius: 6px; cursor: pointer;">Previous</button>`;
        }
        html += `<span style="padding: 8px 16px; display: flex; align-items: center; color: #6b7280; font-size: 14px;">Page ${currentReportedPage + 1} of ${totalPages}</span>`;
        if (currentReportedPage < totalPages - 1) {
            html += `<button class="btn btn-secondary" onclick="loadReportedPosts(${currentReportedPage + 1})" style="background: #ffffff; border: 1px solid #d1d5db; color: #374151; padding: 8px 16px; border-radius: 6px; cursor: pointer;">Next</button>`;
        }
        html += `</div>`;
    }
    
    reportedListEl.innerHTML = html;
}

// Flag a reported post (agree with reports, hide the post)
function flagReportedPost(postId) {
    RemuiAlert.confirm(
        'Flag Post',
        'Flag this post? It will be hidden from the timeline and marked as flagged.',
        () => {
            flagReportedPostConfirmed(postId);
        }
    );
}

function flagReportedPostConfirmed(postId) {
    fetch('<?php echo $CFG->wwwroot; ?>/local/communityhub/ajax.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=flag_reported_post&postid=${postId}&sesskey=<?php echo sesskey(); ?>`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Store success message and panel state for after reload
            sessionStorage.setItem('moderationSuccess', 'Post flagged successfully');
            sessionStorage.setItem('reopenModerationPanel', 'true');
            sessionStorage.setItem('moderationTab', 'reported');
            // Reload the page
            window.location.reload();
        } else {
            RemuiAlert.error(data.error || 'Failed to flag post');
        }
    })
    .catch(error => {
        console.error('Error flagging post:', error);
        RemuiAlert.error('Failed to flag post. Please try again.');
    });
}

// Deny reports (disagree with reports, clear them, keep post visible)
function denyReports(postId) {
    RemuiAlert.confirm(
        'Deny Reports',
        'Dismiss these reports? The post will remain visible and all reports will be cleared.',
        () => {
            denyReportsConfirmed(postId);
        }
    );
}

function denyReportsConfirmed(postId) {
    fetch('<?php echo $CFG->wwwroot; ?>/local/communityhub/ajax.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=deny_reports&postid=${postId}&sesskey=<?php echo sesskey(); ?>`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Store success message and panel state for after reload
            sessionStorage.setItem('moderationSuccess', 'Reports dismissed successfully');
            sessionStorage.setItem('reopenModerationPanel', 'true');
            sessionStorage.setItem('moderationTab', 'reported');
            // Reload the page
            window.location.reload();
        } else {
            RemuiAlert.error(data.error || 'Failed to dismiss reports');
        }
    })
    .catch(error => {
        console.error('Error dismissing reports:', error);
        RemuiAlert.error('Failed to dismiss reports. Please try again.');
    });
}

function approveFlaggedPost(postId) {
    RemuiAlert.confirm(
        'Approve Post',
        'Approve this post? It will be visible to everyone again.',
        () => {
            approveFlaggedPostConfirmed(postId);
        }
    );
}

function approveFlaggedPostConfirmed(postId) {
    fetch('<?php echo $CFG->wwwroot; ?>/local/communityhub/ajax.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=approve_post&postid=${postId}&sesskey=<?php echo sesskey(); ?>`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Store success message and panel state for after reload
            sessionStorage.setItem('moderationSuccess', 'Post approved successfully');
            sessionStorage.setItem('reopenModerationPanel', 'true');
            sessionStorage.setItem('moderationTab', 'flagged');
            // Reload the page
            window.location.reload();
        } else {
            RemuiAlert.error(data.error || 'Failed to approve post');
        }
    })
    .catch(error => {
        console.error('Error approving post:', error);
        RemuiAlert.error('Failed to approve post. Please try again.');
    });
}

function denyFlaggedPost(postId) {
    RemuiAlert.confirm(
        'Deny Post',
        'Deny this post? It will remain hidden from the timeline.',
        () => {
            denyFlaggedPostConfirmed(postId);
        }
    );
}

function denyFlaggedPostConfirmed(postId) {
    fetch('<?php echo $CFG->wwwroot; ?>/local/communityhub/ajax.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=deny_post&postid=${postId}&sesskey=<?php echo sesskey(); ?>`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Store success message and panel state for after reload
            sessionStorage.setItem('moderationSuccess', 'Post denied successfully');
            sessionStorage.setItem('reopenModerationPanel', 'true');
            sessionStorage.setItem('moderationTab', 'flagged');
            // Reload the page
            window.location.reload();
        } else {
            RemuiAlert.error(data.error || 'Failed to deny post');
        }
    })
    .catch(error => {
        console.error('Error denying post:', error);
        RemuiAlert.error('Failed to deny post. Please try again.');
    });
}

// Delete post from moderation panel
function deletePostFromModeration(postId) {
    RemuiAlert.confirm(
        'Delete Post',
        'Are you sure you want to permanently delete this post? This action cannot be undone.',
        () => {
            deletePostFromModerationConfirmed(postId);
        }
    );
}

function deletePostFromModerationConfirmed(postId) {
    fetch('<?php echo $CFG->wwwroot; ?>/local/communityhub/ajax.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=delete_post&postid=${postId}&sesskey=<?php echo sesskey(); ?>`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Store success message and panel state for after reload
            sessionStorage.setItem('moderationSuccess', 'Post deleted successfully');
            sessionStorage.setItem('reopenModerationPanel', 'true');
            sessionStorage.setItem('moderationTab', currentModerationTab || 'flagged');
            // Reload the page
            window.location.reload();
        } else {
            RemuiAlert.error(data.error || 'Failed to delete post');
        }
    })
    .catch(error => {
        console.error('Error deleting post:', error);
        RemuiAlert.error('Failed to delete post. Please try again.');
    });
}

// Quick moderation function - opens a small modal for quick approve/deny
let currentQuickModerationPostId = null;

function openQuickModeration(postId) {
    currentQuickModerationPostId = postId;
    
    // Find the post in current posts data
    const post = currentPostsData.find(p => p.id === parseInt(postId));
    if (!post) {
        alert('Post not found');
        return;
    }
    
    // Get post details
    const postTitle = post.subject ? post.subject.trim() : '';
    const postMessage = post.message ? post.message.replace(/<[^>]*>/g, '').trim() : '';
    const flagReason = post.flag_reason && post.flag_reason.trim() ? post.flag_reason.trim() : 'This post has been flagged for containing inappropriate content.';
    
    // Set modal content
    document.getElementById('quickModPostId').value = postId;
    document.getElementById('quickModPostTitle').textContent = postTitle || 'Post Content';
    document.getElementById('quickModPostMessage').textContent = postMessage;
    document.getElementById('quickModFlagReasonText').textContent = flagReason;
    
    openModal('quickModerationModal');
}

// Approve/Deny functions for timeline posts (same as moderation panel but also refreshes timeline)
function approveFlaggedPost(postId) {
    if (!confirm('Approve this post? It will be visible to everyone again.')) {
        return;
    }
    
    fetch('<?php echo $CFG->wwwroot; ?>/local/communityhub/ajax.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=approve_post&postid=${postId}&sesskey=<?php echo sesskey(); ?>`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Refresh moderation panel if open
            const modModal = document.getElementById('moderationPanelModal');
            if (modModal && modModal.style.display !== 'none') {
                if (currentModerationTab === 'flagged') {
                    loadFlaggedPosts(currentModerationPage);
                } else {
                    loadReportedPosts(currentReportedPage);
                }
            }
            // Refresh timeline posts
            loadPosts(currentCommunityId, currentPostPage, true);
        } else {
            alert('Error: ' + (data.error || 'Failed to approve post'));
        }
    })
    .catch(error => {
        console.error('Error approving post:', error);
        alert('Failed to approve post. Please try again.');
    });
}

function renderAllEventsInModal(events) {
    const eventsListEl = document.getElementById('allEventsList');
    if (!eventsListEl) return;
    
    if (events.length === 0) {
        eventsListEl.innerHTML = '<div class="empty-state"><p class="empty-state-text">No events found</p></div>';
        return;
    }
    
    let html = '';
    const nowSeconds = Date.now() / 1000;
    events.forEach(event => {
        const startDate = new Date(event.starttime * 1000);
        const month = startDate.toLocaleString('default', { month: 'short' }).toUpperCase();
        const day = startDate.getDate();
        const timeStr = formatTime(event.starttime);
        const endTimeStr = event.endtime ? ' - ' + formatTime(event.endtime) : '';
        const description = stripHtml(event.description || '');
        const isPast = event.starttime < nowSeconds;
        const statusBadge = isPast ? '<span class="event-badge event-badge-past">Past</span>' : '';
        
        // Store event in currentEvents for editing if not already there
        if (!currentEvents.find(e => e.id === event.id)) {
            currentEvents.push(event);
        }
        
        html += `
            <div class="event-item" style="position: relative;">
                <div style="display: flex;">
                    <div class="event-date" style="background: #dbeafe; color: #1e40af;">
                        <div class="event-date-month">${month}</div>
                        <div class="event-date-day">${day}</div>
                    </div>
                    <div class="event-content" style="flex: 1;">
                        <h4 class="event-title">${escapeHtml(event.title)}</h4> 
                        <p class="event-meta">${timeStr}${endTimeStr} • ${event.location || 'Virtual'}</p>
                        ${description ? `<p class="event-meta" style="white-space: pre-wrap;">${escapeHtml(description)}</p>` : ''}
                        <div class="event-badges">
                            <span class="event-badge" style="background: #dbeafe; color: #1e40af;">${event.eventtypelabel}</span>
                            ${statusBadge}
                        </div>
                    </div>
                    ${canModerate ? `
                    <button class="event-edit-btn" onclick="openEditEventModal(${event.id})" title="Edit Event" style="position: absolute; top: 8px; right: 8px; width: 28px; height: 28px; border-radius: 6px; border: none; background: #fef3c7; color: #b45309; cursor: pointer; display: flex; align-items: center; justify-content: center;">
                        <i class="fa-solid fa-pen" style="font-size: 0.75rem;"></i>
                    </button>
                    ` : ''}
                </div>
            </div>
        `;
    });
    
    eventsListEl.innerHTML = html;
}

function renderAllEventsPagination(pagination) {
    const paginationEl = document.getElementById('allEventsPagination');
    if (!paginationEl) return;
    
    if (!pagination || pagination.totalpages <= 1) {
        paginationEl.innerHTML = '';
        return;
    }
    
    const totalpages = pagination.totalpages || 1;
    const currentPage = pagination.page || 0;
    const hasNext = pagination.hasnext || false;
    const hasPrev = pagination.hasprev || false;
    
    let html = '';
    
    // Previous button
    html += `
        <button class="pagination-btn" ${!hasPrev ? 'disabled' : ''} onclick="changeAllEventsPage(${currentPage - 1})">
            <i class="fa-solid fa-chevron-left"></i> Previous
        </button>
    `;
    
    // Page numbers (show up to 5 page numbers)
    const maxVisiblePages = 5;
    let startPage = Math.max(0, currentPage - Math.floor(maxVisiblePages / 2));
    let endPage = Math.min(totalpages - 1, startPage + maxVisiblePages - 1);
    
    if (endPage - startPage < maxVisiblePages - 1) {
        startPage = Math.max(0, endPage - maxVisiblePages + 1);
    }
    
    if (startPage > 0) {
        html += `<button class="pagination-btn" onclick="changeAllEventsPage(0)">1</button>`;
        if (startPage > 1) {
            html += `<span class="pagination-info">...</span>`;
        }
    }
    
    for (let i = startPage; i <= endPage; i++) {
        html += `
            <button class="pagination-btn ${i === currentPage ? 'active' : ''}" onclick="changeAllEventsPage(${i})">
                ${i + 1}
            </button>
        `;
    }
    
    if (endPage < totalpages - 1) {
        if (endPage < totalpages - 2) {
            html += `<span class="pagination-info">...</span>`;
        }
        html += `<button class="pagination-btn" onclick="changeAllEventsPage(${totalpages - 1})">${totalpages}</button>`;
    }
    
    // Next button
    html += `
        <button class="pagination-btn" ${!hasNext ? 'disabled' : ''} onclick="changeAllEventsPage(${currentPage + 1})">
            Next <i class="fa-solid fa-chevron-right"></i>
        </button>
    `;
    
    // Page info
    const startItem = currentPage * eventsPerPage + 1;
    const endItem = Math.min((currentPage + 1) * eventsPerPage, totalEvents);
    html += `<span class="pagination-info">Showing ${startItem}-${endItem} of ${totalEvents} events</span>`;
    
    paginationEl.innerHTML = html;
}

function changeAllEventsPage(page) {
    if (page < 0 || !currentCommunityId) return;
    loadAllEvents(currentCommunityId, page);
    // Scroll to top of modal content
    const eventsListEl = document.getElementById('allEventsList');
    if (eventsListEl) {
        eventsListEl.scrollTop = 0;
    }
}

// ========== VIEW ALL RESOURCES MODAL FUNCTIONS ==========
let currentResourcesPage = 0;
let totalResources = 0;
let resourcesPerPage = 5;

function viewAllResources() {
    if (!currentCommunityId) {
        alert('Please select a community first.');
        return;
    }
    
    currentResourcesPage = 0;
    openModal('viewAllResourcesModal');
    loadAllResources(currentCommunityId, 0);
}

function loadAllResources(communityId, page) {
    if (!communityId) return;
    
    const resourcesListEl = document.getElementById('allResourcesList');
    if (resourcesListEl) {
        resourcesListEl.innerHTML = '<div style="text-align: center; padding: 40px; color: #64748b;"><i class="fa-solid fa-spinner fa-spin" style="font-size: 24px; margin-bottom: 12px;"></i><p>Loading resources...</p></div>';
    }
    
    fetch(`<?php echo $CFG->wwwroot; ?>/local/communityhub/ajax.php?action=get_resources&communityid=${communityId}&page=${page}&perpage=${resourcesPerPage}&sesskey=<?php echo sesskey(); ?>`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.data) {
                const resources = data.data.resources || [];
                const pagination = data.data.pagination || {};
                
                totalResources = pagination.total || 0;
                currentResourcesPage = page;
                
                if (resources.length === 0) {
                    resourcesListEl.innerHTML = '<div class="empty-state"><p class="empty-state-text">No resources found</p></div>';
                    renderAllResourcesPagination(pagination);
                    return;
                }
                
                renderAllResourcesInModal(resources);
                renderAllResourcesPagination(pagination);
            } else {
                resourcesListEl.innerHTML = '<div class="empty-state"><p class="empty-state-text">Error loading resources</p></div>';
            }
        })
        .catch(error => {
            console.error('Error loading resources:', error);
            resourcesListEl.innerHTML = '<div class="empty-state"><p class="empty-state-text">Error loading resources. Please try again.</p></div>';
        });
}

function renderAllResourcesInModal(resources) {
    const resourcesListEl = document.getElementById('allResourcesList');
    if (!resourcesListEl) return;
    
    if (resources.length === 0) {
        resourcesListEl.innerHTML = '<div class="empty-state"><p class="empty-state-text">No resources found</p></div>';
        return;
    }
    
    let html = '';
    resources.forEach(resource => {
        const iconClass = getFileIcon(resource.filetype);
        const fileSize = formatFileSize(resource.filesize);
        const typeLabel = (resource.filetype || '').toUpperCase();
        const cleanDescription = stripHtml(resource.description || '');
        const createdLabel = resource.timecreated ? formatDate(resource.timecreated) : '';
        
        html += `
            <div class="resource-item" onclick="downloadResource(${resource.id})">
                <div class="resource-icon" style="background: #dbeafe;">
                    <i class="${iconClass}" style="color: #1e40af;"></i>
                </div>
                <div class="resource-info">
                    <h4 class="resource-title">${escapeHtml(resource.title)}</h4>
                    <p class="resource-meta">${typeLabel} • ${fileSize} • <span id="resource-download-${resource.id}">${resource.downloadcount}</span> downloads</p>
                    ${cleanDescription ? `<p class="resource-meta">${escapeHtml(cleanDescription)}</p>` : ''}
                    <p class="resource-meta">Shared by ${escapeHtml(resource.creatorsname)} ${createdLabel ? `• ${createdLabel}` : ''}</p>
                </div>
                <button class="resource-download-btn" onclick="event.stopPropagation(); downloadResource(${resource.id})">
                    <i class="fa-solid fa-download"></i>
                </button>
            </div>
        `;
    });
    
    resourcesListEl.innerHTML = html;
}

function renderAllResourcesPagination(pagination) {
    const paginationEl = document.getElementById('allResourcesPagination');
    if (!paginationEl) return;
    
    if (!pagination || pagination.totalpages <= 1) {
        paginationEl.innerHTML = '';
        return;
    }
    
    const totalpages = pagination.totalpages || 1;
    const currentPage = pagination.page || 0;
    const hasNext = pagination.hasnext || false;
    const hasPrev = pagination.hasprev || false;
    
    let html = '';
    
    // Previous button
    html += `
        <button class="pagination-btn" ${!hasPrev ? 'disabled' : ''} onclick="changeAllResourcesPage(${currentPage - 1})">
            <i class="fa-solid fa-chevron-left"></i> Previous
        </button>
    `;
    
    // Page numbers (show up to 5 page numbers)
    const maxVisiblePages = 5;
    let startPage = Math.max(0, currentPage - Math.floor(maxVisiblePages / 2));
    let endPage = Math.min(totalpages - 1, startPage + maxVisiblePages - 1);
    
    if (endPage - startPage < maxVisiblePages - 1) {
        startPage = Math.max(0, endPage - maxVisiblePages + 1);
    }
    
    if (startPage > 0) {
        html += `<button class="pagination-btn" onclick="changeAllResourcesPage(0)">1</button>`;
        if (startPage > 1) {
            html += `<span class="pagination-info">...</span>`;
        }
    }
    
    for (let i = startPage; i <= endPage; i++) {
        html += `
            <button class="pagination-btn ${i === currentPage ? 'active' : ''}" onclick="changeAllResourcesPage(${i})">
                ${i + 1}
            </button>
        `;
    }
    
    if (endPage < totalpages - 1) {
        if (endPage < totalpages - 2) {
            html += `<span class="pagination-info">...</span>`;
        }
        html += `<button class="pagination-btn" onclick="changeAllResourcesPage(${totalpages - 1})">${totalpages}</button>`;
    }
    
    // Next button
    html += `
        <button class="pagination-btn" ${!hasNext ? 'disabled' : ''} onclick="changeAllResourcesPage(${currentPage + 1})">
            Next <i class="fa-solid fa-chevron-right"></i>
        </button>
    `;
    
    // Page info
    const startItem = currentPage * resourcesPerPage + 1;
    const endItem = Math.min((currentPage + 1) * resourcesPerPage, totalResources);
    html += `<span class="pagination-info">Showing ${startItem}-${endItem} of ${totalResources} resources</span>`;
    
    paginationEl.innerHTML = html;
}

function changeAllResourcesPage(page) {
    if (page < 0 || !currentCommunityId) return;
    loadAllResources(currentCommunityId, page);
    // Scroll to top of modal content
    const resourcesListEl = document.getElementById('allResourcesList');
    if (resourcesListEl) {
        resourcesListEl.scrollTop = 0;
    }
}

// ========== FULL IMAGE MODAL FUNCTIONS ==========
let currentImageUrl = '';
let currentImageFilename = '';

function openImageModal(imageUrl, filename) {
    currentImageUrl = imageUrl;
    currentImageFilename = filename || 'image';
    
    const fullImageContent = document.getElementById('fullImageContent');
    if (fullImageContent) {
        fullImageContent.src = imageUrl;
        fullImageContent.alt = filename || 'Full size image';
    }
    
    openModal('fullImageModal');
}

function downloadFullImage() {
    if (!currentImageUrl) return;
    
    // Create a temporary anchor element to trigger download
    const link = document.createElement('a');
    link.href = currentImageUrl;
    link.download = currentImageFilename || 'image';
    link.target = '_blank';
    
    // Add to body, click, and remove
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

// Close image modal on escape key
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        const fullImageModal = document.getElementById('fullImageModal');
        if (fullImageModal && fullImageModal.style.display === 'block') {
            closeModal('fullImageModal');
        }
    }
});

// Close image modal when clicking outside the image (on the dark background)
document.addEventListener('click', function(event) {
    const fullImageModal = document.getElementById('fullImageModal');
    if (fullImageModal && fullImageModal.style.display === 'block') {
        const modalContent = fullImageModal.querySelector('.modal-content');
        if (modalContent && event.target === fullImageModal) {
            closeModal('fullImageModal');
        }
    }
});

</script>

<!-- Load RemUI Alert Modal JavaScript inline to ensure it's available -->
<script src="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/javascript/alert_modal.js"></script>

<script>
// Ensure RemuiAlert is initialized after script loads
(function() {
    function initRemuiAlert() {
        if (typeof RemuiAlert !== 'undefined') {
            if (RemuiAlert.modal === null) {
                RemuiAlert.init();
            }
            console.log('RemuiAlert initialized:', RemuiAlert.modal !== null);
            
            // Check for moderation success message after reload
            // We'll show it after the panel is reopened (if needed)
            // This is handled in the DOMContentLoaded event
        } else {
            console.error('RemuiAlert is not defined. Make sure alert_modal.js is loaded.');
            // Retry after a short delay
            setTimeout(initRemuiAlert, 100);
        }
    }
    
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initRemuiAlert);
    } else {
        initRemuiAlert();
    }
})();
</script>

<?php
echo $OUTPUT->footer();
?>