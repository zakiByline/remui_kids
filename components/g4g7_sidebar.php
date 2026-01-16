<?php
/**
 * Grade 4-7 Student Dashboard Sidebar Component
 * Reusable sidebar for G4G7 Dashboard Learning Platform
 * 
 * All strings use get_string() for proper translation support.
 */

defined('MOODLE_INTERNAL') || die();

global $USER, $CFG, $DB;

require_once(__DIR__ . '/../lib/cohort_sidebar_helper.php');

$has_scratch_editor_access = theme_remui_kids_user_has_scratch_editor_access($USER->id);
$has_code_editor_access = theme_remui_kids_user_has_code_editor_access($USER->id);

// Get translated strings
$str_dashboard = get_string('nav_dashboard', 'theme_remui_kids');
$str_mycourses = get_string('nav_mycourses', 'theme_remui_kids');
$str_lessons = get_string('nav_lessons', 'theme_remui_kids');
$str_activities = get_string('nav_activities', 'theme_remui_kids');
$str_achievements = get_string('nav_achievements', 'theme_remui_kids');
$str_competencies = get_string('nav_competencies', 'theme_remui_kids');
$str_grades = get_string('nav_grades', 'theme_remui_kids');
$str_badges = get_string('nav_badges', 'theme_remui_kids');
$str_schedule = get_string('nav_schedule', 'theme_remui_kids');
$str_settings = get_string('nav_settings', 'theme_remui_kids');
$str_treeview = get_string('nav_treeview', 'theme_remui_kids');
$str_studypartner = get_string('nav_studypartner', 'theme_remui_kids');
$str_ebooks = get_string('nav_ebooks', 'theme_remui_kids');
$str_ebooks_desc = get_string('ebooks_desc', 'theme_remui_kids');
$str_scratch = get_string('scratch_editor', 'theme_remui_kids');
$str_scratch_desc = get_string('scratch_editor_desc', 'theme_remui_kids');
$str_code = get_string('code_editor', 'theme_remui_kids');
$str_code_desc = get_string('code_editor_desc', 'theme_remui_kids');
$str_section_dashboard = get_string('section_dashboard', 'theme_remui_kids');
$str_section_tools = get_string('section_tools', 'theme_remui_kids');
$str_section_quickactions = get_string('section_quickactions', 'theme_remui_kids');
$str_learning_platform = get_string('learning_platform', 'theme_remui_kids');
?>

<!-- G4G7 Student Dashboard Sidebar -->
<style>
/* G4G7 Sidebar Styles */
.g4g7-sidebar {
    position: fixed;
    left: 0;
    top: 0;
    width: 280px;
    height: 100vh;
    background: #ffffff;
    border-right: 1px solid #e5e7eb;
    box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1);
    z-index: 1000;
    overflow-y: auto;
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
}

/* Header Section */
.g4g7-header {
    background: #f0f9ff;
    padding: 25px 20px;
    color: #0369a1;
    position: relative;
    border-bottom: 1px solid #bae6fd;
}

.g4g7-logo-container {
    display: flex;
    align-items: center;
    gap: 12px;
    position: relative;
    z-index: 2;
}

.g4g7-logo {
    width: 45px;
    height: 45px;
    background: #e0f2fe;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 2px solid #bae6fd;
}

.g4g7-logo::before {
    content: 'G4G7';
    font-size: 12px;
    font-weight: bold;
    color: #0369a1;
}

.g4g7-brand {
    flex: 1;
}

.g4g7-brand-name {
    font-size: 18px;
    font-weight: 700;
    margin: 0;
    line-height: 1.2;
}

.g4g7-brand-subtitle {
    font-size: 12px;
    font-weight: 400;
    margin: 0;
    opacity: 0.9;
    line-height: 1.3;
}

/* Navigation Content */
.g4g7-nav-content {
    padding: 0;
    background: #ffffff;
}

/* Section Headers */
.g4g7-section-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    padding: 16px 16px 8px 16px;
    font-size: 13px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #374151;
    position: relative;
    cursor: pointer;
}

.g4g7-section-dot {
    width: 6px;
    height: 6px;
    background: #6b7280;
    border-radius: 50%;
}

