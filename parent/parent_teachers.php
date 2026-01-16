<?php
/**
 * Parent Dashboard - Teachers Directory
 * Modern, professional teacher contact directory
 */

// Suppress error display to prevent output before headers/session start
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once('../../../config.php');
require_login();

global $USER, $DB, $CFG, $OUTPUT, $PAGE, $SESSION;

require_once($CFG->dirroot . '/theme/remui_kids/lib/parent_access.php');
try {
    theme_remui_kids_require_parent(new moodle_url('/my/'));
} catch (Exception $e) {
    debugging('Error in parent access check: ' . $e->getMessage());
}

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/parent/parent_teachers.php');
$PAGE->set_title('Teachers Directory - Parent Dashboard');
$PAGE->set_pagelayout('base');

$userid = $USER->id;

// Include child session manager for persistent selection
require_once(__DIR__ . '/../lib/child_session.php');
$selected_child = get_selected_child();

require_once(__DIR__ . '/../lib/get_parent_children.php');
$children = get_parent_children($userid);
$child_lookup = [];
if (!empty($children) && is_array($children)) {
    foreach ($children as $child) {
        $child_lookup[$child['id']] = $child['name'];
    }
}

// Get teachers for children's courses with enhanced information
$teachers = [];
$total_teachers = 0;
$teachers_with_phone = 0;
$courses_count = 0;
$target_children = [];
if ($selected_child && $selected_child !== 'all' && $selected_child != 0) {
    $target_children = [$selected_child];
} elseif (!empty($children) && is_array($children)) {
    $target_children = array_column($children, 'id');
}

