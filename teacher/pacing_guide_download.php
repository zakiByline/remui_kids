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
 * Download pacing guide HTML as PDF.
 *
 * @package   theme_remui_kids
 * @copyright 2025
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/pdflib.php');

$courseid = required_param('courseid', PARAM_INT);
$html = required_param('html', PARAM_RAW);
$scope = optional_param('scope', 'course', PARAM_ALPHA);
$timeframe = optional_param('timeframe', '', PARAM_ALPHA);
$lessonname = optional_param('lessonname', '', PARAM_TEXT);

try {
    $course = get_course($courseid);
    require_login($course);
    require_sesskey();

    $context = context_course::instance($course->id);
    require_capability('moodle/course:update', $context);

    $filename = clean_filename('pacing_guide_' . $course->shortname . '_' . time() . '.pdf');

    $pdf = new pdf('P', 'mm', 'A4');
    $pdf->SetTitle('Pacing Guide - ' . format_string($course->fullname));
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->AddPage();

    $heading = '<h1 style="text-align:center;">Pacing Guide</h1>';
    $sub = '<p style="text-align:center;font-size:12px;">Course: ' . format_string($course->fullname) . '</p>';
    if ($scope === 'lesson' && !empty($lessonname)) {
        $sub .= '<p style="text-align:center;font-size:12px;">Lesson focus: ' . format_string($lessonname) . '</p>';
    }
    if (!empty($timeframe)) {
        $sub .= '<p style="text-align:center;font-size:12px;">Timeframe: ' . s($timeframe) . '</p>';
    }

    $styles = '
        <style>
            table {
                width: 100%;
                border-collapse: collapse;
                font-size: 9pt;
                margin-bottom: 10pt;
            }
            th, td {
                border: 0.35pt solid #d0d7e5;
                padding: 6pt 8pt;
                vertical-align: top;
            }
            thead th {
                background-color: #eef2ff;
                font-weight: 600;
            }
            tbody tr:nth-child(even) td {
                background-color: #f9fbff;
            }
        </style>
    ';

    $pdf->SetFont('helvetica', '', 10);
    $pdf->writeHTML($heading . $sub . $styles . $html, true, false, true, false, '');

    $pdf->Output($filename, 'D');
} catch (Exception $e) {
    header('Content-Type: application/json', true, 500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}

