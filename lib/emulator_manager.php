<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Centralised helpers for emulator catalog and access control.
 *
 * @package   theme_remui_kids
 * @copyright 2025 Kodeit
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

const THEME_REMUI_KIDS_EMULATOR_SCOPE_COMPANY = 'company';
const THEME_REMUI_KIDS_EMULATOR_SCOPE_COHORT = 'cohort';
const THEME_REMUI_KIDS_EMULATOR_DEFAULT_ALLOW = false; // Default DISABLED - must explicitly grant
const THEME_REMUI_KIDS_EMULATOR_DEFAULT_GRANT_ALL = false; // Default DISABLED - must explicitly grant schools

/**
 * Returns the canonical catalog describing every emulator/tool the platform offers.
 *
 * @return array
 */
function theme_remui_kids_emulator_catalog(): array {
    global $CFG;

    static $catalog = null;
    if ($catalog !== null) {
        return $catalog;
    }

    $catalog = [
        'code_editor' => [
            'slug' => 'code_editor',
            'name' => get_string('emulator_code_editor', 'theme_remui_kids'),
            'summary' => get_string('emulator_code_editor_summary', 'theme_remui_kids'),
            'icon' => 'fa-code',
            'category' => 'coding',
            'launchurl' => (new moodle_url('/theme/remui_kids/code_editor.php'))->out(false),
            'modnames' => ['codeeditor'],
        ],
        'scratch' => [
            'slug' => 'scratch',
            'name' => get_string('emulator_scratch', 'theme_remui_kids'),
            'summary' => get_string('emulator_scratch_summary', 'theme_remui_kids'),
            'icon' => 'fa-puzzle-piece',
            'category' => 'creative',
            'launchurl' => (new moodle_url('/theme/remui_kids/scratch_simple.php'))->out(false),
            'modnames' => ['scratch'],
        ],
        'remix' => [
            'slug' => 'remix',
            'name' => get_string('emulator_remix', 'theme_remui_kids'),
            'summary' => get_string('emulator_remix_summary', 'theme_remui_kids'),
            'icon' => 'fa-code-branch',
            'category' => 'coding',
            'launchurl' => (new moodle_url('/theme/remui_kids/remix.php'))->out(false),
            'modnames' => ['mix'],
        ],
        'photopea' => [
            'slug' => 'photopea',
            'name' => get_string('emulator_photopea', 'theme_remui_kids'),
            'summary' => get_string('emulator_photopea_summary', 'theme_remui_kids'),
            'icon' => 'fa-image',
            'category' => 'design',
            'launchurl' => (new moodle_url('/theme/remui_kids/photopea_studio.php'))->out(false),
            'modnames' => ['photopea'],
        ],
        'sql' => [
            'slug' => 'sql',
            'name' => get_string('emulator_sql', 'theme_remui_kids'),
            'summary' => get_string('emulator_sql_summary', 'theme_remui_kids'),
            'icon' => 'fa-database',
            'category' => 'data',
            'launchurl' => (new moodle_url('/theme/remui_kids/sql_lab.php'))->out(false),
            'modnames' => ['sql'],
        ],
        'webdev' => [
            'slug' => 'webdev',
            'name' => get_string('emulator_webdev', 'theme_remui_kids'),
            'summary' => get_string('emulator_webdev_summary', 'theme_remui_kids'),
            'icon' => 'fa-html5',
            'category' => 'coding',
            'launchurl' => (new moodle_url('/theme/remui_kids/webdev_studio.php'))->out(false),
            'modnames' => ['webdev'],
        ],
        'wick' => [
            'slug' => 'wick',
            'name' => get_string('emulator_wick', 'theme_remui_kids'),
            'summary' => get_string('emulator_wick_summary', 'theme_remui_kids'),
            'icon' => 'fa-clone',
            'category' => 'creative',
            'launchurl' => (new moodle_url('/theme/remui_kids/wick_editor.php'))->out(false),
            'modnames' => ['wick'],
        ],
        'wokwi' => [
            'slug' => 'wokwi',
            'name' => get_string('emulator_wokwi', 'theme_remui_kids'),
            'summary' => get_string('emulator_wokwi_summary', 'theme_remui_kids'),
            'icon' => 'fa-microchip',
            'category' => 'hardware',
            'launchurl' => (new moodle_url('/theme/remui_kids/wokwi_studio.php'))->out(false),
            'modnames' => ['wokwi'],
        ],
    ];

    return $catalog;
}

/**
 * Fetch a single emulator definition.
 *
 * @param string $slug
 * @return array|null
 */
function theme_remui_kids_get_emulator(string $slug): ?array {
    $catalog = theme_remui_kids_emulator_catalog();
    return $catalog[$slug] ?? null;
}

/**
 * Returns mapping from modname to emulator slug for quick lookups.
 *
 * @return array
 */
function theme_remui_kids_emulator_module_map(): array {
    static $map = null;
    if ($map !== null) {
        return $map;
    }

    $map = [];
    foreach (theme_remui_kids_emulator_catalog() as $slug => $definition) {
        foreach ($definition['modnames'] as $modname) {
            $map[$modname] = $slug;
        }
    }
    return $map;
}

/**
 * Determine the canonical slug for a Moodle module name if it belongs to the emulator catalog.
 *
 * @param string $modname
 * @return string|null
 */
function theme_remui_kids_emulator_slug_from_mod(string $modname): ?string {
    $map = theme_remui_kids_emulator_module_map();
    return $map[$modname] ?? null;
}

/**
 * Return all cohorts that currently have members in a company.
 *
 * @param int $companyid
 * @return array
 */
