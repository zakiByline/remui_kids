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
 * Delete School Page - Custom Implementation
 *
 * @package    theme_remui_kids
 * @copyright  2024 Kodeit
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../../config.php');
require_once($CFG->libdir.'/adminlib.php');

// Check if user is logged in
require_login();

// Check if user has admin capabilities
$context = context_system::instance();
require_capability('moodle/site:config', $context);

// Verify sesskey for security
require_sesskey();

// Get company ID
$companyid = required_param('id', PARAM_INT);

// Get company record
$company = $DB->get_record('company', ['id' => $companyid], '*', MUST_EXIST);

try {
    // Start transaction
    $transaction = $DB->start_delegated_transaction();
    
    // Delete related records
    
    // 1. Delete company users
    $DB->delete_records('company_users', ['companyid' => $companyid]);
    
    // 2. Delete company courses
    $DB->delete_records('company_course', ['companyid' => $companyid]);
    
    // 3. Delete company domains
    $DB->delete_records('company_domains', ['companyid' => $companyid]);
    
    // 4. Delete departments
    $DB->delete_records('department', ['company' => $companyid]);
    
    // 5. Delete company certificate info
    $DB->delete_records('companycertificate', ['companyid' => $companyid]);
    
    // 6. Delete company pages
    $DB->delete_records('company_pages', ['companyid' => $companyid]);
    
    // 7. Delete role template associations
    $DB->delete_records('company_role_templates_ass', ['companyid' => $companyid]);
    
    // 8. Delete the company itself
    $DB->delete_records('company', ['id' => $companyid]);
    
    // Commit transaction
    $transaction->allow_commit();
    
    // Redirect with success message
    redirect(
        new moodle_url('/theme/remui_kids/admin/schools_management.php'),
        'School "' . $company->name . '" has been deleted successfully',
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
    
} catch (Exception $e) {
    // Rollback on error
    if (isset($transaction)) {
        $transaction->rollback($e);
    }
    
    // Redirect with error message
    redirect(
        new moodle_url('/theme/remui_kids/admin/company_edit.php', ['id' => $companyid]),
        'Error deleting school: ' . $e->getMessage(),
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}
?>

