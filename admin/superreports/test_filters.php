<?php
// Test filter functionality
require_once('../../../../config.php');
require_once($CFG->dirroot . '/theme/remui_kids/admin/superreports/lib.php');
require_login();

global $DB, $CFG;

echo "<h1>Testing Filter Functionality</h1>";

// Test 1: All schools, all grades
echo "<h2>Test 1: All Schools, All Grades</h2>";
$stats = superreports_get_overview_stats(0, 'month', null, null, '');
echo "<pre>" . print_r($stats, true) . "</pre>";

// Test 2: Specific school
echo "<h2>Test 2: School ID 1, All Grades</h2>";
$stats2 = superreports_get_overview_stats(1, 'month', null, null, '');
echo "<pre>" . print_r($stats2, true) . "</pre>";

// Test 3: All schools, specific grade
echo "<h2>Test 3: All Schools, Grade 10</h2>";
$stats3 = superreports_get_overview_stats(0, 'month', null, null, '10');
echo "<pre>" . print_r($stats3, true) . "</pre>";

// Test 4: Check if Grade 10 cohort exists
echo "<h2>Test 4: Check for Grade Cohorts</h2>";
$cohorts = $DB->get_records_sql(
    "SELECT id, name, idnumber FROM {cohort} WHERE " . $DB->sql_like('name', ':pattern', false),
    ['pattern' => '%Grade%']
);
echo "Cohorts found with 'Grade' in name:<br>";
foreach ($cohorts as $cohort) {
    echo "- ID: {$cohort->id}, Name: {$cohort->name}, IDNumber: {$cohort->idnumber}<br>";
}

if (empty($cohorts)) {
    echo "<strong style='color:red;'>NO GRADE COHORTS FOUND!</strong><br>";
    echo "You need to create cohorts named 'Grade 1', 'Grade 2', etc. for grade filtering to work.<br>";
}

echo "<h2>Test Complete</h2>";