if (!empty($target_children)) {
    list($insql, $params) = $DB->get_in_or_equal($target_children, SQL_PARAMS_NAMED);
    
    // Enhanced query to get more teacher information (excluding admins and company managers - only pure teachers)
    // First, get all admin user IDs to exclude
    $exclude_user_ids = $DB->get_fieldset_sql(
        "SELECT DISTINCT ra.userid
         FROM {role_assignments} ra
         JOIN {role} r ON r.id = ra.roleid
         WHERE r.shortname IN ('manager', 'administrator', 'companymanager', 'companydepartmentmanager')"
    );
    
    // Also check for site admins in config
    $site_admins = explode(',', $CFG->siteadmins ?? '');
    $exclude_user_ids = array_merge($exclude_user_ids, array_filter(array_map('trim', $site_admins)));
    $exclude_user_ids = array_unique(array_filter($exclude_user_ids));
    
    // Build exclusion SQL - MUST use NOT IN to exclude these users
    $exclude_sql = '';
    if (!empty($exclude_user_ids)) {
        list($exclude_in_sql, $exclude_params) = $DB->get_in_or_equal($exclude_user_ids, SQL_PARAMS_NAMED, 'exclude');
        $exclude_sql = "AND u.id NOT $exclude_in_sql";
        $params = array_merge($params, $exclude_params);
    }
    
    $sql = "SELECT DISTINCT u.id AS teacherid,
                   u.firstname,
                   u.lastname,
                   u.email,
                   u.phone1,
                   u.phone2,
                   u.department,
                   u.institution,
                   u.description,
                   u.picture,
                   u.imagealt,
                   c.id AS courseid,
                   c.fullname AS coursename
            FROM {user} u
            JOIN {role_assignments} ra ON ra.userid = u.id
            JOIN {context} ctx ON ctx.id = ra.contextid
            JOIN {role} r ON r.id = ra.roleid
       LEFT JOIN {course} c ON c.id = ctx.instanceid
            WHERE r.shortname IN ('editingteacher', 'teacher')
              AND ctx.contextlevel = :ctxcourse
              AND u.deleted = 0
              $exclude_sql
              AND c.id IN (
                  SELECT DISTINCT c2.id
                    FROM {course} c2
                    JOIN {enrol} e ON e.courseid = c2.id
                    JOIN {user_enrolments} ue ON ue.enrolid = e.id
                   WHERE ue.userid $insql
              )
        ORDER BY u.firstname, u.lastname, c.fullname";

    $params['ctxcourse'] = CONTEXT_COURSE;

    try {
        $recordset = $DB->get_recordset_sql($sql, $params);
        $teacherrecords = [];

        // Avatar colors - All light blue shades for consistency
        $avatar_colors = ['#3b82f6', '#60a5fa', '#2563eb', '#1d4ed8', '#93c5fd', '#7dd3fc', '#0ea5e9', '#0284c7'];

        foreach ($recordset as $row) {
            $id = (int)$row->teacherid;

            if (!isset($teacherrecords[$id])) {
                $teacherrecords[$id] = (object) [
                    'id' => $id,
                    'firstname' => $row->firstname,
                    'lastname' => $row->lastname,
                    'email' => $row->email,
                    'phone1' => $row->phone1,
                    'phone2' => $row->phone2,
                    'department' => $row->department,
                    'institution' => $row->institution,
                    'description' => $row->description,
                    'picture' => $row->picture,
                    'imagealt' => $row->imagealt,
                    'courses' => [],
                    'courseids' => [],
                ];
            }

            if (!empty($row->coursename) && !in_array($row->coursename, $teacherrecords[$id]->courses, true)) {
                $teacherrecords[$id]->courses[] = $row->coursename;
            }

            if (!empty($row->courseid) && !in_array((int)$row->courseid, $teacherrecords[$id]->courseids, true)) {
                $teacherrecords[$id]->courseids[] = (int)$row->courseid;
            }
        }

        $recordset->close();

        // Double-check: Filter out any admins or company managers that might have slipped through
        // Check at ANY context level (system, course, category, etc.)
        $filtered_teachers = [];
        $system_context = context_system::instance();
        
        foreach ($teacherrecords as $teacher_id => $teacher) {
            // Check if user has admin or company manager role assignments at ANY context level
            $has_excluded_role = $DB->record_exists_sql(
                "SELECT 1 
                 FROM {role_assignments} ra
                 JOIN {role} r ON r.id = ra.roleid
                 WHERE ra.userid = ? 
                   AND r.shortname IN ('manager', 'administrator', 'companymanager', 'companydepartmentmanager')
                   AND r.shortname IS NOT NULL",
                [$teacher->id]
            );
            
            // Also check if user is site administrator using Moodle's function
            $is_site_admin = false;
            try {
                $teacher_user = $DB->get_record('user', ['id' => $teacher->id], 'id, username');
                if ($teacher_user) {
                    $is_site_admin = is_siteadmin($teacher->id);
                }
            } catch (Exception $e) {
                // If check fails, assume not admin
            }
            
            // Only add if NOT an admin/company manager (neither role-based nor site admin)
            if (!$has_excluded_role && !$is_site_admin) {
                $filtered_teachers[$teacher_id] = $teacher;
            }
        }
        
        // Replace original array with filtered one
        $teacherrecords = $filtered_teachers;

        foreach ($teacherrecords as $teacher) {
            // Get profile picture URL from Moodle
            $profile_picture_url = '';
            $has_profile_picture = false;
            if (isset($teacher->picture) && $teacher->picture > 0) {
                try {
                    // Get full user record with all required fields for user_picture
                    $teacher_user = $DB->get_record('user', ['id' => $teacher->id], '*', MUST_EXIST);
                    if ($teacher_user && $teacher_user->picture > 0) {
                        $user_context = context_user::instance($teacher->id);
                        $fs = get_file_storage();
                        $files = $fs->get_area_files($user_context->id, 'user', 'icon', 0, 'itemid', false);
                        
                        if (!empty($files)) {
                            $user_picture = new user_picture($teacher_user);
                            $user_picture->size = 1; // Full size
                            $profile_picture_url = $user_picture->get_url($PAGE)->out(false);
                            if (!empty($profile_picture_url)) {
                                $has_profile_picture = true;
                            }
                        }
                    }
                } catch (Exception $e) {
                    // Profile picture not available - log error for debugging
                    debugging('Error getting teacher profile picture for user ' . $teacher->id . ': ' . $e->getMessage());
                }
            }
            
            // Get teacher's initials
            $initials = strtoupper(substr($teacher->firstname, 0, 1) . substr($teacher->lastname, 0, 1));
            
            // Assign color based on name hash
            $color_index = abs(crc32($teacher->firstname . $teacher->lastname)) % count($avatar_colors);
            $avatar_color = $avatar_colors[$color_index];
            
            $courses_array = $teacher->courses;
            $course_ids = $teacher->courseids;
            
            // Count children taught
            $children_taught = 0;
            $child_names = [];
            foreach ($target_children as $child_id) {
                foreach ($course_ids as $course_id) {
                    $enrolled = $DB->record_exists_sql(
                        "SELECT 1 FROM {user_enrolments} ue
                         JOIN {enrol} e ON e.id = ue.enrolid
                         WHERE ue.userid = ? AND e.courseid = ?",
                        [$child_id, $course_id]
                    );
                    if ($enrolled) {
                        $children_taught++;
                        if (!empty($child_lookup[$child_id]) && !in_array($child_lookup[$child_id], $child_names, true)) {
                            $child_names[] = $child_lookup[$child_id];
                        }
                        break;
                    }
                }
            }
            
            $teachers[] = [
                'id' => $teacher->id,
                'firstname' => $teacher->firstname,
                'lastname' => $teacher->lastname,
                'fullname' => fullname($teacher),
                'email' => $teacher->email,
                'phone1' => $teacher->phone1 ?: '',
                'phone2' => $teacher->phone2 ?: '',
                'department' => $teacher->department ?: 'General',
                'institution' => $teacher->institution ?: '',
                'description' => strip_tags($teacher->description ?? ''),
                'courses' => $courses_array,
                'course_count' => count($course_ids),
                'children_taught' => $children_taught,
                'child_names' => $child_names,
                'initials' => $initials,
                'avatar_color' => $avatar_color,
                'profile_picture_url' => $profile_picture_url,
                'has_profile_picture' => $has_profile_picture
            ];
            
            $total_teachers++;
            if (!empty($teacher->phone1)) {
                $teachers_with_phone++;
            }
            $courses_count += count($course_ids);
        }
    } catch (Exception $e) {
        error_log('Error fetching teachers: ' . $e->getMessage());
    }
}