function theme_remui_kids_get_company_cohorts(int $companyid): array {
    global $DB;

    if ($companyid <= 0) {
        return [];
    }

    // Get cohorts that have members from this company
    // Similar to search_cohorts.php pattern
    $sql = "SELECT DISTINCT c.id, c.name, 
                   COUNT(DISTINCT cm.userid) AS members
              FROM {cohort} c
              INNER JOIN {cohort_members} cm ON cm.cohortid = c.id
              INNER JOIN {company_users} cu ON cu.userid = cm.userid
             WHERE cu.companyid = :companyid
               AND c.visible = 1
          GROUP BY c.id, c.name
          ORDER BY c.name ASC";

    return $DB->get_records_sql($sql, ['companyid' => $companyid]);
}

/**
 * Internal helper to fetch access rows keyed by emulator and scope id.
 *
 * @param string $scope
 * @param int[] $scopeids
 * @return array
 */
/**
 * Check if companyid column exists in emulator_access table (cached)
 * @return bool
 */
function theme_remui_kids_has_companyid_column(): bool {
    global $DB;
    static $cache = null;
    
    if ($cache !== null) {
        return $cache;
    }
    
    try {
        $table = new xmldb_table('theme_remui_kids_emulator_access');
        $dbman = $DB->get_manager();
        $cache = $dbman->field_exists($table, 'companyid');
        error_log("theme_remui_kids_has_companyid_column: Column exists = " . ($cache ? 'true' : 'false'));
        return $cache;
    } catch (Exception $e) {
        error_log("theme_remui_kids_has_companyid_column: Exception = " . $e->getMessage());
        $cache = false;
        return false;
    }
}

function theme_remui_kids_get_access_records(string $scope, array $scopeids, int $companyid = 0): array {
    global $DB;

    if (empty($scopeids)) {
        return [];
    }

    list($insql, $params) = $DB->get_in_or_equal($scopeids, SQL_PARAMS_NAMED, 'sid');
    $params['scope'] = $scope;

    $has_companyid = theme_remui_kids_has_companyid_column();
    
    // Build WHERE clause
    $where = "scope = :scope AND scopeid $insql";
    
    // Add companyid filter if column exists and companyid is provided
    if ($has_companyid) {
        if ($scope === THEME_REMUI_KIDS_EMULATOR_SCOPE_COHORT && $companyid > 0) {
            $where .= " AND companyid = :companyid";
            $params['companyid'] = $companyid;
            error_log("get_access_records: Adding companyid filter for cohort scope: companyid=$companyid");
        } else if ($scope === THEME_REMUI_KIDS_EMULATOR_SCOPE_COMPANY && $companyid > 0) {
            $where .= " AND companyid = :companyid";
            $params['companyid'] = $companyid;
            error_log("get_access_records: Adding companyid filter for company scope: companyid=$companyid");
        }
    } else {
        error_log("get_access_records: companyid column does not exist, skipping filter");
    }
    
    try {
        $records = $DB->get_records_select('theme_remui_kids_emulator_access', $where, $params);
        error_log("get_access_records: Found " . count($records) . " records for scope=$scope, companyid=$companyid");
    } catch (Exception $e) {
        // If query fails, return empty array
        error_log("get_access_records: Query failed: " . $e->getMessage());
        return [];
    }

    $byemulator = [];
    foreach ($records as $record) {
        // Ensure scopeid is an integer for consistent key matching
        $scopeid = (int)$record->scopeid;
        $byemulator[$record->emulator][$scopeid] = $record;
    }

    return $byemulator;
}

/**
 * Resolve access value walking from the most granular scope to the default.
 *
 * @param string $field
 * @param stdClass|null $cohortrecord
 * @param stdClass|null $companyrecord
 * @param stdClass|null $globalrecord
 * @return array{value: bool, source: string, explicit: bool}
 */
function theme_remui_kids_resolve_access_value(
    string $field,
    ?stdClass $cohortrecord,
    ?stdClass $companyrecord,
    ?stdClass $globalrecord
): array {
    $chain = [
        ['record' => $cohortrecord, 'source' => 'cohort'],
        ['record' => $companyrecord, 'source' => 'company'],
        ['record' => $globalrecord, 'source' => 'global'],
    ];

    foreach ($chain as $entry) {
        if (!$entry['record']) {
            continue;
        }
        if ($entry['record']->{$field} === null) {
            continue;
        }

        return [
            'value' => (bool)$entry['record']->{$field},
            'source' => $entry['source'],
            'explicit' => true,
        ];
    }

    return [
        'value' => THEME_REMUI_KIDS_EMULATOR_DEFAULT_ALLOW,
        'source' => 'default',
        'explicit' => false,
    ];
}

/**
 * Builds an access matrix for a given company id that can be consumed by UI or API consumers.
 *
 * @param int $companyid
 * @return array
 */
