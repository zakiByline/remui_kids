<?php
require_once('../../../config.php');
require_login();
require_capability('moodle/site:config', context_system::instance());
require_once($CFG->libdir . '/excellib.class.php');

$PAGE->set_url('/theme/remui_kids/admin/bulk_download.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Bulk Download');
$PAGE->set_heading('Bulk Download');

// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    switch ($_GET['action']) {
        case 'get_user_stats':
            $total_users = $DB->count_records('user', ['deleted' => 0]);
            $active_users = $DB->count_records_sql(
                "SELECT COUNT(DISTINCT u.id) FROM {user} u 
                 JOIN {user_lastaccess} ul ON u.id = ul.userid 
                 WHERE u.deleted = 0 AND ul.timeaccess > ?",
                [time() - (30 * 24 * 60 * 60)]
            );
            $suspended_users = $DB->count_records('user', ['deleted' => 0, 'suspended' => 1]);
            $students = $DB->count_records_sql(
                "SELECT COUNT(DISTINCT u.id) FROM {user} u 
                 JOIN {role_assignments} ra ON u.id = ra.userid 
                 JOIN {role} r ON ra.roleid = r.id 
                 WHERE u.deleted = 0 AND r.shortname = 'trainee'"
            );
            $teachers = $DB->count_records_sql(
                "SELECT COUNT(DISTINCT u.id) FROM {user} u 
                 JOIN {role_assignments} ra ON u.id = ra.userid 
                 JOIN {role} r ON ra.roleid = r.id 
                 WHERE u.deleted = 0 AND r.shortname = 'teachers'"
            );
            
            echo json_encode([
                'status' => 'success',
                'stats' => [
                    'total_users' => $total_users,
                    'active_users' => $active_users,
                    'suspended_users' => $suspended_users,
                    'students' => $students,
                    'teachers' => $teachers
                ]
            ]);
            exit;
            
        case 'export_users':
            $raw_input = file_get_contents('php://input');
            $data = json_decode($raw_input, true);
            
            try {
                $format = $data['format'] ?? 'csv';
                $filters = $data['filters'] ?? [];
                $fields = $data['fields'] ?? ['username', 'email', 'firstname', 'lastname'];

                if (empty($fields) || !is_array($fields)) {
                    $fields = ['username', 'email', 'firstname', 'lastname'];
                }
                
                // Build query based on filters
                $where_conditions = ['u.deleted = 0'];
                $params = [];
                
                $needsrolejoin = !empty($filters['role']) || in_array('role', $fields, true);

                if (!empty($filters['role'])) {
                    $where_conditions[] = "r.shortname = ?";
                    $params[] = $filters['role'];
                }
                
                if (!empty($filters['status'])) {
                    if ($filters['status'] === 'active') {
                        $where_conditions[] = "ul.timeaccess > ?";
                        $params[] = time() - (30 * 24 * 60 * 60);
                    } elseif ($filters['status'] === 'suspended') {
                        $where_conditions[] = "u.suspended = 1";
                    }
                }
                
                if (!empty($filters['date_from'])) {
                    $where_conditions[] = "u.timecreated >= ?";
                    $params[] = strtotime($filters['date_from']);
                }
                
                if (!empty($filters['date_to'])) {
                    $where_conditions[] = "u.timecreated <= ?";
                    $params[] = strtotime($filters['date_to']);
                }
                
                $where_clause = implode(' AND ', $where_conditions);
                
                // Build field selection
                $field_mapping = [
                    'username' => 'u.username',
                    'email' => 'u.email',
                    'firstname' => 'u.firstname',
                    'lastname' => 'u.lastname',
                    'phone' => "CONCAT_WS(' / ', NULLIF(u.phone1, ''), NULLIF(u.phone2, ''))",
                    'city' => 'u.city',
                    'country' => 'u.country',
                    'timecreated' => 'u.timecreated',
                    'lastaccess' => 'u.lastaccess',
                    'suspended' => 'u.suspended',
                    'role' => 'r.shortname'
                ];
                
                $selected_fields = [];
                foreach ($fields as $field) {
                    if (isset($field_mapping[$field])) {
                        $selected_fields[] = $field_mapping[$field] . ' AS ' . $field;
                    }
                }
                
                if (empty($selected_fields)) {
                    $selected_fields = ['u.username', 'u.email', 'u.firstname', 'u.lastname'];
                }
                
                $field_list = implode(', ', $selected_fields);
                
                // Build the query
                $sql = "SELECT {$field_list} FROM {user} u";
                
                if ($needsrolejoin) {
                    $sql .= " LEFT JOIN {role_assignments} ra ON u.id = ra.userid";
                    $sql .= " LEFT JOIN {role} r ON ra.roleid = r.id";
                }
                
                if (!empty($filters['status']) && $filters['status'] === 'active') {
                    $sql .= " JOIN {user_lastaccess} ul ON u.id = ul.userid";
                }
                
                $sql .= " WHERE {$where_clause} ORDER BY u.firstname, u.lastname";
                
                $users = $DB->get_records_sql($sql, $params);
                
                if ($format === 'csv') {
                    exportToCsv($users, $fields);
                } elseif ($format === 'excel') {
                    exportToExcel($users, $fields);
                } elseif ($format === 'json') {
                    exportToJson($users, $fields);
                } else {
                    throw new Exception('Unsupported format');
                }
                
            } catch (Exception $e) {
                echo json_encode([
                    'status' => 'error',
                    'message' => $e->getMessage()
                ]);
            }
            exit;
            
        case 'get_export_history':
            // For demo purposes, return sample export history
            // In a real implementation, you would query the export_history table
            $history = [
                (object)[
                    'id' => 1,
                    'userid' => $USER->id,
                    'filename' => 'users_export_2024-01-15_14-30-25.csv',
                    'format' => 'csv',
                    'record_count' => 150,
                    'created_date' => time() - (2 * 24 * 60 * 60)
                ],
                (object)[
                    'id' => 2,
                    'userid' => $USER->id,
                    'filename' => 'users_export_2024-01-14_09-15-10.xlsx',
                    'format' => 'excel',
                    'record_count' => 89,
                    'created_date' => time() - (3 * 24 * 60 * 60)
                ],
                (object)[
                    'id' => 3,
                    'userid' => $USER->id,
                    'filename' => 'users_export_2024-01-13_16-45-30.json',
                    'format' => 'json',
                    'record_count' => 203,
                    'created_date' => time() - (4 * 24 * 60 * 60)
                ]
            ];
            
            echo json_encode([
                'status' => 'success',
                'history' => array_values($history)
            ]);
            exit;
    }
}

