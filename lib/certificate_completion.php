<?php
/**
 * Certificate Completion Handler for RemUI Kids Theme
 * 
 * This file handles course completion checking and certificate display
 * for completed courses in the RemUI Kids theme.
 * 
 * @package theme_remui_kids
 * @copyright 2025 Kodeit
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Check course completion status and trigger aggregation if needed
 * 
 * @param stdClass $course Course object
 * @param int $userid User ID
 * @return bool True if course is complete
 */
function theme_remui_kids_check_and_trigger_completion($course, $userid) {
    global $CFG, $DB;
    
    require_once($CFG->libdir . '/completionlib.php');
    
    $completion = new completion_info($course);
    
    if (!$completion->is_enabled()) {
        return false;
    }
    
    // First check if course is marked as complete in course_completions table
    $iscompleted = $completion->is_course_complete($userid);
    
    // If not marked complete, check if all activities/sections are completed
    if (!$iscompleted) {
        $modinfo = get_fast_modinfo($course);
        $sections = $modinfo->get_section_info_all();
        $all_sections_complete = true;
        $has_activities = false;
        $has_completable_activities = false; // Track if there are activities that require completion
        
        foreach ($sections as $section) {
            if ($section->section == 0) {
                continue; // Skip section 0 (general section)
            }
            
            $cms = $modinfo->get_cms();
            $section_cms = array_filter($cms, function($cm) use ($section) {
                return $cm->sectionnum == $section->section;
            });
            
            // If section has no activities, consider it as completed (no status = completed)
            if (empty($section_cms)) {
                continue; // Skip sections with no activities - they are considered completed
            }
            
            $has_activities = true;
            $section_complete = true;
            $section_has_completable_activities = false;
            
            foreach ($section_cms as $cm) {
                // Skip labels and other non-completable items
                if ($cm->modname === 'label') {
                    continue;
                }
                
                // Check if completion tracking is enabled for this activity
                $cmcompletion = $completion->is_enabled($cm);
                if ($cmcompletion == COMPLETION_TRACKING_NONE) {
                    // Activity exists but doesn't require completion - consider as completed (no status = completed)
                    continue; // Skip activities without completion tracking - they are considered completed
                }
                
                // This activity requires completion - mark that we have completable activities
                $has_completable_activities = true;
                $section_has_completable_activities = true;
                
                // Check completion state
                $completiondata = $completion->get_data($cm, false, $userid);
                if ($completiondata->completionstate != COMPLETION_COMPLETE && 
                    $completiondata->completionstate != COMPLETION_COMPLETE_PASS) {
                    $section_complete = false;
                    break;
                }
            }
            
            // If section has no completable activities (all activities have no completion tracking),
            // consider the section as completed (no status = completed)
            if (!$section_has_completable_activities) {
                // Section has activities but none require completion - consider as completed
                continue;
            }
            
            if (!$section_complete) {
                $all_sections_complete = false;
                break;
            }
        }
        
        // IMPORTANT: Only consider course complete if:
        // 1. There are activities in the course, AND
        // 2. There are activities that require completion, AND
        // 3. All completable activities are completed
        // If there are no activities OR no activities require completion, course is NOT complete
        if (!$has_activities || !$has_completable_activities) {
            // No activities or no activities require completion - course cannot be complete
            return false;
        }
        
        // If we have completable activities and all sections are complete, trigger completion aggregation
        if ($has_completable_activities && $all_sections_complete) {
            // Check if course has completion criteria configured
            $criteria = $completion->get_criteria();
            if (!empty($criteria)) {
                // Get or create completion record
                $ccompletion = new completion_completion(array(
                    'userid' => $userid,
                    'course' => $course->id
                ));
                
                // Mark as in progress to trigger reaggregation (this sets reaggregate flag and saves)
                if (!$ccompletion->timestarted) {
                    $ccompletion->mark_inprogress();
                } else if (!$ccompletion->reaggregate || $ccompletion->reaggregate == 0) {
                    // If already in progress but reaggregate flag not set, set it manually
                    if ($ccompletion->id) {
                        $DB->set_field('course_completions', 'reaggregate', time(), array('id' => $ccompletion->id));
                    }
                }
                
                // Trigger aggregation for this specific user/course
                // This will review all criteria and mark course complete if all criteria are met
                if ($ccompletion->id) {
                    require_once($CFG->libdir . '/completionlib.php');
                    aggregate_completions($ccompletion->id);
                    
                    // Re-check if course is now marked complete after aggregation
                    $ccompletion = new completion_completion(array(
                        'userid' => $userid,
                        'course' => $course->id
                    ));
                    $iscompleted = $ccompletion->is_complete();
                } else {
                    // If no completion record exists yet and there are no activities, course is NOT complete
                    // Only consider complete if we have completable activities that are all completed
                    if ($has_completable_activities && $all_sections_complete) {
                        $iscompleted = true;
                    } else {
                        $iscompleted = false;
                    }
                }
            } else {
                // No criteria configured, but all activities complete - only show as complete if we have completable activities
                if ($has_completable_activities && $all_sections_complete) {
                    $iscompleted = true;
                } else {
                    $iscompleted = false;
                }
            }
        } else {
            // Not all sections complete or no completable activities
            $iscompleted = false;
        }
    }
    
    return $iscompleted;
}

