<?php
/**
 * Enroll Users and Cohorts to Course
 * Handles enrollment of individual users and entire cohorts
 */

require_once('../../config.php');
require_login();

global $DB, $USER, $CFG;
require_once($CFG->dirroot . '/enrol/manual/locallib.php');

// Set JSON header
header('Content-Type: application/json');

try {
    // Get JSON data
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);
    
    // Validate required fields
    if (empty($data['course_id'])) {
        throw new Exception('Course ID is required');
    }
    
    if (empty($data['company_id'])) {
        throw new Exception('Company ID is required');
    }
    
    $course_id = (int)$data['course_id'];
    $company_id = (int)$data['company_id'];
    $start_date = !empty($data['start_date']) ? strtotime($data['start_date']) : time();
    $end_date = !empty($data['end_date']) ? strtotime($data['end_date']) : 0;
    
    // Support both old format (single role) and new format (multiple enrollment groups)
    $enrollment_groups = [];
    
    if (!empty($data['enrollment_groups']) && is_array($data['enrollment_groups'])) {
        // NEW FORMAT: Multiple enrollment groups
        $enrollment_groups = $data['enrollment_groups'];
    } else if (!empty($data['role'])) {
        // OLD FORMAT: Single role with users/cohorts (backwards compatibility)
        $enrollment_groups = [[
            'role' => $data['role'],
            'users' => $data['users'] ?? [],
            'cohorts' => $data['cohorts'] ?? []
        ]];
    } else {
        throw new Exception('Either role or enrollment_groups is required');
    }
    
    if (empty($enrollment_groups)) {
        throw new Exception('No enrollment data provided');
    }
    
    // Verify user is a manager of this company
    $is_manager = $DB->record_exists('company_users', [
        'userid' => $USER->id,
        'companyid' => $company_id,
        'managertype' => 1
    ]);
    
    if (!$is_manager) {
        throw new Exception('Access denied: You must be a school manager');
    }
    
    // Get course
    $course = $DB->get_record('course', ['id' => $course_id], '*', MUST_EXIST);
    $context = context_course::instance($course_id);
    
    // Get or create manual enrolment instance
    $enrol_instance = $DB->get_record('enrol', [
        'courseid' => $course_id,
        'enrol' => 'manual'
    ]);
    
    if (!$enrol_instance) {
        // Create manual enrolment instance if it doesn't exist
        $enrol = enrol_get_plugin('manual');
        $enrol_instance_id = $enrol->add_instance($course);
        $enrol_instance = $DB->get_record('enrol', ['id' => $enrol_instance_id], '*', MUST_EXIST);
    }
    
    // Get manual enrolment plugin
    $enrol_manual = enrol_get_plugin('manual');
    
    $enrolled_users_count = 0;
    $enrolled_cohort_members_count = 0;
    $errors = [];
    
    // Process each enrollment group (role + users + cohorts)
    foreach ($enrollment_groups as $group) {
        $role_shortname = $group['role'];
        $users = $group['users'] ?? [];
        $cohorts = $group['cohorts'] ?? [];
        
        // Get role ID
        $role = $DB->get_record('role', ['shortname' => $role_shortname]);
        if (!$role) {
            $errors[] = "Invalid role: {$role_shortname}";
            continue;
        }
        
        // Enroll individual users
        if (!empty($users) && is_array($users)) {
        foreach ($users as $user_data) {
            try {
                $user_id = (int)$user_data['id'];
                
                // Verify user belongs to the company
                $user_in_company = $DB->record_exists('company_users', [
                    'userid' => $user_id,
                    'companyid' => $company_id
                ]);
                
                if (!$user_in_company) {
                    $errors[] = "User {$user_data['name']} does not belong to this school";
                    continue;
                }
                
                // Check if already enrolled
                $already_enrolled = $DB->record_exists('user_enrolments', [
                    'enrolid' => $enrol_instance->id,
                    'userid' => $user_id
                ]);
                
                if ($already_enrolled) {
                    // Update existing enrollment
                    $enrolment = $DB->get_record('user_enrolments', [
                        'enrolid' => $enrol_instance->id,
                        'userid' => $user_id
                    ]);
                    
                    $enrolment->timestart = $start_date;
                    $enrolment->timeend = $end_date;
                    $enrolment->timemodified = time();
                    $enrolment->modifierid = $USER->id;
                    $DB->update_record('user_enrolments', $enrolment);
                } else {
                    // Create new enrollment
                    $enrol_manual->enrol_user($enrol_instance, $user_id, $role->id, $start_date, $end_date);
                }
                
                // Assign role
                role_assign($role->id, $user_id, $context->id);
                
                $enrolled_users_count++;
            } catch (Exception $e) {
                $errors[] = "Error enrolling user {$user_data['name']}: " . $e->getMessage();
            }
        }
    }
    
    // Enroll cohort members
    if (!empty($cohorts) && is_array($cohorts)) {
        foreach ($cohorts as $cohort_data) {
            try {
                $cohort_id = (int)$cohort_data['id'];
                
                // Get cohort members
                $cohort_members = $DB->get_records('cohort_members', ['cohortid' => $cohort_id]);
                
                foreach ($cohort_members as $member) {
                    try {
                        // Verify user belongs to the company
                        $user_in_company = $DB->record_exists('company_users', [
                            'userid' => $member->userid,
                            'companyid' => $company_id
                        ]);
                        
                        // Skip if user doesn't belong to company
                        if (!$user_in_company) {
                            continue;
                        }
                        
                        // Check if already enrolled
                        $already_enrolled = $DB->record_exists('user_enrolments', [
                            'enrolid' => $enrol_instance->id,
                            'userid' => $member->userid
                        ]);
                        
                        if ($already_enrolled) {
                            // Update existing enrollment
                            $enrolment = $DB->get_record('user_enrolments', [
                                'enrolid' => $enrol_instance->id,
                                'userid' => $member->userid
                            ]);
                            
                            $enrolment->timestart = $start_date;
                            $enrolment->timeend = $end_date;
                            $enrolment->timemodified = time();
                            $enrolment->modifierid = $USER->id;
                            $DB->update_record('user_enrolments', $enrolment);
                        } else {
                            // Create new enrollment
                            $enrol_manual->enrol_user($enrol_instance, $member->userid, $role->id, $start_date, $end_date);
                        }
                        
                        // Assign role
                        role_assign($role->id, $member->userid, $context->id);
                        
                        $enrolled_cohort_members_count++;
                    } catch (Exception $e) {
                        // Log but continue with other users
                        error_log("Error enrolling cohort member: " . $e->getMessage());
                    }
                }
            } catch (Exception $e) {
                $errors[] = "Error enrolling cohort {$cohort_data['name']}: " . $e->getMessage();
            }
        }
    }
    
    } // End foreach enrollment_groups
    
    $total_enrolled = $enrolled_users_count + $enrolled_cohort_members_count;
    
    if ($total_enrolled === 0) {
        throw new Exception('No users were enrolled. ' . implode(', ', $errors));
    }
    
    // Success response
    echo json_encode([
        'success' => true,
        'enrolled_users' => $enrolled_users_count,
        'enrolled_cohort_members' => $enrolled_cohort_members_count,
        'total_enrolled' => $total_enrolled,
        'errors' => $errors
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}



