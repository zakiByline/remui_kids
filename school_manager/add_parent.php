<?php
/**
 * Add Parent Page
 * Allows school managers to create a parent account and immediately link it to a student.
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/lib/filelib.php');

require_login();

global $DB, $CFG, $USER, $OUTPUT, $PAGE;

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/theme/remui_kids/school_manager/add_parent.php'));
$PAGE->set_title('Add Parent');
$PAGE->set_heading('Add Parent');
$PAGE->set_pagelayout('standard');

// Ensure current user is a company manager.
$companymanagerrole = $DB->get_record('role', ['shortname' => 'companymanager']);
$is_company_manager = false;

if ($companymanagerrole) {
    $is_company_manager = user_has_role_assignment($USER->id, $companymanagerrole->id, $context->id);
}

if (!$is_company_manager) {
    redirect($CFG->wwwroot . '/my/', get_string('nopermissions', 'error', 'access this page'), null, \core\output\notification::NOTIFY_ERROR);
}

// Fetch company for current manager.
$company_info = $DB->get_record_sql(
    "SELECT c.*
       FROM {company} c
       JOIN {company_users} cu ON c.id = cu.companyid
      WHERE cu.userid = ? AND cu.managertype = 1",
    [$USER->id]
);

if (!$company_info) {
    redirect(new moodle_url('/theme/remui_kids/parent/parent_dashboard.php'), 'Unable to determine your school context.', null, \core\output\notification::NOTIFY_ERROR);
}

$companyid = $company_info->id;

// Fetch students for dropdown.
$students = [];
$studentoptions = [];
$cohortcounts = [];
try {
    $students = $DB->get_records_sql(
        "SELECT DISTINCT u.id,
                u.firstname,
                u.lastname,
                u.username,
                COALESCE(uifd.data, '') AS grade_level
           FROM {user} u
           JOIN {company_users} cu ON cu.userid = u.id AND cu.companyid = :companyid
           JOIN {role_assignments} ra ON ra.userid = u.id
           JOIN {role} r ON r.id = ra.roleid AND r.shortname = 'student'
           LEFT JOIN {user_info_data} uifd ON uifd.userid = u.id
           LEFT JOIN {user_info_field} uiff ON uiff.id = uifd.fieldid AND uiff.shortname = 'gradelevel'
          WHERE u.deleted = 0
       ORDER BY u.firstname, u.lastname",
        ['companyid' => $companyid]
    );
    foreach ($students as $student) {
        $profileimageurl = '';
        try {
            $usercontext = context_user::instance($student->id);
            $fs = get_file_storage();
            $files = $fs->get_area_files($usercontext->id, 'user', 'icon', 0, 'sortorder', false);
            if ($files) {
                foreach ($files as $file) {
                    if ($file->is_valid_image()) {
                        $profileimageurl = moodle_url::make_pluginfile_url(
                            $usercontext->id,
                            'user',
                            'icon',
                            0,
                            '/',
                            $file->get_filename()
                        )->out(false);
                        break;
                    }
                }
            }
        } catch (Exception $e) {
            $profileimageurl = '';
        }
        
        $studentoptions[] = [
            'id' => $student->id,
            'name' => fullname($student),
            'firstname' => $student->firstname,
            'lastname' => $student->lastname,
            'username' => $student->username,
            'cohort' => $student->grade_level ?: 'Not assigned',
            'profileimage' => $profileimageurl,
        ];
        $cohortlabel = $student->grade_level ?: 'Not assigned';
        if (!isset($cohortcounts[$cohortlabel])) {
            $cohortcounts[$cohortlabel] = 0;
        }
        $cohortcounts[$cohortlabel]++;
    }
} catch (Exception $e) {
    debugging('Error fetching students for parent assignment: ' . $e->getMessage(), DEBUG_DEVELOPER);
}

$errors = [];
$formdata = [
    'firstname' => optional_param('firstname', '', PARAM_NOTAGS),
    'lastname' => optional_param('lastname', '', PARAM_NOTAGS),
    'email' => optional_param('email', '', PARAM_RAW_TRIMMED),
    'username' => optional_param('username', '', PARAM_RAW_TRIMMED),
    'password' => '',
    'phone' => optional_param('phone', '', PARAM_TEXT),
    'note' => optional_param('note', '', PARAM_TEXT),
    'student_ids' => optional_param_array('student_ids', [], PARAM_INT),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!confirm_sesskey()) {
        $errors[] = 'Session expired. Please try again.';
    }

    $formdata['password'] = required_param('password', PARAM_RAW);

    if (empty($formdata['firstname'])) {
        $errors[] = 'First name is required.';
    }
    if (empty($formdata['lastname'])) {
        $errors[] = 'Last name is required.';
    }
    if (empty($formdata['email']) || !validate_email($formdata['email'])) {
        $errors[] = 'Please provide a valid email address.';
    }
    if (empty($formdata['username'])) {
        $errors[] = 'Username is required.';
    }
    if (strlen($formdata['password']) < 6) {
        $errors[] = 'Password must contain at least 6 characters.';
    }
    if (empty($formdata['student_ids']) || !is_array($formdata['student_ids'])) {
        $errors[] = 'Please select at least one student to link.';
    } else {
        // Validate all selected students exist and belong to company
        $formdata['student_ids'] = array_filter(array_map('intval', $formdata['student_ids']));
        if (empty($formdata['student_ids'])) {
            $errors[] = 'Please select at least one student to link.';
        } else {
            foreach ($formdata['student_ids'] as $student_id) {
                if (!$DB->record_exists('user', ['id' => $student_id, 'deleted' => 0])) {
                    $errors[] = 'One or more selected students are invalid.';
                    break;
                }
            }
        }
    }
    if ($DB->record_exists('user', ['username' => $formdata['username'], 'mnethostid' => $CFG->mnet_localhost_id, 'deleted' => 0])) {
        $errors[] = 'Username already exists.';
    }
    if ($DB->record_exists('user', ['email' => $formdata['email'], 'mnethostid' => $CFG->mnet_localhost_id, 'deleted' => 0])) {
        $errors[] = 'Email already exists.';
    }

    if (empty($errors)) {
        try {
            $transaction = $DB->start_delegated_transaction();

            $newparent = new stdClass();
            $newparent->username = $formdata['username'];
            $newparent->password = hash_internal_user_password($formdata['password']);
            $newparent->firstname = $formdata['firstname'];
            $newparent->lastname = $formdata['lastname'];
            $newparent->email = $formdata['email'];
            $newparent->phone1 = $formdata['phone'];
            $newparent->city = '';
            $newparent->country = '';
            $newparent->description = $formdata['note'];
            $newparent->auth = 'manual';
            $newparent->confirmed = 1;
            $newparent->mnethostid = $CFG->mnet_localhost_id;
            $newparent->timecreated = time();
            $newparent->timemodified = time();
            $newparent->forcepasswordchange = 0;

            $newparentid = user_create_user($newparent, false, false);

            // Assign parent role to all selected student contexts.
            $parentrole = $DB->get_record('role', ['shortname' => 'parent'], '*', MUST_EXIST);
            foreach ($formdata['student_ids'] as $student_id) {
                // Verify student belongs to company
                $student_in_company = $DB->record_exists('company_users', [
                    'userid' => $student_id,
                    'companyid' => $companyid
                ]);
                
                if ($student_in_company) {
                    $studentcontext = context_user::instance($student_id);
                    role_assign($parentrole->id, $newparentid, $studentcontext->id);
                }
            }

            $transaction->allow_commit();

            redirect(
                new moodle_url('/theme/remui_kids/school_manager/parent_management.php'),
                'Parent account created and linked successfully.',
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
        } catch (Exception $e) {
            if (!empty($transaction)) {
                $transaction->rollback($e);
            }
            $errors[] = $e->getMessage();
        }
    }
}

// Sidebar context reused.
$sidebarcontext = [
    'config' => ['wwwroot' => $CFG->wwwroot],
    'company_name' => $company_info->name,
    'user_info' => ['fullname' => fullname($USER)],
    'parent_management_active' => true,
];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('theme_remui_kids/school_manager_sidebar', $sidebarcontext);
?>

<style>
.school-manager-main-content {
    position: fixed;
    top: 55px;
    left: 280px;
    right: 0;
    bottom: 0;
    background-color: #f8fafc;
    overflow-y: auto;
    padding: 45px 0 50px;
}

.main-content {
    max-width: 1400px;
    margin: 0 auto;
    padding: 0 40px 40px;
}

.page-header {
    background: linear-gradient(135deg, #dbeafe 0%, #e0e7ff 50%, #ede9fe 100%);
    padding: 25px 30px;
    border-radius: 18px;
    margin-bottom: 30px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    box-shadow: 0 10px 30px rgba(15, 23, 42, 0.08);
}

.page-header h1 {
    margin: 0;
    font-size: 1.8rem;
    color: #111827;
}

.parent-form-card {
    background: #ffffff;
    border-radius: 18px;
    padding: 30px;
    box-shadow: 0 15px 40px rgba(15, 23, 42, 0.08);
    border: 1px solid #e5e7eb;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
}

.form-group label {
    display: block;
    font-weight: 600;
    margin-bottom: 6px;
    color: #374151;
}

.required-star {
    color: #dc2626;
    margin-left: 4px;
    font-weight: 700;
}

.form-group input,
.form-group select,
.form-group textarea {
    width: 100%;
    padding: 12px;
    border: 1px solid #d1d5db;
    border-radius: 10px;
    font-size: 0.95rem;
    transition: border 0.2s ease, box-shadow 0.2s ease;
    background: #fff;
}

.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus {
    outline: none;
    border-color: #6366f1;
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.15);
}

.student-filter-row {
    display: flex;
    gap: 15px;
    align-items: flex-end;
}

.cohort-filter-control {
    flex: 2;
    position: relative;
    min-width: 0;
}

.student-select-control {
    flex: 3;
    min-width: 0;
}

.cohort-filter-control label,
.student-select-control label {
    font-size: 0.85rem;
    font-weight: 600;
    color: #4b5563;
    display: block;
    margin-bottom: 4px;
}

/* Custom Cohort Dropdown Styles - Matching course_management.php */
.custom-cohort-dropdown {
    position: relative;
    width: 100%;
}