$course_filters = [];
$department_filters = [];
if (!empty($teachers)) {
    foreach ($teachers as $teacher) {
        foreach ($teacher['courses'] as $course_name) {
            if (!empty($course_name)) {
                $course_filters[$course_name] = true;
            }
        }
        if (!empty($teacher['department'])) {
            $department_filters[$teacher['department']] = true;
        }
    }
    ksort($course_filters, SORT_NATURAL | SORT_FLAG_CASE);
    ksort($department_filters, SORT_NATURAL | SORT_FLAG_CASE);
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

/* Enhanced Modern Teachers Directory Styling - Compact */
.parent-main-content {
    margin-left: 280px;
    padding: 24px 28px;
    min-height: 100vh;
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 50%, #e2e8f0 100%);
    width: calc(100% - 280px);
    max-width: 100%;
    box-sizing: border-box;
    position: relative;
}

.parent-main-content::before {
    content: '';
    position: fixed;
    top: 0;
    left: 280px;
    right: 0;
    bottom: 0;
    background: 
        radial-gradient(circle at 15% 25%, rgba(59, 130, 246, 0.04) 0%, transparent 50%),
        radial-gradient(circle at 85% 75%, rgba(139, 92, 246, 0.03) 0%, transparent 50%);
    pointer-events: none;
    z-index: 0;
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

/* Enhanced Header Section - Compact */
.teachers-header {
    background: linear-gradient(135deg, #3b82f6 0%, #8b5cf6 100%);
    padding: 24px 28px;
    border-radius: 16px;
    margin-bottom: 20px;
    box-shadow: 0 8px 32px rgba(59, 130, 246, 0.25), 0 4px 12px rgba(0, 0, 0, 0.1);
    position: relative;
    overflow: hidden;
    z-index: 1;
}

.teachers-header::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -50%;
    width: 500px;
    height: 500px;
    background: radial-gradient(circle, rgba(255, 255, 255, 0.2) 0%, transparent 70%);
    border-radius: 50%;
    filter: blur(60px);
}

.teachers-header::after {
    content: '';
    position: absolute;
    inset: -40% auto auto 55%;
    width: 420px;
    height: 420px;
    background: radial-gradient(circle, rgba(255, 255, 255, 0.15) 0%, transparent 70%);
    transform: rotate(18deg);
    filter: blur(40px);
}

.teachers-header h1 {
    color: white;
    font-size: 24px;
    font-weight: 800;
    margin: 0 0 8px 0;
    position: relative;
    z-index: 1;
    letter-spacing: -0.3px;
    display: flex;
    align-items: center;
    gap: 12px;
}

.teachers-header h1 i {
    font-size: 22px;
    background: rgba(255, 255, 255, 0.2);
    padding: 8px;
    border-radius: 10px;
    backdrop-filter: blur(10px);
}

.teachers-header p {
    color: rgba(255, 255, 255, 0.95);
    font-size: 13px;
    margin: 0;
    position: relative;
    z-index: 1;
    font-weight: 500;
    line-height: 1.5;
    max-width: 600px;
}

/* Enhanced Statistics Cards - Compact */
.stats-grid-teachers {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 14px;
    margin-bottom: 24px;
    position: relative;
    z-index: 1;
}

.stat-card-teacher {
    background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
    border-radius: 14px;
    padding: 16px 18px;
    text-align: center;
    box-shadow: 0 2px 12px rgba(0, 0, 0, 0.06), 0 1px 3px rgba(0, 0, 0, 0.08);
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    border: 1px solid rgba(226, 232, 240, 0.8);
    border-top: 3px solid;
    position: relative;
    overflow: hidden;
}

.stat-card-teacher::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(90deg, currentColor, transparent);
    opacity: 0;
    transition: opacity 0.3s ease;
}

.stat-card-teacher:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 28px rgba(59, 130, 246, 0.15), 0 2px 8px rgba(0, 0, 0, 0.08);
}

.stat-card-teacher:hover::before {
    opacity: 1;
}

.stat-icon-teacher {
    font-size: 24px;
    margin-bottom: 8px;
    display: inline-block;
    transition: all 0.3s ease;
}

.stat-card-teacher:hover .stat-icon-teacher {
    transform: scale(1.1);
}

.stat-value-teacher {
    font-size: 24px;
    font-weight: 800;
    margin: 6px 0;
    line-height: 1;
    color: #0f172a;
    letter-spacing: -0.5px;
}

.stat-label-teacher {
    font-size: 10px;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.6px;
    font-weight: 700;
    margin-top: 4px;
}

/* Enhanced Search Bar - Compact */
.search-bar-teacher {
    background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
    padding: 14px 18px;
    border-radius: 12px;
    margin-bottom: 20px;
    box-shadow: 0 2px 12px rgba(0, 0, 0, 0.06), 0 1px 3px rgba(0, 0, 0, 0.05);
    display: flex;
    gap: 12px;
    align-items: center;
    border: 1px solid rgba(226, 232, 240, 0.8);
    position: relative;
    z-index: 1;
    transition: all 0.3s ease;
}

.search-bar-teacher:focus-within {
    box-shadow: 0 4px 20px rgba(59, 130, 246, 0.12), 0 1px 4px rgba(0, 0, 0, 0.08);
    border-color: rgba(59, 130, 246, 0.3);
}

.search-icon {
    font-size: 16px;
    color: #3b82f6;
    transition: all 0.3s ease;
}

.search-bar-teacher:focus-within .search-icon {
    transform: scale(1.05);
    color: #2563eb;
}

