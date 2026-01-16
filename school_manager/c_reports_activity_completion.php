<?php
/**
 * C Reports - Activity Completion Report Tab (AJAX fragment)
 */

require_once(__DIR__ . '/../../../config.php');
require_login();

global $USER, $DB, $CFG;

$companymanagerrole = $DB->get_record('role', ['shortname' => 'companymanager']);
$is_company_manager = false;

if ($companymanagerrole) {
    $context = context_system::instance();
    $is_company_manager = user_has_role_assignment($USER->id, $companymanagerrole->id, $context->id);
}

if (!$is_company_manager) {
    redirect($CFG->wwwroot . '/my/', 'Access denied. School manager role required.', null, \core\output\notification::NOTIFY_ERROR);
}

$ajax = optional_param('ajax', 0, PARAM_BOOL);

$company_info = null;
if ($DB->get_manager()->table_exists('company') && $DB->get_manager()->table_exists('company_users')) {
    $company_info = $DB->get_record_sql(
        "SELECT c.*
           FROM {company} c
           JOIN {company_users} cu ON c.id = cu.companyid
          WHERE cu.userid = ? AND cu.managertype = 1",
        [$USER->id]
    );
}

if (!$ajax) {
    $target = new moodle_url('/theme/remui_kids/school_manager/c_reports.php', ['tab' => 'activitycompletion']);
    redirect($target);
}

$activity_filters = [
    'courseid' => optional_param('courseid', 0, PARAM_INT),
    'moduletype' => optional_param('moduletype', '', PARAM_ALPHANUMEXT)
];

$activity_status_summary = [
    'completed' => 0,
    'pending' => 0
];

$activity_completion_rows = [];

if ($company_info) {
    list($courseidsql, $courseparams) = ['', []];
    if (!empty($activity_filters['courseid'])) {
        $courseidsql = "AND c.id = :courseid";
        $courseparams['courseid'] = $activity_filters['courseid'];
    }

    list($modulesql, $moduleparams) = ['', []];
    if (!empty($activity_filters['moduletype'])) {
        $modulesql = "AND cm.module = (SELECT id FROM {modules} WHERE name = :modname)";
        $moduleparams['modname'] = $activity_filters['moduletype'];
    }

    $params = array_merge(
        $courseparams,
        $moduleparams,
        [
            'companyidcourse_ac' => $company_info->id,
            'companyiduser_ac' => $company_info->id
        ]
    );

    $activity_name_sources = [];
    if ($DB->get_manager()->table_exists('quiz')) {
        $activity_name_sources[] = "SELECT q.id, q.name, 'quiz' AS modname FROM {quiz} q";
    }
    if ($DB->get_manager()->table_exists('assign')) {
        $activity_name_sources[] = "SELECT a.id, a.name, 'assign' AS modname FROM {assign} a";
    }
    if ($DB->get_manager()->table_exists('page')) {
        $activity_name_sources[] = "SELECT p.id, p.name, 'page' AS modname FROM {page} p";
    }
    if ($DB->get_manager()->table_exists('hvp')) {
        $activity_name_sources[] = "SELECT hv.id, hv.name, 'hvp' AS modname FROM {hvp} hv";
    }

    if (empty($activity_name_sources)) {
        $activity_name_sources[] = "SELECT NULL AS id, NULL AS name, NULL AS modname";
    }
    $activity_name_join = "LEFT JOIN (" . implode(' UNION ALL ', $activity_name_sources) . ") e ON e.id = cm.instance AND e.modname = m.name";

    $activity_completion_rows = $DB->get_records_sql(
        "SELECT cm.id,
                c.fullname AS coursename,
                m.name AS modulename,
                cm.instance,
                cmc.userid,
                u.firstname,
                u.lastname,
                cmc.completionstate,
                cmc.timemodified,
                e.name AS activityname
           FROM {course_modules} cm
           JOIN {modules} m ON m.id = cm.module
           JOIN {course} c ON c.id = cm.course
           JOIN {company_course} cc ON cc.courseid = c.id AND cc.companyid = :companyidcourse_ac
      LEFT JOIN {course_modules_completion} cmc ON cmc.coursemoduleid = cm.id
      LEFT JOIN {user} u ON u.id = cmc.userid
      LEFT JOIN {company_users} cu ON cu.userid = u.id AND cu.companyid = :companyiduser_ac
      {$activity_name_join}
          WHERE c.visible = 1
            AND cm.deletioninprogress = 0
            AND (cm.visible = 1 OR cm.visibleold = 1)
            AND COALESCE(cu.educator, 0) = 0
            {$courseidsql}
            {$modulesql}
       ORDER BY c.fullname ASC, e.name ASC, u.lastname ASC, u.firstname ASC",
        $params
    );

    foreach ($activity_completion_rows as $row) {
        $is_completed = (int)$row->completionstate === COMPLETION_COMPLETE;
        if ($is_completed) {
            $activity_status_summary['completed']++;
        } else {
            $activity_status_summary['pending']++;
        }
    }
}

