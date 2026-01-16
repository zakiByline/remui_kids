<?php
/**
 * Emulator Hub Page
 * Displays all accessible emulators for students and teachers
 * 
 * @package    theme_remui_kids
 * @copyright  2024 KodeIt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lang_init.php'); // Apply user's selected language
require_once($CFG->dirroot . '/theme/remui_kids/lib/emulator_manager.php');
require_once($CFG->dirroot . '/cohort/lib.php');
require_once(__DIR__ . '/lib/sidebar_helper.php');
require_once(__DIR__ . '/lib/highschool_sidebar.php');

require_login();

global $USER, $OUTPUT, $PAGE, $DB, $CFG;

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/emulators.php');
$PAGE->set_title('Emulators Hub');
$PAGE->set_pagelayout('inherit');

// Determine if user is a teacher or student
$user_roles = get_user_roles($context, $USER->id);
$is_teacher = false;
$is_student = false;

foreach ($user_roles as $role) {
    if (in_array($role->shortname, ['editingteacher', 'teacher', 'coursecreator', 'manager'])) {
        $is_teacher = true;
    }
    if ($role->shortname === 'student') {
        $is_student = true;
    }
}

// Determine dashboard type for students
$dashboardtype = 'default';
if ($is_student && !$is_teacher) {
    try {
        $usercohorts = $DB->get_records_sql(
            "SELECT c.name, c.id 
             FROM {cohort} c 
             JOIN {cohort_members} cm ON c.id = cm.cohortid 
             WHERE cm.userid = ?",
            [$USER->id]
        );
        
        if (!empty($usercohorts)) {
            $cohort = reset($usercohorts);
            $cohortname = $cohort->name;
            
            if (preg_match('/grade\s*(?:1[0-2]|[8-9])/i', $cohortname)) {
                $dashboardtype = 'highschool';
            } elseif (preg_match('/grade\s*[4-7]/i', $cohortname)) {
                $dashboardtype = 'middle';
            } elseif (preg_match('/grade\s*[1-3]/i', $cohortname)) {
                $dashboardtype = 'elementary';
            }
        }
    } catch (Exception $e) {
        error_log("Emulators page: Error determining dashboard type: " . $e->getMessage());
    }
}

// Get accessible emulators based on role
$role = $is_teacher ? 'teacher' : 'student';
$accessible_emulators = theme_remui_kids_get_emulator_quick_actions($USER->id, $role);

// Get full catalog for additional info
$catalog = theme_remui_kids_emulator_catalog();

// Build emulator cards with full details
$emulator_cards = [];
foreach ($accessible_emulators as $emulator) {
    $slug = $emulator['slug'] ?? '';
    $catalog_info = $catalog[$slug] ?? null;
    
    $emulator_cards[] = [
        'slug' => $slug,
        'name' => $emulator['name'],
        'summary' => $emulator['description'] ?? $catalog_info['summary'] ?? '',
        'icon' => $emulator['icon'],
        'background' => $emulator['background'],
        'url' => $emulator['url'],
        'activityonly' => !empty($emulator['activityonly']),
        'category' => $catalog_info['category'] ?? 'general',
    ];
}

echo $OUTPUT->header();

// Render appropriate sidebar with wrapper structure
if ($is_teacher) {
    // Teacher pages use specific wrapper structure
    echo '<div class="teacher-css-wrapper">';
    echo '<div class="teacher-dashboard-wrapper">';
    include(__DIR__ . '/teacher/includes/sidebar.php');
    echo '<div class="teacher-main-content">';
} elseif ($is_student) {
    // Student sidebar based on dashboard type
    if ($dashboardtype === 'elementary') {
        $sidebar_context = theme_remui_kids_get_elementary_sidebar_context('emulators', $USER);
        echo $OUTPUT->render_from_template('theme_remui_kids/dashboard/elementary_sidebar', $sidebar_context);
    } elseif ($dashboardtype === 'highschool') {
        $sidebar_context = remui_kids_build_highschool_sidebar_context('emulators', $USER);
        echo $OUTPUT->render_from_template('theme_remui_kids/highschool_sidebar', $sidebar_context);
    } elseif ($dashboardtype === 'middle') {
        // G4G7 sidebar - need to build context
        $g4g7_context = [
            'dashboardurl' => (new moodle_url('/my/'))->out(),
            'mycoursesurl' => (new moodle_url('/theme/remui_kids/moodle_mycourses.php'))->out(),
            'achievementsurl' => (new moodle_url('/theme/remui_kids/achievements.php'))->out(),
            'competenciesurl' => (new moodle_url('/theme/remui_kids/competencies.php'))->out(),
            'gradesurl' => (new moodle_url('/theme/remui_kids/grades.php'))->out(),
            'badgesurl' => (new moodle_url('/theme/remui_kids/badges.php'))->out(),
            'scheduleurl' => (new moodle_url('/theme/remui_kids/schedule.php'))->out(),
            'ebooksurl' => (new moodle_url('/theme/remui_kids/ebooks.php'))->out(),
            'askteacherurl' => (new moodle_url('/theme/remui_kids/pages/student_doubts.php'))->out(),
            'settingsurl' => (new moodle_url('/user/preferences.php'))->out(),
            'treeviewurl' => (new moodle_url('/theme/remui_kids/treeview.php'))->out(),
            'emulatorsurl' => (new moodle_url('/theme/remui_kids/emulators.php'))->out(),
            'config' => ['wwwroot' => $CFG->wwwroot],
            'has_scratch_editor_access' => theme_remui_kids_user_has_scratch_editor_access($USER->id),
            'has_code_editor_access' => theme_remui_kids_user_has_code_editor_access($USER->id),
        ];
        echo $OUTPUT->render_from_template('theme_remui_kids/g4g7_sidebar', $g4g7_context);
    }
}
?>

<style>
<?php if ($is_student): ?>
/* Override container padding for students only */
.container {
    margin-top: 0 !important;
    padding-left: 280px !important;
    padding-right: 0 !important;
    max-width: 100% !important;
}
<?php endif; ?>

