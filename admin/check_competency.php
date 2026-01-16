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
 * Check Competency Database - Diagnostic tool to verify competency data
 *
 * @package   theme_remui_kids
 * @copyright 2025 Kodeit
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../../config.php');

require_login();
$context = context_system::instance();

// Require admin capability
require_capability('moodle/site:config', $context);

$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/admin/check_competency.php');
$PAGE->set_title('Check Competency Database');
$PAGE->set_heading('Competency Database Check');

echo $OUTPUT->header();

// Include admin sidebar from includes
require_once(__DIR__ . '/includes/admin_sidebar.php');
?>

<!-- Main content area with sidebar -->
<div class='admin-main-content'>

<style>
    body {
        font-family: 'Arial', sans-serif;
        background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
        padding: 20px;
    }
    
    /* Admin Sidebar */
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
    }
    
    .admin-sidebar .sidebar-content {
        padding: 6rem 0 2rem 0;
    }
    
    /* Main content area */
    .admin-main-content {
        position: fixed;
        top: 0;
        left: 280px;
        width: calc(100vw - 280px);
        height: 100vh;
        background-color: #f5f7fa;
        overflow-y: auto;
        z-index: 99;
        padding-top: 80px;
    }
    
    .check-container {
        max-width: 1200px;
        margin: 0 auto;
        background: white;
        border-radius: 15px;
        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
        padding: 40px;
    }
    
    .check-header {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 30px;
        border-radius: 10px;
        margin-bottom: 30px;
        text-align: center;
    }
    
    .check-header h1 {
        margin: 0;
        font-size: 2rem;
        font-weight: 700;
    }
    
    .stats-section {
        margin-bottom: 40px;
    }
    
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }
    
    .stat-card {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 25px;
        border-radius: 12px;
        box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
    }
    
    .stat-card.success {
        background: linear-gradient(135deg, #56ab2f 0%, #a8e063 100%);
    }
    
    .stat-card.warning {
        background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
    }
    
    .stat-card.info {
        background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
    }
    
    .stat-label {
        font-size: 0.9rem;
        opacity: 0.9;
        margin-bottom: 10px;
    }
    
    .stat-value {
        font-size: 2.5rem;
        font-weight: 700;
        margin: 0;
    }
    
    .data-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 20px;
        background: white;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        border-radius: 10px;
        overflow: hidden;
    }
    
    .data-table th {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 15px;
        text-align: left;
        font-weight: 600;
    }
    
    .data-table td {
        padding: 12px 15px;
        border-bottom: 1px solid #e9ecef;
    }
    
    .data-table tr:hover {
        background-color: #f8f9fa;
    }
    
    .section-title {
        font-size: 1.5rem;
        font-weight: 700;
        color: #2c3e50;
        margin: 30px 0 20px 0;
        padding-bottom: 10px;
        border-bottom: 3px solid #667eea;
    }
    
    .query-box {
        background: #f8f9fa;
        padding: 20px;
        border-radius: 10px;
        margin: 20px 0;
        border-left: 4px solid #667eea;
    }
    
    .query-box code {
        font-family: 'Courier New', monospace;
        color: #e83e8c;
        background: white;
        padding: 2px 6px;
        border-radius: 4px;
    }
    
    .empty-state {
        text-align: center;
        padding: 40px;
        color: #6c757d;
        font-style: italic;
    }
    
    .back-button {
        display: inline-block;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 12px 30px;
        border-radius: 25px;
        text-decoration: none;
        font-weight: 600;
        margin-top: 30px;
        transition: transform 0.3s ease;
    }
    
    .back-button:hover {
        transform: translateY(-2px);
        text-decoration: none;
        color: white;
    }
</style>