function theme_remui_kids_build_emulator_matrix(int $companyid): array {
    global $DB;
    
    $catalog = theme_remui_kids_emulator_catalog();
    $cohorts = theme_remui_kids_get_company_cohorts($companyid);
    $cohortids = array_keys($cohorts);

    $globalrecords = theme_remui_kids_get_access_records(THEME_REMUI_KIDS_EMULATOR_SCOPE_COMPANY, [0], 0);
    $companyrecords = theme_remui_kids_get_access_records(THEME_REMUI_KIDS_EMULATOR_SCOPE_COMPANY, [$companyid], $companyid);
    
    // Get cohort records filtered by companyid - this ensures each school has independent access
    $cohortrecords = theme_remui_kids_get_access_records(THEME_REMUI_KIDS_EMULATOR_SCOPE_COHORT, $cohortids, $companyid);

    $matrix = [];
    foreach ($catalog as $slug => $definition) {
        $globalrecord = $globalrecords[$slug][0] ?? null;
        $companyrecord = $companyrecords[$slug][$companyid] ?? null;

        $companyteacher = theme_remui_kids_resolve_access_value('allowteachers', null, $companyrecord, $globalrecord);
        $companystudent = theme_remui_kids_resolve_access_value('allowstudents', null, $companyrecord, $globalrecord);
        if ($companyid === 0) {
            if ($companyteacher['source'] === 'company') {
                $companyteacher['source'] = 'global';
            }
            if ($companystudent['source'] === 'company') {
                $companystudent['source'] = 'global';
            }
        }

        $cohortstates = [];
        foreach ($cohorts as $cohortid => $cohort) {
            // Ensure cohortid is an integer for consistent key matching
            $cohortid = (int)$cohortid;
            $cohortrecord = $cohortrecords[$slug][$cohortid] ?? null;
            
            // Debug logging for cohort records
            if ($cohortrecord) {
                error_log("build_emulator_matrix: Found cohort record for slug=$slug, cohortid=$cohortid, allowstudents=" . ($cohortrecord->allowstudents ?? 'NULL'));
            } else {
                error_log("build_emulator_matrix: No cohort record found for slug=$slug, cohortid=$cohortid");
            }
            
            $teacherstate = theme_remui_kids_resolve_access_value('allowteachers', $cohortrecord, $companyrecord, $globalrecord);
            $studentstate = theme_remui_kids_resolve_access_value('allowstudents', $cohortrecord, $companyrecord, $globalrecord);
            
            error_log("build_emulator_matrix: Cohort $cohortid ($cohort->name) - student state: value=" . ($studentstate['value'] ? '1' : '0') . ", source=" . $studentstate['source']);

            $cohortstates[] = [
                'id' => $cohortid,
                'name' => format_string($cohort->name),
                'members' => (int)$cohort->members,
                'teacher' => $teacherstate,
                'student' => $studentstate,
                'explicit' => ($cohortrecord && ($cohortrecord->allowteachers !== null || $cohortrecord->allowstudents !== null)),
            ];
        }

        $matrix[] = [
            'slug' => $slug,
            'name' => $definition['name'],
            'summary' => $definition['summary'],
            'icon' => $definition['icon'],
            'category' => $definition['category'],
            'company' => [
                'teacher' => $companyteacher,
                'student' => $companystudent,
                'explicit' => ($companyrecord && ($companyrecord->allowteachers !== null || $companyrecord->allowstudents !== null)),
            ],
            'global' => [
                'teacher' => theme_remui_kids_resolve_access_value('allowteachers', null, null, $globalrecord),
                'student' => theme_remui_kids_resolve_access_value('allowstudents', null, null, $globalrecord),
                'explicit' => ($globalrecord && ($globalrecord->allowteachers !== null || $globalrecord->allowstudents !== null)),
            ],
            'cohorts' => $cohortstates,
        ];
    }

    return [
        'emulators' => $matrix,
        'cohorts' => array_values(array_map(function($cohort) {
            return [
                'id' => $cohort->id,
                'name' => format_string($cohort->name),
                'members' => (int)$cohort->members,
            ];
        }, $cohorts)),
    ];
}

/**
 * Upsert a single access flag for a scope.
 *
 * @param string $emulator
 * @param string $scope
 * @param int $scopeid
 * @param string $field teachers|students
 * @param int $value
 * @param int $userid
 * @return stdClass|null
 */
function theme_remui_kids_update_emulator_access(
    string $emulator,
    string $scope,
    int $scopeid,
    string $field,
    int $value,
    int $userid,
    int $companyid = 0
): ?stdClass {
    global $DB;

    $column = $field === 'teachers' ? 'allowteachers' : 'allowstudents';

    // Check if companyid column exists (for backward compatibility during upgrade)
    $has_companyid = theme_remui_kids_has_companyid_column();

    // Determine companyid based on scope
    $record_companyid = 0;
    if ($scope === THEME_REMUI_KIDS_EMULATOR_SCOPE_COMPANY) {
        $record_companyid = $scopeid; // For company scope, companyid = scopeid
    } else if ($scope === THEME_REMUI_KIDS_EMULATOR_SCOPE_COHORT) {
        $record_companyid = $companyid; // For cohort scope, use provided companyid
    }

    $conditions = [
        'emulator' => $emulator,
        'scope' => $scope,
        'scopeid' => $scopeid,
    ];

    if ($has_companyid) {
        $conditions['companyid'] = $record_companyid;
    }

    error_log("theme_remui_kids_update_emulator_access: emulator=$emulator, scope=$scope, scopeid=$scopeid, companyid=$record_companyid, field=$field, value=$value, column=$column");

    $record = $DB->get_record('theme_remui_kids_emulator_access', $conditions);
    $now = time();

    if (!$record) {
        $record = (object)$conditions;
        $record->allowteachers = null;
        $record->allowstudents = null;
        $record->createdby = $userid;
        $record->modifiedby = $userid;
        $record->timecreated = $now;
        $record->timemodified = $now;
        if ($has_companyid) {
            $record->companyid = $record_companyid;
        }
        $record->$column = $value ? 1 : 0;
        
        error_log("theme_remui_kids_update_emulator_access: Inserting new record with $column = " . ($value ? 1 : 0));
        try {
        $record->id = $DB->insert_record('theme_remui_kids_emulator_access', $record);
        error_log("theme_remui_kids_update_emulator_access: Inserted record ID = " . $record->id);
        } catch (Exception $e) {
            // If insert fails (e.g., column doesn't exist), remove companyid and try again
            error_log("theme_remui_kids_update_emulator_access: Insert failed, retrying without companyid: " . $e->getMessage());
            if ($has_companyid && isset($record->companyid)) {
                unset($record->companyid);
            }
            $record->id = $DB->insert_record('theme_remui_kids_emulator_access', $record);
            error_log("theme_remui_kids_update_emulator_access: Inserted record ID = " . $record->id);
        }
    } else {
        $oldValue = $record->$column;
        $record->$column = $value ? 1 : 0;
        $record->modifiedby = $userid;
        $record->timemodified = $now;
        
        error_log("theme_remui_kids_update_emulator_access: Updating record ID=" . $record->id . ", $column from $oldValue to " . ($value ? 1 : 0));
        $result = $DB->update_record('theme_remui_kids_emulator_access', $record);
        error_log("theme_remui_kids_update_emulator_access: Update result = " . ($result ? 'true' : 'false'));
        
        if (!$result) {
            error_log("theme_remui_kids_update_emulator_access: ERROR - Update failed!");
            throw new Exception("Failed to update emulator access record");
        }
    }

    // Verify the save
    $verify = $DB->get_record('theme_remui_kids_emulator_access', ['id' => $record->id]);
    if ($verify) {
        error_log("theme_remui_kids_update_emulator_access: Verified - $column = " . $verify->$column);
    } else {
        error_log("theme_remui_kids_update_emulator_access: WARNING - Record not found after save!");
    }

    return $record;
}

