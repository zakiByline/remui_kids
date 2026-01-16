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
 * Schools Management Page
 *
 * @package    theme_remui_kids
 * @copyright  2024 Kodeit
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../../config.php');
require_once($CFG->libdir.'/adminlib.php');

// Check if user is logged in
require_login();

// Check if user has admin capabilities
$context = context_system::instance();
require_capability('moodle/site:config', $context);

// Get school statistics from database
try {
    // Get total schools from mdl_company table
    $total_schools = $DB->count_records('company');
    
    // Get active schools (companies with courses)
    $active_schools = $DB->count_records_sql(
        "SELECT COUNT(DISTINCT c.id) 
         FROM {company} c 
         JOIN {company_course} cc ON cc.companyid = c.id"
    );
    
    // Get countries from company table
    $countries = $DB->count_records_sql(
        "SELECT COUNT(DISTINCT country) 
         FROM {company} 
         WHERE country != '' AND country IS NOT NULL"
    );
    
    // Get suspended schools count (since timecreated doesn't exist)
    $recent_additions = $DB->count_records('company', ['suspended' => 1]);
    
    // Get all schools for dropdown
    $all_schools = $DB->get_records('company', [], 'name ASC');
    
    // Get selected school ID from URL parameter
    $selected_school_id = optional_param('school_id', 0, PARAM_INT);
    $selected_school = null;
    
    if ($selected_school_id > 0) {
        $selected_school = $DB->get_record('company', ['id' => $selected_school_id]);
    }
    
    // Get teacher/student ratio data for pie chart - simplified query
    $chart_data = $DB->get_records_sql(
        "SELECT 
            c.name as school_name,
            COUNT(DISTINCT cu.userid) as total_users
         FROM {company} c
         LEFT JOIN {company_users} cu ON cu.companyid = c.id
         GROUP BY c.id, c.name
         HAVING total_users > 0
         ORDER BY total_users DESC
         LIMIT 8"
    );
    
    // Get detailed data for selected school
    $school_details = null;
    if ($selected_school) {
        
        // Get teachers count - match the detailed query
        $teachers_count = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT u.id) 
             FROM {user} u
             JOIN {company_users} cu ON cu.userid = u.id
             JOIN {role_assignments} ra ON ra.userid = u.id
             JOIN {role} r ON r.id = ra.roleid
             WHERE cu.companyid = ? AND r.shortname = 'editingteacher' AND u.deleted = 0",
            [$selected_school_id]
        );
        
        // Get students count
        $students_count = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT u.id) 
             FROM {user} u
             JOIN {company_users} cu ON cu.userid = u.id
             JOIN {role_assignments} ra ON ra.userid = u.id
             JOIN {role} r ON r.id = ra.roleid
             WHERE cu.companyid = ? AND r.shortname = 'student' AND u.deleted = 0",
            [$selected_school_id]
        );
        
        // Get courses count
        $courses_count = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT c.id) 
             FROM {course} c
             JOIN {company_course} cc ON cc.courseid = c.id
             WHERE cc.companyid = ? AND c.visible = 1",
            [$selected_school_id]
        );
        
        // Get detailed lists for modal display
        $teachers_list = $DB->get_records_sql(
            "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email, u.lastaccess
             FROM {user} u
             JOIN {company_users} cu ON cu.userid = u.id
             JOIN {role_assignments} ra ON ra.userid = u.id
             JOIN {role} r ON r.id = ra.roleid
             WHERE cu.companyid = ? AND (r.shortname = 'editingteacher') AND u.deleted = 0
             ORDER BY u.firstname, u.lastname",
            [$selected_school_id]
        );
        
        $students_list = $DB->get_records_sql(
            "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email, u.lastaccess
             FROM {user} u
             JOIN {company_users} cu ON cu.userid = u.id
             JOIN {role_assignments} ra ON ra.userid = u.id
             JOIN {role} r ON r.id = ra.roleid
             WHERE cu.companyid = ? AND r.shortname = 'student' AND u.deleted = 0
             ORDER BY u.firstname, u.lastname",
            [$selected_school_id]
        );
        
        $courses_list = $DB->get_records_sql(
            "SELECT DISTINCT c.id, c.fullname, c.shortname, c.summary, c.timecreated
             FROM {course} c
             JOIN {company_course} cc ON cc.courseid = c.id
             WHERE cc.companyid = ? AND c.visible = 1
             ORDER BY c.fullname",
            [$selected_school_id]
        );
        
        $school_details = [
            'teachers' => $teachers_count,
            'students' => $students_count,
            'courses' => $courses_count,
            'teachers_list' => $teachers_list,
            'students_list' => $students_list,
            'courses_list' => $courses_list
        ];
    }
    
} catch (Exception $e) {
    // Fallback values if database queries fail
    $total_schools = 0;
    $active_schools = 0;
    $countries = 0;
    $recent_additions = 0;
    $chart_data = [];
}