.cohort-dropdown-trigger {
    width: 100%;
    padding: 10px 40px 10px 14px;
    border: 2px solid #e9ecef;
    border-radius: 8px;
    font-size: 0.9rem;
    color: #2c3e50;
    background: white;
    cursor: pointer;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
    position: relative;
}

.cohort-dropdown-trigger:hover {
    border-color: #007bff;
}

.cohort-dropdown-trigger.active {
    border-color: #007bff;
    box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1);
}

.cohort-dropdown-trigger .trigger-text {
    flex: 1;
    text-align: left;
    font-weight: 500;
    color: #2c3e50;
}

.cohort-dropdown-trigger .trigger-icon {
    position: absolute;
    right: 12px;
    top: 50%;
    transform: translateY(-50%);
    transition: transform 0.2s ease;
    display: flex;
    align-items: center;
    color: #6c757d;
}

.cohort-dropdown-trigger.active .trigger-icon {
    transform: translateY(-50%) rotate(180deg);
}

.cohort-dropdown-menu {
    position: absolute;
    top: calc(100% + 4px);
    left: 0;
    right: 0;
    background: #ffffff;
    border: 2px solid #e9ecef;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    max-height: 195px;
    overflow-y: auto;
    z-index: 1000;
    display: none;
}

.cohort-dropdown-menu.show {
    display: block;
}