.g4g7-section-toggle {
    font-size: 12px;
    color: #9ca3af;
    transition: transform 0.2s;
}

.g4g7-section-toggle.collapsed {
    transform: rotate(-90deg);
}

/* Navigation Items */
.g4g7-nav-item {
    margin: 0 16px 4px 16px;
    border-radius: 8px;
    overflow: hidden;
}

/* Collapsible Sections */
.g4g7-section-content {
    display: block;
    transition: all 0.3s ease;
}

.g4g7-section-content.collapsed {
    display: none;
}

.g4g7-nav-link {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    text-decoration: none;
    color: #374151;
    font-size: 14px;
    font-weight: 500;
    transition: all 0.2s ease;
    border-radius: 8px;
    position: relative;
}

.g4g7-nav-link:hover {
    background: #f9fafb;
    color: #0369a1;
    text-decoration: none;
}

.g4g7-nav-link.active {
    background: #f0f9ff;
    color: #0369a1;
    border-right: 3px solid #0369a1;
}

.g4g7-nav-icon {
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #f9fafb;
    border-radius: 6px;
    font-size: 14px;
    color: #6b7280;
    transition: all 0.2s ease;
}

.g4g7-nav-link:hover .g4g7-nav-icon {
    background: #e0f2fe;
    color: #0369a1;
}

.g4g7-nav-link.active .g4g7-nav-icon {
    background: #bae6fd;
    color: #0369a1;
}

.g4g7-nav-text {
    flex: 1;
}