function exportToCsv($users, $fields) {
    $filename = 'users_export_' . date('Y-m-d_H-i-s') . '.csv';
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    
    // Write headers
    fputcsv($output, $fields);
    
    // Write data
    foreach ($users as $user) {
        fputcsv($output, build_export_row($user, $fields));
    }
    
    fclose($output);
    
    // Log export
    logExport($filename, 'csv', count($users));
}

function exportToExcel($users, $fields) {
    global $CFG;
    
    $filename = 'users_export_' . date('Y-m-d_H-i-s') . '.xls';
    
    $workbook = new MoodleExcelWorkbook("-");
    $workbook->send($filename);
    
    $worksheet = $workbook->add_worksheet('Users');
    
    $row = 0;
    $col = 0;
    
    foreach ($fields as $field) {
        $worksheet->write_string($row, $col++, format_string(ucwords(str_replace('_', ' ', $field))));
    }
    
    foreach ($users as $user) {
        $row++;
        $col = 0;
        $datarow = build_export_row($user, $fields);
        foreach ($datarow as $value) {
            $worksheet->write_string($row, $col++, (string)$value);
        }
    }
    
    $workbook->close();
    
    logExport($filename, 'excel', count($users));
    exit;
}

function exportToJson($users, $fields) {
    $filename = 'users_export_' . date('Y-m-d_H-i-s') . '.json';
    
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $datarows = [];
    foreach ($users as $user) {
        $datarows[] = build_export_row($user, $fields, true);
    }
    
    $export_data = [
        'export_date' => date('Y-m-d H:i:s'),
        'total_records' => count($users),
        'fields' => $fields,
        'data' => $datarows
    ];
    
    echo json_encode($export_data, JSON_PRETTY_PRINT);
    
    // Log export
    logExport($filename, 'json', count($users));
}

function logExport($filename, $format, $record_count) {
    // For demo purposes, just log to error log
    // In a real implementation, you would insert into export_history table
    error_log("Export logged: {$filename} ({$format}) - {$record_count} records");
}

function build_export_row($user, $fields, $assoc = false) {
    static $countries = null;
    if ($countries === null) {
        $stringmanager = get_string_manager();
        $countries = $stringmanager->get_list_of_countries();
    }
    
    $row = $assoc ? [] : [];
    
    foreach ($fields as $field) {
        $value = $user->$field ?? '';
        $formatted = format_export_value($field, $value, $countries);
        
        if ($assoc) {
            $row[$field] = $formatted;
        } else {
            $row[] = $formatted;
        }
    }
    
    return $row;
}

