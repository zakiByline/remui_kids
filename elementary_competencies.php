<?php
/**
 * Legacy elementary competencies route.
 *
 * All student cohorts now share the unified competencies experience.
 * Keep this file as a thin wrapper so any existing bookmarks or sidebar
 * links continue to work while reusing the central controller.
 *
 * @package   theme_remui_kids
 */

require_once(__DIR__ . '/../../config.php');

require_login();

redirect(new moodle_url('/theme/remui_kids/competencies.php'));

