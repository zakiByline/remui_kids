<?php
/**
 * Comprehensive Company Logo Diagnostic
 */

require_once('../../config.php');
require_login();

global $DB, $CFG, $USER;

echo "<!DOCTYPE html><html><head><title>Logo Diagnostic</title>";
echo "<style>body{font-family:Arial,sans-serif;padding:20px;background:#f5f5f5;} .section{background:white;padding:20px;margin:20px 0;border-radius:8px;box-shadow:0 2px 4px rgba(0,0,0,0.1);} h2{color:#333;border-bottom:2px solid #007bff;padding-bottom:10px;} h3{color:#555;margin-top:20px;} ul{line-height:1.8;} .success{color:green;font-weight:bold;} .warning{color:orange;font-weight:bold;} .error{color:red;font-weight:bold;} code{background:#f0f0f0;padding:2px 6px;border-radius:3px;} .btn{background:#007bff;color:white;padding:10px 20px;border-radius:5px;text-decoration:none;display:inline-block;margin-top:10px;}</style>";
echo "</head><body>";

echo "<h2>🔍 Company Logo Diagnostic Tool</h2>";

// Get company for current user
$company_info = $DB->get_record_sql(
    "SELECT c.* 
     FROM {company} c 
     JOIN {company_users} cu ON c.id = cu.companyid 
     WHERE cu.userid = ? AND cu.managertype = 1",
    [$USER->id]
);

if (!$company_info) {
    echo "<div class='section'><p class='error'>❌ No company found for current user</p></div>";
    exit;
}

echo "<div class='section'>";
echo "<h3>✅ Company Information:</h3>";
echo "<ul>";
echo "<li><strong>ID:</strong> " . $company_info->id . "</li>";
echo "<li><strong>Name:</strong> " . htmlspecialchars($company_info->name) . "</li>";
echo "<li><strong>Dataroot:</strong> <code>" . htmlspecialchars($CFG->dataroot) . "</code></li>";
echo "</ul>";
echo "</div>";

// Check company_logo table
echo "<div class='section'>";
echo "<h3>📋 Method 1: Company Logo Table</h3>";
if ($DB->get_manager()->table_exists('company_logo')) {
    echo "<p class='success'>✓ Table 'company_logo' exists</p>";
    
    $all_logos = $DB->get_records('company_logo');
    echo "<p>Total logos in table: " . count($all_logos) . "</p>";
    
    $company_logo = $DB->get_record('company_logo', ['companyid' => $company_info->id]);
    
    if ($company_logo) {
        echo "<h4 class='success'>✅ Logo Record Found!</h4>";
        echo "<ul>";
        foreach ($company_logo as $key => $value) {
            echo "<li><strong>" . htmlspecialchars($key) . ":</strong> " . htmlspecialchars($value) . "</li>";
        }
        echo "</ul>";
        
        // Check file existence
        $logo_filepath = $CFG->dataroot . '/company/' . $company_info->id . '/' . $company_logo->filename;
        echo "<p><strong>Expected Path:</strong> <code>" . htmlspecialchars($logo_filepath) . "</code></p>";
        
        if (file_exists($logo_filepath)) {
            echo "<p class='success'>✅ File Exists! Size: " . filesize($logo_filepath) . " bytes</p>";
            
            $logo_url = $CFG->wwwroot . '/theme/remui_kids/get_company_logo.php?id=' . $company_info->id;
            echo "<p><strong>Logo URL:</strong> <a href='" . $logo_url . "' target='_blank'>" . htmlspecialchars($logo_url) . "</a></p>";
            echo "<h4>Logo Preview:</h4>";
            echo "<img src='" . $logo_url . "' alt='Company Logo' style='max-width:200px;border:2px solid #ccc;border-radius:10px;padding:10px;background:white;'>";
        } else {
            echo "<p class='error'>❌ File does NOT exist at expected location</p>";
        }
    } else {
        echo "<p class='warning'>⚠ No logo record for company ID: " . $company_info->id . "</p>";
    }
} else {
    echo "<p class='error'>❌ Table 'company_logo' does NOT exist</p>";
}
echo "</div>";