/* Compact scrollbar for cohort dropdown */
.cohort-dropdown-menu::-webkit-scrollbar {
    width: 6px;
}

.cohort-dropdown-menu::-webkit-scrollbar-track {
    background: #f1f1f1;
}

.cohort-dropdown-menu::-webkit-scrollbar-thumb {
    background: #c1c1c1;
    border-radius: 3px;
}

/* Cohort item styling - matching course_management.php user-item */
.cohort-dropdown-item {
    display: flex;
    align-items: center;
    padding: 10px 12px;
    border-bottom: 1px solid #f1f3f5;
    cursor: pointer;
    transition: all 0.2s ease;
}

.cohort-dropdown-item:last-child {
    border-bottom: none;
}

.cohort-dropdown-item:hover {
    background: #f8f9fa;
}

.cohort-dropdown-item.selected {
    background: #e3f2fd;
    border-left: 3px solid #007bff;
}

/* Cohort icon - matching user-avatar from course_management.php */
.cohort-icon {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 10px;
    flex-shrink: 0;
}

.cohort-icon svg {
    width: 18px;
    height: 18px;
    fill: white;
}

/* Cohort info - matching user-info from course_management.php */
.cohort-info {
    flex: 1;
    min-width: 0;
}

.cohort-name {
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 2px;
    font-size: 0.85rem;
}

.cohort-members {
    font-size: 0.75rem;
    color: #6c757d;
}

/* Custom Student Dropdown Styles - Matching second image */
.custom-student-dropdown {
    position: relative;
    width: 100%;
}

.student-dropdown-trigger {
    width: 100%;
    padding: 10px 40px 10px 14px;
    border: 2px solid #e9ecef;
    border-radius: 8px;
    font-size: 0.9rem;
    color: #2c3e50;
    background: white;
    cursor: pointer;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
    position: relative;
    display: flex;
    align-items: center;
    min-height: 42px;
}

.student-dropdown-trigger:hover {
    border-color: #007bff;
}

.student-dropdown-trigger.active {
    border-color: #007bff;
    box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1);
}

.student-dropdown-trigger .trigger-text {
    flex: 1;
    text-align: left;
    font-weight: 500;
    color: #2c3e50;
    display: flex;
    align-items: center;
    gap: 10px;
    min-width: 0;
}

.student-dropdown-trigger .trigger-text .student-avatar {
    flex-shrink: 0;
}

.student-dropdown-trigger .trigger-text > span {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.student-trigger-input {
    position: absolute;
    top: 0;
    left: 50%;
    transform: translateX(-50%);
    width: 85%;
    max-width: 550px;
    height: 100%;
    border: 2px solid #007bff;
    border-radius: 8px;
    padding: 0 40px 0 40px;
    font-size: 0.95rem;
    color: #1f2937;
    background: #ffffff;
    display: none;
    box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1);
    z-index: 10;
    text-align: center;
}

.student-trigger-input::placeholder {
    text-align: center;
    color: #9ca3af;
}

