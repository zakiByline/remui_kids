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

/**
 * Competency Report Export
 * Exports student competency report as PDF showing main and sub-competencies
 *
 * @package   theme_remui_kids
 * @copyright 2025 Kodeit
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../../config.php');
require_once($CFG->libdir . '/pdflib.php');
require_once($CFG->dirroot . '/theme/remui_kids/lib.php');

require_login();
require_sesskey();

$context = context_system::instance();

if (!has_capability('moodle/course:update', $context) && !has_capability('moodle/site:config', $context)) {
    throw new moodle_exception('nopermissions', 'error', '', 'export competency reports');
}

$userid = required_param('userid', PARAM_INT);
$frameworkid = required_param('frameworkid', PARAM_INT);

// Get user info
$user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);

// Get courseids from session or URL (if available)
$courseids = optional_param_array('courseid', [], PARAM_INT);
if (empty($courseids)) {
    $singleCourseid = optional_param('courseid', 0, PARAM_INT);
    if ($singleCourseid) {
        $courseids = [$singleCourseid];
    }
}

// If no courseids provided, get all courses where user is enrolled
if (empty($courseids)) {
    $enrolledcourses = enrol_get_all_users_courses($userid, true);
    $courseids = array_keys($enrolledcourses);
}

if (empty($courseids)) {
    throw new moodle_exception('missingparam', 'error', '', 'courseid');
}

// Get analytics data
$analytics = theme_remui_kids_get_student_analytics($userid, $courseids);

if (!$analytics || empty($analytics['competency']['competencies'])) {
    throw new moodle_exception('nodata', 'error', '', 'No competency data available');
}

// Find the selected framework
$selectedFramework = null;
foreach ($analytics['competency']['frameworks'] as $framework) {
    if ($framework['id'] == $frameworkid) {
        $selectedFramework = $framework;
        break;
    }
}

if (!$selectedFramework) {
    throw new moodle_exception('invalidframework', 'error', '', 'Framework not found');
}

// Filter competencies for selected framework
$frameworkCompetencies = array_filter($analytics['competency']['competencies'], function($c) use ($frameworkid) {
    return $c['frameworkid'] == $frameworkid;
});

// Get course names for display
$coursenames = [];
foreach ($courseids as $cid) {
    try {
        $course = get_course($cid);
        $coursenames[] = format_string($course->fullname);
    } catch (Exception $e) {
        continue;
    }
}

// Create PDF
$pdf = new pdf('P', 'mm', 'A4', true, 'UTF-8');
$pdf->setPrintHeader(true);
$pdf->setPrintFooter(true);
$pdf->SetTitle('Competency Report - ' . fullname($user));
$pdf->SetAuthor('Moodle');
$pdf->SetSubject('Student Competency Report');
$pdf->SetKeywords('Competency, Report, Student');

// Set margins
$pdf->SetMargins(15, 25, 15);
$pdf->SetHeaderMargin(10);
$pdf->SetFooterMargin(10);

// Add first page
$pdf->AddPage();

// Set default font (TCPDF will use helvetica which supports basic Unicode)
$pdf->SetFont('helvetica', '', 10);

// Header HTML
$headerhtml = '
<div style="text-align:center; margin-bottom:20px;">
    <h1 style="color:#8b5cf6; margin-bottom:5px; font-size:24px;">Competency Report</h1>
    <h2 style="color:#475569; margin-top:5px; font-size:18px; font-weight:normal;">' . htmlspecialchars(fullname($user)) . '</h2>
    <p style="color:#64748b; font-size:12px; margin-top:5px;">
        Framework: <strong>' . htmlspecialchars($selectedFramework['name']) . '</strong><br>
        ' . (count($coursenames) == 1 ? 'Course: ' . htmlspecialchars($coursenames[0]) : 'Courses: ' . implode(', ', array_map('htmlspecialchars', $coursenames))) . '<br>
        Generated: ' . date('F j, Y g:i A') . '
    </p>
</div>';

$pdf->writeHTML($headerhtml, true, false, true, false, '');

// Summary Statistics
$proficientCount = 0;
$inProgressCount = 0;
$notAttemptedCount = 0;
$totalCompetencies = 0;

foreach ($frameworkCompetencies as $comp) {
    $totalCompetencies++;
    if ($comp['proficient']) {
        $proficientCount++;
    } elseif ($comp['in_progress']) {
        $inProgressCount++;
    } else {
        $notAttemptedCount++;
    }
}

$masteryPercent = $totalCompetencies > 0 ? round(($proficientCount / $totalCompetencies) * 100, 1) : 0;

$summaryhtml = '
<div style="background-color:#f8fafc; padding:15px; border-radius:5px; margin-bottom:20px;">
    <table style="width:100%; border-collapse:collapse;">
        <tr>
            <td style="width:25%; text-align:center; padding:10px;">
                <div style="font-size:28px; font-weight:bold; color:#8b5cf6;">' . $masteryPercent . '%</div>
                <div style="font-size:11px; color:#64748b; margin-top:5px;">Mastery</div>
            </td>
            <td style="width:25%; text-align:center; padding:10px;">
                <div style="font-size:28px; font-weight:bold; color:#22c55e;">' . $proficientCount . '</div>
                <div style="font-size:11px; color:#64748b; margin-top:5px;">Competent</div>
            </td>
            <td style="width:25%; text-align:center; padding:10px;">
                <div style="font-size:28px; font-weight:bold; color:#f59e0b;">' . $inProgressCount . '</div>
                <div style="font-size:11px; color:#64748b; margin-top:5px;">In Progress</div>
            </td>
            <td style="width:25%; text-align:center; padding:10px;">
                <div style="font-size:28px; font-weight:bold; color:#64748b;">' . $notAttemptedCount . '</div>
                <div style="font-size:11px; color:#64748b; margin-top:5px;">Not Attempted</div>
            </td>
        </tr>
    </table>
</div>';

$pdf->writeHTML($summaryhtml, true, false, true, false, '');

// Competency Table
$tablehtml = '
<table style="width:100%; border-collapse:collapse; margin-top:10px;">
    <thead>
        <tr style="background-color:#64748b; color:#ffffff;">
            <th style="border:1px solid #cbd5e1; padding:10px; text-align:center; font-size:12px; font-weight:bold; width:12%;">Status</th>
            <th style="border:1px solid #cbd5e1; padding:10px; text-align:left; font-size:12px; font-weight:bold; width:38%;">Competency</th>
            <th style="border:1px solid #cbd5e1; padding:10px; text-align:left; font-size:12px; font-weight:bold; width:25%;">Description</th>
            <th style="border:1px solid #cbd5e1; padding:10px; text-align:center; font-size:12px; font-weight:bold; width:10%;">Progress</th>
            <th style="border:1px solid #cbd5e1; padding:10px; text-align:center; font-size:12px; font-weight:bold; width:15%;">Activities</th>
        </tr>
    </thead>
    <tbody>';

foreach ($frameworkCompetencies as $comp) {
    // Determine status with symbols using ZapfDingbats font (supports checkmark)
    $statusIcon = '<div style="text-align:center; background-color:#fee2e2; color:#dc2626; font-size:18px; font-weight:bold; padding:8px 10px; border-radius:50%; display:inline-block; width:32px; height:32px; line-height:32px;">X</div>';
    $statusColor = '#ef4444';
    $statusText = 'Not Attempted';
    
    if ($comp['proficient']) {
        // Use checkmark from ZapfDingbats font (character code 4)
        $statusIcon = '<div style="text-align:center; background-color:#dcfce7; color:#16a34a; font-size:20px; font-weight:bold; padding:8px 10px; border-radius:50%; display:inline-block; width:32px; height:32px; line-height:32px; font-family:zapfdingbats;">4</div>';
        $statusColor = '#22c55e';
        $statusText = 'Competent';
    } elseif ($comp['in_progress']) {
        // Use hourglass from ZapfDingbats (character code 231) or three dots
        $statusIcon = '<div style="text-align:center; background-color:#fef3c7; color:#d97706; font-size:18px; font-weight:bold; padding:8px 10px; border-radius:50%; display:inline-block; width:32px; height:32px; line-height:32px; font-family:zapfdingbats;">&#231;</div>';
        $statusColor = '#f59e0b';
        $statusText = 'In Progress';
    }
    
    // Get competency description (use name as description if no separate description field)
    $description = $comp['name'];
    // Truncate description if too long
    if (strlen($description) > 100) {
        $description = substr($description, 0, 97) . '...';
    }
    
    // Activities info
    $activitiesInfo = $comp['completed_activities'] . ' / ' . $comp['total_activities'];
    if ($comp['total_activities'] == 0) {
        $activitiesInfo = 'N/A';
    }
    
    // Main competency row
    $tablehtml .= '
    <tr style="background-color:#ffffff;">
        <td style="border:1px solid #e2e8f0; padding:12px; text-align:center; font-weight:bold;">' . $statusIcon . '</td>
        <td style="border:1px solid #e2e8f0; padding:8px; font-size:11px; font-weight:bold; color:#1e293b;">' . htmlspecialchars($comp['name']) . '</td>
        <td style="border:1px solid #e2e8f0; padding:8px; font-size:10px; color:#475569;">' . htmlspecialchars($description) . '</td>
        <td style="border:1px solid #e2e8f0; padding:8px; text-align:center; font-size:10px; color:#64748b;">' . $comp['proficiency_percent'] . '%</td>
        <td style="border:1px solid #e2e8f0; padding:8px; text-align:center; font-size:10px; color:#64748b;">' . $activitiesInfo . '</td>
    </tr>';
    
    // Sub-competencies
    if (!empty($comp['sub_competencies']) && is_array($comp['sub_competencies'])) {
        foreach ($comp['sub_competencies'] as $subComp) {
            $subStatusIcon = '<div style="text-align:center; background-color:#fee2e2; color:#dc2626; font-size:14px; font-weight:bold; padding:6px 8px; border-radius:50%; display:inline-block; width:28px; height:28px; line-height:28px;">X</div>';
            $subStatusColor = '#ef4444';
            $subStatusText = 'Not Attempted';
            
            if ($subComp['proficient'] ?? false) {
                // Use checkmark from ZapfDingbats font (character code 4)
                $subStatusIcon = '<div style="text-align:center; background-color:#dcfce7; color:#16a34a; font-size:18px; font-weight:bold; padding:6px 8px; border-radius:50%; display:inline-block; width:28px; height:28px; line-height:28px; font-family:zapfdingbats;">4</div>';
                $subStatusColor = '#22c55e';
                $subStatusText = 'Competent';
            } elseif ($subComp['in_progress'] ?? false) {
                // Use hourglass from ZapfDingbats or three dots
                $subStatusIcon = '<div style="text-align:center; background-color:#fef3c7; color:#d97706; font-size:16px; font-weight:bold; padding:6px 8px; border-radius:50%; display:inline-block; width:28px; height:28px; line-height:28px; font-family:zapfdingbats;">&#231;</div>';
                $subStatusColor = '#f59e0b';
                $subStatusText = 'In Progress';
            }
            
            $subDescription = $subComp['name'];
            if (strlen($subDescription) > 100) {
                $subDescription = substr($subDescription, 0, 97) . '...';
            }
            
            $subActivitiesInfo = ($subComp['completed_activities'] ?? 0) . ' / ' . ($subComp['total_activities'] ?? 0);
            if (($subComp['total_activities'] ?? 0) == 0) {
                $subActivitiesInfo = 'N/A';
            }
            
            // Sub-competency row (indented)
            $tablehtml .= '
            <tr style="background-color:#f8fafc;">
                <td style="border:1px solid #e2e8f0; padding:12px; text-align:center; font-weight:bold;">' . $subStatusIcon . '</td>
                <td style="border:1px solid #e2e8f0; padding:8px; padding-left:25px; font-size:10px; color:#475569;">
                    <span style="color:#94a3b8;">&#9492;&#9472;</span> ' . htmlspecialchars($subComp['name']) . '
                </td>
                <td style="border:1px solid #e2e8f0; padding:8px; font-size:9px; color:#64748b;">' . htmlspecialchars($subDescription) . '</td>
                <td style="border:1px solid #e2e8f0; padding:8px; text-align:center; font-size:9px; color:#64748b;">' . ($subComp['proficiency_percent'] ?? 0) . '%</td>
                <td style="border:1px solid #e2e8f0; padding:8px; text-align:center; font-size:9px; color:#64748b;">' . $subActivitiesInfo . '</td>
            </tr>';
        }
    }
}

$tablehtml .= '
    </tbody>
</table>';

$pdf->writeHTML($tablehtml, true, false, true, false, '');

// Legend
$legendhtml = '
<div style="margin-top:20px; padding:15px; background-color:#f8fafc; border-radius:5px;">
    <h3 style="font-size:12px; color:#1e293b; margin-bottom:10px; font-weight:bold;">Legend</h3>
    <table style="width:100%; border-collapse:collapse;">
        <tr>
            <td style="padding:5px; font-size:10px; color:#64748b;">
                <span style="background-color:#dcfce7; color:#16a34a; font-weight:bold; font-size:14px; padding:6px 10px; border-radius:50%; display:inline-block; margin-right:8px; width:24px; height:24px; line-height:24px; text-align:center; font-family:zapfdingbats;">4</span> Competent - Student has achieved proficiency in this competency
            </td>
        </tr>
        <tr>
            <td style="padding:5px; font-size:10px; color:#64748b;">
                <span style="background-color:#fef3c7; color:#d97706; font-weight:bold; font-size:14px; padding:6px 10px; border-radius:50%; display:inline-block; margin-right:8px; width:24px; height:24px; line-height:24px; text-align:center; font-family:zapfdingbats;">&#231;</span> In Progress - Student is working towards this competency
            </td>
        </tr>
        <tr>
            <td style="padding:5px; font-size:10px; color:#64748b;">
                <span style="background-color:#fee2e2; color:#dc2626; font-weight:bold; font-size:14px; padding:6px 10px; border-radius:50%; display:inline-block; margin-right:8px; width:24px; height:24px; line-height:24px; text-align:center;">X</span> Not Attempted - Student has not yet started this competency
            </td>
        </tr>
    </table>
</div>';

$pdf->writeHTML($legendhtml, true, false, true, false, '');

// Footer
$pdf->SetY(-25);
$pdf->SetFont('helvetica', 'I', 8);
$pdf->SetTextColor(100, 100, 100);
$pdf->Cell(0, 10, 'Page ' . $pdf->getAliasNumPage() . ' / ' . $pdf->getAliasNbPages(), 0, false, 'C');

// Output PDF
$filename = 'competency_report_' . clean_filename(fullname($user)) . '_' . date('Y-m-d') . '.pdf';
$pdf->Output($filename, 'D');
exit;