/**
 * Get certificate status check link HTML
 * 
 * @param stdClass $course Course object
 * @return string HTML for certificate status check link
 */
function theme_remui_kids_get_certificate_status_check_link($course) {
    global $CFG;
    
    $checkurl = new moodle_url('/theme/remui_kids/check_certificate.php', array('courseid' => $course->id));
    
    $html = '<div style="margin: 15px 0; padding: 12px; background: #f0f9ff; border-radius: 8px; border-left: 4px solid #3b82f6;">';
    $html .= '<a href="' . $checkurl->out() . '" style="color: #1e40af; text-decoration: none; font-weight: 600; display: inline-flex; align-items: center; gap: 8px;">';
    $html .= '<i class="fa fa-info-circle"></i> Check Certificate Status';
    $html .= '</a>';
    $html .= '<p style="margin: 8px 0 0 0; color: #64748b; font-size: 14px;">See why your certificate is available or what you need to complete</p>';
    $html .= '</div>';
    
    return $html;
}

/**
 * Get certificate completion card HTML
 * 
 * @param stdClass $course Course object
 * @param int $userid User ID
 * @return string HTML for certificate completion card
 */
function theme_remui_kids_get_certificate_completion_card($course, $userid) {
    global $CFG, $USER, $OUTPUT, $DB;
    
    require_once($CFG->libdir . '/completionlib.php');
    
    // First verify completion is enabled
    $completion = new completion_info($course);
    if (!$completion->is_enabled()) {
        return '';
    }
    $coursecompleted = $completion->is_course_complete($userid);
    
    // EARLY CHECK: Verify course has activities before checking completion
    // If course has 0 activities, it cannot be completed
    $modinfo = get_fast_modinfo($course);
    $sections = $modinfo->get_section_info_all();
    $has_any_activities = false;
    $has_completable_activities_check = false;
    
    foreach ($sections as $section) {
        if ($section->section == 0) {
            continue; // Skip section 0 (general section)
        }
        
        $cms = $modinfo->get_cms();
        $section_cms = array_filter($cms, function($cm) use ($section) {
            return $cm->sectionnum == $section->section;
        });
        
        if (empty($section_cms)) {
            continue; // Skip sections with no activities
        }
        
        $has_any_activities = true;
        
        foreach ($section_cms as $cm) {
            // Skip labels and other non-completable items
            if ($cm->modname === 'label') {
                continue;
            }
            
            // Check if completion tracking is enabled for this activity
            $cmcompletion = $completion->is_enabled($cm);
            if ($cmcompletion != COMPLETION_TRACKING_NONE) {
                $has_completable_activities_check = true;
                break 2; // Found at least one completable activity, break out of both loops
            }
        }
    }
    
    // STRICT REQUIREMENT: If course has no activities or no completable activities, do NOT show certificate
    // Zero activities = course not completed (no certificate should be shown)
    if (!$has_any_activities || !$has_completable_activities_check) {
        // Course has zero activities - do NOT show certificate
        return '';
    }
    
    // CRITICAL CHECK: Verify that ALL sections and ALL activities are completed FIRST
    // This check happens BEFORE database check to ensure strict validation
    // Certificate should ONLY show when EVERY section and EVERY activity requiring completion is done
    // This check happens REGARDLESS of whether course is marked complete in database
    // This ensures that even if course is marked complete, we verify all sections are actually complete
    $modinfo = get_fast_modinfo($course);
    $sections = $modinfo->get_section_info_all();
    $has_completable_activities = false;
    $all_sections_complete = true;
    $incomplete_sections = array();
    $total_completable_activities = 0;
    $completed_completable_activities = 0;
    
    // Check each section individually to ensure ALL are 100% complete
    // IMPORTANT: If a section exists and has 0 activities or 0 completion, course is NOT complete
    foreach ($sections as $section) {
        if ($section->section == 0) {
            continue; // Skip section 0 (general section)
        }
        
        $cms = $modinfo->get_cms();
        $section_cms = array_filter($cms, function($cm) use ($section) {
            return $cm->sectionnum == $section->section;
        });
        
        // Count activities in this section (excluding labels)
        $section_activity_count = 0;
        $section_completable_count = 0;
        $section_completed_count = 0;
        $section_has_completable_activities = false;
        $section_complete = true;
        $section_incomplete_activities = array();
        
        // Check every activity in this section
        foreach ($section_cms as $cm) {
            // Skip labels and other non-completable items
            if ($cm->modname === 'label') {
                continue;
            }
            
            // Count all activities (for display purposes)
            $section_activity_count++;
            
            // Check if completion tracking is enabled for this activity
            $cmcompletion = $completion->is_enabled($cm);
            if ($cmcompletion == COMPLETION_TRACKING_NONE) {
                // Activity without completion tracking - doesn't require completion
                continue;
            }
            
            // This activity requires completion
            $has_completable_activities = true;
            $section_has_completable_activities = true;
            $section_completable_count++;
            $total_completable_activities++;
            
            // Check completion state
            $completiondata = $completion->get_data($cm, false, $userid);
            if ($completiondata->completionstate == COMPLETION_COMPLETE || 
                $completiondata->completionstate == COMPLETION_COMPLETE_PASS) {
                $completed_completable_activities++;
                $section_completed_count++;
            } else {
                $section_complete = false;
                $section_incomplete_activities[] = $cm->name;
                // Don't break - continue checking all activities to get full picture
            }
        }
        
        // CRITICAL: If section has 0 activities total, mark course as incomplete
        // This ensures "0 activities" = course not completed
        // BUT: If section has activities but 0 completable activities, that's OK (nothing to complete)
        if ($section_activity_count == 0) {
            // Section exists but has 0 activities - course is not complete
            $all_sections_complete = false;
            $sectionname = $section->name ?: "Section " . $section->section;
            $incomplete_sections[] = array(
                'name' => $sectionname,
                'section' => $section->section,
                'reason' => 'Section has 0 activities',
                'incomplete_activities' => array()
            );
            continue;
        }
        
        // If section has completable activities, check if they're all completed
        if ($section_has_completable_activities) {
            // Section has activities that require completion - check if all are completed
            if ($section_completed_count == 0) {
                // Section has completable activities but 0 completed - course is not complete
                $all_sections_complete = false;
                $sectionname = $section->name ?: "Section " . $section->section;
                $incomplete_sections[] = array(
                    'name' => $sectionname,
                    'section' => $section->section,
                    'reason' => 'Section has 0 completed activities',
                    'incomplete_activities' => $section_incomplete_activities
                );
                continue;
            }
            
            // If section has completable activities but is not complete, mark it
            if (!$section_complete) {
                $all_sections_complete = false;
                $sectionname = $section->name ?: "Section " . $section->section;
                $incomplete_sections[] = array(
                    'name' => $sectionname,
                    'section' => $section->section,
                    'reason' => 'Section has incomplete activities',
                    'incomplete_activities' => $section_incomplete_activities
                );
            }
        }
        // If section has activities but no completable activities, it's considered complete
        // (nothing requires completion, so section is effectively complete)
    }
    
    // STRICT REQUIREMENT: Certificate only shows if:
    // 1. There ARE completable activities in the course (0 activities = not completed)
    // 2. ALL completable activities are completed (0 completion = not completed)
    // 3. ALL sections with completable activities are 100% complete (every activity in every section is done)
    // If there are NO completable activities (0 activities), do NOT show certificate
    // This check applies REGARDLESS of course completion status - zero activities = no certificate
    if (!$has_completable_activities || $total_completable_activities == 0) {
        // No activities or no activities require completion - course cannot be complete
        // Zero activities = course not done, do NOT show certificate
        return '';
    }
    
    // If 0 activities are completed, do NOT show certificate
    if ($completed_completable_activities == 0) {
        // 0 completion = course not completed
        return '';
    }
    
    // PRIMARY CHECK: Certificate ONLY shows when ALL activities requiring completion are completed
    // This is the STRICT criteria - certificate appears ONLY when:
    // 1. Course has completable activities (not zero)
    // 2. ALL completable activities are completed (100% completion)
    // 3. ALL sections are complete
    // Zero activities = course not done = no certificate
    $all_activities_completed = false;
    if ($all_sections_complete && 
        $has_completable_activities && 
        $total_completable_activities > 0 &&
        $completed_completable_activities == $total_completable_activities) {
        // All activities requiring completion are completed - show certificate
        $all_activities_completed = true;
    }
    
    // STRICT CHECK: If ANY section is incomplete OR zero activities, do NOT show certificate card
    if (!$all_sections_complete) {
        // Log incomplete sections for debugging
        if (!empty($incomplete_sections)) {
            error_log('Certificate card hidden - incomplete sections: ' . json_encode($incomplete_sections));
        } else {
            error_log('Certificate card hidden - all_sections_complete is false but no incomplete_sections array');
        }
        // Not all sections/activities are completed - don't show certificate
        return '';
    }
    
    // FINAL CHECK: Certificate ONLY shows if ALL activities are completed
    // No exceptions - zero activities or incomplete activities = no certificate
    if ($all_activities_completed) {
        // All activities completed - show certificate immediately
        // Try to update database in background (non-blocking)
        try {
            $completion_record = $DB->get_record('course_completions', array(
                'userid' => $userid,
                'course' => $course->id
            ));
            
            $ccompletion = new completion_completion(array('userid' => $userid, 'course' => $course->id));
            if (!$ccompletion->id) {
                $ccompletion->mark_inprogress();
            }
            if ($ccompletion->id && (!$ccompletion->reaggregate || $ccompletion->reaggregate == 0)) {
                $DB->set_field('course_completions', 'reaggregate', time(), array('id' => $ccompletion->id));
            }
            if ($ccompletion->id) {
                require_once($CFG->libdir . '/completionlib.php');
                aggregate_completions($ccompletion->id);
            }
        } catch (Exception $e) {
            // Log but continue - we'll show certificate anyway since all activities are completed
            error_log('Certificate completion aggregation error: ' . $e->getMessage());
        }
        
        // Proceed to show certificate - all activities are completed
    } else {
        // Not all activities completed - don't show certificate
        return '';
    }
    
    // Check if there's a customcert activity in this course
    $customcert_cmid = null;
    $customcert_issue = null;
    if ($DB->get_manager()->table_exists('customcert')) {
        $modinfo = get_fast_modinfo($course);
        $cms = $modinfo->get_cms();
        
        foreach ($cms as $cm) {
            if ($cm->modname === 'customcert' && $cm->uservisible) {
                $customcert_cmid = $cm->id;
                
                // Check if certificate is issued for this user
                $customcert_instance = $DB->get_record('customcert', array('id' => $cm->instance));
                if ($customcert_instance) {
                    $customcert_issue = $DB->get_record('customcert_issues', array(
                        'customcertid' => $customcert_instance->id,
                        'userid' => $userid
                    ));
                }
                break; // Use first customcert found
            }
        }
    }
    
    // Also check for custom certificate from certificate approval system
    $approval_certificate = null;
    if ($DB->get_manager()->table_exists('mod_certificate_approval_instances')) {
        $approval_certificate = $DB->get_record('mod_certificate_approval_instances', array(
            'user_id' => $userid,
            'course_id' => $course->id,
            'status' => 'PUBLISHED'
        ));
    }
    
    // Show certificate card if either customcert or approval certificate exists
    $has_customcert = ($customcert_cmid && $customcert_issue);
    $has_approval_cert = ($approval_certificate !== false);
    
    if (!$has_customcert && !$has_approval_cert) {
        // No certificate available - don't show card
        return '';
    }
    
    // Build certificate card HTML with CSS
    $html = theme_remui_kids_get_certificate_completion_css();
    $html .= '<div class="certificate-completion-card-wrapper">';
    $html .= '<div class="certificate-completion-card">';
    $html .= '<div class="certificate-card-icon">';
    $html .= '<i class="fa fa-certificate"></i>';
    $html .= '</div>';
    $html .= '<div class="certificate-card-content">';
    $html .= '<div class="certificate-card-text">';
    $html .= '<h3 class="certificate-card-title">';
    $html .= '<i class="fa fa-check-circle"></i>';
    $html .= get_string('course_completed', 'core') . '!';
    $html .= '</h3>';
    
    $html .= '<p class="certificate-card-subtitle">Your certificate is ready for download.</p>';
    
    // Add status check link
    $checkurl = new moodle_url('/theme/remui_kids/check_certificate.php', array('courseid' => $course->id));
    $html .= '<a href="' . $checkurl->out() . '" style="font-size: 12px; color: rgba(255,255,255,0.8); text-decoration: none; margin-top: 8px; display: inline-block;">';
    $html .= '<i class="fa fa-info-circle"></i> View certificate details';
    $html .= '</a>';
    
    $html .= '</div>';
    $html .= '</div>';
    $html .= '<div class="certificate-card-action">';
    $html .= '<div class="certificate-action-buttons">';
    
    // Determine which certificate type to use (priority: customcert > approval certificate)
    if ($has_customcert && $customcert_issue) {
        // CustomCert View URL - opens certificate in browser
        $viewurl = new moodle_url('/local/customcert/view.php', array(
            'id' => $customcert_cmid,
            'issueid' => $customcert_issue->id
        ));
        
        // CustomCert Download URL - download PDF using issue ID
        // Try multiple methods to ensure download works
        // Method 1: Use view.php with issueid (most reliable)
        $downloadurl = new moodle_url('/local/customcert/view.php', array(
            'id' => $customcert_cmid,
            'issueid' => $customcert_issue->id
        ));
        
        // View button for customcert - opens in browser
        $html .= html_writer::link($viewurl, 
            html_writer::tag('i', '', array('class' => 'fa fa-eye')) . ' ' . get_string('view', 'core'),
            array('class' => 'certificate-view-btn', 'target' => '_blank', 'title' => get_string('view', 'core'))
        );
        
        // Download button for customcert - students can download from view page or use browser download
        // The view.php page will show the certificate PDF which can be downloaded via browser
        $html .= html_writer::link($downloadurl, 
            html_writer::tag('i', '', array('class' => 'fa fa-download')) . ' ' . get_string('download', 'core'),
            array('class' => 'certificate-download-btn', 'target' => '_blank', 'title' => get_string('download', 'core'), 
                  'download' => 'certificate.pdf')
        );
    } else if ($has_approval_cert) {
        // Certificate Approval System - Direct PDF download
        $downloadurl = new moodle_url('/local/certificate_approval/pdf/generate_pdf.php', array(
            'id' => $approval_certificate->id,
            'sesskey' => sesskey()
        ));
        
        // Download button for approval certificate
        $html .= html_writer::link($downloadurl, 
            html_writer::tag('i', '', array('class' => 'fa fa-download')) . ' ' . get_string('download', 'core'),
            array('class' => 'certificate-download-btn', 'target' => '_blank', 'title' => get_string('download', 'core'))
        );
    }
    
    $html .= '</div>';
    
    $html .= '</div>';
    $html .= '</div>';
    $html .= '</div>'; // Close wrapper
    
    return $html;
}