$module_options = [
    '' => 'All Modules',
    'assign' => 'Assignments',
    'quiz' => 'Quizzes',
    'page' => 'Pages',
    'hvp' => 'Interactive Videos'
];

$course_options = [];
if ($company_info) {
    $course_records = $DB->get_records_sql(
        "SELECT c.id, c.fullname
           FROM {course} c
           JOIN {company_course} cc ON cc.courseid = c.id
          WHERE cc.companyid = ?
            AND c.visible = 1
            AND c.id > 1
       ORDER BY c.fullname ASC",
        [$company_info->id]
    );
    foreach ($course_records as $course) {
        $course_options[$course->id] = $course->fullname;
    }
}

?>

<div class="activity-completion-wrapper">
    <div class="filter-bar">
        <form method="get" id="activityCompletionFilters" onsubmit="return applyActivityCompletionFilters(event);">
            <input type="hidden" name="tab" value="activitycompletion" />
            <input type="hidden" name="ajax" value="1" />
            <div class="filter-group">
                <label for="acCourseFilter">Course</label>
                <select id="acCourseFilter" name="courseid" onchange="applyActivityCompletionFilters(event)">
                    <option value="0">All Courses</option>
                    <?php foreach ($course_options as $course_id => $course_name): ?>
                        <option value="<?php echo $course_id; ?>" <?php echo $activity_filters['courseid'] == $course_id ? 'selected' : ''; ?>>
                            <?php echo format_string($course_name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label for="acModuleFilter">Module type</label>
                <select id="acModuleFilter" name="moduletype" onchange="applyActivityCompletionFilters(event)">
                    <?php foreach ($module_options as $value => $label): ?>
                        <option value="<?php echo $value; ?>" <?php echo $activity_filters['moduletype'] === $value ? 'selected' : ''; ?>>
                            <?php echo $label; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="reset" class="clear-filters-btn" onclick="resetActivityCompletionFilters(event)">
                <i class="fa fa-times"></i> Clear
            </button>
        </form>
    </div>

    <div class="activity-summary-grid">
        <div class="summary-card">
            <p>Total Activities</p>
            <h3><?php echo number_format(count($activity_completion_rows)); ?></h3>
        </div>
        <div class="summary-card success">
            <p>Completed</p>
            <h3><?php echo number_format($activity_status_summary['completed']); ?></h3>
        </div>
        <div class="summary-card warning">
            <p>Not Completed</p>
            <h3><?php echo number_format($activity_status_summary['pending']); ?></h3>
        </div>
    </div>

    <?php if (!empty($activity_completion_rows)): ?>
        <div class="activity-table-section">
            <div class="section-header">
                <div>
                    <h3><i class="fa fa-tasks"></i> Activity Completion Tracker</h3>
                    <p>Monitor completion status for every activity across your courses.</p>
                </div>
                <div class="legend">
                    <span><i class="fa fa-circle completed"></i> Completed</span>
                    <span><i class="fa fa-circle pending"></i> Not completed</span>
                </div>
            </div>

            <div class="table-responsive">
                <table class="activity-completion-table">
                    <thead>
                        <tr>
                            <th>Course</th>
                            <th>Activity</th>
                            <th>Module</th>
                            <th>Student</th>
                            <th>Status</th>
                            <th>Time Completed</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($activity_completion_rows as $row): ?>
                            <?php
                                $is_completed = (int)$row->completionstate === COMPLETION_COMPLETE;
                                $status_label = $is_completed ? 'Completed' : 'Not completed';
                                $status_class = $is_completed ? 'success' : 'muted';
                                $completed_time = $row->timemodified
                                    ? userdate($row->timemodified, get_string('strftimedatetimeshort'))
                                    : '—';
                                $student_name = $row->userid ? fullname((object)[
                                    'id' => $row->userid,
                                    'firstname' => $row->firstname,
                                    'lastname' => $row->lastname
                                ]) : '—';

                                if ($is_completed) {
                                    $completion_state_label = 'Completed';
                                } else {
                                    $completion_state_label = $row->userid ? 'In progress' : 'Not started';
                                }
                            ?>
                            <tr>
                                <td><?php echo format_string($row->coursename); ?></td>
                                <td><?php echo format_string($row->activityname ?? ucfirst($row->modulename) . ' #' . $row->instance); ?></td>
                                <td>
                                    <span class="module-badge"><?php echo ucfirst($row->modulename); ?></span>
                                </td>
                                <td><?php echo s($student_name); ?></td>
                                <td>
                                    <span class="status-badge <?php echo $status_class; ?>">
                                        <?php echo $completion_state_label; ?>
                                    </span>
                                </td>
                                <td><?php echo s($completed_time); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php else: ?>
        <div class="student-empty-state">
            <i class="fa fa-tasks"></i>
            <p style="color: #6b7280; font-weight: 600; margin: 0;">No activities found for the selected filters.</p>
        </div>
    <?php endif; ?>
</div>

<style>
.activity-completion-wrapper {
    display: flex;
    flex-direction: column;
    gap: 25px;
}

.filter-bar {
    background: #ffffff;
    border-radius: 12px;
    padding: 20px;
    border: 1px solid #e5e7eb;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}

.filter-group {
    display: inline-flex;
    flex-direction: column;
    margin-right: 20px;
    margin-bottom: 10px;
}

.filter-group label {
    font-size: 0.85rem;
    font-weight: 600;
    color: #475569;
    margin-bottom: 6px;
}

.filter-group select {
    min-width: 220px;
    padding: 10px 12px;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    font-size: 0.9rem;
    color: #374151;
}

.activity-summary-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 15px;
}

.summary-card {
    background: white;
    border-radius: 12px;
    padding: 18px 20px;
    border: 1px solid #e5e7eb;
    box-shadow: 0 1px 3px rgba(15, 23, 42, 0.08);
}

.summary-card p {
    font-size: 0.85rem;
    font-weight: 600;
    color: #94a3b8;
    margin-bottom: 8px;
    text-transform: uppercase;
}

.summary-card h3 {
    margin: 0;
    font-size: 1.9rem;
    color: #111827;
    font-weight: 700;
}

.summary-card.success {
    border-left: 4px solid #10b981;
}

.summary-card.warning {
    border-left: 4px solid #f97316;
}

.activity-table-section {
    background: #ffffff;
    border-radius: 12px;
    padding: 25px;
    border: 1px solid #e5e7eb;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}

.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.section-header h3 {
    margin: 0;
    font-size: 1.2rem;
    font-weight: 700;
    color: #1f2937;
}

.section-header i {
    color: #6366f1;
    margin-right: 8px;
}

.section-header p {
    margin: 5px 0 0;
    color: #64748b;
    font-size: 0.92rem;
}

.legend {
    display: flex;
    gap: 12px;
    font-size: 0.85rem;
    color: #6b7280;
}

.legend i {
    font-size: 0.7rem;
    margin-right: 6px;
}

.legend .completed {
    color: #10b981;
}

.legend .pending {
    color: #94a3b8;
}

.table-responsive {
    overflow-x: auto;
}

.activity-completion-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 1000px;
}

.activity-completion-table thead th {
    padding: 14px;
    text-align: left;
    text-transform: uppercase;
    font-size: 0.78rem;
    letter-spacing: 0.4px;
    color: #475569;
    border-bottom: 2px solid #e5e7eb;
}

.activity-completion-table tbody td {
    padding: 16px 14px;
    border-bottom: 1px solid #f1f5f9;
    color: #1f2937;
}

.status-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 4px 12px;
    border-radius: 999px;
    font-size: 0.82rem;
    font-weight: 600;
}