/**
 * Reset a scope to inherit defaults.
 *
 * @param string $emulator
 * @param string $scope
 * @param int $scopeid
 * @param string|null $field
 * @param int|null $userid
 */
function theme_remui_kids_reset_emulator_access(
    string $emulator,
    string $scope,
    int $scopeid,
    ?string $field = null,
    ?int $userid = null,
    int $companyid = 0
): void {
    global $DB;

    // Check if companyid column exists (for backward compatibility during upgrade)
    $has_companyid = theme_remui_kids_has_companyid_column();

    // Determine companyid based on scope
    $record_companyid = 0;
    if ($scope === THEME_REMUI_KIDS_EMULATOR_SCOPE_COMPANY) {
        $record_companyid = $scopeid; // For company scope, companyid = scopeid
    } else if ($scope === THEME_REMUI_KIDS_EMULATOR_SCOPE_COHORT) {
        $record_companyid = $companyid; // For cohort scope, use provided companyid
    }

    $conditions = [
        'emulator' => $emulator,
        'scope' => $scope,
        'scopeid' => $scopeid,
    ];
    
    if ($has_companyid) {
        $conditions['companyid'] = $record_companyid;
    }

    $record = $DB->get_record('theme_remui_kids_emulator_access', $conditions);
    if (!$record) {
        return;
    }

    $columns = [
        'teachers' => 'allowteachers',
        'students' => 'allowstudents',
    ];

    if ($field && isset($columns[$field])) {
        $record->{$columns[$field]} = null;
    } else {
        $record->allowteachers = null;
        $record->allowstudents = null;
    }

    if ($record->allowteachers === null && $record->allowstudents === null) {
        $DB->delete_records('theme_remui_kids_emulator_access', $conditions);
        return;
    }

    $record->timemodified = time();
    if ($userid) {
        $record->modifiedby = $userid;
    }
    $DB->update_record('theme_remui_kids_emulator_access', $record);
}

/**
 * Return company ids for a user.
 *
 * @param int $userid
 * @return int[]
 */
function theme_remui_kids_get_user_company_ids(int $userid): array {
    global $DB;

    $records = $DB->get_records('company_users', ['userid' => $userid], '', 'companyid');
    $ids = array_map(fn($record) => (int)$record->companyid, $records);
    if (!in_array(0, $ids, true)) {
        $ids[] = 0; // Global scope fallback.
    }
    return $ids;
}

/**
 * Return cohort ids for a user.
 *
 * @param int $userid
 * @return int[]
 */
function theme_remui_kids_get_user_cohort_ids(int $userid): array {
    global $DB;

    $records = $DB->get_records('cohort_members', ['userid' => $userid], '', 'cohortid');
    return array_map(fn($record) => (int)$record->cohortid, $records);
}

/**
 * Get user cohort IDs that belong to the user's company(s).
 * This ensures cohort access is scoped to the correct school.
 *
 * @param int $userid
 * @return array Array of cohort IDs that belong to the user's company
 */
function theme_remui_kids_get_user_company_cohort_ids(int $userid): array {
    global $DB;

    // Get user's company IDs
    $companyids = theme_remui_kids_get_user_company_ids($userid);
    if (empty($companyids)) {
        return [];
    }

    // Filter out company ID 0 (global scope) for cohort filtering
    $companyids = array_filter($companyids, function($id) {
        return $id > 0;
    });
    
    if (empty($companyids)) {
        return [];
    }

    // Get cohorts that:
    // 1. The user is a member of
    // 2. Have members from the user's company
    list($insql, $params) = $DB->get_in_or_equal($companyids, SQL_PARAMS_NAMED, 'comp');
    $params['userid'] = $userid;

    $sql = "SELECT DISTINCT c.id
            FROM {cohort} c
            INNER JOIN {cohort_members} cm ON cm.cohortid = c.id
            INNER JOIN {company_users} cu ON cu.userid = cm.userid
            WHERE cm.userid = :userid
              AND cu.companyid $insql
              AND c.visible = 1";

    $records = $DB->get_records_sql($sql, $params);
    return array_map(fn($record) => (int)$record->id, $records);
}

/**
 * Resolve the persona (teacher/student) for access checks in a given context.
 *
 * @param context $context
 * @param int|null $userid
 * @return string
 */