// Set up page
$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/admin/schools_management.php');
$PAGE->set_title('Schools Management Center');
$PAGE->set_heading('Schools Management Center');
$PAGE->set_pagelayout('admin');

echo $OUTPUT->header();

// Add custom CSS for the schools management with admin sidebar and pastel colors
echo "<style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
    
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }
    
    body {
        font-family: 'Inter', sans-serif;
        background: linear-gradient(135deg, #fef7f7 0%, #f0f9ff 50%, #f0fdf4 100%);
        min-height: 100vh;
        overflow-x: hidden;
    }
    
    /* Admin Sidebar Navigation - Sticky on all pages */
    .admin-sidebar {
        position: fixed !important;
        top: 0;
        left: 0;
        width: 280px;
        height: 100vh;
        background: white;
        border-right: 1px solid #e9ecef;
        z-index: 1000;
        overflow-y: auto;
        box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1);
        will-change: transform;
        backface-visibility: hidden;
    }
    
    .admin-sidebar .sidebar-content {
        padding: 6rem 0 2rem 0;
    }
    
    .admin-sidebar .sidebar-section {
        margin-bottom: 2rem;
    }
    
    .admin-sidebar .sidebar-category {
        font-size: 0.75rem;
        font-weight: 700;
        color: #6c757d;
        text-transform: uppercase;
        letter-spacing: 1px;
        margin-bottom: 1rem;
        padding: 0 2rem;
        margin-top: 0;
    }
    
    .admin-sidebar .sidebar-menu {
        list-style: none;
        padding: 0;
        margin: 0;
    }
    
    .admin-sidebar .sidebar-item {
        margin-bottom: 0.25rem;
    }
    
    .admin-sidebar .sidebar-link {
        display: flex;
        align-items: center;
        padding: 0.75rem 2rem;
        color: #495057;
        text-decoration: none;
        transition: all 0.3s ease;
        border-left: 3px solid transparent;
    }
    
    .admin-sidebar .sidebar-link:hover {
        background-color: #f8f9fa;
        color: #2c3e50;
        text-decoration: none;
        border-left-color: #667eea;
    }
    
    .admin-sidebar .sidebar-icon {
        width: 20px;
        height: 20px;
        margin-right: 1rem;
        font-size: 1rem;
        color: #6c757d;
        text-align: center;
    }
    
    .admin-sidebar .sidebar-text {
        font-size: 0.9rem;
        font-weight: 500;
    }
    
    .admin-sidebar .sidebar-item.active .sidebar-link {
        background-color: #e3f2fd;
        color: #1976d2;
        border-left-color: #1976d2;
    }
    
    .admin-sidebar .sidebar-item.active .sidebar-icon {
        color: #1976d2;
    }
    
    /* Scrollbar styling */
    .admin-sidebar::-webkit-scrollbar {
        width: 6px;
    }
    
    .admin-sidebar::-webkit-scrollbar-track {
        background: #f1f1f1;
    }
    
    .admin-sidebar::-webkit-scrollbar-thumb {
        background: #c1c1c1;
        border-radius: 3px;
    }
    
    .admin-sidebar::-webkit-scrollbar-thumb:hover {
        background: #a8a8a8;
    }

    /* Main content area with sidebar - FULL SCREEN */
    .admin-main-content {
        position: fixed;
        top: 0;
        left: 280px;
        width: calc(100vw - 280px);
        height: 100vh;
        background-color: #ffffff;
        overflow-y: auto;
        z-index: 99;
        will-change: transform;
        backface-visibility: hidden;
        padding-top: 80px; /* Add padding to account for topbar */
    }
    
    /* Mobile responsive */
    @media (max-width: 768px) {
        .admin-sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: 280px;
            height: 100vh;
            transform: translateX(-100%);
            transition: transform 0.3s ease;
            z-index: 1001;
        }
        
        .admin-sidebar.sidebar-open {
            transform: translateX(0);
        }
        
        .admin-main-content {
            position: relative;
            left: 0;
            width: 100vw;
            height: auto;
            min-height: 100vh;
            padding-top: 20px;
        }
    }

    /* Schools Management Styles */
    .schools-container {
        max-width: 1800px;
        margin: 0 auto;
        padding: 30px;
        background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
        min-height: 100vh;
    }

    /* Page Header */
    .page-header {
        background: linear-gradient(135deg, #e1f5fe 0%, #f3e5f5 100%);
        padding: 2rem;
        border-radius: 15px;
        margin-bottom: 2rem;
        box-shadow: 0 4px 20px rgba(225, 245, 254, 0.3);
        border: 1px solid #b3e5fc;
    }

    .header-content {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 1rem;
    }

    .header-text {
        flex: 1;
        text-align: left;
    }

    .page-title {
        margin: 0 0 0.5rem 0;
        font-size: 2.5rem;
        font-weight: 700;
        color: #1976d2;
        text-shadow: 2px 2px 4px rgba(25, 118, 210, 0.1);
    }

    .page-subtitle {
        margin: 0;
        font-size: 1.1rem;
        color: #546e7a;
        opacity: 0.9;
        font-weight: 400;
        justify-content: center;
        text-align: center;
    }

    .header-actions {
        display: flex;
        gap: 1rem;
    }

    .header-btn {
        background: linear-gradient(135deg, #81d4fa 0%, #4fc3f7 100%);
        color: white;
        border: none;
        padding: 0.75rem 1.5rem;
        border-radius: 10px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 0.9rem;
    }

    .header-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 15px rgba(129, 212, 250, 0.4);
    }

    /* Statistics Cards */
    .stats-container {
        margin-bottom: 2rem;
    }

    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 1.5rem;
    }

    .stat-card {
        background: white;
        border-radius: 15px;
        padding: 2rem;
        display: flex;
        align-items: center;
        gap: 1.5rem;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        border: 1px solid rgba(148, 163, 184, 0.35);
        transition: all 0.3s ease;
        position: relative;
        overflow: hidden;
    }

    .stat-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
    }

    .stat-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 8px 30px rgba(0, 0, 0, 0.15);
    }

    .stat-card.total-schools::before {
        background: linear-gradient(90deg, #81d4fa, #4fc3f7);
    }
    .stat-card.total-schools {
        border-color: rgba(59, 130, 246, 0.2);
    }
    .stat-card.total-schools:hover {
        border-color: rgba(37, 99, 235, 0.35);
    }

    .stat-card.active-schools::before {
        background: linear-gradient(90deg, #a5d6a7, #81c784);
    }
    .stat-card.active-schools {
        border-color: rgba(34, 197, 94, 0.2);
    }
    .stat-card.active-schools:hover {
        border-color: rgba(21, 128, 61, 0.35);
    }

    .stat-card.countries::before {
        background: linear-gradient(90deg, #ce93d8, #ba68c8);
    }
    .stat-card.countries {
        border-color: rgba(139, 92, 246, 0.2);
    }
    .stat-card.countries:hover {
        border-color: rgba(109, 40, 217, 0.35);
    }

    .stat-card.recent-additions::before {
        background: linear-gradient(90deg, #ffcc02, #ff9800);
    }
    .stat-card.recent-additions {
        border-color: rgba(249, 115, 22, 0.2);
    }
    .stat-card.recent-additions:hover {
        border-color: rgba(194, 65, 12, 0.35);
    }

    .stat-icon {
        width: 60px;
        height: 60px;
        border-radius: 15px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.8rem;
        color: white;
        flex-shrink: 0;
    }

    .stat-card.total-schools .stat-icon {
        background: linear-gradient(135deg, #81d4fa 0%, #4fc3f7 100%);
    }

    .stat-card.active-schools .stat-icon {
        background: linear-gradient(135deg, #a5d6a7 0%, #81c784 100%);
    }

    .stat-card.countries .stat-icon {
        background: linear-gradient(135deg, #ce93d8 0%, #ba68c8 100%);
    }

    .stat-card.recent-additions .stat-icon {
        background: linear-gradient(135deg, #ffcc02 0%, #ff9800 100%);
    }

    .stat-content {
        flex: 1;
    }

    .stat-number {
        font-size: 2.5rem;
        font-weight: 700;
        color: #2c3e50;
        line-height: 1;
        margin-bottom: 0.25rem;
    }

    .stat-label {
        font-size: 1rem;
        color: #6c757d;
        font-weight: 500;
    }



    /* Schools Management Grid */
    .schools-management-section {
        background: white;
        border-radius: 15px;
        padding: 2rem;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        border: 1px solid rgba(0, 0, 0, 0.05);
    }

    .management-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
        gap: 1.5rem;
        margin-bottom: 2rem;
    }

    .management-item {
        background: white;
        border-radius: 15px;
        padding: 1.5rem;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        border: 1px solid rgba(0, 0, 0, 0.05);
        transition: all 0.3s ease;
        position: relative;
        overflow: hidden;
        cursor: pointer;
    }

    .management-item:hover {
        transform: translateY(-5px);
        box-shadow: 0 8px 30px rgba(0, 0, 0, 0.15);
    }

    .management-icon {
        width: 60px;
        height: 60px;
        border-radius: 15px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 1rem;
        font-size: 1.5rem;
        color: white;
        flex-shrink: 0;
    }

    .management-icon.create {
        background: linear-gradient(135deg, #a5d6a7 0%, #81c784 100%);
    }

    .management-icon.edit {
        background: linear-gradient(135deg, #81d4fa 0%, #4fc3f7 100%);
    }

    .management-icon.advanced {
        background: linear-gradient(135deg, #ce93d8 0%, #ba68c8 100%);
    }

    .management-icon.manage {
        background: linear-gradient(135deg, #ffcc02 0%, #ff9800 100%);
    }

    .management-icon.departments {
        background: linear-gradient(135deg, #80deea 0%, #4dd0e1 100%);
    }

    .management-icon.assign {
        background: linear-gradient(135deg, #9575cd 0%, #7e57c2 100%);
    }

    .management-icon.profiles {
        background: linear-gradient(135deg, #f48fb1 0%, #f06292 100%);
    }

    .management-icon.restrict {
        background: linear-gradient(135deg, #ef5350 0%, #f44336 100%);
    }

    .management-icon.import {
        background: linear-gradient(135deg, #ffcc02 0%, #ff9800 100%);
    }

    .management-icon.email {
        background: linear-gradient(135deg, #f48fb1 0%, #f06292 100%);
    }

    .management-title {
        font-size: 1.2rem;
        font-weight: 700;
        color: #2c3e50;
        margin: 0 0 0.5rem 0;
        line-height: 1.3;
    }

    .management-description {
        font-size: 0.9rem;
        color: #6c757d;
        margin: 0 0 1rem 0;
        line-height: 1.4;
    }

    .availability-tag {
        background: linear-gradient(135deg, #a5d6a7 0%, #81c784 100%);
        color: white;
        padding: 0.25rem 0.75rem;
        border-radius: 20px;
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .item-count {
        display: block;
        font-size: 0.8rem;
        color: #6c757d;
        margin-top: 0.5rem;
        font-style: italic;
    }


    /* School Details Section */
    .school-details-section {
        background: white;
        border-radius: 15px;
        padding: 2rem;
        margin-bottom: 2rem;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        border: 1px solid rgba(0, 0, 0, 0.05);
    }

    .school-selector-dropdown {
        background: #ffffff;
        padding: 1.5rem;
        border-radius: 15px;
        margin-bottom: 2rem;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        border: 1px solid rgba(0, 0, 0, 0.05);
    }

    .school-dropdown {
        width: 100%;
        padding: 0.75rem 1rem;
        border: 2px solid #e1f5fe;
        border-radius: 10px;
        font-size: 1rem;
        background: white;
        transition: all 0.3s ease;
    }

    .school-dropdown:focus {
        outline: none;
        border-color: #81d4fa;
        box-shadow: 0 0 0 3px rgba(129, 212, 250, 0.1);
    }

    .school-details-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1.5rem;
        margin-bottom: 2rem;
    }

    .detail-card {
        background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
        border-radius: 15px;
        padding: 1.5rem;
        text-align: center;
        border: 2px solid transparent;
        transition: all 0.3s ease;
        position: relative;
        overflow: hidden;
    }

    .detail-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
    }

    .detail-card.teachers::before {
        background: linear-gradient(90deg, #81d4fa, #4fc3f7);
    }

    .detail-card.students::before {
        background: linear-gradient(90deg, #a5d6a7, #81c784);
    }

    .detail-card.courses::before {
        background: linear-gradient(90deg, #ce93d8, #ba68c8);
    }

    .detail-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 8px 25px rgba(129, 212, 250, 0.2);
    }

    .detail-icon {
        width: 50px;
        height: 50px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 1rem auto;
        font-size: 1.5rem;
        color: white;
    }

    .detail-card.teachers .detail-icon {
        background: linear-gradient(135deg, #81d4fa 0%, #4fc3f7 100%);
    }

    .detail-card.students .detail-icon {
        background: linear-gradient(135deg, #a5d6a7 0%, #81c784 100%);
    }

    .detail-card.courses .detail-icon {
        background: linear-gradient(135deg, #ce93d8 0%, #ba68c8 100%);
    }

    .detail-number {
        font-size: 2rem;
        font-weight: 700;
        color: #2c3e50;
        margin: 0 0 0.5rem 0;
    }

    .detail-label {
        font-size: 1rem;
        color: #6c757d;
        font-weight: 500;
        margin: 0;
    }

    .school-chart-container {
        position: relative;
        height: 300px;
        margin: 0 auto;
        max-width: 500px;
    }

    /* Detail List Modal */
    .detail-modal {
        display: none;
        position: fixed;
        z-index: 2000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0, 0, 0, 0.5);
        backdrop-filter: blur(5px);
    }

    .modal-content {
        background: white;
        margin: 5% auto;
        padding: 2rem;
        border-radius: 15px;
        width: 80%;
        max-width: 800px;
        max-height: 80vh;
        overflow-y: auto;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
        position: relative;
    }

    .modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1.5rem;
        padding-bottom: 1rem;
        border-bottom: 2px solid #e9ecef;
    }

    .modal-title {
        font-size: 1.5rem;
        font-weight: 700;
        color: #2c3e50;
        margin: 0;
    }

    .close-modal {
        background: none;
        border: none;
        font-size: 1.5rem;
        color: #6c757d;
        cursor: pointer;
        padding: 0.5rem;
        border-radius: 50%;
        transition: all 0.3s ease;
    }

    .close-modal:hover {
        background: #f8f9fa;
        color: #dc3545;
    }

    .detail-list {
        max-height: 400px;
        overflow-y: auto;
    }

    .detail-item {
        display: flex;
        align-items: center;
        padding: 1rem;
        margin-bottom: 0.5rem;
        background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
        border-radius: 10px;
        border-left: 4px solid transparent;
        transition: all 0.3s ease;
    }

    .detail-item:hover {
        transform: translateX(5px);
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
    }

    .detail-item.teacher {
        border-left-color: #81d4fa;
    }

    .detail-item.student {
        border-left-color: #a5d6a7;
    }

    .detail-item.course {
        border-left-color: #ce93d8;
    }

    .detail-item-avatar {
        width: 50px;
        height: 50px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-right: 1rem;
        font-size: 1.2rem;
        color: white;
        flex-shrink: 0;
    }

    .detail-item.teacher .detail-item-avatar {
        background: linear-gradient(135deg, #81d4fa 0%, #4fc3f7 100%);
    }

    .detail-item.student .detail-item-avatar {
        background: linear-gradient(135deg, #a5d6a7 0%, #81c784 100%);
    }

    .detail-item.course .detail-item-avatar {
        background: linear-gradient(135deg, #ce93d8 0%, #ba68c8 100%);
    }

    .detail-item-info {
        flex: 1;
    }

    .detail-item-name {
        font-size: 1.1rem;
        font-weight: 600;
        color: #2c3e50;
        margin: 0 0 0.25rem 0;
    }

    .detail-item-meta {
        font-size: 0.9rem;
        color: #6c757d;
        margin: 0;
    }

    .no-data {
        text-align: center;
        padding: 2rem;
        color: #6c757d;
        font-style: italic;
    }

    /* Responsive Design */
    @media (max-width: 768px) {
        .schools-container {
            padding: 15px;
        }
        
        .management-grid {
            grid-template-columns: 1fr;
            gap: 1rem;
        }
        
        .header-content {
            flex-direction: column;
            text-align: center;
        }
        
        .header-text {
            text-align: center;
        }
        
        .stats-grid {
            grid-template-columns: 1fr;
            gap: 1rem;
        }
    }
</style>";

// Include admin sidebar from includes
require_once(__DIR__ . '/includes/admin_sidebar.php');

// Main Content
echo "<div class='admin-main-content'>";
echo "<div class='schools-container'>";

// Page Header
echo "<div class='page-header'>";
echo "<div class='header-content'>";
echo "<div class='header-text'>";
echo "<h1 class='page-title'>Schools Management Center</h1>";
echo "<p class='page-subtitle'>Comprehensive school administration and management tools</p>";
echo "</div>";
echo "<div class='header-actions'>";
echo "<button class='header-btn features-btn'>";
echo "<i class='fa fa-cogs'></i>";
echo "<span>Features</span>";
echo "</button>";
echo "<button class='header-btn schools-count-btn'>";
echo "<i class='fa fa-building'></i>";
echo "<span>Schools ({$total_schools})</span>";
echo "</button>";
echo "</div>";
echo "</div>";
echo "</div>";

// Statistics Cards
echo "<div class='stats-container'>";
echo "<div class='stats-grid'>";

// Total Schools Card
echo "<div class='stat-card total-schools'>";
echo "<div class='stat-icon'>";
echo "<i class='fa fa-building'></i>";
echo "</div>";
echo "<div class='stat-content'>";
echo "<div class='stat-number'>{$total_schools}</div>";
echo "<div class='stat-label'>Total Schools</div>";
echo "</div>";
echo "</div>";

// Active Schools Card
echo "<div class='stat-card active-schools'>";
echo "<div class='stat-icon'>";
echo "<i class='fa fa-chart-bar'></i>";
echo "</div>";
echo "<div class='stat-content'>";
echo "<div class='stat-number'>{$active_schools}</div>";
echo "<div class='stat-label'>Active Schools</div>";
echo "</div>";
echo "</div>";

// Countries Card
echo "<div class='stat-card countries'>";
echo "<div class='stat-icon'>";
echo "<i class='fa fa-globe'></i>";
echo "</div>";
echo "<div class='stat-content'>";
echo "<div class='stat-number'>{$countries}</div>";
echo "<div class='stat-label'>Countries</div>";
echo "</div>";
echo "</div>";

// Suspended Schools Card
echo "<div class='stat-card recent-additions'>";
echo "<div class='stat-icon'>";
echo "<i class='fa fa-pause-circle'></i>";
echo "</div>";
echo "<div class='stat-content'>";
echo "<div class='stat-number'>{$recent_additions}</div>";
echo "<div class='stat-label'>Suspended Schools</div>";
echo "</div>";
echo "</div>";

echo "</div>"; // End stats-grid
echo "</div>"; // End stats-container

// School Selector Dropdown
echo "<div class='school-selector-dropdown'>";
echo "<h3 style='margin: 0 0 1rem 0; color: #2c3e50; font-size: 1.2rem;'>Select a School to View Details</h3>";
echo "<form method='GET' action=''>";
echo "<select name='school_id' class='school-dropdown' onchange='this.form.submit()'>";
echo "<option value='0'>-- Select a School --</option>";
foreach ($all_schools as $school) {
    $selected = ($selected_school_id == $school->id) ? 'selected' : '';
    echo "<option value='{$school->id}' {$selected}>{$school->name}</option>";
}
echo "</select>";
echo "</form>";
echo "</div>";

// School Details Section (only show if school is selected)
if ($selected_school && $school_details) {
    echo "<div class='school-details-section'>";
    echo "<div class='chart-header'>";
    echo "<h2 class='chart-title'>{$selected_school->name} - Details</h2>";
    echo "<p class='chart-subtitle'>Teachers, students, and courses overview</p>";
    echo "</div>";
    
    // Detail Cards
    echo "<div class='school-details-grid'>";
    
    // Teachers Card
    echo "<div class='detail-card teachers' onclick='showDetailList(\"teachers\", {$school_details['teachers']})' style='cursor: pointer;'>";
    echo "<div class='detail-icon'>";
    echo "<i class='fa fa-users'></i>";
    echo "</div>";
    echo "<div class='detail-number'>{$school_details['teachers']}</div>";
    echo "<div class='detail-label'>Teachers <small>(click to view)</small></div>";
    echo "</div>";
    
    // Students Card
    echo "<div class='detail-card students' onclick='showDetailList(\"students\", {$school_details['students']})' style='cursor: pointer;'>";
    echo "<div class='detail-icon'>";
    echo "<i class='fa fa-graduation-cap'></i>";
    echo "</div>";
    echo "<div class='detail-number'>{$school_details['students']}</div>";
    echo "<div class='detail-label'>Students <small>(click to view)</small></div>";
    echo "</div>";
    
    // Courses Card
    echo "<div class='detail-card courses' onclick='showDetailList(\"courses\", {$school_details['courses']})' style='cursor: pointer;'>";
    echo "<div class='detail-icon'>";
    echo "<i class='fa fa-book'></i>";
    echo "</div>";
    echo "<div class='detail-number'>{$school_details['courses']}</div>";
    echo "<div class='detail-label'>Courses <small>(click to view)</small></div>";
    echo "</div>";
    
    echo "</div>"; // End school-details-grid
    
    // School-specific Chart
    echo "<div class='chart-container school-chart-container'>";
    echo "<canvas id='schoolDetailsChart'></canvas>";
    echo "</div>";
    
    echo "</div>"; // End school-details-section
    
    // Detail List Modal
    echo "<div id='detailModal' class='detail-modal'>";
    echo "<div class='modal-content'>";
    echo "<div class='modal-header'>";
    echo "<h2 class='modal-title' id='modalTitle'>Details</h2>";
    echo "<button class='close-modal' onclick='closeDetailModal()'>&times;</button>";
    echo "</div>";
    echo "<div class='detail-list' id='detailList'>";
    echo "</div>";
    echo "</div>";
    echo "</div>";
}




// Schools Management Section
echo "<div class='schools-management-section'>";
echo "<div class='management-grid'>";

// Create School - Link to CUSTOM page
$create_school_url = new moodle_url('/theme/remui_kids/admin/company_create.php');
echo "<a href='{$create_school_url}' class='management-item' style='text-decoration: none; color: inherit;'>";
echo "<div class='management-icon create'>";
echo "<i class='fa fa-plus'></i>";
echo "</div>";
echo "<h3 class='management-title'>Create School</h3>";
echo "<p class='management-description'>Add new educational institutions to the system.</p>";
echo "<span class='availability-tag'>Available</span>";
echo "</a>";

// Edit School - Link to companies list where they can select a school to edit
if ($selected_school_id > 0) {
    $edit_school_url = new moodle_url('/theme/remui_kids/admin/company_edit.php', ['id' => $selected_school_id]);
} else {
    // If no school selected, go to list page to select one
    $edit_school_url = new moodle_url('/theme/remui_kids/admin/companies_list.php');
}
echo "<a href='{$edit_school_url}' class='management-item' style='text-decoration: none; color: inherit;'>";
echo "<div class='management-icon edit'>";
echo "<i class='fa fa-edit'></i>";
echo "</div>";
echo "<h3 class='management-title'>Edit School</h3>";
if ($selected_school_id > 0) {
    echo "<p class='management-description'>Modify {$selected_school->name} information and settings.</p>";
} else {
    echo "<p class='management-description'>Select a school from the list to edit.</p>";
}
echo "<span class='availability-tag'>Available</span>";
echo "</a>";

// Manage Schools - Link to CUSTOM list page
$manage_schools_url = new moodle_url('/theme/remui_kids/admin/companies_list.php');
echo "<a href='{$manage_schools_url}' class='management-item' style='text-decoration: none; color: inherit;'>";
echo "<div class='management-icon manage'>";
echo "<i class='fa fa-building'></i>";
echo "</div>";
echo "<h3 class='management-title'>Manage Schools</h3>";
echo "<p class='management-description'>View and organize all registered schools.</p>";
echo "<span class='availability-tag'>Available</span>";
echo "<span class='item-count'>{$total_schools} items</span>";
echo "</a>";

// Manage Departments - Link to IOMAD department management (keep original for now)
$departments_url = new moodle_url('/blocks/iomad_company_admin/company_departments.php');
echo "<a href='{$departments_url}' class='management-item' style='text-decoration: none; color: inherit;'>";
echo "<div class='management-icon departments'>";
echo "<i class='fa fa-users'></i>";
echo "</div>";
echo "<h3 class='management-title'>Manage Departments</h3>";
echo "<p class='management-description'>Organize school departments and hierarchies.</p>";
echo "<span class='availability-tag'>Available</span>";
echo "</a>";

// Assign to School - Link to custom assignment page (already custom)
$assign_school_url = new moodle_url('/theme/remui_kids/admin/assign_to_school.php');
echo "<a href='{$assign_school_url}' class='management-item' style='text-decoration: none; color: inherit;'>";
echo "<div class='management-icon assign'>";
echo "<i class='fa fa-link'></i>";
echo "</div>";
echo "<h3 class='management-title'>Assign to School</h3>";
echo "<p class='management-description'>Assign courses, or resources to schools.</p>";
echo "<span class='availability-tag'>Available</span>";
echo "</a>";

// Restrict Capabilities - Link to IOMAD capabilities management (keep original for now)
$capabilities_url = new moodle_url('/blocks/iomad_company_admin/company_capabilities.php');
echo "<a href='{$capabilities_url}' class='management-item' style='text-decoration: none; color: inherit;'>";
echo "<div class='management-icon restrict'>";
echo "<i class='fa fa-shield-alt'></i>";
echo "</div>";
echo "<h3 class='management-title'>Restrict Capabilities</h3>";
echo "<p class='management-description'>Manage user permissions and access controls.</p>";
echo "<span class='availability-tag'>Available</span>";
echo "</a>";

// Import Schools - Link to CUSTOM import page
$import_url = new moodle_url('/theme/remui_kids/admin/company_import.php');
echo "<a href='{$import_url}' class='management-item' style='text-decoration: none; color: inherit;'>";
echo "<div class='management-icon import'>";
echo "<i class='fa fa-upload'></i>";
echo "</div>";
echo "<h3 class='management-title'>Import Schools</h3>";
echo "<p class='management-description'>Bulk import schools from CSV or external sources.</p>";
echo "<span class='availability-tag'>Available</span>";
echo "</a>";

// Email Templates - Link to local emails management (using IOMAD pattern - keep original)
$email_url = new moodle_url('/local/email/template_list.php');
echo "<a href='{$email_url}' class='management-item' style='text-decoration: none; color: inherit;'>";
echo "<div class='management-icon email'>";
echo "<i class='fa fa-envelope'></i>";
echo "</div>";
echo "<h3 class='management-title'>Email Templates</h3>";
echo "<p class='management-description'>Customize email communications for schools.</p>";
echo "<span class='availability-tag'>Available</span>";
echo "</a>";

echo "</div>"; // End management-grid
echo "</div>"; // End schools-management-section

echo "</div>"; // End schools-container
echo "</div>"; // End admin-main-content

// JavaScript for interactive functionality and Chart.js
echo "<script src='https://cdn.jsdelivr.net/npm/chart.js'></script>";
echo "<script>
document.addEventListener('DOMContentLoaded', function() {
    
    
    // Global variables for modal data
    const schoolData = " . json_encode($school_details) . ";
    // Modal functions
    window.showDetailList = function(type, count) {
        const modal = document.getElementById('detailModal');
        const modalTitle = document.getElementById('modalTitle');
        const detailList = document.getElementById('detailList');
        
        modalTitle.textContent = type.charAt(0).toUpperCase() + type.slice(1) + ' List (' + count + ')';
        
        let listData = [];
        let icon = '';
        
        switch(type) {
            case 'teachers':
                listData = schoolData && schoolData.teachers_list ? Object.values(schoolData.teachers_list) : [];
                icon = 'fa-users';
                break;
            case 'students':
                listData = schoolData && schoolData.students_list ? Object.values(schoolData.students_list) : [];
                icon = 'fa-graduation-cap';
                break;
            case 'courses':
                listData = schoolData && schoolData.courses_list ? Object.values(schoolData.courses_list) : [];
                icon = 'fa-book';
                break;
        }
        
        if (listData.length > 0) {
            let html = '';
            listData.forEach(function(item, index) {
                let name = '';
                let meta = '';
                
                if (type === 'courses') {
                    name = item.fullname || 'Unnamed Course';
                    meta = 'Short: ' + (item.shortname || 'N/A');
                } else {
                    name = (item.firstname || '') + ' ' + (item.lastname || '');
                    meta = 'Email: ' + (item.email || 'N/A');
                    if (item.lastaccess) {
                        const lastAccess = new Date(item.lastaccess * 1000);
                        meta += ' | Last access: ' + lastAccess.toLocaleDateString();
                    }
                }
                
                html += '<div class=\"detail-item ' + type + '\"><div class=\"detail-item-avatar\"><i class=\"fa ' + icon + '\"></i></div><div class=\"detail-item-info\"><div class=\"detail-item-name\">' + name + '</div><div class=\"detail-item-meta\">' + meta + '</div></div></div>';
            });
            detailList.innerHTML = html;
        } else {
            detailList.innerHTML = '<div class=\"no-data\">No ' + type + ' found for this school.</div>';
        }
        
        modal.style.display = 'block';
    };
    
    window.closeDetailModal = function() {
        document.getElementById('detailModal').style.display = 'none';
    };
    
    // Close modal when clicking outside
    window.onclick = function(event) {
        const modal = document.getElementById('detailModal');
        if (event.target === modal) {
            modal.style.display = 'none';
        }
    };

    // School-specific chart (teachers, students, courses)
    const schoolDetailsCtx = document.getElementById('schoolDetailsChart');
    if (schoolDetailsCtx) {
        if (schoolData && schoolData.teachers !== undefined) {
            new Chart(schoolDetailsCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Teachers', 'Students', 'Courses'],
                    datasets: [{
                        label: 'School Details',
                        data: [schoolData.teachers, schoolData.students, schoolData.courses],
                        backgroundColor: [
                            '#81d4fa',
                            '#a5d6a7',
                            '#ce93d8'
                        ],
                        borderColor: '#ffffff',
                        borderWidth: 3
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: true,
                            position: 'bottom'
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.parsed;
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                                    return label + ': ' + value + ' (' + percentage + '%)';
                                }
                            }
                        }
                    }
                }
            });
        }
    }
    
    // Management item click handlers - removed as items are now proper links
    const managementItems = document.querySelectorAll('.management-item');
    
    managementItems.forEach(item => {
        item.addEventListener('mousedown', function() {
            // Add visual feedback on click
            this.style.transform = 'scale(0.98)';
            setTimeout(() => {
                this.style.transform = '';
            }, 150);
        });
    });
    
    // Header button click handlers
    const featuresBtn = document.querySelector('.features-btn');
    const schoolsCountBtn = document.querySelector('.schools-count-btn');
    
    if (featuresBtn) {
        featuresBtn.addEventListener('click', function() {
            // Add features modal or navigation
        });
    }
    
    if (schoolsCountBtn) {
        schoolsCountBtn.addEventListener('click', function() {
            // Navigate to schools list
        });
    }
});
</script>";

echo $OUTPUT->footer();
?>