/* Move page content up */
#page-content {
    padding-top: 0 !important;
    margin-top: 0 !important;
}

.container {
    margin-top: 0 !important;
}

#topofscroll {
    margin-top: 0 !important;
    padding-top: 0 !important;
}

.main-inner {
    margin-top: 0 !important;
    padding-top: 0 !important;
}

.emulator-hub-container {
    max-width: 100%;
    margin: 0;
    padding: 0.5rem 1.5rem;
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
}

.emulator-hub-header {
    background: linear-gradient(135deg,rgb(125, 192, 223) 0%, rgb(194, 215, 238) 50%, rgb(125, 192, 223) 100%);
    color: #2c3e50;
    padding: 2.5rem;
    border-radius: 16px;
    margin-bottom: 2rem;
    box-shadow: 0 8px 30px rgba(224, 187, 228, 0.25);
}

.emulator-hub-header h1 {
    margin: 0 0 0.5rem 0;
    font-size: 2.5rem;
    font-weight: 700;
}

.emulator-hub-header p {
    margin: 0;
    font-size: 1.1rem;
    opacity: 0.95;
}

.emulator-hub-stats {
    display: flex;
    gap: 1.5rem;
    margin-top: 1.5rem;
    flex-wrap: wrap;
}

.emulator-stat {
    background: rgba(255, 255, 255, 0.5);
    padding: 1rem 1.5rem;
    border-radius: 12px;
    backdrop-filter: blur(10px);
    border: 1px solid rgba(255, 255, 255, 0.6);
}

.emulator-stat-number {
    font-size: 2rem;
    font-weight: 700;
    margin: 0;
}

.emulator-stat-label {
    font-size: 0.9rem;
    opacity: 0.9;
    margin: 0.25rem 0 0 0;
}

.emulators-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 1.5rem;
    margin-bottom: 2rem;
}

.emulator-card {
    background: white;
    border-radius: 16px;
    padding: 1.5rem;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
    transition: all 0.3s ease;
    cursor: pointer;
    border: 2px solid transparent;
    display: flex;
    flex-direction: column;
    text-decoration: none;
    color: inherit;
}

.emulator-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 30px rgba(0, 0, 0, 0.15);
    border-color: rgba(102, 126, 234, 0.3);
    text-decoration: none;
    color: inherit;
}

.emulator-card-header {
    display: flex;
    align-items: center;
    gap: 1rem;
    margin-bottom: 1rem;
}

.emulator-card-icon {
    width: 60px;
    height: 60px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.8rem;
    color: white;
    flex-shrink: 0;
}

.emulator-card-title {
    flex: 1;
}