function theme_remui_kids_resolve_emulator_role(context $context, ?int $userid = null): string {
    global $USER;
    $userid = $userid ?? $USER->id;

    $teachercapabilities = [
        'moodle/course:update',
        'moodle/course:manageactivities',
        'moodle/grade:viewall',
    ];

    if (has_any_capability($teachercapabilities, $context, $userid)) {
        return 'teacher';
    }

    return 'student';
}

/**
 * Determines if the given user can launch the emulator in the provided persona.
 *
 * @param int $userid
 * @param string $emulator
 * @param string $role teacher|student
 * @return bool
 */
function theme_remui_kids_user_has_emulator_access(int $userid, string $emulator, string $role = 'student'): bool {
    global $DB;

    try {
    $field = ($role === 'teacher') ? 'allowteachers' : 'allowstudents';

        // For teachers, check individual teacher access first (most specific)
        if ($role === 'teacher') {
            $companyids = theme_remui_kids_get_user_company_ids($userid);
            if (!empty($companyids)) {
                foreach ($companyids as $companyid) {
                    $teacher_access = $DB->get_record('theme_remui_kids_teacher_emulator', [
                        'teacherid' => $userid,
                        'companyid' => $companyid,
                        'emulator' => $emulator,
                    ]);
                    
                    if ($teacher_access !== false) {
                        // Individual teacher access record exists - use it
                        return (bool)$teacher_access->allowed;
                    }
                }
            }
        }

        // Check cohort access - cohorts can be shared across schools
        // We need to verify the user's company granted access to cohorts they're in
        $user_companyids = theme_remui_kids_get_user_company_ids($userid);
        $user_companyids = array_filter($user_companyids, function($id) {
            return $id > 0; // Exclude global scope
        });
        
        error_log("user_has_emulator_access: user=$userid, role=$role, emulator=$emulator, user_companyids=" . print_r($user_companyids, true));
        
        if (!empty($user_companyids)) {
            // Get all cohorts the user is in
            $user_cohortids = theme_remui_kids_get_user_cohort_ids($userid);
            error_log("user_has_emulator_access: user_cohortids=" . print_r($user_cohortids, true));
            
            if (!empty($user_cohortids)) {
                list($cohortinsql, $cohortparams) = $DB->get_in_or_equal($user_cohortids, SQL_PARAMS_NAMED, 'cid');
                list($compinsql, $compparams) = $DB->get_in_or_equal($user_companyids, SQL_PARAMS_NAMED, 'comp');
                
                $params = array_merge($cohortparams, $compparams);
        $params['emulator'] = $emulator;
        $params['scope'] = THEME_REMUI_KIDS_EMULATOR_SCOPE_COHORT;

                // Get access records for cohorts the user is in
                // Filter by companyid if column exists, otherwise use createdby join
                $has_companyid = theme_remui_kids_has_companyid_column();
                
                try {
                    if ($has_companyid) {
                        // Use companyid filter if column exists
                        $sql = "SELECT ea.*
                                FROM {theme_remui_kids_emulator_access} ea
                                WHERE ea.emulator = :emulator 
                                  AND ea.scope = :scope 
                                  AND ea.scopeid $cohortinsql
                                  AND ea.companyid $compinsql";
                        error_log("user_has_emulator_access: Using companyid filter query");
                    } else {
                        // Fallback: filter by createdby user's company (old method)
                        $sql = "SELECT ea.*
                                FROM {theme_remui_kids_emulator_access} ea
                                INNER JOIN {company_users} cu ON cu.userid = ea.createdby
                                WHERE ea.emulator = :emulator 
                                  AND ea.scope = :scope 
                                  AND ea.scopeid $cohortinsql
                                  AND cu.companyid $compinsql";
                        error_log("user_has_emulator_access: Using createdby join query (companyid column not found)");
                    }
                    
                    error_log("user_has_emulator_access: Executing cohort query with params: " . print_r($params, true));
                    $all_cohort_records = $DB->get_records_sql($sql, $params);
                    error_log("user_has_emulator_access: Found " . count($all_cohort_records) . " cohort records");
                    
                    // Log each record found
                    foreach ($all_cohort_records as $rec) {
                        error_log("user_has_emulator_access: Record - scopeid={$rec->scopeid}, companyid=" . ($rec->companyid ?? 'NULL') . ", allowstudents={$rec->allowstudents}, allowteachers={$rec->allowteachers}");
                    }
                } catch (Exception $e) {
                    // If query fails, return empty array
                    error_log("user_has_emulator_access: Cohort query failed: " . $e->getMessage());
                    error_log("user_has_emulator_access: SQL was: " . $sql);
                    error_log("user_has_emulator_access: Params were: " . print_r($params, true));
                    $all_cohort_records = [];
                }
                
                // Simplify: If companyid column exists, we already filtered by companyid, so no need for extra check
                // Only do the extra check if companyid column doesn't exist
                $valid_records = [];
                if ($has_companyid) {
                    // Companyid column exists - records are already filtered by company
                    $valid_records = $all_cohort_records;
                    error_log("user_has_emulator_access: companyid column exists, using all " . count($valid_records) . " records");
                } else {
                    // Companyid column doesn't exist - verify cohort has members from user's company
                    foreach ($all_cohort_records as $record) {
                        $cohortid = (int)$record->scopeid;
                        
                        // Check if this cohort has members from any of the user's companies
                        try {
                            $cohort_has_company_members = $DB->record_exists_sql(
                                "SELECT 1 
                                 FROM {cohort_members} cm
                                 INNER JOIN {company_users} cu ON cu.userid = cm.userid
                                 WHERE cm.cohortid = :cohortid 
                                   AND cu.companyid $compinsql
                                 LIMIT 1",
                                array_merge(['cohortid' => $cohortid], $compparams)
                            );
                        } catch (Exception $e) {
                            error_log("user_has_emulator_access: Cohort member check failed: " . $e->getMessage());
                            $cohort_has_company_members = false;
                        }

                        if ($cohort_has_company_members) {
                            $valid_records[] = $record;
                        }
                    }
                    error_log("user_has_emulator_access: After company member check, " . count($valid_records) . " valid records");
                }
                
                $decision = theme_remui_kids_reduce_records($valid_records, $field);
                error_log("user_has_emulator_access: reduce_records decision for field=$field: " . ($decision === null ? 'null' : ($decision ? 'true' : 'false')));
        if ($decision !== null) {
            return $decision;
        }
            } else {
                error_log("user_has_emulator_access: User has no cohorts");
            }
        } else {
            error_log("user_has_emulator_access: User has no company IDs");
    }

    $companyids = theme_remui_kids_get_user_company_ids($userid);
    list($insql, $params) = $DB->get_in_or_equal($companyids, SQL_PARAMS_NAMED, 'comp');
    $params['emulator'] = $emulator;
    $params['scope'] = THEME_REMUI_KIDS_EMULATOR_SCOPE_COMPANY;

        // For company scope, companyid should equal scopeid
        $has_companyid = theme_remui_kids_has_companyid_column();
        $where = "emulator = :emulator AND scope = :scope AND scopeid $insql";
        
        if ($has_companyid) {
            // Include companyid in the query (companyid should equal scopeid for company scope)
            $where .= " AND companyid $insql";
        }
        
        try {
            $records = $DB->get_records_select('theme_remui_kids_emulator_access', $where, $params);
        } catch (Exception $e) {
            // If query fails, return empty array
            error_log("user_has_emulator_access: Company query failed: " . $e->getMessage());
            $records = [];
        }
    $decision = theme_remui_kids_reduce_records($records, $field);
    if ($decision !== null) {
        return $decision;
    }

    return THEME_REMUI_KIDS_EMULATOR_DEFAULT_ALLOW;
    } catch (Exception $e) {
        // If any error occurs, log it and return default (no access)
        error_log("user_has_emulator_access: Fatal error for user=$userid, emulator=$emulator, role=$role: " . $e->getMessage());
        error_log("user_has_emulator_access: Stack trace: " . $e->getTraceAsString());
        return THEME_REMUI_KIDS_EMULATOR_DEFAULT_ALLOW;
    }
}

