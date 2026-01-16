<?php
/**
 * Parent Dashboard - Parent Profile Page
 * View detailed information about parent's profile
 */

// Suppress error display to prevent output before headers/session start
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once('../../../config.php');
require_login();

global $USER, $DB, $CFG, $OUTPUT, $PAGE;

require_once($CFG->dirroot . '/theme/remui_kids/lib/parent_access.php');
try {
    theme_remui_kids_require_parent(new moodle_url('/my/'));
} catch (Exception $e) {
    debugging('Error in parent access check: ' . $e->getMessage());
}

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/parent/parent_profile.php');
$PAGE->set_title('My Profile - Parent Dashboard');
$PAGE->set_heading('My Profile');
$PAGE->set_pagelayout('base');

$userid = $USER->id;

// Get parent's own profile data
$parent_profile = null;
$enrolled_courses = [];
$recent_activity = [];
$profile_stats = [
    'total_children' => 0,
    'total_courses' => 0,
    'active_days' => 0
];

    try {
    // Get parent's full user record
    $parent_user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
        
    if ($parent_user) {
        // Get cohort/group information if any
            $cohort_info = null;
            try {
                $cohort_info = $DB->get_record_sql(
                    "SELECT c.id, c.name, c.description
                     FROM {cohort} c
                     JOIN {cohort_members} cm ON cm.cohortid = c.id
                     WHERE cm.userid = :userid
                     LIMIT 1",
                ['userid' => $userid]
                );
            } catch (Exception $e) {
                // Cohort not available
            }
            
        // Get profile picture URL from Moodle
        $profile_picture_url = '';
        $has_profile_picture = false;
        if (isset($parent_user->picture) && $parent_user->picture > 0) {
            try {
                $user_context = context_user::instance($userid);
                $fs = get_file_storage();
                $files = $fs->get_area_files($user_context->id, 'user', 'icon', 0, 'itemid', false);
                
                if (!empty($files)) {
                    $user_picture = new user_picture($parent_user);
                    $user_picture->size = 1; // Full size
                    $profile_picture_url = $user_picture->get_url($PAGE)->out(false);
                    if (!empty($profile_picture_url)) {
                        $has_profile_picture = true;
                    }
                }
            } catch (Exception $e) {
                debugging('Error getting parent profile picture: ' . $e->getMessage());
            }
        }
        
        $parent_profile = [
            'id' => $parent_user->id,
            'firstname' => $parent_user->firstname,
            'lastname' => $parent_user->lastname,
            'fullname' => fullname($parent_user),
            'email' => $parent_user->email,
            'username' => $parent_user->username,
            'firstaccess' => $parent_user->firstaccess,
            'lastaccess' => $parent_user->lastaccess,
            'timecreated' => $parent_user->timecreated,
            'city' => $parent_user->city,
            'country' => $parent_user->country,
            'phone1' => $parent_user->phone1,
            'phone2' => $parent_user->phone2,
            'department' => $parent_user->department,
            'institution' => $parent_user->institution,
            'description' => $parent_user->description,
            'descriptionformat' => $parent_user->descriptionformat,
            'cohort_name' => $cohort_info ? $cohort_info->name : 'Not Assigned',
            'profile_picture_url' => $profile_picture_url,
            'has_profile_picture' => $has_profile_picture
        ];
        
        // Get number of children
        require_once(__DIR__ . '/../lib/get_parent_children.php');
        $children = get_parent_children($userid);
        $profile_stats['total_children'] = count($children);
        
        // Get enrolled courses (if parent is enrolled in any)
        $courses = enrol_get_users_courses($userid, true);
        $profile_stats['total_courses'] = count($courses);
            
            // Get enrolled courses with progress
            require_once($CFG->libdir . '/completionlib.php');
            foreach ($courses as $course) {
                $completion = new completion_info($course);
                $percentage = 0;
                
                if ($completion->is_enabled()) {
                $completions = $completion->get_completions($userid);
                    if (!empty($completions)) {
                        $completed = 0;
                        $total = count($completions);
                        foreach ($completions as $c) {
                            if ($c->is_complete()) {
                                $completed++;
                            }
                        }
                        $percentage = $total > 0 ? round(($completed / $total) * 100) : 0;
                    }
                }
                
                $enrolled_courses[] = [
                    'id' => $course->id,
                    'fullname' => $course->fullname,
                    'shortname' => $course->shortname,
                    'progress' => $percentage
                ];
            }
        
        // Get active days (last 30 days)
        try {
            $profile_stats['active_days'] = $DB->count_records_sql(
                "SELECT COUNT(DISTINCT DATE(FROM_UNIXTIME(timecreated)))
                 FROM {logstore_standard_log}
                 WHERE userid = :userid AND timecreated > :since",
                ['userid' => $userid, 'since' => time() - (30 * 24 * 60 * 60)]
            );
        } catch (Exception $e) {
            $profile_stats['active_days'] = 0;
        }
            
            // Get recent activity (last 10)
            try {
                $recent_logs = $DB->get_records_sql(
                    "SELECT id, timecreated, action, target, objecttable
                     FROM {logstore_standard_log}
                     WHERE userid = :userid
                     ORDER BY timecreated DESC
                     LIMIT 10",
                ['userid' => $userid]
                );
                
                foreach ($recent_logs as $log) {
                    $recent_activity[] = [
                        'time' => $log->timecreated,
                        'action' => ucfirst($log->action),
                        'target' => ucfirst($log->target),
                        'description' => ucfirst($log->action) . ' ' . ucfirst($log->target)
                    ];
                }
            } catch (Exception $e) {
                // Logstore not available
            }
        }
    } catch (Exception $e) {
    debugging('Error loading parent profile: ' . $e->getMessage());
}