.filters-toolbar {
    display: flex;
    gap: 16px;
    flex-wrap: wrap;
    margin-bottom: 24px;
    background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
    padding: 16px 18px;
    border-radius: 12px;
    box-shadow: 0 2px 12px rgba(0, 0, 0, 0.06), 0 1px 3px rgba(0, 0, 0, 0.05);
    border: 1px solid rgba(226, 232, 240, 0.8);
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
    min-width: 180px;
    flex: 1;
}

.filter-group label {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: #94a3b8;
    font-weight: 700;
}

.filter-select {
    padding: 10px 12px;
    border-radius: 10px;
    border: 2px solid rgba(226, 232, 240, 0.9);
    font-size: 13px;
    font-weight: 600;
    background: #fff;
    color: #0f172a;
    transition: all 0.2s ease;
}

.filter-select:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
}

.filter-actions {
    display: flex;
    align-items: flex-end;
    gap: 10px;
    flex-wrap: wrap;
}

.quick-filter-btn {
    padding: 10px 16px;
    border-radius: 999px;
    border: 2px solid #dbeafe;
    background: #eff6ff;
    color: #1d4ed8;
    font-size: 12px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    cursor: pointer;
    transition: all 0.2s ease;
}

.quick-filter-btn.active {
    background: #1d4ed8;
    color: white;
    border-color: #1d4ed8;
    box-shadow: 0 4px 12px rgba(29, 78, 216, 0.25);
}

.quick-filter-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.15);
}

.reset-filters-btn {
    padding: 10px 14px;
    border-radius: 10px;
    border: 2px solid #cbd5f5;
    background: #f8fafc;
    font-weight: 700;
    color: #475569;
    cursor: pointer;
    transition: all 0.2s ease;
}

.reset-filters-btn:hover {
    border-color: #3b82f6;
    color: #1d4ed8;
}

.search-input-teacher {
    flex: 1;
    padding: 10px 14px;
    border: 2px solid rgba(226, 232, 240, 0.8);
    border-radius: 10px;
    font-size: 14px;
    font-weight: 500;
    transition: all 0.3s ease;
    background: #ffffff;
    color: #0f172a;
}

.search-input-teacher:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    background: #ffffff;
}

/* Enhanced Teacher Cards - Compact */
.teachers-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 18px;
    position: relative;
    z-index: 1;
}

.teacher-card-modern {
    background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
    border-radius: 16px;
    padding: 0;
    box-shadow: 0 2px 12px rgba(0, 0, 0, 0.06), 0 1px 3px rgba(0, 0, 0, 0.08);
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    overflow: hidden;
    border: 1px solid rgba(226, 232, 240, 0.8);
    position: relative;
}

.teacher-card-modern::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: linear-gradient(90deg, #3b82f6, #8b5cf6);
    opacity: 0;
    transition: opacity 0.3s ease;
}

.teacher-card-modern:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 28px rgba(59, 130, 246, 0.15), 0 2px 8px rgba(0, 0, 0, 0.08);
    border-color: rgba(59, 130, 246, 0.3);
}

.teacher-card-modern:hover::before {
    opacity: 1;
}

.teacher-card-header {
    background: linear-gradient(135deg, #f8fafc, #f1f5f9);
    padding: 20px 18px;
    border-bottom: 2px solid rgba(226, 232, 240, 0.8);
    position: relative;
    overflow: hidden;
}

.teacher-card-header::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -50%;
    width: 150px;
    height: 150px;
    background: radial-gradient(circle, rgba(59, 130, 246, 0.05) 0%, transparent 70%);
    border-radius: 50%;
    filter: blur(20px);
}

.teacher-avatar-large {
    width: 64px;
    height: 64px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 22px;
    font-weight: 800;
    margin: 0 auto 12px;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.12), 0 2px 6px rgba(0, 0, 0, 0.08);
    border: 3px solid rgba(255, 255, 255, 0.3);
    transition: all 0.3s ease;
    position: relative;
    z-index: 1;
}

.teacher-card-modern:hover .teacher-avatar-large {
    transform: scale(1.05);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15), 0 2px 8px rgba(0, 0, 0, 0.1);
}

.teacher-name {
    font-size: 17px;
    font-weight: 800;
    color: #0f172a;
    margin: 0 0 6px 0;
    text-align: center;
    letter-spacing: -0.2px;
    position: relative;
    z-index: 1;
}

.teacher-department {
    font-size: 11px;
    color: #64748b;
    text-align: center;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.6px;
    position: relative;
    z-index: 1;
}

.teacher-card-body {
    padding: 18px;
}

.teacher-info-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 0;
    border-bottom: 1px solid rgba(226, 232, 240, 0.8);
    transition: all 0.2s ease;
}

.teacher-info-item:hover {
    padding-left: 3px;
}

.teacher-info-item:last-child {
    border-bottom: none;
}

.teacher-info-icon {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    flex-shrink: 0;
    color: #3b82f6;
    transition: all 0.3s ease;
    box-shadow: 0 1px 4px rgba(0, 0, 0, 0.06);
}

.teacher-info-item:hover .teacher-info-icon {
    transform: scale(1.05);
    box-shadow: 0 2px 8px rgba(59, 130, 246, 0.15);
}

.teacher-info-content {
    flex: 1;
    min-width: 0;
}

.teacher-info-label {
    font-size: 10px;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-weight: 700;
    margin-bottom: 4px;
}

.teacher-info-value {
    font-size: 13px;
    color: #0f172a;
    font-weight: 600;
    word-break: break-word;
    line-height: 1.4;
}

