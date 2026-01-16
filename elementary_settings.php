<?php
/**
 * Elementary Settings Page (Grades 1-3)
 * A custom, kid-friendly settings page that overrides the default Moodle preferences
 *
 * @package    theme_remui_kids
 * @copyright  2024 WisdmLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/cohort/lib.php');
require_once(__DIR__ . '/lib.php');

// Require login
require_login();

global $USER, $DB, $CFG, $OUTPUT, $PAGE;

// Set page context
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/theme/remui_kids/elementary_settings.php'));
$PAGE->set_pagelayout('base');
$PAGE->set_title('My Settings');
$PAGE->set_heading('My Settings');

// Determine user's cohort and dashboard type
$usercohortid = null;
$usercohortname = '';
$dashboardtype = 'default';

// Try to get user cohorts with error handling
try {
    if (function_exists('cohort_get_user_cohorts')) {
        $usercohorts = cohort_get_user_cohorts($USER->id);
        if (!empty($usercohorts)) {
            $firstcohort = reset($usercohorts);
            $usercohortid = $firstcohort->id;
            $usercohortname = $firstcohort->name;
            
            // Determine dashboard type based on cohort name
            if (stripos($usercohortname, 'elementary') !== false || 
                stripos($usercohortname, 'grade 1') !== false || 
                stripos($usercohortname, 'grade 2') !== false || 
                stripos($usercohortname, 'grade 3') !== false ||
                stripos($usercohortname, 'k-3') !== false) {
                $dashboardtype = 'elementary';
            }
        }
    }
} catch (Exception $e) {
    error_log("Error getting user cohorts: " . $e->getMessage());
}

// Get user profile information
$user_profile = $DB->get_record('user', ['id' => $USER->id]);
$user_grade = '';

// Try to get grade level from custom profile field
try {
    if ($DB->get_manager()->table_exists('user_info_field') && $DB->get_manager()->table_exists('user_info_data')) {
        // Try different possible field names for grade
        $grade_field_names = ['gradelevel', 'grade', 'grade_level', 'class', 'year'];
        
        foreach ($grade_field_names as $field_name) {
            $grade_field = $DB->get_record('user_info_field', ['shortname' => $field_name]);
            if ($grade_field) {
                $grade_data = $DB->get_record('user_info_data', [
                    'userid' => $USER->id,
                    'fieldid' => $grade_field->id
                ]);
                if ($grade_data && !empty($grade_data->data)) {
                    $user_grade = $grade_data->data;
                    break;
                }
            }
        }
        
        // If no grade found, try to determine from cohort name
        if (empty($user_grade) && !empty($usercohortname)) {
            if (stripos($usercohortname, 'grade 1') !== false || stripos($usercohortname, 'g1') !== false) {
                $user_grade = 'Grade 1';
            } elseif (stripos($usercohortname, 'grade 2') !== false || stripos($usercohortname, 'g2') !== false) {
                $user_grade = 'Grade 2';
            } elseif (stripos($usercohortname, 'grade 3') !== false || stripos($usercohortname, 'g3') !== false) {
                $user_grade = 'Grade 3';
            } elseif (stripos($usercohortname, 'kindergarten') !== false || stripos($usercohortname, 'k') !== false) {
                $user_grade = 'Kindergarten';
            }
        }
    }
} catch (Exception $e) {
    error_log("Error getting user grade: " . $e->getMessage());
}

// Get user preferences
$user_preferences = get_user_preferences();

// Prepare template data
$template_data = [
    'user' => $user_profile,
    'user_grade' => $user_grade,
    'user_cohort' => $usercohortname,
    'dashboard_type' => $dashboardtype,
    'preferences' => $user_preferences,
    'wwwroot' => $CFG->wwwroot,
    'sitename' => $SITE->fullname,
    'user_picture' => $OUTPUT->user_picture($USER, ['size' => 100]),
    'current_time' => date('Y-m-d H:i:s'),
    'timezone' => $USER->timezone ?: $CFG->timezone,
    'language' => $USER->lang ?: $CFG->lang,
    'theme' => $USER->theme ?: $CFG->theme,
];

// Include sidebar helper for URLs
require_once(__DIR__ . '/lib/sidebar_helper.php');
$sidebar_data = theme_remui_kids_get_sidebar_data('elementary', 'settings');
$template_data = array_merge($template_data, $sidebar_data);

// Set custom dashboard flag
$template_data['custom_dashboard'] = true;
$template_data['is_settings_page'] = true;

// Render the template
echo $OUTPUT->render_from_template('theme_remui_kids/elementary_settings_page', $template_data);
?>
