<?php
use local_communityhub\repository;
use local_communityhub\constants;

require_once(__DIR__ . '/../../config.php');

require_login();

$spaceid = optional_param('spaceid', 0, PARAM_INT);
$communityid = optional_param('communityid', 0, PARAM_INT);

$repository = new repository();
$userspaces = $repository->get_user_spaces_overview($USER->id);
$usercommunities = $repository->get_user_communities_overview($USER->id);

$contexttype = 'space';
$space = null;
$community = null;

if ($spaceid > 0) {
    $space = $repository->get_space($spaceid);
    if (!$space) {
        throw new moodle_exception('spacenotfound', 'local_communityhub');
    }
    $communityid = (int) $space->communityid;
    $community = $repository->get_community($communityid);
    if (!$community) {
        throw new moodle_exception('communitynotfound', 'local_communityhub');
    }
} elseif ($communityid > 0) {
    $community = $repository->get_community($communityid);
    if (!$community) {
        throw new moodle_exception('communitynotfound', 'local_communityhub');
    }
    $contexttype = 'community';
} else {
    if (!empty($usercommunities)) {
        $community = reset($usercommunities);
        $communityid = (int) $community->id;
        $community = $repository->get_community($communityid);
        $contexttype = 'community';
    } elseif (!empty($userspaces)) {
        $space = reset($userspaces);
        $spaceid = (int) $space->id;
        $space = $repository->get_space($spaceid);
        if (!$space) {
            throw new moodle_exception('spacenotfound', 'local_communityhub');
        }
        $communityid = (int) $space->communityid;
        $community = $repository->get_community($communityid);
        if (!$community) {
            throw new moodle_exception('communitynotfound', 'local_communityhub');
        }
        $contexttype = 'space';
    } else {
        throw new moodle_exception('nopermission', 'local_communityhub');
    }
}

if (!$community) {
    throw new moodle_exception('communitynotfound', 'local_communityhub');
}

$context = context_system::instance();
$PAGE->set_context($context);
$pageparams = [];
if ($spaceid) {
    $pageparams['spaceid'] = $spaceid;
}
if ($contexttype === 'community') {
    $pageparams['communityid'] = $communityid;
}
$PAGE->set_url(new moodle_url('/theme/remui_kids/community_chat.php', $pageparams));
$PAGE->set_pagelayout('standard');
$spaceheading = $space ? ($space->name ?? '') : '';
$headingname = ($contexttype === 'space') ? $spaceheading : ($community->name ?? '');
$PAGE->set_title(format_string($headingname) . ' - Chat');
$PAGE->set_heading(format_string($headingname));
$PAGE->add_body_class('space-chat-page');

$ismanager = false;
$issuperadmin = $repository->is_super_admin($USER->id);
if ($issuperadmin) {
    $ismanager = true;
} else {
    $members = $repository->get_members($community->id);
    foreach ($members as $member) {
        if ((int) $member->userid === (int) $USER->id && $member->role === constants::ROLE_ADMIN) {
            $ismanager = true;
            break;
        }
    }
}

$isspacemember = $spaceid ? $repository->is_space_member($spaceid, $USER->id) : false;
$iscommunitymember = $repository->is_member($community->id, $USER->id);

if ($contexttype === 'space') {
    if (!$isspacemember && !$ismanager) {
        throw new moodle_exception('nopermission', 'local_communityhub');
    }
} else {
    if (!$iscommunitymember && !$ismanager) {
        throw new moodle_exception('nopermission', 'local_communityhub');
    }
}

$contextmembers = $contexttype === 'space'
    ? $repository->get_space_members($spaceid)
    : $repository->get_members($community->id);

$spaceicon = ($space && trim((string) ($space->icon ?? '')) !== '') ? trim((string) $space->icon) : 'fa-solid fa-users';
$spacecolor = ($space && trim((string) ($space->color ?? '')) !== '') ? trim((string) $space->color) : '#e0e7ff';
$communityicon = 'fa-solid fa-people-group';
$communitycolor = '#e0f2ff';

$chatcontext = [
    'type' => $contexttype,
    'id' => ($contexttype === 'space') ? (int) $space->id : (int) $community->id,
    'name' => $headingname,
    'description' => ($contexttype === 'space') ? (string) ($space->description ?? '') : (string) ($community->description ?? ''),
    'communityid' => (int) $community->id,
    'membercount' => count($contextmembers),
    'icon' => $contexttype === 'space' ? $spaceicon : $communityicon,
    'color' => $contexttype === 'space' ? $spacecolor : $communitycolor,
    'communityname' => $contexttype === 'space' ? (string) ($community->name ?? '') : '',
];

$avatar = $OUTPUT->user_picture($USER, ['link' => false]);

echo $OUTPUT->header();
?>
<style>
html, body {
    width: 100%;
    height: 100%;
    margin: 0;
    padding: 0;
    background: #ffffff;
}

body.chat-modal-open {
    overflow: hidden;
}

#page-header,
.page-header-headings,
.page-context-header,
#region-main > .card > .card-body > h2 {
    display: none !important;
}

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

:root {
    --chat-bg: #f3f5ff;
    --chat-primary: #2563eb;
    --chat-secondary: #1e40af;
}

.chat-page-wrapper {
    width: 100vw;
    max-width: 100vw;
    min-height: calc(100vh - 64px);
    padding: 32px 48px 48px;
    box-sizing: border-box;
    background: #ffffff;
    margin-left: calc(50% - 50vw);
    margin-top: -50px;
    display: flex;
    flex-direction: column;
}

.chat-top-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 16px;
}

.chat-top-bar h1 {
    margin: 0;
    font-size: 1.75rem;
    font-weight: 700;
    color: #0f172a;
}

.space-chat-wrapper {
    width: 100%;
    padding: 0;
    box-sizing: border-box;
}

.space-chat-layout {
    width: 100%;
    display: grid;
    grid-template-columns: 400px 1fr;
    gap: 16px;
    align-items: stretch;
    flex: 1;
}

.chat-panel {
    width: 100%;
    background: #fff;
    border-radius: 20px;
    box-shadow: 0 25px 60px rgba(15, 23, 42, 0.08);
    border: 1px solid #e2e8f0;
    padding: 24px;
    box-sizing: border-box;
    height: calc(100vh - 140px);
    display: flex;
    flex-direction: column;
}

.chat-space-list {
    width: 400px;
    background: #fff;
    border-radius: 18px;
    box-shadow: 0 20px 50px rgba(15, 23, 42, 0.08);
    border: 1px solid #e2e8f0;
    padding: 20px;
    box-sizing: border-box;
    height: calc(100vh - 130px);
    display: flex;
    flex-direction: column;
    position: sticky;
    top: 32px;
}

.chat-space-list-header {
    display: flex;
    flex-direction: column;
    gap: 12px;
    margin-bottom: 16px;
}

.chat-space-list-header-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.chat-space-list-header h4 {
    margin: 0;
    font-size: 1.05rem;
    color: #0f172a;
}

.chat-search-bar {
    position: relative;
    width: 100%;
}

.chat-search-bar input {
    width: 100%;
    padding: 10px 14px 10px 40px;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    font-size: 0.9rem;
    background: #f8fafc;
    color: #0f172a;
    transition: all 0.2s ease;
    box-sizing: border-box;
}

.chat-search-bar input:focus {
    outline: none;
    border-color: #2563eb;
    background: #fff;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
}

.chat-search-bar i {
    position: absolute;
    left: 14px;
    top: 50%;
    transform: translateY(-50%);
    color: #94a3b8;
    font-size: 0.9rem;
}

.chat-search-bar input:focus + i {
    color: #2563eb;
}

.chat-space-list-body {
    display: flex;
    flex-direction: column;
    gap: 12px;
    overflow-y: auto;
    padding-right: 4px;
    flex: 1;
}

.chat-list-section {
    margin-bottom: 18px;
}

.chat-list-section-title {
    margin: 12px 0 8px;
    font-size: 0.75rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: #94a3b8;
}

.chat-space-item {
    display: flex;
    align-items: center;
    gap: 12px;
    text-decoration: none;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 12px;
    transition: all 0.2s ease;
    color: #0f172a;
    background: #f8fafc;
}

.chat-space-item:hover {
    border-color: #2563eb;
    box-shadow: 0 8px 16px rgba(37, 99, 235, 0.12);
    text-decoration: none;
}