echo $OUTPUT->header();
include_once(__DIR__ . '/../components/parent_sidebar.php');
?>

<link rel="stylesheet" href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/style/parent_dashboard.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<style>
/* Force full width and remove all margins */
#page,
#page-wrapper,
#region-main,
#region-main-box,
.main-inner,
[role="main"] {
    max-width: 100% !important;
    margin: 0 !important;
    padding: 0 !important;
}

.parent-main-content {
    margin-left: 280px;
    padding: 0;
    min-height: 100vh;
    background: linear-gradient(135deg, #f8fbff 0%, #ffffff 100%);
    width: calc(100% - 280px);
    max-width: 100%;
    box-sizing: border-box;
}

.parent-content-wrapper {
    width: 100%;
    max-width: 100%;
    box-sizing: border-box;
}

.container,
.container-fluid,
#region-main,
#region-main-box {
    margin: 0 !important;
    padding: 0 !important;
    max-width: 100% !important;
}

/* Compact Profile Styles */
.profile-card {
    background: white;
    border-radius: 10px;
    padding: 15px;
    box-shadow: 0 1px 4px rgba(59,130,246,0.08);
    margin-bottom: 15px;
    border: 1px solid #e0f2fe;
}
.info-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 0;
    border-bottom: 1px solid #f0f4f8;
}
.info-row:last-child {
    border-bottom: none;
}
.info-label {
    color: #6b7280;
    font-size: 12px;
    font-weight: 600;
}
.info-value {
    color: #4b5563;
    font-size: 12px;
    font-weight: 700;
    text-align: right;
}
.stat-card-profile {
    background: #dbeafe;
    border-radius: 10px;
    padding: 12px;
    text-align: center;
    box-shadow: 0 1px 4px rgba(59,130,246,0.08);
    border-left: 3px solid #3b82f6;
}
.course-badge {
    background: #f0f9ff;
    border-radius: 8px;
    padding: 12px;
    box-shadow: 0 1px 4px rgba(59,130,246,0.06);
    border-left: 3px solid #3b82f6;
    border: 1px solid #e0f2fe;
}