/**
 * Get certificate information for a course (for section view)
 * Returns certificate data if available, null otherwise
 * 
 * @param stdClass $course Course object
 * @param int $userid User ID
 * @return array|null Certificate info array with view_url, download_url, and available flag
 */
function theme_remui_kids_get_course_certificate_info($course, $userid) {
    global $CFG, $DB, $USER;
    
    require_once($CFG->libdir . '/completionlib.php');
    require_once($CFG->libdir . '/enrollib.php');
    
    // Check if student is enrolled in the course
    $coursecontext = context_course::instance($course->id);
    if (!is_enrolled($coursecontext, $userid, '', true)) {
        return null; // Not enrolled or enrollment not active
    }
    
    // Check if course completion is enabled
    $completioninfo = new completion_info($course);
    if (!$completioninfo->is_enabled()) {
        return null; // Completion not enabled
    }
    
    // Check if the course is marked as completed
    $completion = $DB->get_record('course_completions', array(
        'userid' => $userid,
        'course' => $course->id
    ));
    
    // Course must have a completion record with timecompleted
    if (!$completion || $completion->timecompleted === null) {
        return null; // Course not completed
    }
    
    // Check if there's a customcert activity in this course
    $customcert_cmid = null;
    $customcert_issue = null;
    $customcert_instance = null;
    $has_customcert_activity = false;
    
    if ($DB->get_manager()->table_exists('customcert')) {
        $modinfo = get_fast_modinfo($course);
        $cms = $modinfo->get_cms();
        
        foreach ($cms as $cm) {
            if ($cm->modname === 'customcert' && $cm->uservisible) {
                $has_customcert_activity = true;
                $customcert_cmid = $cm->id;
                
                // Check if certificate is issued for this user
                $customcert_instance = $DB->get_record('customcert', array('id' => $cm->instance));
                if ($customcert_instance) {
                    $customcert_issue = $DB->get_record('customcert_issues', array(
                        'customcertid' => $customcert_instance->id,
                        'userid' => $userid
                    ));
                }
                break; // Use first customcert found
            }
        }
    }
    
    // Only show certificate section if Custom certificate activity is created
    if (!$has_customcert_activity) {
        return null; // No customcert activity, don't show certificate section
    }
    
    // Return certificate info if customcert is issued
    if ($customcert_cmid && $customcert_issue && $customcert_instance) {
        // CustomCert certificate available
        $viewurl = new moodle_url('/local/customcert/view.php', array(
            'id' => $customcert_cmid,
            'issueid' => $customcert_issue->id
        ));
        
        return array(
            'available' => true,
            'type' => 'customcert',
            'view_url' => $viewurl->out(),
            'download_url' => $viewurl->out(),
            'certificate_name' => $customcert_instance->name ?? 'Course Completion Certificate'
        );
    }
    
    return null; // Customcert activity exists but certificate not yet issued
}