.chat-space-item.active {
    border-color: #2563eb;
    background: #ffffff;
    box-shadow: inset 0 0 0 1px #c7d2fe;
    text-decoration: none;
}

.chat-space-item-icon {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 1.1rem;
    color: #ffffff;
    background: #e0e7ff;
    flex-shrink: 0;
}

.chat-space-item-content {
    flex: 1;
    min-width: 0;
}

.chat-space-item-title {
    margin: 0;
    font-size: 0.95rem;
    font-weight: 600;
}

.chat-space-item-community {
    margin: 2px 0 0 0;
    font-size: 0.8rem;
    color: #64748b;
}

.chat-space-item-community-name {
    margin: 2px 0 0 0;
    font-size: 0.78rem;
    color: #94a3b8;
}

.chat-space-item-meta {
    margin-top: 6px;
    display: flex;
    gap: 8px;
    font-size: 0.75rem;
    color: #475569;
}

.chat-back-link {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: var(--chat-secondary);
    font-weight: 600;
    text-decoration: none;
    margin-bottom: 1rem;
}

.chat-panel-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.5rem;
    gap: 12px;
}

.chat-space-identity {
    display: inline-flex;
    align-items: center;
    gap: 12px;
}

.chat-space-identity-icon {
    width: 60px;
    height: 60px;
    border-radius: 18px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 1.4rem;
    background: #e0e7ff;
    color: #ffffff;
    box-shadow: inset 0 0 0 2px rgba(255, 255, 255, 0.2);
}

.chat-space-identity-meta {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.chat-space-identity-meta span {
    font-size: 0.85rem;
    color: #94a3b8;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    font-weight: 600;
}

.chat-space-identity-meta strong {
    font-size: 1.1rem;
    color: #0f172a;
    font-weight: 700;
}

.chat-space-identity-community {
    font-size: 0.85rem;
    color: #64748b;
    margin: 2px 0 0 0;
}

.chat-space-identity-description {
    font-size: 0.9rem;
    color: #475569;
    margin: 4px 0 0 0;
    max-width: 420px;
}

.chat-panel-body {
    flex: 1;
    display: flex;
    flex-direction: column;
    border: 1px solid #e2e8f0;
    border-radius: 18px;
    background: #f8fafc;
    min-height: 0;
    overflow: hidden;
}

.chat-meta span {
    display: block;
    font-size: 0.9rem;
    color: #475569;
}

.chat-meta-space {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-weight: 600;
    color: #0f172a;
}

.chat-space-pill {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 6px 14px;
    border-radius: 999px;
    border: 1px solid rgba(99, 102, 241, 0.25);
    background: #eef2ff;
    color: #312e81;
    font-size: 0.9rem;
}

.chat-space-pill i {
    color: #ffffff;
}

.chat-members-pill {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 18px;
    border-radius: 999px;
    border: none;
    background: linear-gradient(135deg, #eef2ff, #e0f2ff);
    color: #111827;
    font-weight: 600;
    font-size: 0.95rem;
    box-shadow: 0 8px 18px rgba(148, 163, 184, 0.25);
    cursor: pointer;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.chat-members-pill:hover {
    transform: translateY(-1px);
    box-shadow: 0 12px 24px rgba(148, 163, 184, 0.35);
}

.chat-header-meta {
    display: flex;
    flex-direction: column;
    gap: 6px;
    align-items: flex-end;
}

.chat-meta-timestamp {
    font-size: 0.85rem;
    color: #475569;
}

.chat-modal {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.55);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 5000;
    padding: 24px;
}

.chat-modal.open {
    display: flex;
}

.chat-modal-panel {
    background: #ffffff;
    border-radius: 20px;
    width: min(560px, 100%);
    max-height: 85vh;
    display: flex;
    flex-direction: column;
    box-shadow: 0 25px 80px rgba(15, 23, 42, 0.2);
    overflow: hidden;
}

.chat-modal-header {
    padding: 20px 24px;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.chat-modal-header h3 {
    margin: 0;
    font-size: 1.15rem;
    color: #0f172a;
}

.chat-modal-close {
    border: none;
    background: transparent;
    font-size: 1.25rem;
    color: #94a3b8;
    cursor: pointer;
}

.chat-modal-body {
    padding: 20px 24px;
    overflow-y: auto;
}

.chat-members-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.chat-member-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 14px;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    background: #f8fafc;
}

.chat-member-info {
    display: flex;
    align-items: center;
    gap: 12px;
}

.chat-member-avatar {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid #ffffff;
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.15);
    background: #e2e8f0;
}

.chat-member-meta {
    display: flex;
    flex-direction: column;
    line-height: 1.2;
}

.chat-member-name {
    font-weight: 600;
    color: #0f172a;
    margin: 0;
}

.chat-member-email {
    font-size: 0.8rem;
    color: #94a3b8;
    margin: 2px 0 0 0;
}

.chat-member-role {
    padding: 4px 10px;
    border-radius: 999px;
    background: #eef2ff;
    color: #312e81;
    font-size: 0.75rem;
    font-weight: 600;
}

.user-select-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    margin-bottom: 8px;
    cursor: pointer;
    transition: all 0.2s ease;
    background: #fff;
}

.user-select-item:hover {
    background: #f8fafc;
    border-color: #2563eb;
}

.user-select-item.selected {
    background: #eef2ff;
    border-color: #2563eb;
}

.user-select-item-info {
    display: flex;
    align-items: center;
    gap: 12px;
    flex: 1;
}

.user-select-item-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    object-fit: cover;
}

.user-select-item-name {
    font-weight: 600;
    color: #0f172a;
    margin: 0;
    font-size: 0.9rem;
}

.selected-user-tag {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    background: #eef2ff;
    color: #312e81;
    border-radius: 999px;
    font-size: 0.875rem;
    font-weight: 500;
    border: 1px solid #c7d2fe;
}

.selected-user-tag-remove {
    cursor: pointer;
    font-size: 1.2rem;
    line-height: 1;
    color: #64748b;
    transition: color 0.2s ease;
}

.selected-user-tag-remove:hover {
    color: #ef4444;
}

.chat-modal {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.55);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 5000;
    padding: 24px;
}

.chat-modal.open {
    display: flex;
}

.chat-modal-panel {
    background: #ffffff;
    border-radius: 20px;
    width: min(560px, 100%);
    max-height: 85vh;
    display: flex;
    flex-direction: column;
    box-shadow: 0 25px 80px rgba(15, 23, 42, 0.2);
    overflow: hidden;
}

.chat-modal-header {
    padding: 20px 24px;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.chat-modal-header h3 {
    margin: 0;
    font-size: 1.15rem;
    color: #0f172a;
}

.chat-modal-close {
    border: none;
    background: transparent;
    font-size: 1.25rem;
    color: #94a3b8;
    cursor: pointer;
}

.chat-modal-body {
    padding: 20px 24px;
    overflow-y: auto;
}

.chat-members-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.chat-member-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 14px;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    background: #f8fafc;
}

.chat-member-info {
    display: flex;
    align-items: center;
    gap: 12px;
}

.chat-member-avatar {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid #ffffff;
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.15);
    background: #e2e8f0;
}

.chat-member-meta {
    display: flex;
    flex-direction: column;
    line-height: 1.2;
}

.chat-member-name {
    font-weight: 600;
    color: #0f172a;
    margin: 0;
}

.chat-member-email {
    font-size: 0.8rem;
    color: #94a3b8;
    margin: 2px 0 0 0;
}

.chat-member-role {
    padding: 4px 10px;
    border-radius: 999px;
    background: #eef2ff;
    color: #312e81;
    font-size: 0.75rem;
    font-weight: 600;
}

.chat-messages-container {
    flex: 1;
    display: flex;
    flex-direction: column;
    min-height: 0;
    border-radius: 18px 18px 0 0;
    background: #f8fafc;
    overflow: hidden;
}

.chat-messages-header {
    padding: 0.75rem 1rem;
    border-bottom: 1px solid #e2e8f0;
    text-align: center;
    background: #f8fafc;
}

.chat-messages {
    flex: 1;
    overflow-y: auto;
    padding: 1.5rem;
    display: flex;
    flex-direction: column;
    gap: 1rem;
    min-height: 0;
}