/* Quick Actions */
.g4g7-quick-actions {
    padding: 0 16px;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.g4g7-action-card {
    background: #f9fafb;
    border-radius: 8px;
    padding: 12px;
    cursor: pointer;
    transition: all 0.2s ease;
    border: 1px solid #e5e7eb;
}

.g4g7-action-card:hover {
    background: #f3f4f6;
    border-color: #0369a1;
}

.g4g7-action-content {
    display: flex;
    align-items: center;
    gap: 12px;
}

.g4g7-action-icon {
    width: 36px;
    height: 36px;
    background: #e0f2fe;
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #0369a1;
    font-size: 14px;
}

.g4g7-action-info {
    flex: 1;
}

.g4g7-action-title {
    font-size: 14px;
    font-weight: 600;
    color: #1f2937;
    margin: 0;
}

.g4g7-action-desc {
    font-size: 12px;
    color: #6b7280;
    margin: 2px 0 0 0;
}

.g4g7-action-arrow {
    color: #9ca3af;
    font-size: 12px;
}

/* Mobile Responsive */
@media (max-width: 768px) {
    .g4g7-sidebar {
        transform: translateX(-100%);
        transition: transform 0.3s ease;
    }
    
    .g4g7-sidebar.open {
        transform: translateX(0);
    }
    
    .g4g7-sidebar-toggle {
        display: block !important;
        position: fixed;
        top: 10px;
        left: 10px;
        z-index: 1001;
        background: #f0f9ff;
        color: #0369a1;
        border: 1px solid #bae6fd;
        padding: 10px 12px;
        border-radius: 8px;
        cursor: pointer;
    }
}
</style>

<!-- Sidebar Toggle Button for Mobile -->
<button class="g4g7-sidebar-toggle" onclick="toggleG4G7Sidebar()" style="display: none;">
    <i class="fa fa-bars"></i>
</button>

<!-- Main Sidebar -->
<div class="g4g7-sidebar" id="g4g7-sidebar">
    <!-- Header -->
    <div class="g4g7-header">
        <div class="g4g7-logo-container">
            <div class="g4g7-logo"></div>
            <div class="g4g7-brand">
                <h1 class="g4g7-brand-name">G4G7</h1>
                <p class="g4g7-brand-subtitle"><?php echo $str_dashboard; ?></p>
                <p class="g4g7-brand-subtitle"><?php echo $str_learning_platform; ?></p>
            </div>
        </div>
    </div>

    <!-- Navigation Content -->
    <div class="g4g7-nav-content">
        <!-- My Courses Section -->
        <div class="g4g7-section-header" onclick="toggleSection('mycourses')">
            <div style="display: flex; align-items: center; gap: 8px;">
                <div class="g4g7-section-dot"></div>
                <span><?php echo $str_mycourses; ?></span>
            </div>
            <i class="fa fa-chevron-down g4g7-section-toggle"></i>
        </div>

        <div id="mycourses-section" class="g4g7-section-content">
            <div class="g4g7-nav-item">
                <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/moodle_mycourses.php" class="g4g7-nav-link active">
                    <div class="g4g7-nav-icon">
                        <i class="fa fa-book"></i>
                    </div>
                    <span class="g4g7-nav-text"><?php echo $str_mycourses; ?></span>
                </a>
            </div>
        </div>

        <!-- Reports & Progress Section -->
        <div class="g4g7-section-header" onclick="toggleSection('reports')">
            <div style="display: flex; align-items: center; gap: 8px;">
                <div class="g4g7-section-dot"></div>
                <span>Reports & Progress</span>
            </div>
            <i class="fa fa-chevron-down g4g7-section-toggle"></i>
        </div>

        <div id="reports-section" class="g4g7-section-content">
            <div class="g4g7-nav-item">
                <a href="<?php echo $CFG->wwwroot; ?>/my/" class="g4g7-nav-link">
                    <div class="g4g7-nav-icon">
                        <i class="fa fa-chart-bar"></i>
                    </div>
                    <span class="g4g7-nav-text">My Reports</span>
                </a>
            </div>

            <div class="g4g7-nav-item">
                <a href="<?php echo $CFG->wwwroot; ?>/badges/mybadges.php" class="g4g7-nav-link">
                    <div class="g4g7-nav-icon">
                        <i class="fa fa-trophy"></i>
                    </div>
                    <span class="g4g7-nav-text"><?php echo $str_achievements; ?></span>
                </a>
            </div>

            <div class="g4g7-nav-item">
                <a href="<?php echo $CFG->wwwroot; ?>/badges/" class="g4g7-nav-link">
                    <div class="g4g7-nav-icon">
                        <i class="fa fa-shield-alt"></i>
                    </div>
                    <span class="g4g7-nav-text"><?php echo $str_badges; ?></span>
                </a>
            </div>

            <div class="g4g7-nav-item">
                <a href="<?php echo $CFG->wwwroot; ?>/grade/" class="g4g7-nav-link">
                    <div class="g4g7-nav-icon">
                        <i class="fa fa-graduation-cap"></i>
                    </div>
                    <span class="g4g7-nav-text"><?php echo $str_grades; ?></span>
                </a>
            </div>
        </div>

        <!-- Quick Actions Section -->
        <div class="g4g7-section-header" onclick="toggleSection('quickactions')">
            <div style="display: flex; align-items: center; gap: 8px;">
                <div class="g4g7-section-dot"></div>
                <span><?php echo $str_section_quickactions; ?></span>
            </div>
            <i class="fa fa-chevron-down g4g7-section-toggle"></i>
        </div>

        <div id="quickactions-section" class="g4g7-section-content">
            <div class="g4g7-quick-actions">
                <?php if ($has_scratch_editor_access): ?>
                <div class="g4g7-action-card" onclick="window.location.href='<?php echo $CFG->wwwroot; ?>/theme/remui_kids/scratch_simple.php'">
                    <div class="g4g7-action-content">
                        <div class="g4g7-action-icon">
                            <i class="fa fa-puzzle-piece"></i>
                        </div>
                        <div class="g4g7-action-info">
                            <h4 class="g4g7-action-title"><?php echo $str_scratch; ?></h4>
                            <p class="g4g7-action-desc"><?php echo $str_scratch_desc; ?></p>
                        </div>
                        <div class="g4g7-action-arrow">
                            <i class="fa fa-chevron-right"></i>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($has_code_editor_access): ?>
                <div class="g4g7-action-card" onclick="window.location.href='<?php echo $CFG->wwwroot; ?>/theme/remui_kids/code_editor_simple.php'">
                    <div class="g4g7-action-content">
                        <div class="g4g7-action-icon">
                            <i class="fa fa-code"></i>
                        </div>
                        <div class="g4g7-action-info">
                            <h4 class="g4g7-action-title"><?php echo $str_code; ?></h4>
                            <p class="g4g7-action-desc"><?php echo $str_code_desc; ?></p>
                        </div>
                        <div class="g4g7-action-arrow">
                            <i class="fa fa-chevron-right"></i>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php
                // Check if Study Partner is enabled for student dashboard
                $showstudypartner = get_config('local_studypartner', 'showstudentnav');
                if ($showstudypartner === null) {
                    $showstudypartner = true;
                }
                if ($showstudypartner) {
                    // Check if user has capability to view Study Partner (only if capability exists)
                    $context = context_system::instance();
                    if (get_capability_info('local/studypartner:view') && has_capability('local/studypartner:view', $context)) {
                ?>
                <div class="g4g7-action-card" onclick="window.location.href='<?php echo $CFG->wwwroot; ?>/local/studypartner/'">
                    <div class="g4g7-action-content">
                        <div class="g4g7-action-icon">
                            <i class="fa fa-robot"></i>
                        </div>
                        <div class="g4g7-action-info">
                            <h4 class="g4g7-action-title"><?php echo $str_studypartner; ?></h4>
                            <p class="g4g7-action-desc">Get help with your studies</p>
                        </div>
                        <div class="g4g7-action-arrow">
                            <i class="fa fa-chevron-right"></i>
                        </div>
                    </div>
                </div>
                <?php
                    }
                }
                ?>
            </div>
        </div>
        
        <!-- Leaderboard Section in Sidebar -->
        <?php
        // Get leaderboard data from globals
        $sidebar_leaderboard_students = isset($GLOBALS['leaderboard_students']) ? $GLOBALS['leaderboard_students'] : [];
        $sidebar_enrolled_courses = isset($GLOBALS['enrolled_courses']) ? $GLOBALS['enrolled_courses'] : [];
        ?>
        <div class="g4g7-sidebar-leaderboard" style="margin: 20px 16px; background: #f9fafb; border-radius: 12px; padding: 16px; border: 1px solid #e5e7eb;">
                            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <i class="fa fa-trophy" style="color: #f59e0b; font-size: 18px;"></i>
                    <h4 style="margin: 0; font-size: 16px; font-weight: 700; color: #374151;">Leaderboard</h4>
                </div>
                <a href="#" style="font-size: 11px; color: #0369a1; text-decoration: none; font-weight: 600;">See all <i class="fa fa-chevron-right" style="font-size: 9px;"></i></a>
            </div>
            <p style="font-size: 12px; color: #6b7280; margin: 0 0 12px 0; font-weight: 600;">Weekly Points</p>

            <!-- Tabs -->
            <div style="display: flex; gap: 4px; margin-bottom: 12px; border-bottom: 1px solid #e5e7eb;">
                <button class="sidebar-leaderboard-tab active" data-tab="class" style="flex: 1; padding: 6px 8px; border: none; background: transparent; color: #0369a1; font-weight: 600; font-size: 11px; cursor: pointer; border-bottom: 2px solid #bae6fd; margin-bottom: -1px;">Class</button>
                <button class="sidebar-leaderboard-tab" data-tab="grade" style="flex: 1; padding: 6px 8px; border: none; background: transparent; color: #9ca3af; font-weight: 500; font-size: 11px; cursor: pointer;">Grade</button>
                <button class="sidebar-leaderboard-tab" data-tab="school" style="flex: 1; padding: 6px 8px; border: none; background: transparent; color: #9ca3af; font-weight: 500; font-size: 11px; cursor: pointer;">School</button>
            </div>
            
            <!-- Leaderboard List -->
            <div class="sidebar-leaderboard-list" style="max-height: 300px; overflow-y: auto;">
                <?php if (!empty($sidebar_leaderboard_students)): ?>
                    <?php foreach (array_slice($sidebar_leaderboard_students, 0, 5) as $idx => $leader): ?>
                        <?php
                        $is_current_user = isset($leader['is_current_user']) && $leader['is_current_user'];
                        $points_value = round($leader['overall_score'] * 12);
                        ?>
                        <div style="display: flex; align-items: center; gap: 10px; padding: 10px 0; border-bottom: 1px solid #e5e7eb; <?php echo $is_current_user ? 'background: #e0f2fe; margin: 0 -8px; padding: 10px 8px; border-radius: 6px;' : ''; ?>">
                            <div style="width: 36px; height: 36px; border-radius: 50%; overflow: hidden; flex-shrink: 0; border: 1px solid #bae6fd;">
                                <?php if (isset($leader['has_profile_picture']) && $leader['has_profile_picture']): ?>
                                    <img src="<?php echo htmlspecialchars($leader['profile_picture_url']); ?>" alt="<?php echo htmlspecialchars($leader['name']); ?>" style="width: 100%; height: 100%; object-fit: cover;">
                                <?php else: ?>
                                    <div style="width: 100%; height: 100%; background: #e0f2fe; display: flex; align-items: center; justify-content: center; color: #0369a1; font-weight: 700; font-size: 14px;">
                                        <?php echo strtoupper(substr($leader['name'], 0, 1)); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div style="flex: 1; min-width: 0;">
                                <div style="display: flex; align-items: center; gap: 4px; margin-bottom: 2px;">
                                    <span style="font-size: 13px; font-weight: 600; color: #374151;"><?php echo htmlspecialchars($leader['full_name'] ?? $leader['name']); ?></span>
                                    <?php if ($is_current_user): ?>
                                        <i class="fa fa-star" style="color: #f59e0b; font-size: 10px;"></i>
                                    <?php endif; ?>
                                </div>
                                <div style="font-size: 11px; color: #6b7280; font-weight: 500;"><?php echo number_format($points_value); ?> points</div>
                            </div>
                            <div style="text-align: right; flex-shrink: 0;">
                                <div style="font-size: 13px; font-weight: 700; color: #0369a1;"><?php echo number_format($points_value); ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <!-- Default demo data -->
                    <div style="display: flex; align-items: center; gap: 10px; padding: 10px 0; border-bottom: 1px solid #e5e7eb;">
                        <div style="width: 36px; height: 36px; border-radius: 50%; background: #e0f2fe; display: flex; align-items: center; justify-content: center; color: #0369a1; font-weight: 700; font-size: 14px;">J</div>
                        <div style="flex: 1;"><div style="font-size: 13px; font-weight: 600; color: #374151;">John</div><div style="font-size: 11px; color: #6b7280;">1,250 points</div></div>
                        <div style="font-size: 13px; font-weight: 700; color: #0369a1;">1,250</div>
                    </div>
                    <div style="display: flex; align-items: center; gap: 10px; padding: 10px 0; background: #e0f2fe; margin: 0 -8px; padding: 10px 8px; border-radius: 6px;">
                        <div style="width: 36px; height: 36px; border-radius: 50%; background: #bae6fd; display: flex; align-items: center; justify-content: center; color: #0369a1; font-weight: 700; font-size: 14px;">Y</div>
                        <div style="flex: 1;"><div style="font-size: 13px; font-weight: 600; color: #374151; display: flex; align-items: center; gap: 4px;">You <i class="fa fa-star" style="color: #f59e0b; font-size: 10px;"></i></div><div style="font-size: 11px; color: #6b7280;">1,120 points</div></div>
                        <div style="font-size: 13px; font-weight: 700; color: #0369a1;">1,120</div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Friends Section -->
        <?php
        // Get friends/classmates from same courses
        $friends_list = [];
        if (!empty($sidebar_enrolled_courses)) {
            $course_ids = array_keys($sidebar_enrolled_courses);
            list($course_insql, $course_params) = $DB->get_in_or_equal($course_ids, SQL_PARAMS_NAMED, 'course');
            $student_role = $DB->get_record('role', ['shortname' => 'student']);

            if ($student_role) {
                $friends = $DB->get_records_sql(
                    "SELECT DISTINCT u.id, u.firstname, u.lastname
                     FROM {user} u
                     INNER JOIN {user_enrolments} ue ON ue.userid = u.id
                     INNER JOIN {enrol} e ON e.id = ue.enrolid
                     WHERE e.courseid $course_insql
                     AND u.id != :userid
                     AND u.deleted = 0
                     AND u.suspended = 0
                     ORDER BY u.firstname
                     LIMIT 4",
                    array_merge($course_params, ['userid' => $USER->id])
                );

                foreach ($friends as $friend) {
                    // Calculate points for friend
                    $friend_points = 0;
                    foreach ($course_ids as $cid) {
                        $quiz_points = $DB->get_field_sql(
                            "SELECT SUM(qa.sumgrades)
                             FROM {quiz_attempts} qa
                             JOIN {quiz} q ON qa.quiz = q.id
                             WHERE qa.userid = ? AND qa.state = 'finished' AND q.course = ?",
                            [$friend->id, $cid]
                        );
                        $friend_points += round($quiz_points ? $quiz_points : 0);
                    }

                    $friends_list[] = [
                        'name' => fullname($friend),
                        'points' => $friend_points
                    ];
                }
            }
        }
        ?>
        <div class="g4g7-sidebar-friends" style="margin: 20px 16px; background: #f9fafb; border-radius: 12px; padding: 16px; border: 1px solid #e5e7eb;">
            <h4 style="margin: 0 0 16px 0; font-size: 16px; font-weight: 700; color: #374151;">Classmates</h4>
            <div style="display: flex; flex-direction: column; gap: 12px;">
                <?php if (!empty($friends_list)): ?>
                    <?php foreach ($friends_list as $friend): ?>
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <div style="width: 40px; height: 40px; border-radius: 50%; background: #e0f2fe; display: flex; align-items: center; justify-content: center; color: #0369a1; font-weight: 700; font-size: 16px;">
                                <?php echo strtoupper(substr($friend['name'], 0, 1)); ?>
                            </div>
                            <div style="flex: 1;">
                                <div style="font-size: 14px; font-weight: 600; color: #374151;"><?php echo htmlspecialchars($friend['name']); ?></div>
                                <div style="font-size: 12px; color: #6b7280; font-weight: 500;"><?php echo number_format($friend['points']); ?> Points</div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <!-- Demo friends -->
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <div style="width: 40px; height: 40px; border-radius: 50%; background: #e0f2fe; display: flex; align-items: center; justify-content: center; color: #0369a1; font-weight: 700; font-size: 16px;">A</div>
                        <div style="flex: 1;"><div style="font-size: 14px; font-weight: 600; color: #374151;">Ava Lee</div><div style="font-size: 12px; color: #6b7280; font-weight: 500;">82 Points</div></div>
                    </div>
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <div style="width: 40px; height: 40px; border-radius: 50%; background: #e0f2fe; display: flex; align-items: center; justify-content: center; color: #0369a1; font-weight: 700; font-size: 16px;">N</div>
                        <div style="flex: 1;"><div style="font-size: 14px; font-weight: 600; color: #374151;">Noah Kim</div><div style="font-size: 12px; color: #6b7280; font-weight: 500;">128 Points</div></div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
function toggleG4G7Sidebar() {
    var sidebar = document.getElementById('g4g7-sidebar');
    if (sidebar) {
        sidebar.classList.toggle('open');
    }
}

// Toggle collapsible sections
function toggleSection(sectionId) {
    const section = document.getElementById(sectionId + '-section');
    const toggle = document.querySelector('[onclick="toggleSection(\'' + sectionId + '\')"] .g4g7-section-toggle');

    if (section && toggle) {
        section.classList.toggle('collapsed');
        toggle.classList.toggle('collapsed');
    }
}

// Close sidebar when clicking outside on mobile
document.addEventListener('click', function(event) {
    var sidebar = document.getElementById('g4g7-sidebar');
    var toggle = document.querySelector('.g4g7-sidebar-toggle');

    if (window.innerWidth <= 768 && sidebar && toggle) {
        if (!sidebar.contains(event.target) && !toggle.contains(event.target)) {
            sidebar.classList.remove('open');
        }
    }
});

// Leaderboard tab switching
document.addEventListener('DOMContentLoaded', function() {
    const sidebarTabs = document.querySelectorAll('.sidebar-leaderboard-tab');
    sidebarTabs.forEach(tab => {
        tab.addEventListener('click', function() {
            sidebarTabs.forEach(t => {
                t.classList.remove('active');
                t.style.color = '#6b7280';
                t.style.fontWeight = '500';
                t.style.borderBottom = 'none';
            });
            this.classList.add('active');
            this.style.color = '#0369a1';
            this.style.fontWeight = '600';
            this.style.borderBottom = '2px solid #bae6fd';

            const tabType = this.getAttribute('data-tab');
            console.log('Switched to', tabType, 'leaderboard');
        });
    });
});
</script>
