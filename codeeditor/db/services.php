<?php
/**
 * Web services for Code Editor
 *
 * @package    mod_codeeditor
 * @copyright  2025
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'mod_codeeditor_analyze_code' => [
        'classname' => 'mod_codeeditor\external\analyze_code',
        'methodname' => 'analyze_code',
        'classpath' => '',
        'description' => 'Analyze code using AI',
        'type' => 'write',
        'capabilities' => 'mod/codeeditor:grade',
        'ajax' => true,
    ],
];

$services = [
    'Code Editor AI Analysis' => [
        'functions' => ['mod_codeeditor_analyze_code'],
        'restrictedusers' => 0,
        'enabled' => 1,
    ],
];