function format_export_value($field, $value, $countries) {
    switch ($field) {
        case 'timecreated':
        case 'lastaccess':
            if (empty($value)) {
                return '';
            }
            return userdate($value, '%Y-%m-%d %H:%M:%S');
        case 'suspended':
            return $value ? 'Suspended' : 'Active';
        case 'country':
            if (empty($value)) {
                return '';
            }
            return $countries[$value] ?? $value;
        case 'phone':
            return trim($value) !== '' ? $value : '';
        default:
            return $value;
    }
}

// Get template data
$template_data = [
    'config' => [
        'wwwroot' => $CFG->wwwroot
    ],
    'user' => [
        'firstname' => $USER->firstname,
        'lastname' => $USER->lastname
    ]
];

echo $OUTPUT->header();

// Admin Sidebar Navigation
// Include admin sidebar from includes
require_once(__DIR__ . '/includes/admin_sidebar.php');

// Sidebar toggle button for mobile
echo "<button class='sidebar-toggle' onclick='toggleSidebar()' aria-label='Toggle sidebar'>";
echo "<i class='fa fa-bars'></i>";
echo "</button>";

// Add CSS for sidebar
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
        padding: 1rem 2rem;
        color: #495057;
        text-decoration: none;
        transition: all 0.3s ease;
        position: relative;
        font-weight: 500;
        font-size: 0.95rem;
    }
    
    .admin-sidebar .sidebar-link:hover {
        background: #f8f9fa;
        color: #2196F3;
        padding-left: 2.5rem;
    }
    
    .admin-sidebar .sidebar-item.active .sidebar-link {
        background: linear-gradient(90deg, rgba(33, 150, 243, 0.1) 0%, transparent 100%);
        color: #2196F3;
        border-left: 4px solid #2196F3;
        font-weight: 600;
    }
    
    .admin-sidebar .sidebar-icon {
        margin-right: 1rem;
        font-size: 1.1rem;
        width: 20px;
        text-align: center;
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
        padding-top: 80px;
    }
    
    /* Sidebar toggle button for mobile */
    .sidebar-toggle {
        display: none;
        position: fixed;
        top: 20px;
        left: 20px;
        z-index: 1001;
        background: #2196F3;
        color: white;
        border: none;
        width: 45px;
        height: 45px;
        border-radius: 50%;
        cursor: pointer;
        box-shadow: 0 4px 12px rgba(33, 150, 243, 0.4);
        transition: all 0.3s ease;
    }
    
    .sidebar-toggle:hover {
        background: #1976D2;
        transform: scale(1.1);
    }
    
    /* Mobile responsive */
    @media (max-width: 768px) {
        .admin-sidebar {
            position: fixed;
            top: 0;
            left: -280px;
            transition: left 0.3s ease;
        }
        
        .admin-sidebar.sidebar-open {
            left: 0;
        }
        
        .admin-main-content {
            position: relative;
            left: 0;
            width: 100vw;
            height: auto;
            min-height: 100vh;
            padding-top: 20px;
        }
        
        .sidebar-toggle {
            display: flex;
            align-items: center;
            justify-content: center;
        }
    }
</style>";

// Add JavaScript for sidebar toggle
echo "<script>
function toggleSidebar() {
    const sidebar = document.querySelector('.admin-sidebar');
    sidebar.classList.toggle('sidebar-open');
}

// Close sidebar when clicking outside on mobile
document.addEventListener('click', function(event) {
    const sidebar = document.querySelector('.admin-sidebar');
    const toggleBtn = document.querySelector('.sidebar-toggle');
    
    if (window.innerWidth <= 768) {
        if (!sidebar.contains(event.target) && !toggleBtn.contains(event.target)) {
            sidebar.classList.remove('sidebar-open');
        }
    }
});

// Handle window resize
window.addEventListener('resize', function() {
    const sidebar = document.querySelector('.admin-sidebar');
    if (window.innerWidth > 768) {
        sidebar.classList.remove('sidebar-open');
    }
});
</script>";

// Main content wrapper
echo "<div class='admin-main-content'>";

// Render template
echo $OUTPUT->render_from_template('theme_remui_kids/bulk_download', $template_data);

echo "</div>"; // End admin-main-content
echo $OUTPUT->footer();
?>