/**
 * Collapse multiple explicit records down to a final boolean (deny wins).
 *
 * @param array $records
 * @param string $field
 * @return bool|null
 */
function theme_remui_kids_reduce_records(array $records, string $field): ?bool {
    $hasallow = false;

    foreach ($records as $record) {
        if ($record->$field === null) {
            continue;
        }

        if ((int)$record->$field === 0) {
            return false;
        }

        $hasallow = true;
    }

    return $hasallow ? true : null;
}

/**
 * Convenience helper that throws a printable notice when access is blocked.
 *
 * @param moodle_page $page
 * @param string $slug
 * @param string $role
 */
function theme_remui_kids_render_emulator_block_notice(string $slug, string $role): void {
    global $OUTPUT, $COURSE;

    $definition = theme_remui_kids_get_emulator($slug);
    $a = (object)[
        'emulator' => $definition['name'] ?? $slug,
        'audience' => $role === 'teacher' ? get_string('emulator_role_teachers', 'theme_remui_kids')
            : get_string('emulator_role_students', 'theme_remui_kids'),
        'course' => $COURSE->fullname ?? '',
    ];

    echo $OUTPUT->header();
    echo html_writer::div(
        html_writer::tag('h3', get_string('emulator_disabled_heading', 'theme_remui_kids', $a)) .
        html_writer::tag('p', get_string('emulator_disabled_body', 'theme_remui_kids', $a)),
        'theme-remui-kids-emulator-locked alert alert-warning'
    );
    echo $OUTPUT->footer();
}

/**
 * Build quick action metadata for every emulator so sidebars can render them.
 *
 * @param int $userid
 * @param string $role teacher|student
 * @return array
 */
function theme_remui_kids_get_emulator_quick_actions(int $userid, string $role = 'student'): array {
    $catalog = theme_remui_kids_emulator_catalog();
    $gradients = [
        'code_editor' => 'linear-gradient(135deg, #fa709a 0%, #fee140 100%)',
        'scratch' => 'linear-gradient(135deg, #43e97b 0%, #38f9d7 100%)',
        'remix' => 'linear-gradient(135deg, #fc5c7d 0%, #6a82fb 100%)',
        'photopea' => 'linear-gradient(135deg, #2193b0 0%, #6dd5ed 100%)',
        'sql' => 'linear-gradient(135deg, #00b09b 0%, #96c93d 100%)',
        'webdev' => 'linear-gradient(135deg, #ff9966 0%, #ff5e62 100%)',
        'wick' => 'linear-gradient(135deg, #f857a6 0%, #ff5858 100%)',
        'wokwi' => 'linear-gradient(135deg, #36d1dc 0%, #5b86e5 100%)',
    ];

    $actions = [];
    $roleflag = ($role === 'teacher') ? 'teacher' : 'student';
    $teacherfallback = ($role === 'teacher')
        ? (new moodle_url('/theme/remui_kids/teacher/emulators.php'))->out(false)
        : null;

    foreach ($catalog as $slug => $definition) {
        $launchurl = $definition['launchurl'] ?? null;
        $accessible = theme_remui_kids_user_has_emulator_access($userid, $slug, $roleflag);
        if (!$accessible) {
            continue;
        }

        $targeturl = $launchurl;
        $statuslabel = get_string('emulator_tag_launch', 'theme_remui_kids');
        $activityonly = empty($launchurl);

        if ($activityonly) {
            if ($role !== 'teacher') {
                continue;
            }
            $statuslabel = get_string('emulator_tag_activity', 'theme_remui_kids');
            $targeturl = $teacherfallback ? $teacherfallback . '#emulator-' . $slug : null;
        }

        if (empty($targeturl)) {
            continue;
        }

        $actions[] = [
            'slug' => $slug,
            'name' => $definition['name'],
            'description' => $definition['summary'],
            'icon' => $definition['icon'] ?? 'fa-microchip',
            'url' => $targeturl,
            'statuslabel' => $statuslabel,
            'activityonly' => $activityonly,
            'background' => $gradients[$slug] ?? 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)',
        ];
    }

    return $actions;
}