/**
 * Get certificate completion card CSS
 * 
 * @return string CSS for certificate completion card
 */
function theme_remui_kids_get_certificate_completion_css() {
    return '<style>
        .certificate-completion-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 16px;
            padding: 24px 32px;
            margin: 24px auto;
            max-width: 1200px;
            box-shadow: 0 4px 20px rgba(102, 126, 234, 0.3);
            color: #ffffff;
            display: flex !important;
            align-items: center;
            justify-content: space-between;
            position: relative;
            overflow: hidden;
            z-index: 1000;
        }
        
        #page-course-view .certificate-completion-card-wrapper {
            width: 100%;
            padding: 0 15px;
            margin-top: 20px;
        }
        
        .certificate-completion-card::before {
            content: "";
            position: absolute;
            top: -50%;
            right: -50px;
            width: 200px;
            height: 200px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
        }
        
        .certificate-completion-card::after {
            content: "";
            position: absolute;
            bottom: -30px;
            left: -30px;
            width: 150px;
            height: 150px;
            background: rgba(255, 255, 255, 0.08);
            border-radius: 50%;
        }
        
        .certificate-card-content {
            flex: 1;
            position: relative;
            z-index: 1;
        }
        
        .certificate-card-icon {
            width: 64px;
            height: 64px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            margin-right: 20px;
            flex-shrink: 0;
        }
        
        .certificate-card-text {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .certificate-card-title {
            font-size: 24px;
            font-weight: 700;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .certificate-card-subtitle {
            font-size: 14px;
            opacity: 0.9;
            margin: 0;
        }
        
        .certificate-card-action {
            position: relative;
            z-index: 1;
            flex-shrink: 0;
        }
        
        .certificate-action-buttons {
            display: flex !important;
            gap: 12px;
            align-items: center;
            flex-wrap: nowrap;
        }
        
        .certificate-view-btn,
        .certificate-download-btn {
            background: #ffffff;
            color: #667eea;
            border: none;
            border-radius: 12px;
            padding: 12px 24px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
            white-space: nowrap;
        }
        
        .certificate-view-btn:hover,
        .certificate-download-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
            color: #764ba2;
            text-decoration: none;
        }
        
        .certificate-view-btn i,
        .certificate-download-btn i {
            font-size: 18px;
        }
        
        @media (max-width: 768px) {
            .certificate-completion-card {
                flex-direction: column;
                align-items: flex-start;
                gap: 16px;
            }
            
            .certificate-card-icon {
                margin-right: 0;
                margin-bottom: 12px;
            }
            
            .certificate-card-action {
                width: 100%;
            }
            
            .certificate-action-buttons {
                flex-direction: column;
                width: 100%;
            }
            
            .certificate-view-btn,
            .certificate-download-btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>';
}

/**
 * Get all certificates assigned, viewed, or available to a student
 * 
 * This function fetches all certificates across all certificate types:
 * - CustomCert certificates
 * - Certificate Approval System certificates
 * - IOMad certificates
 * - Track certificates (local_iomad_track_certs)
 * 
 * @param int $userid User ID (defaults to current user)
 * @param bool $include_course_info Whether to include course information
 * @return array Array of certificate records with details
 */
function theme_remui_kids_get_student_certificates($userid = null, $include_course_info = true) {
    global $USER, $DB, $CFG;
    
    if ($userid === null) {
        $userid = $USER->id;
    }
    
    $certificates = array();
    
    // Get all enrolled courses for the student
    require_once($CFG->libdir . '/enrollib.php');
    $enrolled_courses = enrol_get_all_users_courses($userid, true);
    $course_ids = array_keys($enrolled_courses);
    
    if (empty($course_ids)) {
        return $certificates;
    }
    
    list($course_sql, $course_params) = $DB->get_in_or_equal($course_ids, SQL_PARAMS_NAMED);
    
    // 1. Fetch CustomCert certificates
    if ($DB->get_manager()->table_exists('customcert')) {
        $sql = "SELECT ci.id as certificate_id,
                       ci.customcertid,
                       ci.userid,
                       ci.timecreated as issued_date,
                       ci.code as certificate_code,
                       c.id as course_id,
                       c.fullname as course_name,
                       c.shortname as course_shortname,
                       cm.id as cmid,
                       cert.name as certificate_name,
                       'customcert' as certificate_type
                FROM {customcert_issues} ci
                JOIN {customcert} cert ON cert.id = ci.customcertid
                JOIN {course_modules} cm ON cm.instance = cert.id AND cm.module = (
                    SELECT id FROM {modules} WHERE name = 'customcert'
                )
                JOIN {course} c ON c.id = cert.course
                WHERE ci.userid = :userid
                AND cert.course {$course_sql}
                ORDER BY ci.timecreated DESC";
        
        $params = array_merge(array('userid' => $userid), $course_params);
        $customcert_records = $DB->get_records_sql($sql, $params);
        
        foreach ($customcert_records as $record) {
            $certificates[] = array(
                'id' => $record->certificate_id,
                'type' => 'customcert',
                'certificate_name' => $record->certificate_name,
                'course_id' => $record->course_id,
                'course_name' => $record->course_name,
                'course_shortname' => $record->course_shortname,
                'issued_date' => $record->issued_date,
                'certificate_code' => $record->certificate_code,
                'view_url' => new moodle_url('/local/customcert/view.php', array('id' => $record->cmid)),
                'download_url' => new moodle_url('/local/customcert/view.php', array(
                    'id' => $record->cmid,
                    'action' => 'download'
                )),
                'status' => 'issued',
                'course_info' => $include_course_info ? $enrolled_courses[$record->course_id] : null
            );
        }
    }
    
    // 2. Fetch Certificate Approval System certificates
    if ($DB->get_manager()->table_exists('mod_certificate_approval_instances')) {
        $sql = "SELECT cai.id as certificate_id,
                       cai.user_id,
                       cai.course_id,
                       cai.status,
                       cai.created_at as issued_date,
                       cai.updated_at as updated_date,
                       c.id as course_db_id,
                       c.fullname as course_name,
                       c.shortname as course_shortname,
                       'approval' as certificate_type
                FROM {mod_certificate_approval_instances} cai
                JOIN {course} c ON c.id = cai.course_id
                WHERE cai.user_id = :userid
                AND cai.course_id {$course_sql}
                AND cai.status = 'PUBLISHED'
                ORDER BY cai.created_at DESC";
        
        $params = array_merge(array('userid' => $userid), $course_params);
        $approval_records = $DB->get_records_sql($sql, $params);
        
        foreach ($approval_records as $record) {
            $certificates[] = array(
                'id' => $record->certificate_id,
                'type' => 'approval',
                'certificate_name' => $record->course_name . ' Certificate',
                'course_id' => $record->course_id,
                'course_name' => $record->course_name,
                'course_shortname' => $record->course_shortname,
                'issued_date' => $record->issued_date,
                'updated_date' => $record->updated_date,
                'status' => strtolower($record->status),
                'download_url' => new moodle_url('/local/certificate_approval/pdf/generate_pdf.php', array(
                    'id' => $record->certificate_id
                )),
                'download_url_needs_sesskey' => true, // Flag to indicate sesskey needs to be added when using URL
                'course_info' => $include_course_info ? $enrolled_courses[$record->course_id] : null
            );
        }
    }
    
    // 3. Fetch IOMad certificates
    if ($DB->get_manager()->table_exists('iomadcertificate_issues')) {
        $sql = "SELECT ci.id as certificate_id,
                       ci.iomadcertificateid,
                       ci.userid,
                       ci.timecreated as issued_date,
                       ci.code as certificate_code,
                       cert.name as certificate_name,
                       cert.course as course_id,
                       c.fullname as course_name,
                       c.shortname as course_shortname,
                       cm.id as cmid,
                       'iomadcertificate' as certificate_type
                FROM {iomadcertificate_issues} ci
                JOIN {iomadcertificate} cert ON cert.id = ci.iomadcertificateid
                JOIN {course_modules} cm ON cm.instance = cert.id AND cm.module = (
                    SELECT id FROM {modules} WHERE name = 'iomadcertificate'
                )
                JOIN {course} c ON c.id = cert.course
                WHERE ci.userid = :userid
                AND cert.course {$course_sql}
                ORDER BY ci.timecreated DESC";
        
        $params = array_merge(array('userid' => $userid), $course_params);
        $iomadcert_records = $DB->get_records_sql($sql, $params);
        
        foreach ($iomadcert_records as $record) {
            $certificates[] = array(
                'id' => $record->certificate_id,
                'type' => 'iomadcertificate',
                'certificate_name' => $record->certificate_name,
                'course_id' => $record->course_id,
                'course_name' => $record->course_name,
                'course_shortname' => $record->course_shortname,
                'issued_date' => $record->issued_date,
                'certificate_code' => $record->certificate_code,
                'view_url' => new moodle_url('/local/iomadcertificate/view.php', array('id' => $record->cmid)),
                'status' => 'issued',
                'course_info' => $include_course_info ? $enrolled_courses[$record->course_id] : null
            );
        }
    }
    
    // 4. Fetch Track certificates (local_iomad_track_certs)
    if ($DB->get_manager()->table_exists('local_iomad_track_certs') && 
        $DB->get_manager()->table_exists('local_iomad_track')) {
        $sql = "SELECT tc.id as certificate_id,
                       tc.trackid,
                       tc.filename,
                       t.userid,
                       t.courseid as course_id,
                       t.timecompleted as issued_date,
                       c.fullname as course_name,
                       c.shortname as course_shortname,
                       'track' as certificate_type
                FROM {local_iomad_track_certs} tc
                JOIN {local_iomad_track} t ON t.id = tc.trackid
                JOIN {course} c ON c.id = t.courseid
                WHERE t.userid = :userid
                AND t.courseid {$course_sql}
                AND t.timecompleted IS NOT NULL
                ORDER BY t.timecompleted DESC";
        
        $params = array_merge(array('userid' => $userid), $course_params);
        $track_records = $DB->get_records_sql($sql, $params);
        
        foreach ($track_records as $record) {
            $usercontext = context_user::instance($userid);
            $certurl = moodle_url::make_file_url('/pluginfile.php', 
                '/'.$usercontext->id.'/local_iomad_track/issue/'.$record->trackid.'/'.$record->filename);
            
            $certificates[] = array(
                'id' => $record->certificate_id,
                'type' => 'track',
                'certificate_name' => $record->course_name . ' Certificate',
                'course_id' => $record->course_id,
                'course_name' => $record->course_name,
                'course_shortname' => $record->course_shortname,
                'issued_date' => $record->issued_date,
                'filename' => $record->filename,
                'download_url' => $certurl,
                'status' => 'issued',
                'course_info' => $include_course_info ? $enrolled_courses[$record->course_id] : null
            );
        }
    }
    
    // Sort all certificates by issued date (most recent first)
    usort($certificates, function($a, $b) {
        $date_a = isset($a['issued_date']) ? $a['issued_date'] : 0;
        $date_b = isset($b['issued_date']) ? $b['issued_date'] : 0;
        return $date_b - $date_a;
    });
    
    return $certificates;
}

/**
 * Get certificates count for a student
 * 
 * @param int $userid User ID (defaults to current user)
 * @return array Array with counts by type and total
 */
function theme_remui_kids_get_student_certificates_count($userid = null) {
    $certificates = theme_remui_kids_get_student_certificates($userid, false);
    
    $counts = array(
        'total' => count($certificates),
        'customcert' => 0,
        'approval' => 0,
        'iomadcertificate' => 0,
        'track' => 0
    );
    
    foreach ($certificates as $cert) {
        if (isset($counts[$cert['type']])) {
            $counts[$cert['type']]++;
        }
    }
    
    return $counts;
}