.student-trigger-input:focus {
    outline: none;
    border-color: #007bff;
    box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.15);
    text-align: left;
}

.student-trigger-input:focus::placeholder {
    text-align: left;
}

.student-trigger-search-icon {
    position: absolute;
    left: calc(7.5% + 14px);
    top: 50%;
    transform: translateY(-50%);
    color: #6c757d;
    font-size: 0.9rem;
    pointer-events: none;
    z-index: 11;
    display: none;
}

@media (max-width: 768px) {
    .student-trigger-search-icon {
        left: 14px;
    }
    .student-trigger-input {
        width: calc(100% - 80px);
        max-width: none;
        left: 40px;
        transform: none;
        text-align: left;
    }
    .student-trigger-input::placeholder {
        text-align: left;
    }
}

.student-dropdown-trigger.search-mode .student-trigger-search-icon {
    display: block;
}

.student-dropdown-trigger.search-mode .student-trigger-input {
    display: block;
}

.student-dropdown-trigger.search-mode .trigger-text {
    visibility: hidden;
}

.student-dropdown-trigger.search-mode .trigger-icon {
    z-index: 11;
}

.student-dropdown-trigger .trigger-icon {
    position: absolute;
    right: 12px;
    top: 50%;
    transform: translateY(-50%);
    transition: transform 0.2s ease;
    display: flex;
    align-items: center;
    color: #6c757d;
}

.student-dropdown-trigger.active .trigger-icon {
    transform: translateY(-50%) rotate(180deg);
}

.student-dropdown-menu {
    position: absolute;
    top: calc(100% + 4px);
    left: 0;
    right: 0;
    background: #ffffff;
    border: 2px solid #e9ecef;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    max-height: 300px;
    overflow: hidden;
    z-index: 1000;
    display: none;
    flex-direction: column;
}

.student-dropdown-menu.show {
    display: flex;
}

.student-search-box {
    padding: 12px;
    border-bottom: 1px solid #e9ecef;
    background: #f8f9fa;
    position: relative;
    z-index: 10;
}

.student-search-input {
    width: 100%;
    padding: 10px 12px 10px 36px;
    border: 2px solid #e9ecef;
    border-radius: 6px;
    font-size: 0.9rem;
    color: #2c3e50;
    background: #ffffff;
    transition: all 0.3s ease;
    box-sizing: border-box;
}

.student-search-input:focus {
    outline: none;
    border-color: #007bff;
    box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1);
}

.student-search-box .search-icon {
    position: absolute;
    left: 24px;
    top: 50%;
    transform: translateY(-50%);
    color: #6c757d;
    font-size: 0.85rem;
    pointer-events: none;
}

.student-dropdown-list {
    overflow-y: auto;
    max-height: 240px;
    flex: 1;
}

/* Compact scrollbar for student dropdown */
.student-dropdown-list::-webkit-scrollbar {
    width: 6px;
}

.student-dropdown-list::-webkit-scrollbar-track {
    background: #f1f1f1;
}

.student-dropdown-list::-webkit-scrollbar-thumb {
    background: #c1c1c1;
    border-radius: 3px;
}

/* Student item styling - matching second image */
.student-dropdown-item {
    display: flex;
    align-items: center;
    padding: 12px 16px;
    border-bottom: 1px solid #f1f3f5;
    cursor: pointer;
    transition: all 0.2s ease;
}

.student-dropdown-item:last-child {
    border-bottom: none;
}

.student-dropdown-item:hover {
    background: #f8f9fa;
}

.student-dropdown-item.selected {
    background: #e3f2fd;
    border-left: 3px solid #007bff;
}

/* Student avatar - circular with initials or profile picture */
.student-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 12px;
    flex-shrink: 0;
    overflow: hidden;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    font-weight: 600;
    font-size: 0.9rem;
}

.student-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.student-avatar .initials {
    font-size: 0.9rem;
    font-weight: 600;
    color: white;
}

/* Student info - matching second image */
.student-info {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
}

.student-name {
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 2px;
    font-size: 0.95rem;
    line-height: 1.3;
}

.student-username {
    font-size: 0.85rem;
    color: #6c757d;
    line-height: 1.3;
}

.student-info-panel {
    background: #f8fafc;
    border: 2px dashed #c7d2fe;
    border-radius: 12px;
    padding: 18px 20px;
    margin-top: 10px;
    color: #374151;
    box-shadow: 0 2px 8px rgba(199, 210, 254, 0.1);
}

.student-info-panel strong {
    display: block;
    margin-bottom: 14px;
    font-size: 1rem;
    font-weight: 700;
    color: #1f2937;
    letter-spacing: 0.3px;
}

.student-info-content {
    display: flex;
    align-items: center;
    gap: 24px;
    flex-wrap: wrap;
}

.student-info-item {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 1rem;
    line-height: 1.5;
}