/**
 * Get all teachers for a specific school/company.
 *
 * @param int $companyid
 * @return array Array of teacher objects with id, firstname, lastname, email
 */
function theme_remui_kids_get_school_teachers(int $companyid): array {
    global $DB;

    if ($companyid <= 0) {
        return [];
    }

    // Get all users in the company who have the editingteacher role assigned (anywhere)
    // This is the simplest and most reliable approach
    $sql = "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email
              FROM {user} u
              JOIN {company_users} cu ON cu.userid = u.id
              JOIN {role_assignments} ra ON ra.userid = u.id
              JOIN {role} r ON r.id = ra.roleid
             WHERE cu.companyid = :companyid
               AND r.shortname = 'editingteacher'
               AND u.deleted = 0
               AND u.suspended = 0
          ORDER BY u.lastname ASC, u.firstname ASC";

    $teachers = $DB->get_records_sql($sql, ['companyid' => $companyid]);
    
    // If still no teachers found, try with educator flag as fallback (in case role assignments don't exist)
    if (empty($teachers)) {
        // Check if educator column exists
        $columns = $DB->get_columns('company_users');
        if (isset($columns['educator'])) {
            $sql = "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email
                      FROM {user} u
                      JOIN {company_users} cu ON cu.userid = u.id
                     WHERE cu.companyid = :companyid
                       AND cu.educator = 1
                       AND u.deleted = 0
                       AND u.suspended = 0
                  ORDER BY u.lastname ASC, u.firstname ASC";
            
            $teachers = $DB->get_records_sql($sql, ['companyid' => $companyid]);
        }
    }
    
    return $teachers;
}

/**
 * Get teacher emulator access for a specific emulator and company.
 *
 * @param string $emulator
 * @param int $companyid
 * @return array Keyed by teacherid
 */
function theme_remui_kids_get_teacher_emulator_access(string $emulator, int $companyid): array {
    global $DB;

    $records = $DB->get_records('theme_remui_kids_teacher_emulator', [
        'emulator' => $emulator,
        'companyid' => $companyid,
    ]);

    $access = [];
    foreach ($records as $record) {
        $access[$record->teacherid] = (bool)$record->allowed;
    }

    return $access;
}

/**
 * Update individual teacher emulator access.
 *
 * @param int $teacherid
 * @param int $companyid
 * @param string $emulator
 * @param bool $allowed
 * @param int $userid
 * @return stdClass|null
 */
function theme_remui_kids_update_teacher_emulator_access(
    int $teacherid,
    int $companyid,
    string $emulator,
    bool $allowed,
    int $userid
): ?stdClass {
    global $DB;

    $conditions = [
        'teacherid' => $teacherid,
        'companyid' => $companyid,
        'emulator' => $emulator,
    ];

    $record = $DB->get_record('theme_remui_kids_teacher_emulator', $conditions);
    $now = time();

    if (!$record) {
        $record = (object)$conditions;
        $record->allowed = $allowed ? 1 : 0;
        $record->createdby = $userid;
        $record->modifiedby = $userid;
        $record->timecreated = $now;
        $record->timemodified = $now;
        $record->id = $DB->insert_record('theme_remui_kids_teacher_emulator', $record);
    } else {
        $record->allowed = $allowed ? 1 : 0;
        $record->modifiedby = $userid;
        $record->timemodified = $now;
        $DB->update_record('theme_remui_kids_teacher_emulator', $record);
    }

    return $record;
}

/**
 * Check if an emulator is granted to a specific school.
 * Returns true if granted, false if explicitly denied, null if no record (use default behavior).
 *
 * @param string $emulator
 * @param int $companyid
 * @return bool
 */
function theme_remui_kids_is_emulator_granted_to_school(string $emulator, int $companyid): bool {
    global $DB;

    if ($companyid === 0) {
        // Global scope always has access to all emulators
        return true;
    }

    try {
    $record = $DB->get_record('theme_remui_kids_emulator_school_grants', [
        'emulator' => $emulator,
        'companyid' => $companyid,
    ]);

    if ($record) {
            $granted = (bool)$record->granted;
            error_log("is_emulator_granted_to_school: emulator=$emulator, companyid=$companyid, record found, granted=$granted");
            return $granted;
    }

    // No record exists - use default behavior
        $default = THEME_REMUI_KIDS_EMULATOR_DEFAULT_GRANT_ALL;
        error_log("is_emulator_granted_to_school: emulator=$emulator, companyid=$companyid, no record found, using default=$default");
        return $default;
    } catch (Exception $e) {
        error_log("is_emulator_granted_to_school: Exception for emulator=$emulator, companyid=$companyid: " . $e->getMessage());
    return THEME_REMUI_KIDS_EMULATOR_DEFAULT_GRANT_ALL;
    }
}