.chat-bubble {
    max-width: min(520px, 75%);
    width: fit-content;
    padding: 12px 16px;
    border-radius: 16px;
    background: #fff;
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.08);
    position: relative;
    word-break: break-word;
}

.chat-bubble.own {
    margin-left: auto;
    background: #e0e7ff;
}

.chat-bubble .bubble-meta {
    font-size: 0.78rem;
    color: #94a3b8;
    margin-bottom: 6px;
}

.chat-date-separator {
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 24px 0 16px;
    position: relative;
}

.chat-date-separator::before,
.chat-date-separator::after {
    content: '';
    flex: 1;
    height: 1px;
    background: #e2e8f0;
}

.chat-date-separator span {
    padding: 6px 14px;
    background: #f1f5f9;
    border-radius: 999px;
    font-size: 0.75rem;
    font-weight: 600;
    color: #64748b;
    margin: 0 12px;
    border: 1px solid #e2e8f0;
}

.chat-attachments {
    margin-top: 10px;
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
}

.chat-attachment-preview {
    background: #f1f5f9;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 8px;
    max-width: 240px;
    box-shadow: inset 0 1px 2px rgba(15, 23, 42, 0.05);
}

.chat-attachment-preview img,
.chat-attachment-preview video {
    width: 100%;
    border-radius: 10px;
    display: block;
}

.chat-attachment-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 0.85rem;
    color: var(--chat-secondary);
    text-decoration: none;
    padding: 6px 10px;
    border-radius: 999px;
    border: 1px solid #bae6fd;
    background: #f0f9ff;
}

.chat-input-bar {
    padding: 1rem 1.25rem;
    border-top: 1px solid #e2e8f0;
    display: grid;
    grid-template-columns: auto 1fr auto;
    gap: 1rem;
    align-items: center;
    border-radius: 0 0 18px 18px;
    background: linear-gradient(135deg, #f9fafb 0%, #ffffff 50%, #eef2ff 100%);
}

.chat-input-bar textarea {
    width: 100%;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 14px 18px;
    resize: none;
    min-height: 72px;
    box-shadow: inset 0 1px 2px rgba(15, 23, 42, 0.08);
    background: rgba(255, 255, 255, 0.95);
    transition: border 0.2s ease, box-shadow 0.2s ease;
    font-size: 0.95rem;
}

.chat-input-bar textarea:focus {
    outline: none;
    border-color: #a5b4fc;
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.15);
}

.chat-input-tools {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.5rem;
}

.chat-attachment-trigger {
    width: 48px;
    height: 48px;
    border-radius: 14px;
    border: 1px dashed #a5b4fc;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #4338ca;
    cursor: pointer;
    transition: all 0.2s ease;
    background: #eef2ff;
}

.chat-attachment-trigger:hover {
    border-style: solid;
    box-shadow: 0 10px 20px rgba(67, 56, 202, 0.18);
}

.chat-input-actions {
    display: flex;
    flex-direction: column;
    gap: 0.4rem;
    align-items: flex-end;
}