// Check Moodle Files table
echo "<div class='section'>";
echo "<h3>📁 Method 2: Moodle File Storage (mdl_files)</h3>";
try {
    $files = $DB->get_records_sql(
        "SELECT f.* FROM {files} f 
         WHERE f.component = 'local_iomad' 
         AND (f.filearea = 'companylogo' OR f.filearea = 'logo' OR f.filearea = 'company_logo')
         AND f.itemid = ?
         AND f.filename != '.'
         ORDER BY f.timemodified DESC
         LIMIT 10",
        [$company_info->id]
    );
    
    if (!empty($files)) {
        echo "<p class='success'>✅ Found " . count($files) . " file(s) in Moodle file storage</p>";
        foreach ($files as $file) {
            echo "<h4>File: " . htmlspecialchars($file->filename) . "</h4>";
            echo "<ul>";
            echo "<li><strong>Component:</strong> " . htmlspecialchars($file->component) . "</li>";
            echo "<li><strong>File area:</strong> " . htmlspecialchars($file->filearea) . "</li>";
            echo "<li><strong>Item ID:</strong> " . $file->itemid . "</li>";
            echo "<li><strong>Context ID:</strong> " . $file->contextid . "</li>";
            echo "<li><strong>File size:</strong> " . $file->filesize . " bytes</li>";
            echo "<li><strong>Mime type:</strong> " . htmlspecialchars($file->mimetype) . "</li>";
            echo "</ul>";
            
            // Generate pluginfile URL
            $url = moodle_url::make_pluginfile_url(
                $file->contextid,
                $file->component,
                $file->filearea,
                $file->itemid,
                $file->filepath,
                $file->filename
            )->out();
            echo "<p><strong>File URL:</strong> <a href='" . $url . "' target='_blank'>" . htmlspecialchars($url) . "</a></p>";
            echo "<img src='" . $url . "' alt='Logo' style='max-width:200px;border:2px solid #ccc;border-radius:10px;margin:10px 0;'>";
        }
    } else {
        echo "<p class='warning'>⚠ No files found in Moodle file storage for this company</p>";
        echo "<p>Searching all company-related files...</p>";
        
        $all_company_files = $DB->get_records_sql(
            "SELECT f.* FROM {files} f 
             WHERE (f.component LIKE '%company%' OR f.component = 'local_iomad')
             AND f.filename != '.'
             AND f.mimetype LIKE 'image/%'
             LIMIT 20"
        );
        
        if (!empty($all_company_files)) {
            echo "<p>Found " . count($all_company_files) . " image files:</p>";
            echo "<table border='1' cellpadding='5' style='border-collapse:collapse;width:100%;'>";
            echo "<tr><th>Component</th><th>Filearea</th><th>ItemID</th><th>Filename</th><th>Size</th></tr>";
            foreach ($all_company_files as $f) {
                echo "<tr>";
                echo "<td>" . htmlspecialchars($f->component) . "</td>";
                echo "<td>" . htmlspecialchars($f->filearea) . "</td>";
                echo "<td>" . $f->itemid . "</td>";
                echo "<td>" . htmlspecialchars($f->filename) . "</td>";
                echo "<td>" . $f->filesize . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        }
    }
} catch (Exception $e) {
    echo "<p class='error'>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}
echo "</div>";

// Check company table columns
echo "<div class='section'>";
echo "<h3>🗂️ Method 3: Company Table Fields</h3>";
$company_columns = $DB->get_columns('company');
echo "<p>Company table has " . count($company_columns) . " columns:</p>";
echo "<ul>";
foreach ($company_columns as $colname => $colinfo) {
    $value = isset($company_info->$colname) ? $company_info->$colname : 'NULL';
    if (stripos($colname, 'logo') !== false || stripos($colname, 'image') !== false || stripos($colname, 'pic') !== false) {
        echo "<li class='success'><strong>" . htmlspecialchars($colname) . ":</strong> " . htmlspecialchars($value) . "</li>";
    }
}
echo "</ul>";
echo "</div>";

// Show sample logo creation
echo "<div class='section'>";
echo "<h3>📝 How to Add a Logo:</h3>";
echo "<ol>";
echo "<li>Go to: <strong>Site administration → Plugins → Local plugins → IOMAD</strong></li>";
echo "<li>Click: <strong>Edit Companies</strong></li>";
echo "<li>Find: <strong>" . htmlspecialchars($company_info->name) . "</strong></li>";
echo "<li>Click: <strong>Edit</strong> button</li>";
echo "<li>Upload logo in the <strong>Company Logo</strong> field</li>";
echo "<li>Click: <strong>Save changes</strong></li>";
echo "<li>Clear Moodle caches</li>";
echo "</ol>";
echo "</div>";

echo "<div class='section'>";
echo "<a href='" . $CFG->wwwroot . "/my/' class='btn'>← Back to Dashboard</a> ";
echo "<a href='" . $CFG->wwwroot . "/theme/remui_kids/school_manager/activity_log.php' class='btn'>→ Activity Log</a>";
echo "</div>";

echo "</body></html>";
?>

