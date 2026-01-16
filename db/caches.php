<?php
/**
 * Cache definitions for remui_kids theme
 */
 
defined('MOODLE_INTERNAL') || die();
 
$definitions = [
    'company_data' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => false,
        'ttl' => 300, // 5 minutes
        'staticacceleration' => true,
        'staticaccelerationsize' => 100
    ],
    'user_pictures' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => true,
        'ttl' => 600, // 10 minutes
        'staticacceleration' => true,
        'staticaccelerationsize' => 200
    ],
    'elementary_courses' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => false,
        'simpledata' => false,
        'ttl' => 300, // 5 minutes cache time
    ],
];