.chat-input-actions button {
    background: linear-gradient(135deg, #2563eb,rgb(157, 110, 238));
    color: #fff;
    border: none;
    padding: 12px 24px;
    border-radius: 999px;
    font-weight: 600;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    box-shadow: 0 12px 24px rgba(37, 99, 235, 0.25);
    transition: transform 0.2s ease, box-shadow 0.2s ease, opacity 0.2s ease;
}

.chat-input-actions button:hover {
    transform: translateY(-1px);
    box-shadow: 0 15px 30px rgba(79, 70, 229, 0.3);
}

.chat-input-helper {
    font-size: 0.75rem;
    color: #94a3b8;
}

.chat-file-preview-list {
    display: none;
    margin-top: 0.75rem;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.chat-file-preview-list.visible {
    display: flex;
}

.chat-file-chip {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 6px 10px;
    border-radius: 999px;
    background: #e0e7ff;
    color: #312e81;
    font-size: 0.8rem;
    border: 1px solid #c7d2fe;
}

.chat-file-chip i {
    color: #4338ca;
}

.chat-empty-state {
    text-align: center;
    padding: 3rem 1rem;
    color: #94a3b8;
}

.chat-badge {
    display: inline-flex;
    padding: 4px 12px;
    border-radius: 999px;
    font-size: 0.75rem;
    background: #e0f2fe;
    color: #0369a1;
    font-weight: 600;
}

@media (max-width: 1024px) {
    .space-chat-layout {
        flex-direction: column;
    }
    .chat-space-list {
        width: 100%;
        max-height: none;
        position: relative;
        top: 0;
    }
    .chat-panel {
        min-height: auto;
    }
}
</style>

<div class="chat-page-wrapper">
    <div class="space-chat-wrapper">
        <div class="space-chat-layout">
            <aside class="chat-space-list">
                <div class="chat-space-list-header">
                    <div class="chat-space-list-header-top">
                        <h4>Spaces & Communities</h4>
                        <span id="chatListCount"><?php echo count($userspaces) + count($usercommunities); ?></span>
                    </div>
                    <div class="chat-search-bar">
                        <input type="text" id="chatSearchInput" placeholder="Search communities and spaces..." autocomplete="off">
                        <i class="fa-solid fa-magnifying-glass"></i>
                    </div>
                </div>
                <div class="chat-space-list-body" id="chatSpaceListBody">
                    <div class="chat-list-section">
                        <p class="chat-list-section-title">Communities</p>
                        <?php if (!empty($usercommunities)): ?>
                            <?php foreach ($usercommunities as $usercommunity): ?>
                                <?php
                                    $communitylink = new moodle_url('/theme/remui_kids/community_chat.php', ['communityid' => $usercommunity->id]);
                                    $communityactive = ($contexttype === 'community' && (int) $usercommunity->id === (int) $chatcontext['id']) ? 'active' : '';
                                    $communitymembers = (int) ($usercommunity->membercount ?? 0);
                                ?>
                                <a class="chat-space-item <?php echo $communityactive; ?>" 
                                   href="<?php echo $communitylink->out(false); ?>"
                                   data-search-text="<?php echo htmlspecialchars(strtolower(format_string($usercommunity->name)), ENT_QUOTES, 'UTF-8'); ?>"
                                   data-type="community">
                                    <span class="chat-space-item-icon" style="background: #e0f2ff;">
                                        <i class="fa-solid fa-people-group"></i>
                                    </span>
                                    <div class="chat-space-item-content">
                                        <p class="chat-space-item-title"><?php echo format_string($usercommunity->name); ?></p>
                                        <p class="chat-space-item-community-name">
                                            <?php echo $communitymembers; ?> <?php echo $communitymembers === 1 ? 'Member' : 'Members'; ?>
                                        </p>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="chat-empty-state" style="padding: 1rem;"><?php echo get_string('nothingtodisplay'); ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="chat-list-section">
                        <p class="chat-list-section-title">Spaces</p>
                        <?php if (!empty($userspaces)): ?>
                            <?php foreach ($userspaces as $userspace): ?>
                                <?php
                                    $spaceurl = new moodle_url('/theme/remui_kids/community_chat.php', ['spaceid' => $userspace->id]);
                                    $isactive = ($contexttype === 'space' && (int) $userspace->id === (int) $chatcontext['id']) ? 'active' : '';
                                    $itemicon = trim((string) ($userspace->icon ?? '')) ?: 'fa-solid fa-users';
                                    $itemcolor = trim((string) ($userspace->color ?? '')) ?: '#e0e7ff';
                                ?>
                                <a class="chat-space-item <?php echo $isactive; ?>" 
                                   href="<?php echo $spaceurl->out(false); ?>"
                                   data-search-text="<?php echo htmlspecialchars(strtolower(format_string($userspace->name . ' ' . $userspace->communityname)), ENT_QUOTES, 'UTF-8'); ?>"
                                   data-type="space">
                                    <span class="chat-space-item-icon" style="background: <?php echo $itemcolor; ?>">
                                        <i class="<?php echo s($itemicon); ?>"></i>
                                    </span>
                                    <div class="chat-space-item-content">
                                        <p class="chat-space-item-title"><?php echo format_string($userspace->name); ?></p>
                                        <p class="chat-space-item-community-name">
                                            <?php echo format_string($userspace->communityname); ?>
                                        </p>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="chat-empty-state" style="padding: 1rem;"><?php echo get_string('nothingtodisplay'); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            </aside>

            <section class="chat-panel">
                <div class="chat-panel-header">
                    <div class="chat-space-identity">
                        <span class="chat-space-identity-icon" style="background: <?php echo $chatcontext['color']; ?>;">
                            <i class="<?php echo s($chatcontext['icon']); ?>"></i>
                        </span>
                        <div class="chat-space-identity-meta">
                            <span><?php echo strtoupper($chatcontext['type']); ?></span>
                            <strong><?php echo format_string($chatcontext['name']); ?></strong>
                            <?php if (!empty($chatcontext['communityname'])): ?>
                                <p class="chat-space-identity-community">
                                    <?php echo format_string($chatcontext['communityname']); ?>
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="chat-header-meta">
                        <button type="button"
                                class="chat-members-pill"
                                onclick="openChatMembersModal('<?php echo $chatcontext['type']; ?>', <?php echo $chatcontext['id']; ?>, '<?php echo addslashes(format_string($chatcontext['name'])); ?>')">
                            <i class="fa-solid fa-users" style="color:#111827;"></i>
                            <?php echo $chatcontext['membercount']; ?> <?php echo $chatcontext['membercount'] === 1 ? 'Member' : 'Members'; ?>
                        </button>
                        <span class="chat-meta-timestamp">
                            <?php echo userdate(time(), get_string('strftimedatetime', 'langconfig')); ?>
                        </span>
                    </div>
                </div>

                <div class="chat-panel-body">
                    <div class="chat-messages-container">
                        <div class="chat-messages" id="spaceChatMessages">
                            <div class="chat-empty-state">Loading messages…</div>
                        </div>
                        <div class="chat-messages-header">
                            <button id="spaceChatLoadMore" class="btn btn-secondary" style="display: none;">See earlier messages</button>
                        </div>
                    </div>
                    <form id="spaceChatForm" class="chat-input-bar" autocomplete="off">
                        <div class="chat-input-tools">
                            <label for="spaceChatFileInput" class="chat-attachment-trigger" title="Add attachment">
                                <i class="fa-solid fa-paperclip"></i>
                            </label>
                            <input type="file" id="spaceChatFileInput" name="attachments[]" multiple hidden onchange="validateChatFileSize(this, 30)">
                        </div>
                        <div>
                            <textarea id="spaceChatInput" placeholder="Write a message..." rows="2"></textarea>
                            <div id="spaceChatFilePreview" class="chat-file-preview-list" aria-live="polite"></div>
                        </div>
                        <div class="chat-input-actions">
                            <span class="chat-input-helper">Media, docs & links</span>
                            <button type="submit">
                                <span>Send</span>
                                <i class="fa-solid fa-paper-plane"></i>
                            </button>
                        </div>
                    </form>
                </div>
            </section>
        </div>
    </div>
</div>

<div id="chatSpaceMembersModal" class="chat-modal" aria-hidden="true" role="dialog">
    <div class="chat-modal-panel">
        <div class="chat-modal-header">
            <h3 data-role="members-title">Members</h3>
            <div style="display: flex; align-items: center; gap: 12px;">
                <button type="button" id="chatAddMembersBtn" class="btn btn-primary" onclick="openChatAddMembersModal()" style="display: none; padding: 8px 16px; font-size: 0.875rem;">
                    <i class="fa-solid fa-plus" style="margin-right: 6px;"></i>Add Members
                </button>
                <button type="button" class="chat-modal-close" onclick="closeChatMembersModal()">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
        </div>
        <div class="chat-modal-body">
            <div id="chatSpaceMembersList" class="chat-members-list">
                <div class="chat-empty-state" style="padding: 20px 0;">Loading members...</div>
            </div>
        </div>
    </div>
</div>

<!-- Add Members Modal -->
<div id="chatAddMembersModal" class="chat-modal" aria-hidden="true" role="dialog" style="z-index: 10001;">
    <div class="chat-modal-panel" style="max-width: 600px;">
        <div class="chat-modal-header">
            <h3 data-role="add-members-title">Add Members</h3>
            <button type="button" class="chat-modal-close" onclick="closeChatAddMembersModal()">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div class="chat-modal-body">
            <div style="margin-bottom: 16px;">
                <label style="display: block; font-weight: 600; margin-bottom: 8px; font-size: 0.9rem;">Filter by Role</label>
                <select id="chatRoleFilterSelect" onchange="handleChatRoleFilterChange()" style="width: 100%; padding: 10px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 0.9rem;">
                    <option value="all">All Users</option>
                    <option value="students">Students Only</option>
                    <option value="teachers">Teachers Only</option>
                    <option value="parents">Parents Only</option>
                    <?php if ($issuperadmin): ?>
                    <option value="schooladmins">School Admins Only</option>
                    <?php endif; ?>
                </select>
            </div>
            <div style="margin-bottom: 16px;">
                <label style="display: block; font-weight: 600; margin-bottom: 8px; font-size: 0.9rem;">Search Users</label>
                <input type="text" id="chatUserSearchInput" placeholder="Type to search users..." onkeyup="chatSearchUsers()" style="width: 100%; padding: 10px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 0.9rem;">
            </div>
            <div id="chatAvailableUsersList" style="max-height: 300px; overflow-y: auto; margin-top: 16px; border: 1px solid #e2e8f0; border-radius: 8px; padding: 8px;">
                <div style="text-align: center; padding: 20px; color: #64748b;">
                    <p>Start typing to search for users...</p>
                </div>
            </div>
            <div id="chatSelectedUsersList" style="margin-top: 16px; padding-top: 16px; border-top: 1px solid #e2e8f0;">
                <label style="font-weight: 600; margin-bottom: 8px; display: block; font-size: 0.9rem;">Selected Users:</label>
                <div id="chatSelectedUsersTags" style="display: flex; flex-wrap: wrap; gap: 8px;">
                    <p style="color: #64748b; font-size: 0.875rem;">No users selected</p>
                </div>
            </div>
        </div>
        <div class="chat-modal-footer" style="display: flex; justify-content: flex-end; gap: 12px; padding: 16px; border-top: 1px solid #e2e8f0;">
            <button type="button" class="btn btn-secondary" onclick="closeChatAddMembersModal()" style="padding: 10px 20px;">Cancel</button>
            <button type="button" class="btn btn-primary" onclick="submitChatAddMembers()" style="padding: 10px 20px;">Add Members</button>
        </div>
    </div>
</div>

<script>
const spaceChatConfig = <?php echo json_encode([
    'spaceId' => $contexttype === 'space' ? (int) $chatcontext['id'] : 0,
    'communityId' => (int) $chatcontext['communityid'],
    'contextType' => $chatcontext['type'],
    'contextId' => (int) $chatcontext['id'],
    'spaceName' => $chatcontext['name'],
    'spaceIcon' => $chatcontext['icon'],
    'spaceColor' => $chatcontext['color'],
    'userId' => $USER->id,
    'sesskey' => sesskey(),
    'ajax' => $CFG->wwwroot . '/local/communityhub/ajax.php',
    'userAvatar' => $OUTPUT->user_picture($USER, ['link' => false, 'size' => 48]),
    'baseUrl' => $CFG->wwwroot,
]); ?>;

const chatState = {
    conversationId: 0,
    currentPage: 0,
    perPage: 25,
    isLoading: false,
    hasMore: false,
};

document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('spaceChatForm');
    const loadMoreBtn = document.getElementById('spaceChatLoadMore');
    const fileInput = document.getElementById('spaceChatFileInput');
    const searchInput = document.getElementById('chatSearchInput');

    form.addEventListener('submit', handleChatSubmit);
    loadMoreBtn.addEventListener('click', loadOlderMessages);
    if (fileInput) {
        fileInput.addEventListener('change', handleAttachmentPreview);
    }
    if (searchInput) {
        searchInput.addEventListener('input', handleSidebarSearch);
    }

    loadSpaceConversation();
});

function apiUrl(params) {
    const query = new URLSearchParams(params);
    return `${spaceChatConfig.ajax}?${query.toString()}`;
}

