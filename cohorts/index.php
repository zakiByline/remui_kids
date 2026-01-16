<?php
/**
 * Cohort Navigation Page - Display all cohorts in a proper admin interface
 */

require_once('../../../config.php');
global $DB, $CFG, $OUTPUT, $PAGE;

// Set up the page
$PAGE->set_url('/theme/remui_kids/cohorts/index.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Cohort Navigation');
$PAGE->set_heading('Cohort Navigation');
$PAGE->set_pagelayout('admin');

// Check if user has admin capabilities
require_capability('moodle/cohort:view', context_system::instance());

echo $OUTPUT->header();

// Add custom CSS for the cohorts list with admin sidebar
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
    
    .cohorts-container {
        background: #fff;
        border-radius: 8px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        padding: 20px;
        margin: 20px 0;
    }
    
    .cohorts-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 30px;
        padding: 20px;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        border-radius: 16px;
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.08);
        color: white;
    }
    
    .cohorts-title {
        font-size: 28px;
        font-weight: 600;
        margin: 0;
    }
    
    .cohorts-subtitle {
        color: rgba(255, 255, 255, 0.8);
        margin: 5px 0 0 0;
        font-size: 16px;
    }
    
    .cohorts-stats {
        display: flex;
        gap: 20px;
        align-items: center;
    }
    
    .stat-item {
        text-align: center;
        padding: 10px 15px;
        border-radius: 6px;
        min-width: 80px;
        background: rgba(255, 255, 255, 0.1);
        backdrop-filter: blur(10px);
    }
    
    .stat-number {
        font-size: 24px;
        font-weight: bold;
        color: white;
        margin: 0;
    }
    
    .stat-label {
        font-size: 12px;
        color: rgba(255, 255, 255, 0.8);
        margin: 0;
        text-transform: uppercase;
    }
    
    .cohorts-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 20px;
        background: #fff;
        border-radius: 8px;
        overflow: hidden;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    }
    
    .cohorts-table th {
        background: #f8f9fa;
        color: #495057;
        padding: 15px 12px;
        text-align: left;
        font-weight: 600;
        font-size: 14px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .cohorts-table td {
        padding: 15px 12px;
        border-bottom: 1px solid #e9ecef;
        vertical-align: middle;
    }
    
    .cohorts-table tr:hover {
        background: #f8f9fa;
    }
    
    .cohort-name {
        font-weight: 600;
        color: #2c3e50;
        margin: 0;
    }
    
    .cohort-id {
        color: #6c757d;
        font-size: 14px;
        margin: 0;
    }
    
    .size-badge {
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
        background: #dbeafe;
        color: #1e40af;
    }
    
    .source-badge {
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
        background: #f0fdf4;
        color: #166534;
    }
    
    .action-buttons {
        display: flex;
        gap: 8px;
    }
    
    .btn {
        padding: 6px 12px;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-size: 12px;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }
    
    .btn-primary {
        background: #007bff;
        color: white;
    }
    
    .btn-secondary {
        background: #6c757d;
        color: white;
    }
    
    .btn-danger {
        background: #dc3545;
        color: white;
    }
    
    .btn:hover {
        opacity: 0.9;
        text-decoration: none;
        color: inherit;
    }
    
    .search-filter-bar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        padding: 15px;
        background: #f8f9fa;
        border-radius: 6px;
    }
    
    .search-box {
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .search-input {
        padding: 8px 12px;
        border: 1px solid #ced4da;
        border-radius: 4px;
        width: 300px;
    }
    
    .filter-select {
        padding: 8px 12px;
        border: 1px solid #ced4da;
        border-radius: 4px;
        background: white;
    }
    
    .no-cohorts {
        text-align: center;
        padding: 60px 20px;
        color: #6c757d;
    }
    
    .no-cohorts i {
        font-size: 48px;
        margin-bottom: 20px;
        color: #dee2e6;
    }
</style>";

// Include admin sidebar from includes
require_once(__DIR__ . '/../admin/includes/admin_sidebar.php');

// Main content area with sidebar
echo "<div class='admin-main-content'>";

try {
    // Get cohorts data
    $cohorts = $DB->get_records('cohort', null, 'name ASC');
    
    // Count statistics
    $total_cohorts = count($cohorts);
    $total_members = 0;
    
    foreach ($cohorts as $cohort) {
        $total_members += $DB->count_records('cohort_members', ['cohortid' => $cohort->id]);
    }
    
    // Main container
    echo "<div class='cohorts-container'>";
    
    // Header
    echo "<div class='cohorts-header'>";
    echo "<div>";
    echo "<h1 class='cohorts-title'>Cohort Navigation</h1>";
    echo "<p class='cohorts-subtitle'>Manage and organize student groups efficiently</p>";
    echo "</div>";
    echo "<div class='cohorts-stats'>";
    echo "<div class='stat-item'>";
    echo "<div class='stat-number'>$total_cohorts</div>";
    echo "<div class='stat-label'>Total Cohorts</div>";
    echo "</div>";
    echo "<div class='stat-item'>";
    echo "<div class='stat-number'>$total_members</div>";
    echo "<div class='stat-label'>Total Members</div>";
    echo "</div>";
    echo "</div>";
    echo "</div>";
    
    // Search and filter bar
    echo "<div class='search-filter-bar'>";
    echo "<div class='search-box'>";
    echo "<input type='text' class='search-input' placeholder='Search cohorts by name or ID...' id='cohort-search'>";
    echo "<select class='filter-select' id='source-filter'>";
    echo "<option value='all'>All Sources</option>";
    echo "<option value='manual'>Created Manually</option>";
    echo "<option value='imported'>Imported</option>";
    echo "</select>";
    echo "</div>";
    echo "<div style='display: flex; gap: 10px;'>";
    echo "<a href='{$CFG->wwwroot}/theme/remui_kids/cohorts/upload_cohorts.php?contextid=" . context_system::instance()->id . "' class='btn btn-secondary' title='Upload cohorts from CSV file'>";
    echo "<i class='fa fa-upload'></i> Upload Cohorts";
    echo "</a>";
    echo "<a href='{$CFG->wwwroot}/theme/remui_kids/cohorts/add_cohort.php?contextid=" . context_system::instance()->id . "' class='btn btn-primary'>";
    echo "<i class='fa fa-plus'></i> Add New Cohort";
    echo "</a>";
    echo "</div>";
    echo "</div>";
    
    if ($total_cohorts > 0) {
        // Cohorts table
        echo "<table class='cohorts-table' id='cohorts-table'>";
        echo "<thead>";
        echo "<tr>";
        echo "<th>Name</th>";
        echo "<th>Description</th>";
        echo "<th>Size</th>";
        echo "<th>Source</th>";
        echo "<th>Actions</th>";
        echo "</tr>";
        echo "</thead>";
        echo "<tbody>";
        
        foreach ($cohorts as $cohort) {
            $size = $DB->count_records('cohort_members', ['cohortid' => $cohort->id]);
            $source = $cohort->component ? 'Imported from ' . $cohort->component : 'Created manually';
            
            echo "<tr>";
            echo "<td>";
            echo "<div class='cohort-name'>{$cohort->name}</div>";
            echo "</td>";
            echo "<td>" . ($cohort->description ? $cohort->description : 'No description') . "</td>";
            echo "<td><span class='size-badge'>$size</span></td>";
            echo "<td><span class='source-badge'>$source</span></td>";
            echo "<td>";
                echo "<div class='action-buttons'>";
                echo "<a href='{$CFG->wwwroot}/theme/remui_kids/cohorts/delete_cohort.php?id={$cohort->id}&sesskey=" . sesskey() . "' class='btn btn-danger' title='Delete Cohort'>";
                echo "<i class='fa fa-trash'></i> Delete";
                echo "</a>";
                echo "<a href='{$CFG->wwwroot}/theme/remui_kids/cohorts/edit_cohort.php?id={$cohort->id}' class='btn btn-secondary' title='Edit Cohort'>";
                echo "<i class='fa fa-edit'></i> Edit";
                echo "</a>";
                echo "<a href='{$CFG->wwwroot}/theme/remui_kids/cohorts/manage_members.php?id={$cohort->id}' class='btn btn-primary' title='Manage Members'>";
                echo "<i class='fa fa-users'></i> Members";
                echo "</a>";
                echo "</div>";
            echo "</td>";
            echo "</tr>";
        }
        
        echo "</tbody>";
        echo "</table>";
        
    } else {
        // No cohorts found
        echo "<div class='no-cohorts'>";
        echo "<i class='fa fa-users'></i>";
        echo "<h3>No Cohorts Found</h3>";
        echo "<p>There are no cohorts in your system.</p>";
        echo "<p><a href='{$CFG->wwwroot}/theme/remui_kids/cohorts/add_cohort.php?contextid=" . context_system::instance()->id . "' class='btn btn-primary'>Create Your First Cohort</a></p>";
        echo "</div>";
    }
    
    echo "</div>"; // End cohorts-container
    
} catch (Exception $e) {
    echo "<div class='alert alert-danger'>";
    echo "<h4>❌ Error</h4>";
    echo "<p>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "</div>";
}

// Add JavaScript for search and filter functionality
echo "<script>
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('cohort-search');
    const sourceFilter = document.getElementById('source-filter');
    const table = document.getElementById('cohorts-table');
    
    if (table) {
        const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
        
        function filterTable() {
            const searchTerm = searchInput.value.toLowerCase();
            const sourceValue = sourceFilter.value;
            
            for (let i = 0; i < rows.length; i++) {
                const row = rows[i];
                const name = row.cells[0].textContent.toLowerCase();
                const id = row.cells[1].textContent.toLowerCase();
                const source = row.cells[4].textContent.toLowerCase();
                
                const matchesSearch = name.includes(searchTerm) || id.includes(searchTerm);
                const matchesSource = sourceValue === 'all' || 
                                    (sourceValue === 'manual' && source.includes('created manually')) ||
                                    (sourceValue === 'imported' && source.includes('imported'));
                
                row.style.display = (matchesSearch && matchesSource) ? '' : 'none';
            }
        }
        
        searchInput.addEventListener('input', filterTable);
        sourceFilter.addEventListener('change', filterTable);
    }
});
</script>";

echo "</div>"; // End admin-main-content

echo $OUTPUT->footer();
?>