<div class="check-container">
    <div class="check-header">
        <h1>🔍 Competency Database Diagnostic</h1>
        <p>Checking competency data in Moodle database</p>
    </div>

    <?php
    // Get overall statistics
    echo '<div class="stats-section">';
    echo '<h2 class="section-title">📊 Overall Statistics</h2>';
    echo '<div class="stats-grid">';
    
    // Total Frameworks
    $totalFrameworks = $DB->count_records('competency_framework');
    echo '<div class="stat-card">';
    echo '<div class="stat-label">Total Competency Frameworks</div>';
    echo '<div class="stat-value">' . $totalFrameworks . '</div>';
    echo '</div>';
    
    // Total Competencies
    $totalCompetencies = $DB->count_records('competency');
    echo '<div class="stat-card success">';
    echo '<div class="stat-label">Total Competencies</div>';
    echo '<div class="stat-value">' . $totalCompetencies . '</div>';
    echo '</div>';
    
    // Total Course Links
    $totalCourseLinks = $DB->count_records('competency_coursecomp');
    echo '<div class="stat-card info">';
    echo '<div class="stat-label">Course-Competency Links</div>';
    echo '<div class="stat-value">' . $totalCourseLinks . '</div>';
    echo '</div>';
    
    // Total Proficiency Records
    $totalProficiency = $DB->count_records('competency_usercompcourse');
    echo '<div class="stat-card warning">';
    echo '<div class="stat-label">User Proficiency Records</div>';
    echo '<div class="stat-value">' . $totalProficiency . '</div>';
    echo '</div>';
    
    echo '</div>';
    echo '</div>';
    
    // Show SQL query used
    echo '<div class="query-box">';
    echo '<strong>SQL Query Used:</strong><br>';
    echo '<code>SELECT COUNT(*) FROM {competency}</code>';
    echo '</div>';
    
    // List all competency frameworks
    echo '<h2 class="section-title">📚 Competency Frameworks</h2>';
    $frameworks = $DB->get_records('competency_framework', null, 'shortname ASC');
    
    if (empty($frameworks)) {
        echo '<div class="empty-state">';
        echo '❌ No competency frameworks found in the database.<br>';
        echo 'You may need to create competency frameworks first.';
        echo '</div>';
    } else {
        echo '<table class="data-table">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>ID</th>';
        echo '<th>Short Name</th>';
        echo '<th>Full Name</th>';
        echo '<th>Competencies</th>';
        echo '<th>ID Number</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';
        
        foreach ($frameworks as $fw) {
            $compCount = $DB->count_records('competency', ['competencyframeworkid' => $fw->id]);
            echo '<tr>';
            echo '<td>' . $fw->id . '</td>';
            echo '<td>' . format_string($fw->shortname) . '</td>';
            echo '<td>' . format_string($fw->fullname) . '</td>';
            echo '<td><strong>' . $compCount . '</strong></td>';
            echo '<td>' . s($fw->idnumber) . '</td>';
            echo '</tr>';
        }
        
        echo '</tbody>';
        echo '</table>';
    }
    
    // List all competencies (limit to 20 for performance)
    echo '<h2 class="section-title">🎯 Competencies (Latest 20)</h2>';
    $competencies = $DB->get_records('competency', null, 'timecreated DESC', '*', 0, 20);
    
    if (empty($competencies)) {
        echo '<div class="empty-state">';
        echo '❌ No competencies found in the database.<br>';
        echo 'You may need to create competencies first.';
        echo '</div>';
    } else {
        echo '<table class="data-table">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>ID</th>';
        echo '<th>Short Name</th>';
        echo '<th>Framework</th>';
        echo '<th>Parent ID</th>';
        echo '<th>Sort Order</th>';
        echo '<th>Time Created</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';
        
        foreach ($competencies as $comp) {
            $framework = $DB->get_record('competency_framework', ['id' => $comp->competencyframeworkid]);
            echo '<tr>';
            echo '<td>' . $comp->id . '</td>';
            echo '<td>' . format_string($comp->shortname) . '</td>';
            echo '<td>' . ($framework ? format_string($framework->shortname) : 'N/A') . '</td>';
            echo '<td>' . ($comp->parentid ? $comp->parentid : '-') . '</td>';
            echo '<td>' . $comp->sortorder . '</td>';
            echo '<td>' . userdate($comp->timecreated, '%Y-%m-%d %H:%M') . '</td>';
            echo '</tr>';
        }
        
        echo '</tbody>';
        echo '</table>';
        
        if ($totalCompetencies > 20) {
            echo '<p style="text-align: center; margin-top: 20px; color: #6c757d;">';
            echo 'Showing 20 of ' . $totalCompetencies . ' total competencies';
            echo '</p>';
        }
    }
    
    // Check table structure
    echo '<h2 class="section-title">🔧 Database Tables Check</h2>';
    echo '<div class="stats-grid">';
    
    $tables = [
        'competency_framework' => 'Competency Framework Table',
        'competency' => 'Competency Table',
        'competency_coursecomp' => 'Course Competency Links',
        'competency_modulecomp' => 'Module Competency Links',
        'competency_usercompcourse' => 'User Competency Course',
        'competency_usercomp' => 'User Competency'
    ];
    
    foreach ($tables as $table => $label) {
        $exists = $DB->get_manager()->table_exists($table);
        $count = 0;
        if ($exists) {
            try {
                $count = $DB->count_records($table);
            } catch (Exception $e) {
                $count = 'Error';
            }
        }
        
        echo '<div class="stat-card ' . ($exists ? 'success' : 'warning') . '">';
        echo '<div class="stat-label">' . $label . '</div>';
        echo '<div class="stat-value">' . ($exists ? '✓' : '✗') . '</div>';
        echo '<div style="font-size: 0.9rem; margin-top: 10px;">';
        echo $exists ? 'Records: ' . $count : 'Table not found';
        echo '</div>';
        echo '</div>';
    }
    
    echo '</div>';
    
    ?>
    
    <div style="text-align: center;">
        <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/admin/competency_maps.php" class="back-button">
            ← Back to Competency Maps
        </a>
        <a href="<?php echo $CFG->wwwroot; ?>/admin/tool/lp/competencyframeworks.php" class="back-button" style="margin-left: 10px;">
            Manage Competencies
        </a>
    </div>
</div>

</div><!-- Close admin-main-content -->

<?php
echo $OUTPUT->footer();
?>