function loadSpaceConversation() {
    if (chatState.isLoading) {
        return;
    }
    chatState.isLoading = true;
    const params = {
        action: spaceChatConfig.contextType === 'community' ? 'get_community_conversation' : 'get_space_conversation',
        page: 0,
        perpage: chatState.perPage,
        sesskey: spaceChatConfig.sesskey
    };
    if (spaceChatConfig.contextType === 'community') {
        params.communityid = spaceChatConfig.contextId;
    } else {
        params.spaceid = spaceChatConfig.spaceId;
    }
    fetch(apiUrl(params), { credentials: 'same-origin' })
        .then(response => response.json())
        .then(res => {
            if (!res.success) {
                throw new Error(res.error || 'Unable to load conversation');
            }
            hydrateConversation(res.data, true);
        })
        .catch(showChatError)
        .finally(() => {
            chatState.isLoading = false;
        });
}

function hydrateConversation(payload, resetList = false) {
    if (!payload || !payload.conversation) {
        return;
    }

    chatState.conversationId = payload.conversation.id;
    chatState.currentPage = 0;
    const messagePayload = payload.messages || {};
    const messageItems = Array.isArray(messagePayload.items) ? messagePayload.items : (Array.isArray(payload.messages) ? payload.messages : []);
    chatState.hasMore = !!(messagePayload.pagination && messagePayload.pagination.hasnext);

    if (messageItems.length) {
        renderMessages(messageItems, { replace: true });
        const latest = messageItems[messageItems.length - 1];
        if (latest) {
            markConversationRead(latest.id);
        }
    } else {
        const container = document.getElementById('spaceChatMessages');
        container.innerHTML = `<div class="chat-empty-state">Start the conversation by sending the first message.</div>`;
    }

    toggleLoadMore(chatState.hasMore);
}

function renderMessages(messages, options = {}) {
    const { replace = false, prepend = false } = options;
    const container = document.getElementById('spaceChatMessages');
    if (replace) {
        container.innerHTML = '';
    }

    const fragment = document.createDocumentFragment();
    let lastDateKey = null;
    
    // If prepending, get the first existing message's date to avoid duplicate separator
    if (prepend && container.children.length > 0) {
        // Find the first actual message bubble (skip any existing separators)
        const firstBubble = Array.from(container.children).find(el => el.classList.contains('chat-bubble'));
        if (firstBubble && firstBubble.dataset.timestamp) {
            lastDateKey = getDateKey(parseInt(firstBubble.dataset.timestamp, 10));
        }
    }
    
    messages.forEach((message, index) => {
        const sender = message.sender || {};
        const senderId = parseInt(sender.id ?? message.userid ?? 0, 10);
        const senderName = sender.name || message.authorname || '';
        const body = typeof message.message === 'string' ? message.message : '';
        const attachments = message.files || message.attachments || message.media || [];
        if (!body && attachments.length === 0) {
            return;
        }

        const timestamp = message.timecreated || 0;
        const currentDateKey = getDateKey(timestamp);
        
        // Insert date separator if date changed
        if (currentDateKey && currentDateKey !== lastDateKey) {
            const separator = document.createElement('div');
            separator.className = 'chat-date-separator';
            separator.innerHTML = `<span>${escapeHtml(formatDateSeparator(timestamp))}</span>`;
            fragment.appendChild(separator);
            lastDateKey = currentDateKey;
        }

        const item = document.createElement('div');
        item.className = 'chat-bubble' + (senderId === parseInt(spaceChatConfig.userId, 10) ? ' own' : '');
        item.dataset.timestamp = timestamp;
        item.innerHTML = `
            <div class="bubble-meta">
                <strong>${escapeHtml(senderName)}</strong>
                <span>${formatDate(timestamp)}</span>
            </div>
            <div class="bubble-body">${body}</div>
            ${renderAttachments(attachments)}
        `;
        fragment.appendChild(item);
    });

    if (!fragment.children.length && replace) {
        container.innerHTML = `<div class="chat-empty-state">Start the conversation by sending the first message.</div>`;
        return;
    }

    if (prepend) {
        container.prepend(fragment);
    } else {
        container.appendChild(fragment);
        container.scrollTop = container.scrollHeight;
    }
}

function renderAttachments(files) {
    if (!files || !files.length) {
        return '';
    }

    const items = files.map(file => {
        const type = (file.filetype || '').toLowerCase();
        const filename = escapeHtml(file.filename || 'attachment');
        if (type === 'image') {
            return `
                <div class="chat-attachment-preview image">
                    <img src="${file.fileurl}" alt="${filename}" loading="lazy" onclick="window.open('${file.fileurl}', '_blank')">
                </div>
            `;
        }
        if (type === 'video') {
            return `
                <div class="chat-attachment-preview video">
                    <video controls src="${file.fileurl}" preload="metadata"></video>
                </div>
            `;
        }
        return `
            <a class="chat-attachment-link" href="${file.fileurl}" target="_blank" rel="noopener">
                <i class="fa-solid fa-paperclip"></i>
                <span>${filename}</span>
            </a>
        `;
    }).join('');

    return `<div class="chat-attachments">${items}</div>`;
}

function handleAttachmentPreview(event) {
    const preview = document.getElementById('spaceChatFilePreview');
    if (!preview) {
        return;
    }
    const fileInput = event?.target;
    const files = Array.from((fileInput?.files) || []);
    if (!files.length) {
        preview.innerHTML = '';
        preview.classList.remove('visible');
        return;
    }
    
    // Validate file sizes first
    if (!validateChatFileSize(fileInput, 30)) {
        preview.innerHTML = '';
        preview.classList.remove('visible');
        return;
    }

    const chips = files.map(file => {
        const icon = fileIconFromType(file.type);
        const name = escapeHtml(file.name || 'file');
        const size = formatFileSize(file.size);
        return `
            <span class="chat-file-chip">
                <i class="fa-solid ${icon}"></i>
                <span>${name} (${size})</span>
            </span>
        `;
    }).join('');

    preview.innerHTML = chips;
    preview.classList.add('visible');
}

function clearAttachmentPreview() {
    const preview = document.getElementById('spaceChatFilePreview');
    if (preview) {
        preview.innerHTML = '';
        preview.classList.remove('visible');
    }
}

function fileIconFromType(type = '') {
    const lower = type.toLowerCase();
    if (lower.includes('image')) {
        return 'fa-image';
    }
    if (lower.includes('video')) {
        return 'fa-video';
    }
    if (lower.includes('audio')) {
        return 'fa-microphone';
    }
    if (lower.includes('pdf')) {
        return 'fa-file-pdf';
    }
    if (lower.includes('zip')) {
        return 'fa-file-zipper';
    }
    return 'fa-paperclip';
}

function handleChatSubmit(event) {
    event.preventDefault();
    if (!chatState.conversationId || chatState.isSending) {
        return;
    }

    const messageField = document.getElementById('spaceChatInput');
    const fileInput = document.getElementById('spaceChatFileInput');
    const message = messageField.value.trim();

    if (!message && (!fileInput.files || !fileInput.files.length)) {
        return;
    }
    
    // Validate file sizes before upload
    if (fileInput.files && fileInput.files.length > 0) {
        if (!validateChatFileSize(fileInput, 30)) {
            chatState.isSending = false;
            return;
        }
    }

    chatState.isSending = true;
    const formData = new FormData();
    formData.append('action', 'send_message');
    formData.append('conversationid', chatState.conversationId);
    formData.append('message', message);
    formData.append('messagetype', message ? 'text' : 'file');
    formData.append('sesskey', spaceChatConfig.sesskey);

    if (fileInput.files && fileInput.files.length) {
        Array.from(fileInput.files).forEach(file => formData.append('attachments[]', file));
    }

    fetch(spaceChatConfig.ajax, {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
    })
        .then(response => response.json())
        .then(res => {
            if (!res.success) {
                // Check if it's a file size error
                if (res.error && (res.error.includes('too large') || res.error.includes('30MB') || res.error.includes('filetoobig'))) {
                    if (typeof RemuiAlert !== 'undefined' && RemuiAlert.error) {
                        RemuiAlert.error(res.error, 'File Too Large');
                    } else {
                        alert(res.error);
                    }
                } else {
                    throw new Error(res.error || 'Failed to send message');
                }
                chatState.isSending = false;
                return;
            }
            
            // Success - clear file input and message
            if (fileInput) {
                fileInput.value = '';
            }
            if (messageField) {
                messageField.value = '';
            }
            clearAttachmentPreview();
            messageField.value = '';
            fileInput.value = '';
            clearAttachmentPreview();
            if (res.data && res.data.message) {
                renderMessages([res.data.message]);
                markConversationRead(res.data.message.id);
            }
        })
        .catch(showChatError)
        .finally(() => {
            chatState.isSending = false;
        });
}