.teacher-info-value a {
    color: #3b82f6;
    text-decoration: none;
    transition: all 0.2s ease;
    font-weight: 700;
}

.teacher-info-value a:hover {
    color: #2563eb;
    text-decoration: underline;
    transform: translateX(2px);
}

.child-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    border-radius: 999px;
    background: #f0f9ff;
    color: #0369a1;
    font-size: 11px;
    font-weight: 700;
}

.courses-tag-container {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 8px;
}

/* Enhanced Course Tags - Compact */
.course-tag {
    background: linear-gradient(135deg, #eff6ff, #dbeafe);
    color: #1e40af;
    padding: 6px 12px;
    border-radius: 10px;
    font-size: 10px;
    font-weight: 700;
    border: 1px solid rgba(59, 130, 246, 0.3);
    transition: all 0.2s ease;
    box-shadow: 0 1px 4px rgba(0, 0, 0, 0.05);
}

.course-tag:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(59, 130, 246, 0.12);
    border-color: rgba(59, 130, 246, 0.5);
}

/* Enhanced Teacher Stats Row - Compact */
.teacher-stats-row {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 10px;
    margin-top: 18px;
    padding-top: 18px;
    border-top: 2px solid rgba(226, 232, 240, 0.8);
}

.teacher-stat-item {
    text-align: center;
    padding: 10px;
    border-radius: 10px;
    background: linear-gradient(135deg, #f8fafc, #f1f5f9);
    transition: all 0.3s ease;
}

.teacher-stat-item:hover {
    background: linear-gradient(135deg, #eff6ff, #dbeafe);
    transform: translateY(-2px);
}

.teacher-stat-value {
    font-size: 22px;
    font-weight: 800;
    color: #3b82f6;
    line-height: 1;
    margin-bottom: 6px;
    letter-spacing: -0.3px;
}

.teacher-stat-label {
    font-size: 9px;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-weight: 700;
}

/* Enhanced Empty State - Compact */
.empty-state-teachers {
    text-align: center;
    padding: 60px 40px;
    background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
    border-radius: 16px;
    border: 2px dashed rgba(148, 163, 184, 0.4);
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06), 0 1px 3px rgba(0, 0, 0, 0.05);
    position: relative;
    overflow: hidden;
    z-index: 1;
}

.empty-state-teachers::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -50%;
    width: 200px;
    height: 200px;
    background: radial-gradient(circle, rgba(59, 130, 246, 0.05) 0%, transparent 70%);
    border-radius: 50%;
    filter: blur(30px);
}

.empty-icon-teachers {
    font-size: 70px;
    color: #cbd5e1;
    margin-bottom: 24px;
    display: inline-block;
    animation: float 3s ease-in-out infinite;
    opacity: 0.7;
    position: relative;
    z-index: 1;
}

@keyframes float {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(-10px); }
}

.empty-title-teachers {
    font-size: 24px;
    font-weight: 800;
    color: #475569;
    margin: 0 0 12px 0;
    letter-spacing: -0.3px;
    position: relative;
    z-index: 1;
}

.empty-text-teachers {
    font-size: 14px;
    color: #64748b;
    margin: 0;
    line-height: 1.6;
    font-weight: 500;
    position: relative;
    z-index: 1;
}

/* Enhanced Child Banner - Compact */
.child-banner {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    background: linear-gradient(135deg, #ffffff, #f8fafc);
    padding: 10px 18px;
    border-radius: 12px;
    margin-bottom: 20px;
    border: 2px solid rgba(59, 130, 246, 0.3);
    box-shadow: 0 4px 16px rgba(59, 130, 246, 0.12), 0 1px 4px rgba(0, 0, 0, 0.06);
    transition: all 0.3s ease;
    position: relative;
    z-index: 1;
}

.child-banner:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(59, 130, 246, 0.18), 0 2px 8px rgba(0, 0, 0, 0.08);
    border-color: rgba(59, 130, 246, 0.5);
}

