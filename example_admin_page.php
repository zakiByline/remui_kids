<?php
// SPDX-License-Identifier: GPL-3.0-or-later
/**
 * Example admin page using the new highschool sidebar with toggle functionality
 *
 * @package    theme_remui_kids
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib/highschool_sidebar.php');

require_login();

$PAGE->set_context(context_system::instance());
$PAGE->set_url('/theme/remui_kids/example_admin_page.php');
$PAGE->set_title('Admin Dashboard Example');
$PAGE->set_heading('Admin Dashboard Example');

// Example page content
$page_content = '
<div class="admin-dashboard-example">
    <div class="page-header">
        <h1>Admin Dashboard</h1>
        <p class="page-description">This is an example page showing the new collapsible sidebar design.</p>
    </div>
    
    <div class="dashboard-cards">
        <div class="dashboard-card">
            <div class="card-icon">
                <i class="fa fa-users"></i>
            </div>
            <div class="card-content">
                <h3>Total Users</h3>
                <div class="card-number">1,234</div>
                <div class="card-change positive">+12% from last month</div>
            </div>
        </div>
        
        <div class="dashboard-card">
            <div class="card-icon">
                <i class="fa fa-book"></i>
            </div>
            <div class="card-content">
                <h3>Active Courses</h3>
                <div class="card-number">56</div>
                <div class="card-change positive">+3 new courses</div>
            </div>
        </div>
        
        <div class="dashboard-card">
            <div class="card-icon">
                <i class="fa fa-certificate"></i>
            </div>
            <div class="card-content">
                <h3>Certificates Issued</h3>
                <div class="card-number">789</div>
                <div class="card-change positive">+45 this week</div>
            </div>
        </div>
        
        <div class="dashboard-card">
            <div class="card-icon">
                <i class="fa fa-chart-line"></i>
            </div>
            <div class="card-content">
                <h3>System Health</h3>
                <div class="card-number">98%</div>
                <div class="card-change neutral">Excellent</div>
            </div>
        </div>
    </div>
    
    <div class="content-section">
        <h2>Recent Activity</h2>
        <div class="activity-list">
            <div class="activity-item">
                <div class="activity-icon">
                    <i class="fa fa-user-plus"></i>
                </div>
                <div class="activity-content">
                    <div class="activity-title">New user registered</div>
                    <div class="activity-time">2 minutes ago</div>
                </div>
            </div>
            
            <div class="activity-item">
                <div class="activity-icon">
                    <i class="fa fa-certificate"></i>
                </div>
                <div class="activity-content">
                    <div class="activity-title">Certificate approved</div>
                    <div class="activity-time">15 minutes ago</div>
                </div>
            </div>
            
            <div class="activity-item">
                <div class="activity-icon">
                    <i class="fa fa-book"></i>
                </div>
                <div class="activity-content">
                    <div class="activity-title">New course created</div>
                    <div class="activity-time">1 hour ago</div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.admin-dashboard-example {
    max-width: 1200px;
    margin: 0 auto;
}

.page-header {
    margin-bottom: 30px;
}

.page-header h1 {
    color: #1e293b;
    font-size: 2rem;
    font-weight: 700;
    margin-bottom: 8px;
}

.page-description {
    color: #64748b;
    font-size: 1rem;
    margin: 0;
}

.dashboard-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-bottom: 40px;
}

.dashboard-card {
    background: linear-gradient(135deg, #faf8ff 0%, #ffffff 100%);
    border: 1px solid #f0e8f7;
    border-radius: 12px;
    padding: 24px;
    display: flex;
    align-items: center;
    gap: 16px;
    transition: all 0.2s ease;
    box-shadow: 0 2px 4px rgba(184, 169, 217, 0.08);
}

.dashboard-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(184, 169, 217, 0.15);
}

.card-icon {
    width: 60px;
    height: 60px;
    background: linear-gradient(135deg, #b8a9d9, #a599d1);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #ffffff;
    font-size: 24px;
}

.card-content h3 {
    color: #64748b;
    font-size: 14px;
    font-weight: 500;
    margin: 0 0 8px 0;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.card-number {
    color: #1e293b;
    font-size: 28px;
    font-weight: 700;
    margin-bottom: 4px;
}

.card-change {
    font-size: 12px;
    font-weight: 500;
}

.card-change.positive {
    color: #a9d9c9;
}

.card-change.neutral {
    color: #9d8bb5;
}

.content-section {
    background: #ffffff;
    border: 1px solid #f0e8f7;
    border-radius: 12px;
    padding: 24px;
}

.content-section h2 {
    color: #1e293b;
    font-size: 1.5rem;
    font-weight: 600;
    margin: 0 0 20px 0;
}

.activity-list {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.activity-item {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 16px;
    background: #faf8ff;
    border-radius: 8px;
    border: 1px solid #f0e8f7;
}

.activity-icon {
    width: 40px;
    height: 40px;
    background: linear-gradient(135deg, #a9d9c9, #8cc9b8);
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #ffffff;
    font-size: 16px;
}

.activity-title {
    color: #1e293b;
    font-weight: 500;
    margin-bottom: 4px;
}

.activity-time {
    color: #64748b;
    font-size: 12px;
}

@media (max-width: 768px) {
    .dashboard-cards {
        grid-template-columns: 1fr;
    }
    
    .dashboard-card {
        padding: 20px;
    }
    
    .content-section {
        padding: 20px;
    }
}
</style>
';

// Use the new layout function
remui_kids_render_highschool_page_with_layout($OUTPUT, 'dashboard', $USER, $page_content);