function loadOlderMessages() {
    if (!chatState.conversationId || !chatState.hasMore || chatState.isLoading) {
        return;
    }

    chatState.isLoading = true;
    chatState.currentPage++;

    fetch(apiUrl({
        action: 'get_conversation_messages',
        conversationid: chatState.conversationId,
        page: chatState.currentPage,
        perpage: chatState.perPage,
        sesskey: spaceChatConfig.sesskey
    }), { credentials: 'same-origin' })
        .then(response => response.json())
        .then(res => {
            if (!res.success) {
                throw new Error(res.error || 'Unable to load more messages');
            }
            const messagesPayload = res.data.messages || {};
            const items = Array.isArray(messagesPayload.items) ? messagesPayload.items : (Array.isArray(res.data.messages) ? res.data.messages : []);
             renderMessages(items, { prepend: true });
            chatState.hasMore = !!(messagesPayload.pagination && messagesPayload.pagination.hasnext);
            toggleLoadMore(chatState.hasMore);
        })
        .catch(showChatError)
        .finally(() => {
            chatState.isLoading = false;
        });
}

function toggleLoadMore(visible) {
    const btn = document.getElementById('spaceChatLoadMore');
    btn.style.display = visible ? 'inline-flex' : 'none';
}

function markConversationRead(messageId) {
    if (!messageId) {
        return;
    }
    fetch(apiUrl({
        action: 'mark_conversation_read',
        conversationid: chatState.conversationId,
        messageid: messageId,
        sesskey: spaceChatConfig.sesskey
    }), { method: 'POST', credentials: 'same-origin' });
}

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

function formatDate(timestamp) {
    if (!timestamp) {
        return '';
    }
    const date = new Date(Number(timestamp) * 1000);
    if (Number.isNaN(date.getTime())) {
        return '';
    }
    return date.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
}

// Format file size
function formatFileSize(bytes) {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

// Validate file size before upload (30MB limit) - for chat
function validateChatFileSize(fileInput, maxSizeMB) {
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
        
        // Use alert if RemuiAlert is not available, otherwise use RemuiAlert
        if (typeof RemuiAlert !== 'undefined' && RemuiAlert.error) {
            RemuiAlert.error(errorMsg, 'File Too Large');
        } else {
            alert(errorMsg);
        }
        return false;
    }
    
    return true;
}

function formatDateSeparator(timestamp) {
    if (!timestamp) {
        return '';
    }
    const date = new Date(Number(timestamp) * 1000);
    if (Number.isNaN(date.getTime())) {
        return '';
    }
    
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    
    const yesterday = new Date(today);
    yesterday.setDate(yesterday.getDate() - 1);
    
    const messageDate = new Date(date);
    messageDate.setHours(0, 0, 0, 0);
    
    if (messageDate.getTime() === today.getTime()) {
        return 'Today';
    } else if (messageDate.getTime() === yesterday.getTime()) {
        return 'Yesterday';
    } else {
        return date.toLocaleDateString([], { 
            weekday: 'long',
            year: 'numeric',
            month: 'long',
            day: 'numeric'
        });
    }
}

function getDateKey(timestamp) {
    if (!timestamp) {
        return '';
    }
    const date = new Date(Number(timestamp) * 1000);
    if (Number.isNaN(date.getTime())) {
        return '';
    }
    date.setHours(0, 0, 0, 0);
    return date.toISOString().split('T')[0];
}

function showChatError(error) {
    console.error(error);
    const container = document.getElementById('spaceChatMessages');
    container.innerHTML = `<div class="chat-empty-state">Something went wrong: ${escapeHtml(error.message || 'Please try again.')}</div>`;
}

function handleSidebarSearch(event) {
    const query = (event.target.value || '').trim().toLowerCase();
    const listBody = document.getElementById('chatSpaceListBody');
    const countElement = document.getElementById('chatListCount');
    
    if (!listBody) {
        return;
    }
    
    // Filter sidebar items (communities and spaces)
    const items = listBody.querySelectorAll('.chat-space-item');
    let visibleCount = 0;
    
    items.forEach(item => {
        const searchText = item.getAttribute('data-search-text') || '';
        const matches = !query || searchText.includes(query);
        
        if (matches) {
            item.style.display = '';
            visibleCount++;
        } else {
            item.style.display = 'none';
        }
    });
    
    // Update count
    if (countElement) {
        countElement.textContent = visibleCount;
    }
    
    // Show/hide section titles based on visible items
    const sections = listBody.querySelectorAll('.chat-list-section');
    sections.forEach(section => {
        const allItems = section.querySelectorAll('.chat-space-item');
        const visibleItems = Array.from(allItems).filter(item => item.style.display !== 'none');
        const sectionTitle = section.querySelector('.chat-list-section-title');
        if (sectionTitle) {
            sectionTitle.style.display = visibleItems.length > 0 ? '' : 'none';
        }
    });
}