/* Animations */
@keyframes fadeIn {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes slideIn {
    from {
        opacity: 0;
        transform: translateX(-20px);
    }
    to {
        opacity: 1;
        transform: translateX(0);
    }
}

@keyframes scaleIn {
    from {
        opacity: 0;
        transform: scale(0.95);
    }
    to {
        opacity: 1;
        transform: scale(1);
    }
}

.teachers-header {
    animation: fadeIn 0.6s ease-out;
}

.stat-card-teacher {
    animation: scaleIn 0.5s ease-out;
}

.teacher-card-modern {
    animation: slideIn 0.4s ease-out;
}

.search-bar-teacher {
    animation: fadeIn 0.5s ease-out;
}

/* Responsive */
@media (max-width: 768px) {
    .parent-main-content {
        margin-left: 0;
        padding: 20px;
    }
    
    .filters-toolbar {
        flex-direction: column;
    }
    
    .teachers-grid {
        grid-template-columns: 1fr;
    }
    
    .stats-grid-teachers {
        grid-template-columns: repeat(2, 1fr);
    }
}
</style>

<div class="parent-main-content">
    <!-- Header -->
    <div class="teachers-header">
        <h1><i class="fas fa-chalkboard-teacher"></i> Teachers Directory</h1>
        <p>Connect with your child's educators and teaching staff</p>
    </div>

        <?php 
        // Show selected child banner
        if ($selected_child && $selected_child !== 'all' && $selected_child != 0):
            $selected_child_name = '';
            foreach ($children as $child) {
                if ($child['id'] == $selected_child) {
                    $selected_child_name = $child['name'];
                    break;
                }
            }
        ?>
    <div class="child-banner">
        <i class="fas fa-user-check" style="color: #3b82f6; font-size: 18px;"></i>
        <span style="font-size: 15px; font-weight: 700; color: #3b82f6;">Viewing: <?php echo htmlspecialchars($selected_child_name); ?></span>
            <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/parent/parent_dashboard.php" 
           style="color: #3b82f6; text-decoration: none; font-size: 14px; font-weight: 700; margin-left: 5px; transition: all 0.2s;"
           title="Change Child"
           onmouseover="this.style.transform='scale(1.2)'"
           onmouseout="this.style.transform='scale(1)'">
                <i class="fas fa-sync-alt"></i>
            </a>
        </div>
        <?php endif; ?>

        <?php if (!empty($children)): ?>

    <!-- Statistics -->
    <?php if ($total_teachers > 0): ?>
    <div class="stats-grid-teachers">
        <div class="stat-card-teacher" style="border-top-color: #3b82f6;">
            <div class="stat-icon-teacher" style="color: #3b82f6;"><i class="fas fa-chalkboard-teacher"></i></div>
            <div class="stat-value-teacher"><?php echo $total_teachers; ?></div>
            <div class="stat-label-teacher">Total Teachers</div>
        </div>
        <div class="stat-card-teacher" style="border-top-color: #60a5fa;">
            <div class="stat-icon-teacher" style="color: #60a5fa;"><i class="fas fa-book"></i></div>
            <div class="stat-value-teacher"><?php echo $courses_count; ?></div>
            <div class="stat-label-teacher">Courses</div>
        </div>
        <div class="stat-card-teacher" style="border-top-color: #2563eb;">
            <div class="stat-icon-teacher" style="color: #2563eb;"><i class="fas fa-phone"></i></div>
            <div class="stat-value-teacher"><?php echo $teachers_with_phone; ?></div>
            <div class="stat-label-teacher">With Phone</div>
        </div>
        <div class="stat-card-teacher" style="border-top-color: #1d4ed8;">
            <div class="stat-icon-teacher" style="color: #1d4ed8;"><i class="fas fa-envelope"></i></div>
            <div class="stat-value-teacher"><?php echo $total_teachers; ?></div>
            <div class="stat-label-teacher">Email Available</div>
        </div>
    </div>

    <!-- Search Bar -->
    <div class="search-bar-teacher">
        <i class="fas fa-search search-icon"></i>
        <input type="text" 
               id="teacherSearch" 
               class="search-input-teacher" 
               placeholder="Search teachers by name, course, department, or child..." 
               oninput="applyTeacherFilters()">
    </div>
    <?php endif; ?>

    <?php if (!empty($teachers)): ?>
    <div class="filters-toolbar">
        <div class="filter-group">
            <label for="courseFilter">Course Focus</label>
            <select id="courseFilter" class="filter-select" onchange="applyTeacherFilters()">
                <option value="all">All Courses</option>
                <?php foreach ($course_filters as $coursename => $unused): ?>
                <option value="<?php echo htmlspecialchars(strtolower($coursename)); ?>"><?php echo htmlspecialchars($coursename); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label for="departmentFilter">Department</label>
            <select id="departmentFilter" class="filter-select" onchange="applyTeacherFilters()">
                <option value="all">All Departments</option>
                <?php foreach ($department_filters as $deptname => $unused): ?>
                <option value="<?php echo htmlspecialchars(strtolower($deptname)); ?>"><?php echo htmlspecialchars($deptname); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label for="contactFilter">Contact Options</label>
            <select id="contactFilter" class="filter-select" onchange="applyTeacherFilters()">
                <option value="all">Phone & Email</option>
                <option value="phone">Has Direct Phone</option>
                <option value="email">Email Only</option>
            </select>
        </div>
        <div class="filter-group">
            <label for="teacherSort">Sort By</label>
            <select id="teacherSort" class="filter-select" onchange="sortTeachers(this.value)">
                <option value="name_asc">Name (A → Z)</option>
                <option value="name_desc">Name (Z → A)</option>
                <option value="courses_desc">Most Courses</option>
                <option value="children_desc">Teaches Most of Your Children</option>
            </select>
        </div>
        <div class="filter-actions">
            <button type="button" class="quick-filter-btn active" id="quickFilterAll" onclick="setTeacherQuickFilter('all')">
                All Teachers
            </button>
            <button type="button" class="quick-filter-btn" id="quickFilterChildren" onclick="setTeacherQuickFilter('children')">
                Teaches Your Children
            </button>
            <button type="button" class="quick-filter-btn" id="quickFilterMulti" onclick="setTeacherQuickFilter('multi')">
                Multi-course Experts
            </button>
            <button type="button" class="reset-filters-btn" onclick="resetTeacherFilters()">
                Reset Filters
            </button>
        </div>
    </div>
    <?php endif; ?>

    <!-- Teachers Grid -->
    <?php if (!empty($teachers)): ?>
    <div class="teachers-grid" id="teachersContainer">
        <?php foreach ($teachers as $teacher): ?>
        <div class="teacher-card-modern teacher-item" 
             data-name="<?php echo htmlspecialchars(strtolower($teacher['fullname'])); ?>"
             data-courses="<?php echo htmlspecialchars(strtolower(implode(' ', $teacher['courses']))); ?>"
             data-department="<?php echo htmlspecialchars(strtolower($teacher['department'])); ?>"
             data-hasphone="<?php echo !empty($teacher['phone1']) ? 'yes' : 'no'; ?>"
             data-childcount="<?php echo (int)$teacher['children_taught']; ?>"
             data-coursecount="<?php echo (int)$teacher['course_count']; ?>"
             data-childnames="<?php echo htmlspecialchars(strtolower(implode(' ', $teacher['child_names']))); ?>">
            
            <!-- Card Header -->
            <div class="teacher-card-header">
                <div class="teacher-avatar-large" style="background: <?php echo $teacher['avatar_color']; ?>; <?php echo $teacher['has_profile_picture'] ? 'background-image: url(' . htmlspecialchars($teacher['profile_picture_url']) . '); background-size: cover; background-position: center;' : ''; ?>">
                    <?php if ($teacher['has_profile_picture']): ?>
                        <img src="<?php echo htmlspecialchars($teacher['profile_picture_url']); ?>" alt="<?php echo htmlspecialchars($teacher['fullname']); ?>" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
                    <?php else: ?>
                        <?php echo $teacher['initials']; ?>
                    <?php endif; ?>
                </div>
                <h3 class="teacher-name"><?php echo htmlspecialchars($teacher['fullname']); ?></h3>
                <p class="teacher-department"><?php echo htmlspecialchars($teacher['department']); ?></p>
            </div>
            
            <!-- Card Body -->
            <div class="teacher-card-body">
                <!-- Courses -->
                <div class="teacher-info-item">
                    <div class="teacher-info-icon" style="background: #f0f9ff;">
                        <i class="fas fa-book"></i>
                    </div>
                    <div class="teacher-info-content">
                        <div class="teacher-info-label">Teaching Courses</div>
                        <div class="courses-tag-container">
                            <?php foreach (array_slice($teacher['courses'], 0, 3) as $course): ?>
                            <span class="course-tag" title="<?php echo htmlspecialchars($course); ?>">
                                <?php echo htmlspecialchars(strlen($course) > 20 ? substr($course, 0, 20) . '...' : $course); ?>
                            </span>
                            <?php endforeach; ?>
                            <?php if (count($teacher['courses']) > 3): ?>
                            <span class="course-tag" style="background: #f3f4f6; color: #6b7280; border-color: #e5e7eb;">
                                +<?php echo count($teacher['courses']) - 3; ?> more
                            </span>
                        <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Children Taught -->
                <div class="teacher-info-item">
                    <div class="teacher-info-icon" style="background: #ecfeff;">
                        <i class="fas fa-children"></i>
                    </div>
                    <div class="teacher-info-content">
                        <div class="teacher-info-label">Your Children</div>
                        <div class="teacher-info-value">
                            <?php if (!empty($teacher['child_names'])): ?>
                                <?php foreach ($teacher['child_names'] as $child_name): ?>
                                    <span class="child-chip">
                                        <i class="fas fa-user"></i> <?php echo htmlspecialchars($child_name); ?>
                                    </span>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span style="color: #94a3b8; font-weight: 600;">Not teaching selected child yet</span>
                        <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Statistics Row -->
                <div class="teacher-stats-row">
                    <div class="teacher-stat-item">
                        <div class="teacher-stat-value"><?php echo $teacher['course_count']; ?></div>
                        <div class="teacher-stat-label">Courses</div>
                    </div>
                    <div class="teacher-stat-item">
                        <div class="teacher-stat-value"><?php echo $teacher['children_taught']; ?></div>
                        <div class="teacher-stat-label">Your Children</div>
                    </div>
                    <div class="teacher-stat-item">
                        <div class="teacher-stat-value">
                            <i class="fas fa-star" style="color: #f59e0b;"></i>
                        </div>
                        <div class="teacher-stat-label">Active</div>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    
    <!-- No Results Message -->
    <div id="noTeachersResults" class="empty-state-teachers" style="display: none;">
        <div class="empty-icon-teachers"><i class="fas fa-search"></i></div>
        <h3 class="empty-title-teachers">No Teachers Found</h3>
        <p class="empty-text-teachers">Try adjusting your search criteria</p>
        </div>
        <?php else: ?>
    <div class="empty-state-teachers">
        <div class="empty-icon-teachers"><i class="fas fa-chalkboard-teacher"></i></div>
        <h3 class="empty-title-teachers">No Teachers Found</h3>
        <p class="empty-text-teachers">
            <?php echo ($selected_child && $selected_child !== 'all' && $selected_child != 0) 
                ? 'The selected child does not have any teachers assigned yet.' 
                : 'No teachers found for your children\'s courses.'; ?>
        </p>
        </div>
        <?php endif; ?>
    <?php else: ?>
    <div class="empty-state-teachers">
        <div class="empty-icon-teachers"><i class="fas fa-users"></i></div>
        <h3 class="empty-title-teachers">No Children Found</h3>
        <p class="empty-text-teachers">You don't have any children linked to your parent account yet.</p>
        <a href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/quick_setup_parent.php" 
           style="display: inline-block; margin-top: 24px; padding: 14px 32px; background: linear-gradient(135deg, #3b82f6, #1d4ed8); color: white; text-decoration: none; border-radius: 12px; font-weight: 700; font-size: 16px; box-shadow: 0 4px 16px rgba(59, 130, 246, 0.3);">
            <i class="fas fa-plus-circle"></i> Setup Now
        </a>
    </div>
    <?php endif; ?>
</div>

<script>
let activeTeacherQuickFilter = 'all';

function applyTeacherFilters() {
    const searchValue = (document.getElementById('teacherSearch')?.value || '').toLowerCase();
    const courseValue = document.getElementById('courseFilter')?.value || 'all';
    const deptValue = document.getElementById('departmentFilter')?.value || 'all';
    const contactValue = document.getElementById('contactFilter')?.value || 'all';
    const teachers = document.querySelectorAll('.teacher-item');
    const noResults = document.getElementById('noTeachersResults');
    const container = document.getElementById('teachersContainer');
    let visibleCount = 0;
    
    teachers.forEach(teacher => {
        const name = teacher.dataset.name || '';
        const courses = teacher.dataset.courses || '';
        const department = teacher.dataset.department || '';
        const childNames = teacher.dataset.childnames || '';
        const hasPhone = teacher.dataset.hasphone || 'no';
        const childCount = Number(teacher.dataset.childcount || 0);
        const courseCount = Number(teacher.dataset.coursecount || 0);

        let matches = true;

        if (searchValue) {
            const matchesSearch = name.includes(searchValue) || courses.includes(searchValue) || department.includes(searchValue) || childNames.includes(searchValue);
            if (!matchesSearch) {
                matches = false;
            }
        }

        if (matches && courseValue !== 'all' && !courses.includes(courseValue)) {
            matches = false;
        }

        if (matches && deptValue !== 'all' && department !== deptValue) {
            matches = false;
        }

        if (matches && contactValue === 'phone' && hasPhone !== 'yes') {
            matches = false;
        }

        if (matches && contactValue === 'email' && hasPhone === 'yes') {
            matches = false;
        }

        if (matches && activeTeacherQuickFilter === 'children' && childCount === 0) {
            matches = false;
        }

        if (matches && activeTeacherQuickFilter === 'multi' && courseCount < 2) {
            matches = false;
        }

        teacher.style.display = matches ? 'block' : 'none';
        if (matches) {
            visibleCount++;
        }
    });
    
    if (container && noResults) {
    if (visibleCount === 0) {
        container.style.display = 'none';
        noResults.style.display = 'block';
    } else {
        container.style.display = 'grid';
        noResults.style.display = 'none';
    }
    }
}

function sortTeachers(order) {
    const container = document.getElementById('teachersContainer');
    if (!container) return;
    const cards = Array.from(container.querySelectorAll('.teacher-item'));

    cards.sort((a, b) => {
        const nameA = a.dataset.name || '';
        const nameB = b.dataset.name || '';
        const courseA = Number(a.dataset.coursecount || 0);
        const courseB = Number(b.dataset.coursecount || 0);
        const childA = Number(a.dataset.childcount || 0);
        const childB = Number(b.dataset.childcount || 0);

        switch (order) {
            case 'name_desc':
                return nameB.localeCompare(nameA);
            case 'courses_desc':
                return courseB - courseA;
            case 'children_desc':
                return childB - childA;
            case 'name_asc':
            default:
                return nameA.localeCompare(nameB);
        }
    });

    cards.forEach(card => container.appendChild(card));
}

function setTeacherQuickFilter(filter) {
    activeTeacherQuickFilter = filter;
    const buttons = document.querySelectorAll('.quick-filter-btn');
    buttons.forEach(btn => btn.classList.remove('active'));

    switch (filter) {
        case 'children':
            document.getElementById('quickFilterChildren')?.classList.add('active');
            break;
        case 'multi':
            document.getElementById('quickFilterMulti')?.classList.add('active');
            break;
        case 'all':
        default:
            activeTeacherQuickFilter = 'all';
            document.getElementById('quickFilterAll')?.classList.add('active');
            break;
    }

    applyTeacherFilters();
}

function resetTeacherFilters() {
    const searchInput = document.getElementById('teacherSearch');
    const courseFilter = document.getElementById('courseFilter');
    const departmentFilter = document.getElementById('departmentFilter');
    const contactFilter = document.getElementById('contactFilter');
    const sortSelect = document.getElementById('teacherSort');

    if (searchInput) searchInput.value = '';
    if (courseFilter) courseFilter.value = 'all';
    if (departmentFilter) departmentFilter.value = 'all';
    if (contactFilter) contactFilter.value = 'all';
    if (sortSelect) {
        sortSelect.value = 'name_asc';
        sortTeachers('name_asc');
    }

    setTeacherQuickFilter('all');
    applyTeacherFilters();
}

// Add entrance animations on page load
document.addEventListener('DOMContentLoaded', function() {
    const cards = document.querySelectorAll('.teacher-card-modern');
    cards.forEach((card, index) => {
        setTimeout(() => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(20px)';
            card.style.transition = 'all 0.5s ease';
            
            setTimeout(() => {
                card.style.opacity = '1';
                card.style.transform = 'translateY(0)';
            }, 50);
        }, index * 80);
    });

    sortTeachers(document.getElementById('teacherSort')?.value || 'name_asc');
    applyTeacherFilters();
});
</script>

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

<?php echo $OUTPUT->footer(); ?>