@media (max-width: 1024px) {
    .parent-main-content {
        margin-left: 0;
        width: 100%;
        padding: 24px 20px 40px;
    }
}
</style>

<div class="parent-main-content">
    <div class="parent-content-wrapper">
        
        <nav class="parent-breadcrumb">
            <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/parent/parent_dashboard.php" class="breadcrumb-link">Dashboard</a>
            <i class="fas fa-chevron-right breadcrumb-separator"></i>
            <span class="breadcrumb-current">My Profile</span>
        </nav>

        <?php if ($parent_profile): ?>

        <!-- Compact Profile Header -->
        <div style="background: linear-gradient(135deg, #3b82f6, #2563eb); padding: 18px 20px; border-radius: 12px; margin-bottom: 20px; color: white; box-shadow: 0 2px 8px rgba(59, 130, 246, 0.2);">
            <div style="display: flex; align-items: center; gap: 15px;">
                <!-- Avatar -->
                <div style="width: 60px; height: 60px; background: rgba(255,255,255,0.2); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 24px; border: 2px solid rgba(255,255,255,0.3); overflow: hidden;">
                    <?php if ($parent_profile['has_profile_picture']): ?>
                        <img src="<?php echo htmlspecialchars($parent_profile['profile_picture_url']); ?>" alt="<?php echo htmlspecialchars($parent_profile['fullname']); ?>" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
                    <?php else: ?>
                        <?php echo strtoupper(core_text::substr($parent_profile['firstname'], 0, 1) . core_text::substr($parent_profile['lastname'], 0, 1)); ?>
                    <?php endif; ?>
                </div>
                
                <!-- Profile Info -->
                <div style="flex: 1;">
                    <h1 style="margin: 0 0 4px 0; font-size: 20px; font-weight: 700;">
                        <?php echo htmlspecialchars($parent_profile['fullname']); ?>
                    </h1>
                    <p style="margin: 0; font-size: 12px; opacity: 0.9;">
                        <i class="fas fa-user-tie"></i> Parent Account
                        <?php if (!empty($parent_profile['cohort_name']) && $parent_profile['cohort_name'] !== 'Not Assigned'): ?>
                            • <?php echo htmlspecialchars($parent_profile['cohort_name']); ?>
                        <?php endif; ?>
                    </p>
                </div>
                
                <!-- Back Button -->
                <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/parent/parent_dashboard.php" 
                   style="background: rgba(255,255,255,0.2); color: white; padding: 8px 16px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 12px; border: 1px solid rgba(255,255,255,0.3);">
                    <i class="fas fa-arrow-left"></i> Back
                </a>
            </div>
        </div>

        <!-- Profile Overview Stats -->
        <div class="parent-section">
            <h2 class="section-title" style="font-size: 16px; font-weight: 700; color: #1f2937; margin-bottom: 12px;">
                <i class="fas fa-chart-bar" style="color: #3b82f6;"></i>
                Profile Overview
            </h2>
            
            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 20px;">
                <div class="stat-card-profile">
                    <div style="font-size: 22px; font-weight: 700; color: #3b82f6; margin-bottom: 4px;">
                        <?php echo $profile_stats['total_children']; ?>
                    </div>
                    <div style="font-size: 10px; color: #1e40af; font-weight: 700; text-transform: uppercase;">Children</div>
                </div>
                
                <div class="stat-card-profile">
                    <div style="font-size: 22px; font-weight: 700; color: #3b82f6; margin-bottom: 4px;">
                        <?php echo $profile_stats['total_courses']; ?>
                    </div>
                    <div style="font-size: 10px; color: #1e40af; font-weight: 700; text-transform: uppercase;">My Courses</div>
                </div>
                
                <div class="stat-card-profile">
                    <div style="font-size: 22px; font-weight: 700; color: #3b82f6; margin-bottom: 4px;">
                        <?php echo $profile_stats['active_days']; ?>
                    </div>
                    <div style="font-size: 10px; color: #1e40af; font-weight: 700; text-transform: uppercase;">Active Days</div>
                </div>
            </div>
        </div>

        <!-- Compact Information Grid -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 20px;">
        <!-- Personal Information -->
        <div class="parent-section">
                <h2 class="section-title" style="font-size: 16px; font-weight: 700; color: #1f2937; margin-bottom: 12px;">
                    <i class="fas fa-info-circle" style="color: #3b82f6;"></i>
                Personal Information
            </h2>
            
            <div class="profile-card">
                <div class="info-row">
                    <span class="info-label">Full Name</span>
                    <span class="info-value"><?php echo htmlspecialchars($parent_profile['fullname']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Username</span>
                    <span class="info-value"><?php echo htmlspecialchars($parent_profile['username']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Email</span>
                    <span class="info-value"><?php echo htmlspecialchars($parent_profile['email']); ?></span>
                </div>
                <?php if (!empty($parent_profile['phone1'])): ?>
                <div class="info-row">
                    <span class="info-label">Phone</span>
                    <span class="info-value"><?php echo htmlspecialchars($parent_profile['phone1']); ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($parent_profile['city'])): ?>
                <div class="info-row">
                    <span class="info-label">City</span>
                    <span class="info-value"><?php echo htmlspecialchars($parent_profile['city']); ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($parent_profile['country'])): ?>
                <div class="info-row">
                    <span class="info-label">Country</span>
                    <span class="info-value"><?php echo htmlspecialchars($parent_profile['country']); ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($parent_profile['department'])): ?>
                <div class="info-row">
                    <span class="info-label">Department</span>
                    <span class="info-value"><?php echo htmlspecialchars($parent_profile['department']); ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($parent_profile['institution'])): ?>
                <div class="info-row">
                    <span class="info-label">Institution</span>
                    <span class="info-value"><?php echo htmlspecialchars($parent_profile['institution']); ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($parent_profile['cohort_name']) && $parent_profile['cohort_name'] !== 'Not Assigned'): ?>
                <div class="info-row">
                    <span class="info-label">Group/Cohort</span>
                    <span class="info-value"><?php echo htmlspecialchars($parent_profile['cohort_name']); ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Account Information -->
        <div class="parent-section">
                <h2 class="section-title" style="font-size: 16px; font-weight: 700; color: #1f2937; margin-bottom: 12px;">
                    <i class="fas fa-user-clock" style="color: #3b82f6;"></i>
                Account Information
            </h2>
            
            <div class="profile-card">
                <div class="info-row">
                    <span class="info-label">Account Created</span>
                    <span class="info-value"><?php echo userdate($parent_profile['timecreated'], '%d %b %Y'); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">First Access</span>
                    <span class="info-value">
                        <?php echo $parent_profile['firstaccess'] ? userdate($parent_profile['firstaccess'], '%d %b %Y') : 'Never'; ?>
                    </span>
                </div>
                <div class="info-row">
                    <span class="info-label">Last Access</span>
                    <span class="info-value">
                        <?php echo $parent_profile['lastaccess'] ? userdate($parent_profile['lastaccess'], '%d %b %Y, %I:%M %p') : 'Never'; ?>
                    </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Description Section -->
        <?php if (!empty($parent_profile['description'])): ?>
        <div class="parent-section" style="margin-bottom: 20px;">
            <h2 class="section-title" style="font-size: 16px; font-weight: 700; color: #1f2937; margin-bottom: 12px;">
                <i class="fas fa-info-circle" style="color: #3b82f6;"></i>
                About Me
            </h2>
            <div class="profile-card">
                <div style="font-size: 13px; color: #475569; line-height: 1.6;">
                    <?php echo format_text($parent_profile['description'], $parent_profile['descriptionformat'], ['para' => false, 'filter' => true]); ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Compact Courses & Activity -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
        <!-- Enrolled Courses -->
        <?php if (!empty($enrolled_courses)): ?>
        <div class="parent-section">
                <h2 class="section-title" style="font-size: 16px; font-weight: 700; color: #1f2937; margin-bottom: 12px;">
                    <i class="fas fa-book" style="color: #3b82f6;"></i>
                My Enrolled Courses (<?php echo count($enrolled_courses); ?>)
            </h2>
            
                <div style="display: grid; gap: 10px;">
                <?php foreach ($enrolled_courses as $course): 
                    $progress_color = $course['progress'] >= 75 ? '#3b82f6' : ($course['progress'] >= 50 ? '#60a5fa' : '#93c5fd');
                ?>
                <div class="course-badge">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                            <h3 style="margin: 0; font-size: 13px; font-weight: 700; color: #3b82f6;">
                            <?php echo htmlspecialchars($course['fullname']); ?>
                        </h3>
                            <span style="font-size: 12px; font-weight: 700; color: <?php echo $progress_color; ?>;">
                            <?php echo $course['progress']; ?>%
                        </span>
                    </div>
                        <div style="background: #e5e7eb; border-radius: 10px; height: 6px; overflow: hidden;">
                        <div style="background: <?php echo $progress_color; ?>; height: 100%; width: <?php echo $course['progress']; ?>%; transition: width 0.3s;"></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Recent Activity -->
        <?php if (!empty($recent_activity)): ?>
        <div class="parent-section">
                <h2 class="section-title" style="font-size: 16px; font-weight: 700; color: #1f2937; margin-bottom: 12px;">
                    <i class="fas fa-history" style="color: #3b82f6;"></i>
                Recent Activity
            </h2>
            
                <div style="background: white; border-radius: 10px; padding: 12px; box-shadow: 0 1px 4px rgba(59,130,246,0.08); border: 1px solid #e0f2fe;">
                <?php foreach ($recent_activity as $index => $activity): ?>
                    <div style="display: flex; align-items: center; gap: 10px; padding: 8px 0; <?php echo $index < count($recent_activity) - 1 ? 'border-bottom: 1px solid #f0f4f8;' : ''; ?>">
                        <div style="width: 32px; height: 32px; background: #dbeafe; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #3b82f6; font-size: 12px; flex-shrink: 0;">
                        <i class="fas fa-<?php echo $activity['action'] == 'Viewed' ? 'eye' : ($activity['action'] == 'Submitted' ? 'paper-plane' : 'check'); ?>"></i>
                    </div>
                        <div style="flex: 1; min-width: 0;">
                            <div style="font-size: 12px; font-weight: 600; color: #1f2937;">
                            <?php echo htmlspecialchars($activity['description']); ?>
                        </div>
                            <div style="font-size: 10px; color: #6b7280; margin-top: 2px;">
                                <?php echo userdate($activity['time'], '%d %b %y, %H:%M'); ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <?php else: ?>
        <div style="text-align: center; padding: 60px 40px; background: #f0f9ff; border-radius: 12px; border: 2px dashed #bfdbfe;">
            <i class="fas fa-exclamation-circle" style="font-size: 64px; color: #3b82f6; margin-bottom: 20px;"></i>
            <h3 style="margin: 0 0 10px 0; color: #3b82f6; font-size: 24px;">Profile Not Found</h3>
            <p style="color: #6b7280; margin: 0 0 20px 0;">Unable to load your profile information.</p>
            <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/parent/parent_dashboard.php" 
               style="display: inline-block; background: #3b82f6; color: white; padding: 12px 24px; border-radius: 8px; text-decoration: none; font-weight: 600;">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
        </div>
        <?php endif; ?>

    </div>
</div>

<style>
/* Hide Moodle footer - same as other parent pages */
#page-footer,
.site-footer,
footer,
.footer {
    display: none !important;
    visibility: hidden !important;
    height: 0 !important;
    margin: 0 !important;
    padding: 0 !important;
    overflow: hidden !important;
}
</style>

<?php
echo $OUTPUT->footer();
?>