function openChatMembersModal(contextType, entityId, entityName) {
    const modal = document.getElementById('chatSpaceMembersModal');
    const list = document.getElementById('chatSpaceMembersList');
    const title = modal?.querySelector('[data-role="members-title"]');
    if (!modal || !list) {
        return;
    }
    modal.classList.add('open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('chat-modal-open');
    modal.dataset.contextType = contextType;
    modal.dataset.entityId = entityId;
    if (title) {
        title.textContent = `${entityName || ''} Members`;
    }
    list.innerHTML = '<div class="chat-empty-state" style="padding: 32px 0;">Loading members...</div>';
    loadChatMembers(contextType, entityId);
}

function closeChatMembersModal() {
    const modal = document.getElementById('chatSpaceMembersModal');
    if (!modal) {
        return;
    }
    modal.classList.remove('open');
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('chat-modal-open');
}

document.addEventListener('keydown', event => {
    if (event.key === 'Escape') {
        const addModal = document.getElementById('chatAddMembersModal');
        if (addModal && addModal.classList.contains('open')) {
            closeChatAddMembersModal();
            return;
        }
        const modal = document.getElementById('chatSpaceMembersModal');
        if (modal && modal.classList.contains('open')) {
            closeChatMembersModal();
        }
    }
});

function loadChatMembers(contextType, entityId) {
    const params = {
        action: contextType === 'community' ? 'get_members' : 'get_space_members',
        sesskey: spaceChatConfig.sesskey
    };
    if (contextType === 'community') {
        params.communityid = entityId;
    } else {
        params.spaceid = entityId;
    }
    fetch(apiUrl(params), { credentials: 'same-origin' })
        .then(response => response.json())
        .then(res => {
            if (!res.success) {
                throw new Error(res.error || 'Unable to load members');
            }
            renderChatSpaceMembers(res.data || []);
        })
        .catch(error => {
            const list = document.getElementById('chatSpaceMembersList');
            if (list) {
                list.innerHTML = `<div class="chat-empty-state" style="padding: 32px 0;">${escapeHtml(error.message || 'Failed to load members')}</div>`;
            }
        });
}

let chatSelectedUsersForAdd = [];
let chatAvailableUsersList = [];
let chatSearchTimeout = null;
let chatSelectedRoleFilter = 'all';
let chatCurrentAddModalContextType = '';
let chatCurrentAddModalEntityId = 0;

function renderChatSpaceMembers(members) {
    const list = document.getElementById('chatSpaceMembersList');
    const addBtn = document.getElementById('chatAddMembersBtn');
    if (!list) {
        return;
    }
    
    // Check if user can manage (show add button)
    const modal = document.getElementById('chatSpaceMembersModal');
    const contextType = modal?.dataset.contextType || '';
    const entityId = parseInt(modal?.dataset.entityId || 0, 10);
    
    // Show add button if user is manager (you can enhance this with actual permission check)
    if (addBtn && contextType && entityId) {
        // For now, show if user is admin or super admin - you can add proper permission check
        addBtn.style.display = 'block';
    } else if (addBtn) {
        addBtn.style.display = 'none';
    }
    
    if (!Array.isArray(members) || !members.length) {
        list.innerHTML = '<div class="chat-empty-state" style="padding: 32px 0;">No members yet</div>';
        return;
    }
    const items = members.map(member => {
        const userId = member.userid || member.id || 0;
        const avatarSrc = `${spaceChatConfig.baseUrl}/user/pix.php/${userId}/f1.jpg`;
        const safeName = escapeHtml(member.name || `${member.firstname || ''} ${member.lastname || ''}`.trim());
        const safeEmail = escapeHtml(member.email || '');
        const roleLabel = member.role === 'admin' ? 'Admin' : 'Member';
        return `
            <div class="chat-member-item">
                <div class="chat-member-info">
                    <img class="chat-member-avatar" src="${avatarSrc}" alt="${safeName}" onerror="this.src='${spaceChatConfig.baseUrl}/pix/u/f1.png'">
                    <div class="chat-member-meta">
                        <p class="chat-member-name">${safeName}</p>
                        ${safeEmail ? `<p class="chat-member-email">${safeEmail}</p>` : ''}
                    </div>
                </div>
                <span class="chat-member-role">${roleLabel}</span>
            </div>
        `;
    }).join('');
    list.innerHTML = items;
}

function openChatAddMembersModal() {
    const modal = document.getElementById('chatSpaceMembersModal');
    if (!modal) {
        return;
    }
    
    chatCurrentAddModalContextType = modal.dataset.contextType || '';
    chatCurrentAddModalEntityId = parseInt(modal.dataset.entityId || 0, 10);
    
    if (!chatCurrentAddModalContextType || !chatCurrentAddModalEntityId) {
        alert('Unable to determine context for adding members');
        return;
    }
    
    chatSelectedUsersForAdd = [];
    chatAvailableUsersList = [];
    chatSelectedRoleFilter = 'all';
    document.getElementById('chatRoleFilterSelect').value = 'all';
    document.getElementById('chatUserSearchInput').value = '';
    document.getElementById('chatAvailableUsersList').innerHTML = '<div style="text-align: center; padding: 20px; color: #64748b;"><i class="fa-solid fa-spinner fa-spin" style="font-size: 24px; margin-bottom: 12px;"></i><p>Loading users...</p></div>';
    document.getElementById('chatSelectedUsersTags').innerHTML = '<p style="color: #64748b; font-size: 0.875rem;">No users selected</p>';
    
    const title = document.querySelector('[data-role="add-members-title"]');
    if (title) {
        title.textContent = chatCurrentAddModalContextType === 'community' ? 'Add Members to Community' : 'Add Members to Space';
    }
    
    const addModal = document.getElementById('chatAddMembersModal');
    if (addModal) {
        addModal.classList.add('open');
        addModal.setAttribute('aria-hidden', 'false');
    }
    
    // Load initial 10 users
    loadChatInitialUsers();
}

function closeChatAddMembersModal() {
    const modal = document.getElementById('chatAddMembersModal');
    if (modal) {
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');
    }
}

function handleChatRoleFilterChange() {
    const select = document.getElementById('chatRoleFilterSelect');
    chatSelectedRoleFilter = select.value;
    const searchTerm = document.getElementById('chatUserSearchInput').value.trim();
    if (searchTerm.length >= 2) {
        chatSearchUsers();
    } else {
        loadChatInitialUsers();
    }
}

function loadChatInitialUsers() {
    document.getElementById('chatAvailableUsersList').innerHTML = '<div style="text-align: center; padding: 20px; color: #64748b;"><i class="fa-solid fa-spinner fa-spin" style="font-size: 24px; margin-bottom: 12px;"></i><p>Loading users...</p></div>';
    
    // Get current members to exclude them
    const params = {
        action: chatCurrentAddModalContextType === 'community' ? 'get_members' : 'get_space_members',
        sesskey: spaceChatConfig.sesskey
    };
    if (chatCurrentAddModalContextType === 'community') {
        params.communityid = chatCurrentAddModalEntityId;
    } else {
        params.spaceid = chatCurrentAddModalEntityId;
    }
    
    fetch(apiUrl(params), { credentials: 'same-origin' })
        .then(response => response.json())
        .then(membersData => {
            const currentMemberIds = membersData.success && membersData.data ? membersData.data.map(m => parseInt(m.userid || m.id)) : [];
            
            // If adding to a space, we need to get community members first
            if (chatCurrentAddModalContextType === 'space') {
                const communityId = spaceChatConfig.communityId;
                if (!communityId) {
                    document.getElementById('chatAvailableUsersList').innerHTML = '<div style="text-align: center; padding: 20px; color: #ef4444;"><p>Unable to determine community</p></div>';
                    return;
                }
                
                // Get all community members
                fetch(apiUrl({
                    action: 'get_members',
                    communityid: communityId,
                    sesskey: spaceChatConfig.sesskey
                }), { credentials: 'same-origin' })
                    .then(response => response.json())
                    .then(communityMembersData => {
                        const communityMemberIds = communityMembersData.success && communityMembersData.data 
                            ? communityMembersData.data.map(m => parseInt(m.userid || m.id)) 
                            : [];
                        
                        // Get available users with role filter
                        const roleTypeParam = chatSelectedRoleFilter !== 'all' ? `&roletype=${chatSelectedRoleFilter}` : '';
                        fetch(apiUrl({
                            action: 'get_users',
                            sesskey: spaceChatConfig.sesskey
                        }) + roleTypeParam, { credentials: 'same-origin' })
                            .then(response => response.json())
                            .then(data => {
                                if (data.success && data.data) {
                                    // Filter to only community members, exclude current space members, and limit to 10
                                    chatAvailableUsersList = data.data.filter(user => {
                                        const userId = parseInt(user.id);
                                        return communityMemberIds.includes(userId) && !currentMemberIds.includes(userId);
                                    }).slice(0, 10);
                                    renderChatAvailableUsers();
                                } else {
                                    document.getElementById('chatAvailableUsersList').innerHTML = '<div style="text-align: center; padding: 20px; color: #ef4444;"><p>Error loading users</p></div>';
                                }
                            })
                            .catch(error => {
                                console.error('Error loading users:', error);
                                document.getElementById('chatAvailableUsersList').innerHTML = '<div style="text-align: center; padding: 20px; color: #ef4444;"><p>Error loading users</p></div>';
                            });
                    })
                    .catch(error => {
                        console.error('Error getting community members:', error);
                        document.getElementById('chatAvailableUsersList').innerHTML = '<div style="text-align: center; padding: 20px; color: #ef4444;"><p>Error loading community members</p></div>';
                    });
            } else {
                // For community, show all users (existing behavior)
                const roleTypeParam = chatSelectedRoleFilter !== 'all' ? `&roletype=${chatSelectedRoleFilter}` : '';
                fetch(apiUrl({
                    action: 'get_users',
                    sesskey: spaceChatConfig.sesskey
                }) + roleTypeParam, { credentials: 'same-origin' })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success && data.data) {
                            // Filter to exclude current members and limit to 10
                            chatAvailableUsersList = data.data.filter(user => {
                                const userId = parseInt(user.id);
                                return !currentMemberIds.includes(userId);
                            }).slice(0, 10);
                            renderChatAvailableUsers();
                        } else {
                            document.getElementById('chatAvailableUsersList').innerHTML = '<div style="text-align: center; padding: 20px; color: #ef4444;"><p>Error loading users</p></div>';
                        }
                    })
                    .catch(error => {
                        console.error('Error loading users:', error);
                        document.getElementById('chatAvailableUsersList').innerHTML = '<div style="text-align: center; padding: 20px; color: #ef4444;"><p>Error loading users</p></div>';
                    });
            }
        })
        .catch(error => {
            console.error('Error getting current members:', error);
        });
}