/**
 * Get all emulators granted to a specific school.
 *
 * @param int $companyid
 * @return array Array of emulator slugs
 */
function theme_remui_kids_get_granted_emulators_for_school(int $companyid): array {
    global $DB;

    if ($companyid === 0) {
        // Global scope has all emulators
        return array_keys(theme_remui_kids_emulator_catalog());
    }

    $catalog = theme_remui_kids_emulator_catalog();
    $granted = [];

    error_log("get_granted_emulators_for_school: Checking companyid=$companyid, catalog has " . count($catalog) . " emulators");

    foreach ($catalog as $slug => $definition) {
        $is_granted = theme_remui_kids_is_emulator_granted_to_school($slug, $companyid);
        error_log("get_granted_emulators_for_school: emulator=$slug, granted=" . ($is_granted ? 'true' : 'false'));
        if ($is_granted) {
            $granted[] = $slug;
        }
    }

    error_log("get_granted_emulators_for_school: Returning " . count($granted) . " granted emulators: " . implode(', ', $granted));
    return $granted;
}

/**
 * Update emulator grant status for a school.
 *
 * @param string $emulator
 * @param int $companyid
 * @param bool $granted
 * @param int $userid
 * @return stdClass|null
 */
function theme_remui_kids_update_emulator_school_grant(
    string $emulator,
    int $companyid,
    bool $granted,
    int $userid
): ?stdClass {
    global $DB;

    if ($companyid === 0) {
        // Cannot set grants for global scope
        return null;
    }

    $conditions = [
        'emulator' => $emulator,
        'companyid' => $companyid,
    ];

    $record = $DB->get_record('theme_remui_kids_emulator_school_grants', $conditions);
    $now = time();

    if (!$record) {
        $record = (object)$conditions;
        $record->granted = $granted ? 1 : 0;
        $record->createdby = $userid;
        $record->modifiedby = $userid;
        $record->timecreated = $now;
        $record->timemodified = $now;
        $record->id = $DB->insert_record('theme_remui_kids_emulator_school_grants', $record);
    } else {
        $record->granted = $granted ? 1 : 0;
        $record->modifiedby = $userid;
        $record->timemodified = $now;
        $DB->update_record('theme_remui_kids_emulator_school_grants', $record);
    }

    // If access is revoked, revoke all individual teacher access for this emulator and company
    if (!$granted) {
        // Delete all individual teacher access records for this emulator and company
        $DB->delete_records('theme_remui_kids_teacher_emulator', [
            'emulator' => $emulator,
            'companyid' => $companyid,
        ]);
        
        // Also revoke all cohort-level access for this company
        // Get all cohorts for this company
        $cohorts = theme_remui_kids_get_company_cohorts($companyid);
        if (is_array($cohorts) && !empty($cohorts)) {
            $cohortids = array_map(function($cohort) {
                return (int)$cohort->id;
            }, $cohorts);
            
            if (!empty($cohortids)) {
                list($insql, $params) = $DB->get_in_or_equal($cohortids, SQL_PARAMS_NAMED, 'cid');
                $params['emulator'] = $emulator;
                $params['scope'] = THEME_REMUI_KIDS_EMULATOR_SCOPE_COHORT;
                
                // Delete all cohort-level access records for this company
                $params['companyid'] = $companyid;
                $DB->delete_records_select('theme_remui_kids_emulator_access',
                    "emulator = :emulator AND scope = :scope AND scopeid $insql AND companyid = :companyid",
                    $params);
            }
        }
        
        // Revoke company-level access
        $DB->delete_records('theme_remui_kids_emulator_access', [
            'emulator' => $emulator,
            'scope' => THEME_REMUI_KIDS_EMULATOR_SCOPE_COMPANY,
            'scopeid' => $companyid,
        ]);
    }

    return $record;
}

/**
 * Get grant status for all schools for a specific emulator.
 *
 * @param string $emulator
 * @return array Keyed by companyid
 */
function theme_remui_kids_get_emulator_school_grants(string $emulator): array {
    global $DB;

    $records = $DB->get_records('theme_remui_kids_emulator_school_grants', ['emulator' => $emulator]);
    
    $grants = [];
    foreach ($records as $record) {
        $grants[$record->companyid] = (bool)$record->granted;
    }

    return $grants;
}

/**
 * Build grant matrix showing which emulators are granted to which schools.
 *
 * @return array
 */
function theme_remui_kids_build_school_grant_matrix(): array {
    global $DB;

    $catalog = theme_remui_kids_emulator_catalog();
    $companies = $DB->get_records('company', null, 'name ASC', 'id, name, shortname');

    $matrix = [];
    foreach ($catalog as $slug => $definition) {
        $grants = theme_remui_kids_get_emulator_school_grants($slug);
        
        $schoolgrants = [];
        foreach ($companies as $company) {
            $companyid = (int)$company->id;
            
            // Check if explicitly set
            $explicit = isset($grants[$companyid]);
            $granted = $explicit ? $grants[$companyid] : THEME_REMUI_KIDS_EMULATOR_DEFAULT_GRANT_ALL;
            
            $schoolgrants[] = [
                'companyid' => $companyid,
                'companyname' => format_string($company->name),
                'granted' => $granted,
                'explicit' => $explicit,
            ];
        }

        $matrix[] = [
            'slug' => $slug,
            'name' => $definition['name'],
            'summary' => $definition['summary'],
            'icon' => $definition['icon'],
            'schools' => $schoolgrants,
        ];
    }

    return [
        'emulators' => $matrix,
        'companies' => array_values(array_map(function($c) {
            return [
                'id' => (int)$c->id,
                'name' => format_string($c->name),
                'shortname' => $c->shortname,
            ];
        }, $companies)),
    ];
}