.emulator-card-title h3 {
    margin: 0 0 0.25rem 0;
    font-size: 1.3rem;
    font-weight: 700;
    color: #2c3e50;
}

.emulator-card-category {
    font-size: 0.85rem;
    color: #7f8c8d;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    font-weight: 600;
}

.emulator-card-summary {
    color: #5d6d7e;
    font-size: 0.95rem;
    line-height: 1.6;
    margin: 0 0 1rem 0;
    flex: 1;
}

.emulator-card-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding-top: 1rem;
    border-top: 1px solid #e5e7eb;
}

.emulator-card-action {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    color: #667eea;
    font-weight: 600;
    font-size: 0.95rem;
}

.emulator-card-action i {
    transition: transform 0.3s ease;
}

.emulator-card:hover .emulator-card-action i {
    transform: translateX(4px);
}

.emulator-card-badge {
    background: #f0f4ff;
    color: #667eea;
    padding: 0.35rem 0.75rem;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 600;
}

.empty-state {
    text-align: center;
    padding: 4rem 2rem;
    background: white;
    border-radius: 16px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
}

.empty-state-icon {
    font-size: 4rem;
    color: #cbd5e0;
    margin-bottom: 1rem;
}

.empty-state h3 {
    color: #2c3e50;
    font-size: 1.5rem;
    margin: 0 0 0.5rem 0;
}

.empty-state p {
    color: #7f8c8d;
    font-size: 1rem;
    margin: 0;
}

@media (max-width: 768px) {
    .emulator-hub-container {
        padding: 1rem;
    }
    
    .emulator-hub-header {
        padding: 1.5rem;
    }
    
    .emulator-hub-header h1 {
        font-size: 2rem;
    }
    
    .emulators-grid {
        grid-template-columns: 1fr;
    }
    
    .emulator-hub-stats {
        flex-direction: column;
        gap: 1rem;
    }
}
</style>

<div class="emulator-hub-container">
    <div class="emulator-hub-header">
        <h1><i class="fa fa-rocket"></i> Emulators Hub</h1>
        <p>Access all your available educational tools and coding environments in one place</p>
        
        <div class="emulator-hub-stats">
            <div class="emulator-stat">
                <div class="emulator-stat-number"><?php echo count($emulator_cards); ?></div>
                <div class="emulator-stat-label">Available Emulators</div>
            </div>
            <div class="emulator-stat">
                <div class="emulator-stat-number"><?php echo $is_teacher ? 'Teacher' : 'Student'; ?></div>
                <div class="emulator-stat-label">Account Type</div>
            </div>
        </div>
    </div>

    <?php if (empty($emulator_cards)): ?>
        <div class="empty-state">
            <div class="empty-state-icon">
                <i class="fa fa-inbox"></i>
            </div>
            <h3>No Emulators Available</h3>
            <p>You don't have access to any emulators at the moment. Please contact your administrator.</p>
        </div>
    <?php else: ?>
        <div class="emulators-grid">
            <?php foreach ($emulator_cards as $card): ?>
                <a href="<?php echo s($card['url']); ?>" class="emulator-card" target="_blank">
                    <div class="emulator-card-header">
                        <div class="emulator-card-icon" style="background: <?php echo s($card['background']); ?>">
                            <i class="fa <?php echo s($card['icon']); ?>"></i>
                        </div>
                        <div class="emulator-card-title">
                            <h3><?php echo format_string($card['name']); ?></h3>
                            <div class="emulator-card-category"><?php echo s(ucfirst($card['category'])); ?></div>
                        </div>
                    </div>
                    
                    <div class="emulator-card-summary">
                        <?php echo format_text($card['summary'], FORMAT_PLAIN); ?>
                    </div>
                    
                    <div class="emulator-card-footer">
                        <div class="emulator-card-action">
                            <?php if (!empty($card['activityonly'])): ?>
                                <span>Assign in Course</span>
                                <i class="fa fa-arrow-right"></i>
                            <?php else: ?>
                                <span>Launch Emulator</span>
                                <i class="fa fa-arrow-right"></i>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($card['activityonly'])): ?>
                            <span class="emulator-card-badge">Course Activity</span>
                        <?php endif; ?>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php
// Close teacher wrapper divs
if ($is_teacher) {
    echo '</div>'; // Close teacher-main-content
    echo '</div>'; // Close teacher-dashboard-wrapper
    echo '</div>'; // Close teacher-css-wrapper
}

echo $OUTPUT->footer();