.status-badge.success {
    background: rgba(16, 185, 129, 0.15);
    color: #047857;
}

.status-badge.muted {
    background: rgba(148, 163, 184, 0.2);
    color: #475569;
}

.module-badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 8px;
    background: #eef2ff;
    color: #4338ca;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: capitalize;
}

@media (max-width: 768px) {
    .filter-group {
        width: 100%;
    }

    .filter-group select {
        width: 100%;
    }

    .section-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
    }
}
</style>

<script>
function applyActivityCompletionFilters(event) {
    if (event) {
        event.preventDefault();
    }
    const form = document.getElementById('activityCompletionFilters');
    const params = new URLSearchParams(new FormData(form));
    const baseUrl = '<?php echo new moodle_url('/theme/remui_kids/school_manager/c_reports.php'); ?>';
    const baseUrl = '<?php echo new moodle_url('/theme/remui_kids/school_manager/c_reports.php'); ?>';
    const targetUrl = baseUrl + '?' + params.toString();

    if (typeof activateTab === 'function') {
        activateTab('activitycompletion', { pushState: false, bypassCache: true, fetchUrlOverride: targetUrl });
    } else {
        window.location.href = targetUrl;
    }
    return false;
}

function resetActivityCompletionFilters(event) {
    event.preventDefault();
    const form = document.getElementById('activityCompletionFilters');
    form.reset();
    applyActivityCompletionFilters();
}
</script>