.student-info-item .label {
    font-weight: 700;
    color: #4c51bf;
    font-size: 1rem;
}

.student-info-item .value {
    color: #0f172a;
    font-weight: 700;
    font-size: 1rem;
}

.selected-students-list {
    margin-top: 15px;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.selected-student-chip {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 12px;
    background: #e3f2fd;
    border: 1px solid #007bff;
    border-radius: 8px;
}

.selected-student-chip-info {
    display: flex;
    align-items: center;
    gap: 10px;
    flex: 1;
    min-width: 0;
}

.selected-student-chip .student-avatar {
    width: 32px;
    height: 32px;
    margin-right: 0;
    font-size: 0.8rem;
}

.selected-student-chip .student-name {
    font-size: 0.9rem;
    margin: 0;
    font-weight: 600;
    color: #2c3e50;
}

.remove-student-btn {
    background: #dc2626;
    color: white;
    border: none;
    width: 24px;
    height: 24px;
    border-radius: 50%;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.75rem;
    transition: all 0.2s ease;
    flex-shrink: 0;
}

.remove-student-btn:hover {
    background: #b91c1c;
    transform: scale(1.1);
}

.no-children {
    padding: 15px;
    text-align: center;
    color: #6c757d;
    font-style: italic;
    background: #f8f9fa;
    border-radius: 8px;
    border: 1px dashed #dee2e6;
}

.submit-section {
    margin-top: 25px;
    display: flex;
    gap: 15px;
}

.submit-btn {
    background: linear-gradient(135deg, #4f46e5, #7c3aed);
    color: white;
    border: none;
    padding: 12px 28px;
    border-radius: 12px;
    font-size: 1rem;
    font-weight: 600;
    cursor: pointer;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.submit-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 15px 30px rgba(79, 70, 229, 0.35);
}

.cancel-btn {
    background: #e5e7eb;
    border: none;
    padding: 12px 20px;
    border-radius: 12px;
    font-weight: 600;
    cursor: pointer;
}

.error-alert {
    background: #fef2f2;
    border: 1px solid #fecaca;
    color: #b91c1c;
    padding: 12px 16px;
    border-radius: 10px;
    margin-bottom: 18px;
}

@media (max-width: 768px) {
    .school-manager-main-content {
        position: relative;
        top: 55px;
        left: 0;
        right: 0;
        bottom: auto;
        width: 100%;
        padding: 25px 0 30px;
    }
    .page-header {
        flex-direction: column;
        text-align: center;
        gap: 10px;
    }
    .student-filter-row {
        flex-direction: column;
        gap: 12px;
    }
    .cohort-filter-control {
        flex: 1;
        width: 100%;
    }
    .student-select-control {
        flex: 1;
        width: 100%;
    }
}
</style>

<div class="school-manager-main-content">
    <div class="main-content">
        <div class="page-header">
            <h1>Create Parent Account</h1>
            <a class="cancel-btn" href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/school_manager/parent_management.php">Back to Parent Management</a>
        </div>

        <div class="parent-form-card">
            <?php if (!empty($errors)): ?>
                <div class="error-alert">
                    <ul style="margin:0; padding-left:18px;">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo format_string($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if (empty($students)): ?>
                <p>No students found for your school. Please add students before creating parent accounts.</p>
            <?php else: ?>
                <form method="post">
                    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="firstname">First Name <span class="required-star">*</span></label>
                            <input type="text" id="firstname" name="firstname" value="<?php echo format_string($formdata['firstname']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="lastname">Last Name <span class="required-star">*</span></label>
                            <input type="text" id="lastname" name="lastname" value="<?php echo format_string($formdata['lastname']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="email">Email <span class="required-star">*</span></label>
                            <input type="email" id="email" name="email" value="<?php echo format_string($formdata['email']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="username">Username <span class="required-star">*</span></label>
                            <input type="text" id="username" name="username" value="<?php echo format_string($formdata['username']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="password">Password <span class="required-star">*</span></label>
                            <input type="password" id="password" name="password" placeholder="At least 6 characters" required>
                        </div>
                        <div class="form-group">
                            <label for="phone">Phone (optional)</label>
                            <input type="text" id="phone" name="phone" value="<?php echo format_string($formdata['phone']); ?>">
                        </div>
                    </div>

                    <div class="form-group" style="margin-top:20px;">
                        <label for="studentids">Select Students to Assign <span class="required-star">*</span></label>
                        <div class="student-filter-row">
                            <div class="cohort-filter-control">
                                <label for="cohortFilter">Filter by Cohort</label>
                                <div class="custom-cohort-dropdown">
                                    <div class="cohort-dropdown-trigger" id="cohortFilterTrigger">
                                        <span class="trigger-text">All Cohorts</span>
                                        <span class="trigger-icon">
                                            <i class="fa fa-chevron-down"></i>
                                        </span>
                                    </div>
                                    <div class="cohort-dropdown-menu" id="cohortFilterMenu">
                                        <!-- Options will be populated by JavaScript -->
                                    </div>
                                    <input type="hidden" id="cohortFilter" value="">
                                </div>
                            </div>
                            <div class="student-select-control">
                                <label for="studentFilterTrigger">Select students <span class="required-star">*</span></label>
                                <div class="custom-student-dropdown">
                                    <div class="student-dropdown-trigger" id="studentFilterTrigger">
                                        <i class="fa fa-search student-trigger-search-icon"></i>
                                        <input type="text" class="student-trigger-input" id="studentSearchInput" placeholder="Search students..." autocomplete="off">
                                        <span class="trigger-text">Select students</span>
                                        <span class="trigger-icon">
                                            <i class="fa fa-chevron-down"></i>
                                        </span>
                                    </div>
                                    <div class="student-dropdown-menu" id="studentFilterMenu">
                                        <div class="student-search-box">
                                            <i class="fa fa-search search-icon"></i>
                                            <input type="text" class="student-search-input" id="studentDropdownSearch" placeholder="Search students..." autocomplete="off">
                                        </div>
                                        <div class="student-dropdown-list" id="studentDropdownList">
                                            <!-- Options will be populated by JavaScript -->
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="selected-students-list" id="selectedStudentsList">
                            <!-- Selected students will appear here -->
                        </div>
                        <input type="hidden" name="student_ids[]" id="hiddenStudentIds" value="">
                    </div>

                    <div class="form-group" style="margin-top:20px;">
                        <label for="note">Notes (optional)</label>
                        <textarea id="note" name="note" rows="3" placeholder="Internal notes or special considerations"><?php echo format_string($formdata['note']); ?></textarea>
                    </div>

                    <div class="submit-section">
                        <button type="submit" class="submit-btn">Create Parent</button>
                        <a class="cancel-btn" href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/school_manager/parent_management.php">Cancel</a>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
const studentsData = <?php echo json_encode(array_values($studentoptions)); ?>;
const cohortCounts = <?php echo json_encode($cohortcounts); ?>;
let selectedStudentIds = [];

const studentSelectHidden = document.getElementById('hiddenStudentIds');
const studentFilterTrigger = document.getElementById('studentFilterTrigger');
const studentFilterMenu = document.getElementById('studentFilterMenu');
const studentSearchInput = document.getElementById('studentSearchInput');
const studentDropdownSearch = document.getElementById('studentDropdownSearch');
const cohortFilterHidden = document.getElementById('cohortFilter');
const cohortFilterTrigger = document.getElementById('cohortFilterTrigger');
const cohortFilterMenu = document.getElementById('cohortFilterMenu');
const selectedStudentsList = document.getElementById('selectedStudentsList');

// SVG icon for three human figures
const cohortIconSVG = `<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" fill="white">
    <!-- Left person -->
    <circle cx="6" cy="5.5" r="2.5"/>
    <path d="M6 9.5c-1.5 0-4 0.75-4 2.25v3h8v-3c0-1.5-2.5-2.25-4-2.25z"/>
    <!-- Center person -->
    <circle cx="12" cy="5.5" r="2.5"/>
    <path d="M12 9.5c-1.5 0-4 0.75-4 2.25v3h8v-3c0-1.5-2.5-2.25-4-2.25z"/>
    <!-- Right person -->
    <circle cx="18" cy="5.5" r="2.5"/>
    <path d="M18 9.5c-1.5 0-4 0.75-4 2.25v3h8v-3c0-1.5-2.5-2.25-4-2.25z"/>
</svg>`;

function buildCohortOptions() {
    if (!cohortFilterMenu) {
        return;
    }
    
    cohortFilterMenu.innerHTML = '';
    
    // Add "All Cohorts" option
    const totalStudents = studentsData.length;
    const allCount = totalStudents === 1 ? '1 member' : `${totalStudents} members`;
    const allItem = createCohortItem('', 'All Cohorts', totalStudents, true);
    cohortFilterMenu.appendChild(allItem);
    
    // Add individual cohort options
    const cohorts = Array.from(new Set(studentsData.map(student => student.cohort || 'Not assigned'))).sort();
    cohorts.forEach(cohort => {
        const count = cohortCounts[cohort] || 0;
        const item = createCohortItem(cohort, cohort, count, false);
        cohortFilterMenu.appendChild(item);
    });
}

function createCohortItem(value, name, count, isAll) {
    const item = document.createElement('div');
    item.className = 'cohort-dropdown-item';
    item.dataset.value = value;
    
    const membersText = count === 1 ? '1 member' : `${count} members`;
    
    item.innerHTML = `
        <div class="cohort-icon">${cohortIconSVG}</div>
        <div class="cohort-info">
            <div class="cohort-name">${name}</div>
            <div class="cohort-members">${membersText}</div>
        </div>
    `;
    
    item.addEventListener('click', () => {
        selectCohort(value, name, count, isAll);
    });
    
    return item;
}

function selectCohort(value, name, count, isAll) {
    // Update hidden input
    if (cohortFilterHidden) {
        cohortFilterHidden.value = value;
    }
    
    // Update trigger text
    if (cohortFilterTrigger) {
        const membersText = count === 1 ? '1 member' : `${count} members`;
        const triggerText = cohortFilterTrigger.querySelector('.trigger-text');
        if (triggerText) {
            triggerText.textContent = `${name} — ${membersText}`;
        }
    }
    
    // Update selected state
    const items = cohortFilterMenu.querySelectorAll('.cohort-dropdown-item');
    items.forEach(item => {
        item.classList.remove('selected');
        if (item.dataset.value === value) {
            item.classList.add('selected');
        }
    });
    
    // Close dropdown
    closeCohortDropdown();
    
    // Filter students
    renderStudentOptions();
}

function openCohortDropdown() {
    if (cohortFilterTrigger && cohortFilterMenu) {
        cohortFilterTrigger.classList.add('active');
        cohortFilterMenu.classList.add('show');
    }
}

function closeCohortDropdown() {
    if (cohortFilterTrigger && cohortFilterMenu) {
        cohortFilterTrigger.classList.remove('active');
        cohortFilterMenu.classList.remove('show');
    }
}

// Get initials from name
function getInitials(firstname, lastname) {
    const first = firstname ? firstname.charAt(0).toUpperCase() : '';
    const last = lastname ? lastname.charAt(0).toUpperCase() : '';
    return (first + last) || '?';
}

// Create student dropdown item
function createStudentItem(student) {
    const item = document.createElement('div');
    item.className = 'student-dropdown-item';
    item.dataset.studentId = student.id;
    item.dataset.cohort = student.cohort || 'Not assigned';
    item.dataset.username = student.username;
    
    const isSelected = selectedStudentIds.includes(Number(student.id));
    if (isSelected) {
        item.classList.add('selected');
    }
    
    const initials = getInitials(student.firstname || '', student.lastname || '');
    const hasProfileImage = student.profileimage && student.profileimage.trim() !== '';
    
    let avatarHtml = '';
    if (hasProfileImage) {
        avatarHtml = `<img src="${student.profileimage}" alt="${student.name}">`;
    } else {
        avatarHtml = `<span class="initials">${initials}</span>`;
    }
    
    item.innerHTML = `
        <div class="student-avatar">
            ${avatarHtml}
        </div>
        <div class="student-info">
            <div class="student-name">${student.name}</div>
            <div class="student-username">@${student.username}</div>
        </div>
    `;
    
    item.addEventListener('click', () => {
        toggleStudent(student.id, student.name, student.username, student.cohort, hasProfileImage ? student.profileimage : '', initials);
    });
    
    return item;
}

function renderStudentOptions() {
    const studentDropdownList = document.getElementById('studentDropdownList');
    if (!studentDropdownList) {
        return;
    }
    
    const cohortValue = cohortFilterHidden ? cohortFilterHidden.value : '';
    const searchQuery = studentDropdownSearch ? studentDropdownSearch.value.toLowerCase().trim() : '';

    studentDropdownList.innerHTML = '';

    const filteredStudents = studentsData.filter(student => {
        const matchesCohort = !cohortValue || student.cohort === cohortValue;
        const matchesSearch = !searchQuery || 
            student.name.toLowerCase().includes(searchQuery) ||
            student.username.toLowerCase().includes(searchQuery) ||
            (student.firstname && student.firstname.toLowerCase().includes(searchQuery)) ||
            (student.lastname && student.lastname.toLowerCase().includes(searchQuery));
        return matchesCohort && matchesSearch;
    });

    if (filteredStudents.length === 0) {
        studentDropdownList.innerHTML = '<div style="padding: 20px; text-align: center; color: #6c757d;">No students found</div>';
        return;
    }

    filteredStudents.forEach(student => {
        const item = createStudentItem(student);
        studentDropdownList.appendChild(item);
    });
}

function toggleStudent(studentId, studentName, username, cohort, profileImage, initials) {
    const studentIdNum = Number(studentId);
    const index = selectedStudentIds.indexOf(studentIdNum);
    
    if (index > -1) {
        // Remove if already selected
        selectedStudentIds.splice(index, 1);
    } else {
        // Add if not selected
        selectedStudentIds.push(studentIdNum);
    }
    
    // Update UI
    renderStudentOptions();
    updateSelectedStudentsList();
    updateHiddenInputs();
}

function updateSelectedStudentsList() {
    if (!selectedStudentsList) return;
    
    selectedStudentsList.innerHTML = '';
    
    if (selectedStudentIds.length === 0) {
        selectedStudentsList.innerHTML = '<div class="no-children">No students selected</div>';
        return;
    }
    
    selectedStudentIds.forEach(studentId => {
        const student = studentsData.find(s => Number(s.id) === Number(studentId));
        if (!student) return;
        
        const initials = getInitials(student.firstname || '', student.lastname || '');
        const hasProfileImage = student.profileimage && student.profileimage.trim() !== '';
        
        const chip = document.createElement('div');
        chip.className = 'selected-student-chip';
        chip.innerHTML = `
            <div class="selected-student-chip-info">
                <div class="student-avatar">
                    ${hasProfileImage ? `<img src="${student.profileimage}" alt="${student.name}">` : `<span class="initials">${initials}</span>`}
                </div>
                <div class="student-name">${student.name}</div>
            </div>
            <button type="button" class="remove-student-btn" onclick="removeStudent(${studentId})" title="Remove">
                <i class="fa fa-times"></i>
            </button>
        `;
        selectedStudentsList.appendChild(chip);
    });
}

function removeStudent(studentId) {
    const index = selectedStudentIds.indexOf(Number(studentId));
    if (index > -1) {
        selectedStudentIds.splice(index, 1);
        updateSelectedStudentsList();
        renderStudentOptions();
        updateHiddenInputs();
    }
}

function updateHiddenInputs() {
    if (!studentSelectHidden) return;
    
    // Remove existing hidden inputs
    const existingInputs = document.querySelectorAll('input[name="student_ids[]"]');
    existingInputs.forEach(input => {
        if (input.id !== 'hiddenStudentIds') input.remove();
    });
    
    // Add hidden inputs for each selected student
    selectedStudentIds.forEach(studentId => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'student_ids[]';
        input.value = studentId;
        studentSelectHidden.parentNode.insertBefore(input, studentSelectHidden);
    });
}

function openStudentDropdown() {
    if (studentFilterTrigger && studentFilterMenu) {
        studentFilterTrigger.classList.add('active');
        studentFilterTrigger.classList.add('search-mode');
        studentFilterMenu.classList.add('show');
        
        // Focus search input when dropdown opens
        setTimeout(() => {
            const searchInput = document.getElementById('studentSearchInput');
            if (searchInput) {
                searchInput.focus();
            }
        }, 100);
    }
}

function closeStudentDropdown() {
    if (studentFilterTrigger && studentFilterMenu) {
        studentFilterTrigger.classList.remove('active');
        studentFilterTrigger.classList.remove('search-mode');
        if (studentSearchInput) {
            studentSearchInput.blur();
        }
        if (studentDropdownSearch) {
            studentDropdownSearch.blur();
        }
        studentFilterMenu.classList.remove('show');
    }
}

// Initialize
if (studentsData.length && studentFilterMenu) {
    buildCohortOptions();
    renderStudentOptions();
    updateSelectedStudentsList();
    
    // Set initial "All Cohorts" display
    const totalStudents = studentsData.length;
    const allCount = totalStudents === 1 ? '1 member' : `${totalStudents} members`;
    if (cohortFilterTrigger) {
        const triggerText = cohortFilterTrigger.querySelector('.trigger-text');
        if (triggerText) {
            triggerText.textContent = `All Cohorts — ${allCount}`;
        }
    }

    // Cohort dropdown toggle
    if (cohortFilterTrigger) {
        cohortFilterTrigger.addEventListener('click', (e) => {
            e.stopPropagation();
            if (cohortFilterMenu.classList.contains('show')) {
                closeCohortDropdown();
            } else {
                openCohortDropdown();
            }
        });
    }
    
    // Student dropdown toggle
    if (studentFilterTrigger) {
        studentFilterTrigger.addEventListener('click', (e) => {
            e.stopPropagation();
            if (studentFilterMenu.classList.contains('show')) {
                closeStudentDropdown();
            } else {
                openStudentDropdown();
            }
        });
    }
    
    // Student search functionality in dropdown
    if (studentDropdownSearch) {
        let searchTimeout;
        studentDropdownSearch.addEventListener('input', (e) => {
            e.stopPropagation();
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                renderStudentOptions();
            }, 200);
        });
        
        studentDropdownSearch.addEventListener('click', (e) => {
            e.stopPropagation();
        });
    }
    
    // Student search functionality in trigger
    if (studentSearchInput) {
        studentSearchInput.addEventListener('click', (e) => {
            e.stopPropagation();
            openStudentDropdown();
        });
    }
    
    // Close dropdowns when clicking outside
    document.addEventListener('click', (e) => {
        // Close cohort dropdown
        if (cohortFilterMenu && cohortFilterTrigger && 
            !cohortFilterMenu.contains(e.target) && 
            !cohortFilterTrigger.contains(e.target)) {
            closeCohortDropdown();
        }
        
        // Close student dropdown
        if (studentFilterMenu && studentFilterTrigger && 
            !studentFilterMenu.contains(e.target) && 
            !studentFilterTrigger.contains(e.target)) {
            closeStudentDropdown();
        }
    });
}
</script>

<?php
echo $OUTPUT->footer();