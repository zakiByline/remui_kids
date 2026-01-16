<?php
/**
 * Parent Bulk Upload Page - Upload multiple parents at once using CSV files.
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/user/lib.php');

require_login();

global $USER, $DB, $CFG, $OUTPUT, $PAGE, $SESSION;

// Ensure current user is a school/company manager.
$companymanagerrole = $DB->get_record('role', ['shortname' => 'companymanager']);
$is_company_manager = false;
$context = context_system::instance();

if ($companymanagerrole) {
    $is_company_manager = user_has_role_assignment($USER->id, $companymanagerrole->id, $context->id);
}

if (!$is_company_manager) {
    redirect($CFG->wwwroot . '/my/', get_string('nopermissions', 'error', 'access parent bulk upload'), null, \core\output\notification::NOTIFY_ERROR);
}

// Fetch company info.
$company_info = $DB->get_record_sql(
    "SELECT c.*
       FROM {company} c
       JOIN {company_users} cu ON c.id = cu.companyid
      WHERE cu.userid = ? AND cu.managertype = 1",
    [$USER->id]
);

if (!$company_info) {
    redirect($CFG->wwwroot . '/my/', 'Company not found', null, \core\output\notification::NOTIFY_ERROR);
}

$companyid = $company_info->id;
$parent_role = $DB->get_record('role', ['shortname' => 'parent']);

// Parent statistics.
$total_parents = $DB->count_records_sql(
    "SELECT COUNT(DISTINCT ra.userid)
       FROM {role_assignments} ra
       JOIN {context} ctx ON ctx.id = ra.contextid
       JOIN {user} child ON child.id = ctx.instanceid
       JOIN {company_users} cu ON cu.userid = child.id AND cu.companyid = ?
      WHERE ra.roleid = ?
        AND ctx.contextlevel = ?
        AND child.deleted = 0",
    [$companyid, $parent_role->id, CONTEXT_USER]
);

$assigned_parents = $DB->count_records_sql(
    "SELECT COUNT(DISTINCT ra.userid)
       FROM {role_assignments} ra
       JOIN {context} ctx ON ctx.id = ra.contextid
       JOIN {user} child ON child.id = ctx.instanceid
       JOIN {company_users} cu ON cu.userid = child.id AND cu.companyid = ?
      WHERE ra.roleid = ?
        AND ctx.contextlevel = ?
        AND child.deleted = 0",
    [$companyid, $parent_role->id, CONTEXT_USER]
);

$unassigned_parents = max(0, $total_parents - $assigned_parents);

// Handle CSV upload.
$upload_result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $upload_token = optional_param('upload_token', '', PARAM_RAW);
    if (!isset($SESSION->parent_upload_token) || $SESSION->parent_upload_token !== $upload_token) {
        $upload_result = process_parent_csv_upload($_FILES['csv_file'], $company_info);
        $SESSION->parent_upload_token = $upload_token;
    } else {
        $upload_result = ['success' => false, 'message' => 'Duplicate upload detected. Please try again with a new file.'];
    }
}

function process_parent_csv_upload($file, $company_info) {
    global $DB, $CFG;

    $required_columns = ['username', 'password', 'firstname', 'lastname', 'email', 'studentusername'];
    $optional_columns = ['phone', 'notes'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'File upload error: ' . $file['error']];
    }

    if ($file['size'] > 5 * 1024 * 1024) {
        return ['success' => false, 'message' => 'File size exceeds 5MB limit'];
    }

    if (($handle = fopen($file['tmp_name'], 'r')) === false) {
        return ['success' => false, 'message' => 'Unable to read CSV file'];
    }

    $header = fgetcsv($handle);
    if (!$header) {
        fclose($handle);
        return ['success' => false, 'message' => 'CSV file is empty'];
    }

    $missing_columns = array_diff($required_columns, $header);
    if (!empty($missing_columns)) {
        fclose($handle);
        return ['success' => false, 'message' => 'Missing required columns: ' . implode(', ', $missing_columns)];
    }

    $success_count = 0;
    $error_count = 0;
    $errors = [];
    $parent_role = $DB->get_record('role', ['shortname' => 'parent']);

    $department = $DB->get_record_sql(
        "SELECT id FROM {department} WHERE company = ? ORDER BY id ASC LIMIT 1",
        [$company_info->id]
    );

    while (($data = fgetcsv($handle)) !== false) {
        if (count($data) !== count($header)) {
            $error_count++;
            $errors[] = 'Column count mismatch in one of the rows.';
            continue;
        }

        $row = array_combine($header, $data);
        $row = array_map('trim', $row);

        // Validate required fields.
        foreach ($required_columns as $field) {
            if (empty($row[$field])) {
                $error_count++;
                $errors[] = "Missing value for '{$field}'";
                continue 2;
            }
        }

        $username = strtolower($row['username']);

        if (!preg_match('/^[a-z0-9._-]+$/', $username)) {
            $error_count++;
            $errors[] = "Invalid username format for '{$row['username']}'";
            continue;
        }

        if ($DB->record_exists('user', ['username' => $username, 'mnethostid' => $CFG->mnet_localhost_id, 'deleted' => 0])) {
            $error_count++;
            $errors[] = "Username '{$username}' already exists";
            continue;
        }

        if ($DB->record_exists('user', ['email' => $row['email'], 'mnethostid' => $CFG->mnet_localhost_id, 'deleted' => 0])) {
            $error_count++;
            $errors[] = "Email '{$row['email']}' already exists";
            continue;
        }

        $student = $DB->get_record_sql(
            "SELECT u.*
               FROM {user} u
               JOIN {company_users} cu ON cu.userid = u.id AND cu.companyid = :companyid
               JOIN {role_assignments} ra ON ra.userid = u.id
               JOIN {role} r ON r.id = ra.roleid AND r.shortname = 'student'
              WHERE u.username = :username
                AND u.deleted = 0",
            ['companyid' => $company_info->id, 'username' => $row['studentusername']]
        );

        if (!$student) {
            $error_count++;
            $errors[] = "Student '{$row['studentusername']}' not found or not part of this school";
            continue;
        }

        try {
            $user = new stdClass();
            $user->username = $username;
            $user->password = hash_internal_user_password($row['password']);
            $user->firstname = $row['firstname'];
            $user->lastname = $row['lastname'];
            $user->email = $row['email'];
            $user->auth = 'manual';
            $user->confirmed = 1;
            $user->mnethostid = $CFG->mnet_localhost_id;
            $user->suspended = 0;
            $user->deleted = 0;
            $user->phone1 = $row['phone'] ?? '';
            $user->description = $row['notes'] ?? '';

            $userid = user_create_user($user, false, false);

            // Associate with company if possible.
            if ($department) {
                $company_user = new stdClass();
                $company_user->userid = $userid;
                $company_user->companyid = $company_info->id;
                $company_user->departmentid = $department->id;
                $company_user->managertype = 0;
                $company_user->educator = 0;
                $company_user->suspended = 0;
                $DB->insert_record('company_users', $company_user);
            }

            // Assign parent role to student context.
            $studentcontext = context_user::instance($student->id);
            if (!$DB->record_exists('role_assignments', [
                'roleid' => $parent_role->id,
                'userid' => $userid,
                'contextid' => $studentcontext->id
            ])) {
                role_assign($parent_role->id, $userid, $studentcontext->id);
            }

            $success_count++;
        } catch (Exception $e) {
            $error_count++;
            $errors[] = "Failed to create parent '{$username}': " . $e->getMessage();
        }
    }

    fclose($handle);

    return [
        'success' => $success_count > 0,
        'success_count' => $success_count,
        'error_count' => $error_count,
        'errors' => $errors
    ];
}

// Sidebar context.
$sidebarcontext = [
    'company_name' => $company_info->name,
    'user_info' => ['fullname' => fullname($USER)],
    'config' => ['wwwroot' => $CFG->wwwroot],
    'parent_management_active' => true
];

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/theme/remui_kids/school_manager/bulk_add_parents.php'));
$PAGE->set_pagelayout('standard');
$PAGE->set_title('Parent Bulk Upload');
$PAGE->set_heading('Parent Bulk Upload');

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('theme_remui_kids/school_manager_sidebar', $sidebarcontext);
?>

<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');

.school-manager-main-content {
    position: fixed;
    top: 55px;
    left: 280px;
    width: calc(100vw - 280px);
    height: calc(100vh - 55px);
    background-color: #f8f9fa;
    overflow-y: auto;
    padding: 45px 0 50px;
}

.main-content {
    max-width: 1400px;
    margin: 0 auto;
    padding: 0 40px 40px;
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 25px;
}

.page-header h1 {
    margin: 0;
    font-size: 2rem;
    color: #111827;
}

.stats-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 18px;
    margin-bottom: 25px;
}

.stat-card {
    background: #ffffff;
    padding: 20px;
    border-radius: 16px;
    box-shadow: 0 15px 35px rgba(15, 23, 42, 0.08);
    border: 1px solid #e5e7eb;
}

.stat-card h3 {
    margin: 0;
    font-size: 0.95rem;
    color: #6b7280;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

.stat-card .stat-value {
    font-size: 2.2rem;
    font-weight: 700;
    color: #111827;
    margin-top: 10px;
}

.upload-card {
    background: #ffffff;
    border-radius: 18px;
    padding: 30px;
    box-shadow: 0 20px 45px rgba(15, 23, 42, 0.1);
    border: 1px solid #e5e7eb;
}

.upload-card h2 {
    margin-top: 0;
    font-size: 1.5rem;
    color: #111827;
}

.upload-description {
    color: #6b7280;
    margin-bottom: 25px;
}

.upload-area {
    border: 2px dashed #d1d5db;
    border-radius: 16px;
    padding: 30px;
    text-align: center;
    background: #f9fafb;
    margin-bottom: 25px;
    cursor: pointer;
    transition: border-color 0.2s ease, transform 0.2s ease;
}

.upload-area:hover {
    border-color: #6366f1;
    transform: translateY(-2px);
}

.upload-area i {
    font-size: 2.5rem;
    color: #9ca3af;
    margin-bottom: 10px;
}

.upload-area .upload-text {
    font-weight: 600;
    color: #374151;
}

.upload-area .upload-subtext {
    color: #6b7280;
}

.choose-file-btn {
    background: linear-gradient(135deg, #4f46e5, #7c3aed);
    color: #fff;
    border: none;
    padding: 12px 24px;
    border-radius: 12px;
    font-size: 1rem;
    font-weight: 600;
    cursor: pointer;
    margin-top: 15px;
}

.csv-requirements {
    background: #f8fafc;
    border-radius: 14px;
    padding: 20px;
    border: 1px solid #e5e7eb;
    margin-top: 25px;
}

.csv-requirements ul {
    padding-left: 18px;
    color: #4b5563;
}

.result-message {
    padding: 15px 20px;
    border-radius: 12px;
    margin-bottom: 20px;
    font-weight: 600;
}

.result-success {
    background: #ecfdf5;
    border: 1px solid #a7f3d0;
    color: #065f46;
}

.result-error {
    background: #fef2f2;
    border: 1px solid #fecaca;
    color: #b91c1c;
}

.error-list {
    background: #fff;
    border-radius: 10px;
    padding: 15px;
    border: 1px solid #fee2e2;
    margin-top: 15px;
    max-height: 200px;
    overflow-y: auto;
}

.error-list ul {
    margin: 0;
    padding-left: 18px;
    color: #b91c1c;
    font-size: 0.9rem;
}

.csv-example {
    background: #111827;
    color: #f1f5f9;
    padding: 20px;
    border-radius: 12px;
    margin-top: 25px;
    font-family: 'Fira Code', 'Courier New', monospace;
    font-size: 0.9rem;
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

    .main-content {
        padding: 0 20px 30px;
    }

    .page-header {
        flex-direction: column;
        gap: 15px;
        text-align: center;
    }

    .upload-card {
        padding: 20px;
    }
}
</style>

<div class="school-manager-main-content">
    <div class="main-content">
        <div class="page-header">
            <h1>Parent Bulk Upload</h1>
            <a class="choose-file-btn" href="<?php echo $CFG->wwwroot; ?>/theme/remui_kids/school_manager/parent_management.php">Back to Parent Management</a>
        </div>

        <div class="stats-row">
            <div class="stat-card">
                <h3>Total Parents</h3>
                <div class="stat-value"><?php echo number_format($total_parents); ?></div>
            </div>
            <div class="stat-card">
                <h3>Assigned Parents</h3>
                <div class="stat-value"><?php echo number_format($assigned_parents); ?></div>
            </div>
            <div class="stat-card">
                <h3>Unassigned Parents</h3>
                <div class="stat-value"><?php echo number_format($unassigned_parents); ?></div>
            </div>
        </div>

        <div class="upload-card">
            <h2>Upload Parent CSV</h2>
            <p class="upload-description">
                Upload a CSV file to create multiple parent accounts at once. Each parent will be automatically linked to their child (student) using the provided student username.
            </p>

            <?php if ($upload_result): ?>
                <div class="result-message <?php echo $upload_result['success'] ? 'result-success' : 'result-error'; ?>">
                    <?php if (!empty($upload_result['message'])): ?>
                        <?php echo format_string($upload_result['message']); ?>
                    <?php else: ?>
                        <?php echo $upload_result['success_count']; ?> parents created,
                        <?php echo $upload_result['error_count']; ?> errors.
                    <?php endif; ?>
                </div>

                <?php if (!empty($upload_result['errors'])): ?>
                    <div class="error-list">
                        <ul>
                            <?php foreach ($upload_result['errors'] as $error): ?>
                                <li><?php echo format_string($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="upload_token" value="<?php echo sesskey(); ?>">
                <div class="upload-area" id="uploadArea">
                    <i class="fa fa-cloud-upload-alt"></i>
                    <div class="upload-text">Drop your CSV file here</div>
                    <div class="upload-subtext">or click to browse files</div>
                    <button type="button" class="choose-file-btn" id="chooseFileBtn">Choose File</button>
                    <input type="file" id="csv_file" name="csv_file" accept=".csv" style="display: none;">
                </div>
            </form>

            <div class="csv-requirements">
                <h3>CSV Columns (required):</h3>
                <ul>
                    <li><strong>username</strong>, <strong>password</strong>, <strong>firstname</strong>, <strong>lastname</strong>, <strong>email</strong>, <strong>studentusername</strong></li>
                    <li>Optional columns: phone, notes</li>
                    <li>Student username must belong to this school and already exist.</li>
                </ul>
            </div>

            <div class="csv-example">
                username,password,firstname,lastname,email,studentusername,phone,notes<br>
                maria.parent,strongPass1,Maria,Parent,maria.parent@school.com,alfis_g09_aarav.patel25,+971500000000,Medical emergency contact<br>
                david.dad,securePass2,David,Dad,david.dad@school.com,alfis_g09_olivia.davis25,,
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const uploadArea = document.getElementById('uploadArea');
    const fileInput = document.getElementById('csv_file');
    const chooseFileBtn = document.getElementById('chooseFileBtn');

    function handleFiles(files) {
        if (!files.length) {
            return;
        }

        const file = files[0];
        if (!file.name.endsWith('.csv')) {
            alert('Please upload a CSV file.');
            return;
        }

        const form = uploadArea.closest('form');
        fileInput.files = files;
        form.submit();
    }

    uploadArea.addEventListener('dragover', (e) => {
        e.preventDefault();
        uploadArea.classList.add('dragover');
    });

    uploadArea.addEventListener('dragleave', () => {
        uploadArea.classList.remove('dragover');
    });

    uploadArea.addEventListener('drop', (e) => {
        e.preventDefault();
        uploadArea.classList.remove('dragover');
        handleFiles(e.dataTransfer.files);
    });

    chooseFileBtn.addEventListener('click', () => fileInput.click());
    fileInput.addEventListener('change', () => handleFiles(fileInput.files));
});
</script>

<?php
echo $OUTPUT->footer();