function chatSearchUsers() {
    const searchTerm = document.getElementById('chatUserSearchInput').value.trim();
    
    if (chatSearchTimeout) {
        clearTimeout(chatSearchTimeout);
    }
    
    if (searchTerm.length < 2) {
        loadChatInitialUsers();
        return;
    }
    
    chatSearchTimeout = setTimeout(() => {
        // Get current members to exclude them
        const params = {
            action: chatCurrentAddModalContextType === 'community' ? 'get_members' : 'get_space_members',
            sesskey: spaceChatConfig.sesskey
        };
        if (chatCurrentAddModalContextType === 'community') {
            params.communityid = chatCurrentAddModalEntityId;
        } else {
            params.spaceid = chatCurrentAddModalEntityId;
        }
        
        fetch(apiUrl(params), { credentials: 'same-origin' })
            .then(response => response.json())
            .then(membersData => {
                const currentMemberIds = membersData.success && membersData.data ? membersData.data.map(m => parseInt(m.userid || m.id)) : [];
                
                // If adding to a space, we need to get community members first
                if (chatCurrentAddModalContextType === 'space') {
                    // Use community ID from config
                    const communityId = spaceChatConfig.communityId;
                    if (!communityId) {
                        document.getElementById('chatAvailableUsersList').innerHTML = '<div style="text-align: center; padding: 20px; color: #ef4444;"><p>Unable to determine community</p></div>';
                        return;
                    }
                    
                    // Get all community members
                    fetch(apiUrl({
                        action: 'get_members',
                        communityid: communityId,
                        sesskey: spaceChatConfig.sesskey
                    }), { credentials: 'same-origin' })
                        .then(response => response.json())
                        .then(communityMembersData => {
                            const communityMemberIds = communityMembersData.success && communityMembersData.data 
                                ? communityMembersData.data.map(m => parseInt(m.userid || m.id)) 
                                : [];
                            
                            // Get available users with role filter
                            const roleTypeParam = chatSelectedRoleFilter !== 'all' ? `&roletype=${chatSelectedRoleFilter}` : '';
                            fetch(apiUrl({
                                action: 'get_users',
                                sesskey: spaceChatConfig.sesskey
                            }) + roleTypeParam, { credentials: 'same-origin' })
                                .then(response => response.json())
                                .then(data => {
                                    if (data.success && data.data) {
                                        chatAvailableUsersList = data.data.filter(user => {
                                            const userId = parseInt(user.id);
                                            const fullName = (user.name || `${user.firstname || ''} ${user.lastname || ''}`.trim() || '').toLowerCase();
                                            const email = (user.email || '').toLowerCase();
                                            const searchLower = searchTerm.toLowerCase();
                                            // Only show users who are community members and not already space members
                                            return communityMemberIds.includes(userId) &&
                                                   !currentMemberIds.includes(userId) && 
                                                   (fullName.includes(searchLower) || email.includes(searchLower));
                                        }).slice(0, 10);
                                        renderChatAvailableUsers();
                                    } else {
                                        document.getElementById('chatAvailableUsersList').innerHTML = '<div style="text-align: center; padding: 20px; color: #ef4444;"><p>Error loading users</p></div>';
                                    }
                                })
                                .catch(error => {
                                    console.error('Error searching users:', error);
                                    document.getElementById('chatAvailableUsersList').innerHTML = '<div style="text-align: center; padding: 20px; color: #ef4444;"><p>Error searching users</p></div>';
                                });
                        })
                        .catch(error => {
                            console.error('Error getting community members:', error);
                            document.getElementById('chatAvailableUsersList').innerHTML = '<div style="text-align: center; padding: 20px; color: #ef4444;"><p>Error loading community members</p></div>';
                        });
                } else {
                    // For community, show all users (existing behavior)
                    const roleTypeParam = chatSelectedRoleFilter !== 'all' ? `&roletype=${chatSelectedRoleFilter}` : '';
                    fetch(apiUrl({
                        action: 'get_users',
                        sesskey: spaceChatConfig.sesskey
                    }) + roleTypeParam, { credentials: 'same-origin' })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success && data.data) {
                                chatAvailableUsersList = data.data.filter(user => {
                                    const userId = parseInt(user.id);
                                    const fullName = (user.name || `${user.firstname || ''} ${user.lastname || ''}`.trim() || '').toLowerCase();
                                    const email = (user.email || '').toLowerCase();
                                    const searchLower = searchTerm.toLowerCase();
                                    return !currentMemberIds.includes(userId) && 
                                           (fullName.includes(searchLower) || email.includes(searchLower));
                                }).slice(0, 10);
                                renderChatAvailableUsers();
                            } else {
                                document.getElementById('chatAvailableUsersList').innerHTML = '<div style="text-align: center; padding: 20px; color: #ef4444;"><p>Error loading users</p></div>';
                            }
                        })
                        .catch(error => {
                            console.error('Error searching users:', error);
                            document.getElementById('chatAvailableUsersList').innerHTML = '<div style="text-align: center; padding: 20px; color: #ef4444;"><p>Error searching users</p></div>';
                        });
                }
            })
            .catch(error => {
                console.error('Error getting current members:', error);
            });
    }, 300);
}

function renderChatAvailableUsers() {
    const container = document.getElementById('chatAvailableUsersList');
    
    if (!chatAvailableUsersList.length) {
        container.innerHTML = '<div style="text-align: center; padding: 20px; color: #64748b;"><p>No users found</p></div>';
        return;
    }
    
    const html = chatAvailableUsersList.map(user => {
        const avatarUrl = `${spaceChatConfig.baseUrl}/user/pix.php/${user.id}/f1.jpg`;
        const isSelected = chatSelectedUsersForAdd.some(su => parseInt(su.id) === parseInt(user.id));
        const fullName = user.name || `${user.firstname || ''} ${user.lastname || ''}`.trim() || 'Unknown User';
        
        return `
            <div class="user-select-item ${isSelected ? 'selected' : ''}" onclick="chatToggleUserSelection(${user.id}, '${escapeHtml(fullName)}', '${escapeHtml(user.email || '')}')">
                <div class="user-select-item-info">
                    <img src="${avatarUrl}" alt="${escapeHtml(fullName)}" class="user-select-item-avatar" onerror="this.src='${spaceChatConfig.baseUrl}/pix/u/f1.png'">
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
    renderChatSelectedUsersTags();
}

function chatToggleUserSelection(userId, userName, userEmail) {
    const user = chatAvailableUsersList.find(u => parseInt(u.id) === parseInt(userId));
    if (!user) return;
    
    const index = chatSelectedUsersForAdd.findIndex(su => parseInt(su.id) === parseInt(userId));
    if (index > -1) {
        chatSelectedUsersForAdd.splice(index, 1);
    } else {
        chatSelectedUsersForAdd.push({
            id: userId,
            name: userName,
            email: userEmail
        });
    }
    renderChatAvailableUsers();
}

function renderChatSelectedUsersTags() {
    const container = document.getElementById('chatSelectedUsersTags');
    
    if (!chatSelectedUsersForAdd.length) {
        container.innerHTML = '<p style="color: #64748b; font-size: 0.875rem;">No users selected</p>';
        return;
    }
    
    const html = chatSelectedUsersForAdd.map(user => `
        <span class="selected-user-tag">
            ${escapeHtml(user.name)}
            <span class="selected-user-tag-remove" onclick="event.stopPropagation(); chatRemoveSelectedUser(${user.id})">×</span>
        </span>
    `).join('');
    
    container.innerHTML = html;
}

function chatRemoveSelectedUser(userId) {
    chatSelectedUsersForAdd = chatSelectedUsersForAdd.filter(u => parseInt(u.id) !== parseInt(userId));
    renderChatAvailableUsers();
}

function submitChatAddMembers() {
    if (!chatSelectedUsersForAdd.length) {
        alert('Please select at least one user to add');
        return;
    }
    
    const memberIds = chatSelectedUsersForAdd.map(u => u.id);
    const submitBtn = event.target;
    const originalText = submitBtn.textContent;
    submitBtn.disabled = true;
    submitBtn.textContent = 'Adding...';
    
    const formData = new FormData();
    const action = chatCurrentAddModalContextType === 'community' ? 'add_members' : 'add_space_members';
    formData.append('action', action);
    formData.append('sesskey', spaceChatConfig.sesskey);
    
    if (chatCurrentAddModalContextType === 'community') {
        formData.append('communityid', chatCurrentAddModalEntityId);
    } else {
        formData.append('spaceid', chatCurrentAddModalEntityId);
    }
    
    memberIds.forEach(id => {
        formData.append('memberids[]', id);
    });
    
    fetch(spaceChatConfig.ajax, {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
    })
        .then(response => response.json())
        .then(data => {
            submitBtn.disabled = false;
            submitBtn.textContent = originalText;
            
            if (data.success) {
                closeChatAddMembersModal();
                // Reload members
                const modal = document.getElementById('chatSpaceMembersModal');
                if (modal) {
                    loadChatMembers(modal.dataset.contextType, parseInt(modal.dataset.entityId, 10));
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
</script>

<?php
echo $OUTPUT->footer();

