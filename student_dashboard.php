<?php
require_once('config.php');
require_once(__DIR__ . '/lang_init.php'); // Apply user's selected language
require_login();

global $USER, $DB, $CFG, $OUTPUT, $PAGE;

// Include completion library
require_once($CFG->libdir . '/completionlib.php');

// Set up page context
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/student_dashboard.php');
$PAGE->set_title('G4G7 Student Dashboard');
$PAGE->set_heading('G4G7 Dashboard Learning Platform');

$showstudypartnercta = get_config('local_studypartner', 'showstudentnav');
if ($showstudypartnercta === null) {
    // Default to visible if the setting hasn't been created yet.
    $showstudypartnercta = true;
} else {
    $showstudypartnercta = (bool)$showstudypartnercta;
}
// Check if user has the capability to view Study Partner (only if capability exists)
$hasstudypartnercapability = get_capability_info('local/studypartner:view') && has_capability('local/studypartner:view', $context);
// Only show if both config is enabled AND user has capability
$showstudypartnercta = $showstudypartnercta && $hasstudypartnercapability;
$studypartnerurl = new moodle_url('/local/studypartner/index.php');
$showdiagnosticcta = has_capability('moodle/site:config', $context);
$studypartnerdiagurl = new moodle_url('/theme/remui_kids/study_partner_diagnostic.php');

// ========================================
// FETCH REAL DATA FOR DASHBOARD
// ========================================

// Get enrolled courses count and in-progress
$enrolled_courses = enrol_get_users_courses($USER->id, true);
$total_courses = count($enrolled_courses);
$in_progress_courses = 0;
foreach ($enrolled_courses as $course) {
    $completion = new completion_info($course);
    $progress = $completion->get_progress($USER->id);
    if ($progress > 0 && $progress < 100) {
        $in_progress_courses++;
    }
}

// Get lessons/completions count (this month)
$start_of_month = strtotime('first day of this month 00:00:00');
$completed_lessons = 0;
// Count completed course modules this month
foreach ($enrolled_courses as $course) {
    $completion = new completion_info($course);
    $completions = $completion->get_completions($USER->id);
    foreach ($completions as $comp) {
        if ($comp->timecompleted && $comp->timecompleted >= $start_of_month) {
            $completed_lessons++;
        }
    }
}

// Calculate study time (this week) - approximate from log entries
$start_of_week = strtotime('monday this week 00:00:00');
$study_time_hours = 0;
try {
    $log_entries = $DB->get_records_sql(
        "SELECT COUNT(*) as count
         FROM {logstore_standard_log} 
         WHERE userid = ? 
         AND timecreated >= ?
         AND action = 'viewed'
         AND component != 'core'",
        [$USER->id, $start_of_week]
    );
    // Rough estimate: each log entry = ~5 minutes
    if (!empty($log_entries)) {
        $entry = reset($log_entries);
        $study_time_hours = round(($entry->count * 5) / 60, 1);
    }
} catch (Exception $e) {
    // Fallback if log table doesn't exist
    $study_time_hours = 0;
}

// Get achievements/badges count
$total_badges = 0;
try {
    $badges = $DB->get_records_sql(
        "SELECT COUNT(DISTINCT bi.badgeid) as count
         FROM {badge_issued} bi
         WHERE bi.userid = ?",
        [$USER->id]
    );
    if (!empty($badges)) {
        $badge = reset($badges);
        $total_badges = $badge->count;
    }
} catch (Exception $e) {
    $total_badges = 0;
}

// Calculate overall progress percentage
$overall_progress = 0;
$total_progress = 0;
foreach ($enrolled_courses as $course) {
    $completion = new completion_info($course);
    $progress = $completion->get_progress($USER->id);
    $total_progress += $progress;
}
if ($total_courses > 0) {
    $overall_progress = round($total_progress / $total_courses);
}

// Calculate points (based on activities, assignments, quizzes)
$total_points = 0;
foreach ($enrolled_courses as $course) {
    // Points from quizzes
    $quiz_points = $DB->get_records_sql(
        "SELECT SUM(qa.sumgrades) as points
         FROM {quiz_attempts} qa
         JOIN {quiz} q ON qa.quiz = q.id
         JOIN {course_modules} cm ON cm.instance = q.id
         WHERE qa.userid = ? AND qa.state = 'finished' AND q.course = ?",
        [$USER->id, $course->id]
    );
    if (!empty($quiz_points)) {
        $qp = reset($quiz_points);
        $total_points += round($qp->points ? $qp->points : 0);
    }
    
    // Points from assignments (simplified calculation)
    $assign_count = $DB->count_records_sql(
        "SELECT COUNT(DISTINCT a.id)
         FROM {assign} a
         JOIN {assign_submission} asub ON asub.assignment = a.id
         WHERE a.course = ? AND asub.userid = ? AND asub.status = 'submitted'",
        [$course->id, $USER->id]
    );
    $total_points += $assign_count * 50; // 50 points per assignment
}

// ========================================
// FETCH LEADERBOARD DATA (Top Students)
// ========================================
$leaderboard_students = [];

try {
    // Get student's enrolled courses
    $student_courses = enrol_get_users_courses($USER->id, true);
    $student_courseids = array_keys($student_courses);
    
    if (!empty($student_courseids)) {
        // Get student role
        $student_role = $DB->get_record('role', ['shortname' => 'student']);
        
        if ($student_role) {
            // Get all students from the same courses
            list($course_insql, $course_params) = $DB->get_in_or_equal($student_courseids, SQL_PARAMS_NAMED, 'course');
            
            // Fetch all students with activity - include all fields required for user_picture
            $picture_fields = \core_user\fields::get_picture_fields();
            $picture_fields_sql = 'u.' . implode(', u.', $picture_fields);
            $sql = "SELECT DISTINCT $picture_fields_sql
                    FROM {user} u
                    INNER JOIN {user_enrolments} ue ON ue.userid = u.id
                    INNER JOIN {enrol} e ON e.id = ue.enrolid
                    INNER JOIN {role_assignments} ra ON ra.userid = u.id
                    INNER JOIN {context} ctx ON ra.contextid = ctx.id AND ctx.contextlevel = 50
                    WHERE e.courseid $course_insql
                    AND e.courseid = ctx.instanceid
                    AND ra.roleid = :roleid
                    AND u.deleted = 0
                    AND u.suspended = 0
                    AND ue.status = 0
                    ORDER BY u.firstname, u.lastname";
            
            $students = $DB->get_records_sql($sql, array_merge($course_params, ['roleid' => $student_role->id]));
            
            // Calculate performance for each student (using same logic as parent dashboard)
            foreach ($students as $student) {
                $total_score = 0;
                $grade_avg = 0;
                $grade_count = 0;
                $comp_rate = 0;
                $assign_rate = 0;
                $quiz_avg = 0;
                
                // 1. GRADE AVERAGE (40% weight)
                foreach ($student_courseids as $cid) {
                    $grade_item = $DB->get_record('grade_items', [
                        'courseid' => $cid,
                        'itemtype' => 'course'
                    ]);
                    
                    if ($grade_item) {
                        $grade = $DB->get_record('grade_grades', [
                            'itemid' => $grade_item->id,
                            'userid' => $student->id
                        ]);
                        
                        if ($grade && $grade->finalgrade !== null && $grade_item->grademax > 0) {
                            $grade_percent = ($grade->finalgrade / $grade_item->grademax) * 100;
                            $grade_avg += $grade_percent;
                            $grade_count++;
                        }
                    }
                }
                
                if ($grade_count > 0) {
                    $grade_avg = $grade_avg / $grade_count;
                    $total_score += $grade_avg * 0.4;
                }
                
                // 2. COMPETENCY PROFICIENCY (30% weight)
                $student_params = array_merge(['userid' => $student->id], $course_params);
                
                $total_comps = $DB->count_records_sql(
                    "SELECT COUNT(DISTINCT cc.competencyid)
                     FROM {competency_coursecomp} cc
                     WHERE cc.courseid $course_insql",
                    $course_params
                );
                
                $proficient_comps = $DB->count_records_sql(
                    "SELECT COUNT(DISTINCT ucc.competencyid)
                     FROM {competency_usercompcourse} ucc
                     WHERE ucc.userid = :userid
                     AND ucc.courseid $course_insql
                     AND ucc.proficiency = 1",
                    $student_params
                );
                
                if ($total_comps > 0) {
                    $comp_rate = ($proficient_comps / $total_comps) * 100;
                    $total_score += $comp_rate * 0.3;
                }
                
                // 3. ASSIGNMENT COMPLETION (15% weight)
                $total_assigns = $DB->count_records_sql(
                    "SELECT COUNT(a.id)
                     FROM {assign} a
                     JOIN {course_modules} cm ON cm.instance = a.id
                     JOIN {modules} m ON m.id = cm.module AND m.name = 'assign'
                     WHERE a.course $course_insql
                     AND cm.deletioninprogress = 0",
                    $course_params
                );
                
                $completed_assigns = $DB->count_records_sql(
                    "SELECT COUNT(DISTINCT a.id)
                     FROM {assign} a
                     JOIN {course_modules} cm ON cm.instance = a.id
                     JOIN {modules} m ON m.id = cm.module AND m.name = 'assign'
                     JOIN {assign_submission} asub ON asub.assignment = a.id
                     WHERE a.course $course_insql
                     AND cm.deletioninprogress = 0
                     AND asub.userid = :userid
                     AND asub.status = 'submitted'",
                    $student_params
                );
                
                if ($total_assigns > 0) {
                    $assign_rate = ($completed_assigns / $total_assigns) * 100;
                    $total_score += $assign_rate * 0.15;
                }
                
                // 4. QUIZ PERFORMANCE (15% weight)
                $quiz_count = 0;
                $quiz_attempts = $DB->get_records_sql(
                    "SELECT qa.sumgrades, q.sumgrades as maxgrade
                     FROM {quiz_attempts} qa
                     JOIN {quiz} q ON qa.quiz = q.id
                     JOIN {course_modules} cm ON cm.instance = q.id
                     JOIN {modules} m ON m.id = cm.module AND m.name = 'quiz'
                     WHERE q.course $course_insql
                     AND cm.deletioninprogress = 0
                     AND qa.userid = :userid
                     AND qa.state = 'finished'",
                    $student_params
                );
                
                foreach ($quiz_attempts as $attempt) {
                    if ($attempt->maxgrade > 0) {
                        $quiz_avg += ($attempt->sumgrades / $attempt->maxgrade) * 100;
                        $quiz_count++;
                    }
                }
                
                if ($quiz_count > 0) {
                    $quiz_avg = $quiz_avg / $quiz_count;
                    $total_score += $quiz_avg * 0.15;
                }
                
                // Only include students with some activity
                if ($grade_count > 0 || $proficient_comps > 0 || $completed_assigns > 0 || $quiz_count > 0) {
                    // Get profile picture - ensure student has all required fields
                    if (!property_exists($student, 'firstnamephonetic') || !property_exists($student, 'lastnamephonetic')) {
                        $student = $DB->get_record('user', ['id' => $student->id], 
                            implode(',', \core_user\fields::get_picture_fields()), MUST_EXIST);
                    }
                    $user_picture = new user_picture($student);
                    $user_picture->size = 1; // Size f1
                    $avatar_url = $user_picture->get_url($PAGE)->out(false);
                    
                    $leaderboard_students[] = [
                        'id' => $student->id,
                        'name' => fullname($student),
                        'full_name' => fullname($student),
                        'email' => $student->email,
                        'grade_percentage' => round($grade_avg, 1),
                        'competency_percentage' => round($comp_rate, 1),
                        'assignment_percentage' => round($assign_rate, 1),
                        'quiz_percentage' => round($quiz_avg, 1),
                        'overall_score' => round($total_score),
                        'points' => round($total_score * 10), // Convert to points (multiply by 10 for display)
                        'profile_picture_url' => $avatar_url,
                        'has_profile_picture' => true,
                        'is_current_user' => ($student->id == $USER->id),
                        'actual_rank' => 0 // Will be set after sorting
                    ];
                }
            }
            
            // Sort by overall score descending
            usort($leaderboard_students, function($a, $b) {
                if ($a['overall_score'] == $b['overall_score']) {
                    return strcmp($a['name'], $b['name']);
                }
                return $b['overall_score'] - $a['overall_score'];
            });
            
            // Set ranks
            foreach ($leaderboard_students as $idx => &$student) {
                $student['actual_rank'] = $idx + 1;
            }
            
            // Limit to top 5 for display
            $leaderboard_students = array_slice($leaderboard_students, 0, 5);
        }
    }
} catch (Exception $e) {
    debugging('Error fetching leaderboard data: ' . $e->getMessage());
}

echo $OUTPUT->header();

// Make variables available to sidebar
$GLOBALS['leaderboard_students'] = $leaderboard_students;
$GLOBALS['enrolled_courses'] = $enrolled_courses;

// Include the G4G7 Sidebar Component
include_once('components/g4g7_sidebar.php');
?>

<!-- Main Content Area -->
<div class="g4g7-main-content" style="margin-left: 280px; padding: 20px 20px 20px 10px; min-height: 100vh; background: #f8fafc;">
    <div class="g4g7-content-container" style="max-width: 1200px; margin: 0 auto;">
        
        <!-- Welcome Section - Simple Light Blue -->
        <div class="g4g7-welcome-section" style="background: #f0f9ff; color: #0369a1; padding: 30px; border-radius: 16px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05); border: 1px solid #bae6fd;">
            <h1 style="font-size: 2rem; font-weight: 700; margin: 0 0 8px 0; color: #0369a1;">Welcome back, <?php echo htmlspecialchars($USER->firstname); ?>! 👋</h1>
            <p style="font-size: 1rem; color: #0284c7; margin: 0; font-weight: 500;">Ready to learn something new?</p>
        </div>

        <?php if ($showstudypartnercta) : ?>
        <div class="g4g7-study-partner-cta" style="background: white; border-radius: 18px; padding: 24px; margin-bottom: 30px; display: flex; align-items: center; justify-content: space-between; box-shadow: 0 10px 25px rgba(79,70,229,0.08); gap: 20px;">
            <div>
                <p style="margin:0; text-transform: uppercase; letter-spacing: 1px; color: #6366f1; font-size: 12px; font-weight: 700;">NEW</p>
                <h2 style="margin: 8px 0 6px 0; font-size: 1.5rem; color: #1f2937;">Meet your Study Partner buddy 🤖</h2>
                <p style="margin:0; color:#4b5563;">Chat with your AI learning friend, take quizzes, and track your progress in one cozy place.</p>
                <?php if ($showdiagnosticcta) : ?>
                <div style="margin-top: 12px;">
                    <a href="<?php echo $studypartnerdiagurl->out(); ?>" style="font-size: 13px; color: #6366f1; text-decoration: underline;">Run Study Partner diagnostics</a>
                </div>
                <?php endif; ?>
            </div>
            <a href="<?php echo $studypartnerurl->out(); ?>" style="background: linear-gradient(135deg, #818cf8, #c084fc); color: white; padding: 14px 26px; border-radius: 999px; font-weight: 700; text-decoration: none; box-shadow: 0 12px 20px rgba(99,102,241,0.3);">
                Open Study Partner
            </a>
        </div>
        <?php endif; ?>

        <!-- Quick Stats Cards -->
        <div class="g4g7-stats-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px;">
            
            <!-- Courses Card - Light Green -->
            <div class="g4g7-stat-card" style="background: #f0fdf4; padding: 20px; border-radius: 12px; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05); border: 1px solid #bbf7d0;">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <div style="width: 40px; height: 40px; background: #dcfce7; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: #166534; font-size: 18px;">
                        <i class="fa fa-book"></i>
                    </div>
                    <div>
                        <h3 style="font-size: 1.8rem; font-weight: 600; margin: 0; color: #166534;"><?php echo $total_courses; ?></h3>
                        <p style="color: #16a34a; margin: 0; font-weight: 500; font-size: 14px;">My Courses</p>
                    </div>
                </div>
            </div>

            <!-- Achievements Card - Light Yellow -->
            <div class="g4g7-stat-card" style="background: #fefce8; padding: 20px; border-radius: 12px; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05); border: 1px solid #fde047;">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <div style="width: 40px; height: 40px; background: #fef3c7; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: #92400e; font-size: 18px;">
                        <i class="fa fa-star"></i>
                    </div>
                    <div>
                        <h3 style="font-size: 1.8rem; font-weight: 600; margin: 0; color: #92400e;"><?php echo $total_badges; ?></h3>
                        <p style="color: #ca8a04; margin: 0; font-weight: 500; font-size: 14px;">Achievements</p>
                    </div>
                </div>
            </div>

            <!-- Progress Card - Light Blue -->
            <div class="g4g7-stat-card" style="background: #f0f9ff; padding: 20px; border-radius: 12px; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05); border: 1px solid #bae6fd;">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <div style="width: 40px; height: 40px; background: #e0f2fe; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: #0c4a6e; font-size: 18px;">
                        <i class="fa fa-chart-line"></i>
                    </div>
                    <div>
                        <h3 style="font-size: 1.8rem; font-weight: 600; margin: 0; color: #0c4a6e;"><?php echo $overall_progress; ?>%</h3>
                        <p style="color: #0369a1; margin: 0; font-weight: 500; font-size: 14px;">My Progress</p>
                    </div>
                </div>
            </div>

            <!-- Study Time Card - Light Purple -->
            <div class="g4g7-stat-card" style="background: #faf5ff; padding: 20px; border-radius: 12px; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05); border: 1px solid #e9d5ff;">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <div style="width: 40px; height: 40px; background: #f3e8ff; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: #6b21a8; font-size: 18px;">
                        <i class="fa fa-clock"></i>
                    </div>
                    <div>
                        <h3 style="font-size: 1.8rem; font-weight: 600; margin: 0; color: #6b21a8;"><?php echo $study_time_hours; ?>h</h3>
                        <p style="color: #9333ea; margin: 0; font-weight: 500; font-size: 14px;">Study Time</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Activity Section - Simple White -->
        <div class="g4g7-activity-section" style="background: white; border-radius: 12px; padding: 24px; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05); border: 1px solid #e5e7eb;">
            <h2 style="font-size: 1.4rem; font-weight: 600; margin: 0 0 16px 0; color: #374151;">What I've Been Doing</h2>

            <div class="g4g7-activity-list">
                <div class="g4g7-activity-item" style="display: flex; align-items: center; gap: 12px; padding: 12px 0; border-bottom: 1px solid #f3f4f6;">
                    <div style="width: 36px; height: 36px; background: #dcfce7; border-radius: 6px; display: flex; align-items: center; justify-content: center; color: #166534;">
                        <i class="fa fa-check"></i>
                    </div>
                    <div style="flex: 1;">
                        <h4 style="margin: 0 0 4px 0; font-size: 14px; font-weight: 600; color: #111827;">Completed Math Quiz</h4>
                        <p style="margin: 0; font-size: 12px; color: #6b7280;">Mathematics - Grade 5 • 2 hours ago</p>
                    </div>
                    <span style="background: #dcfce7; color: #166534; padding: 4px 8px; border-radius: 12px; font-size: 11px; font-weight: 500;">Done!</span>
                </div>

                <div class="g4g7-activity-item" style="display: flex; align-items: center; gap: 12px; padding: 12px 0; border-bottom: 1px solid #f3f4f6;">
                    <div style="width: 36px; height: 36px; background: #fef3c7; border-radius: 6px; display: flex; align-items: center; justify-content: center; color: #92400e;">
                        <i class="fa fa-book-open"></i>
                    </div>
                    <div style="flex: 1;">
                        <h4 style="margin: 0 0 4px 0; font-size: 14px; font-weight: 600; color: #111827;">Read Science Chapter 3</h4>
                        <p style="margin: 0; font-size: 12px; color: #6b7280;">Science - Grade 4 • 5 hours ago</p>
                    </div>
                    <span style="background: #fef3c7; color: #92400e; padding: 4px 8px; border-radius: 12px; font-size: 11px; font-weight: 500;">Reading</span>
                </div>

                <div class="g4g7-activity-item" style="display: flex; align-items: center; gap: 12px; padding: 12px 0; border-bottom: 1px solid #f3f4f6;">
                    <div style="width: 36px; height: 36px; background: #e0f2fe; border-radius: 6px; display: flex; align-items: center; justify-content: center; color: #0c4a6e;">
                        <i class="fa fa-star"></i>
                    </div>
                    <div style="flex: 1;">
                        <h4 style="margin: 0 0 4px 0; font-size: 14px; font-weight: 600; color: #111827;">Earned New Badge</h4>
                        <p style="margin: 0; font-size: 12px; color: #6b7280;">Reading Master • Yesterday</p>
                    </div>
                    <span style="background: #e0f2fe; color: #0c4a6e; padding: 4px 8px; border-radius: 12px; font-size: 11px; font-weight: 500;">Badge!</span>
                </div>

                <div class="g4g7-activity-item" style="display: flex; align-items: center; gap: 12px; padding: 12px 0;">
                    <div style="width: 36px; height: 36px; background: #f3e8ff; border-radius: 6px; display: flex; align-items: center; justify-content: center; color: #6b21a8;">
                        <i class="fa fa-play"></i>
                    </div>
                    <div style="flex: 1;">
                        <h4 style="margin: 0 0 4px 0; font-size: 14px; font-weight: 600; color: #111827;">Started English Lesson</h4>
                        <p style="margin: 0; font-size: 12px; color: #6b7280;">English - Grade 6 • 2 days ago</p>
                    </div>
                    <span style="background: #f3e8ff; color: #6b21a8; padding: 4px 8px; border-radius: 12px; font-size: 11px; font-weight: 500;">Started</span>
                </div>
            </div>
        </div>

        <!-- Leaderboard Section - Simple Light Gray -->
        <div class="g4g7-leaderboard-section" style="background: #f9fafb; border-radius: 12px; padding: 24px; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05); margin-top: 20px; border: 1px solid #e5e7eb;">
            <h2 style="font-size: 20px; font-weight: 700; margin: 0 0 20px 0; display: flex; align-items: center; gap: 10px; color: #4c1d95; padding-bottom: 16px; border-bottom: 2px solid #c7d2fe;">
                <i class="fas fa-trophy" style="color: #818cf8; font-size: 22px;"></i>
                <span>Leaderboard</span>
                <span style="margin-left: auto; font-size: 14px; font-weight: 600; color: #6366f1;">Weekly Points</span>
            </h2>
            
            <div class="g4g7-leaderboard-list">
                <?php if (!empty($leaderboard_students)): ?>
                    <?php 
                    foreach ($leaderboard_students as $idx => $leader):
                        $rank = isset($leader['actual_rank']) ? $leader['actual_rank'] : ($idx + 1);
                        $is_current_user = isset($leader['is_current_user']) && $leader['is_current_user'];
                        
                        // Determine best achievement description
                        $best_achievement = '';
                        $best_value = 0;
                        if ($leader['grade_percentage'] > $best_value) {
                            $best_value = $leader['grade_percentage'];
                            $best_achievement = 'Best in marks';
                        }
                        if ($leader['competency_percentage'] > $best_value) {
                            $best_value = $leader['competency_percentage'];
                            $best_achievement = 'Best in competencies';
                        }
                        if ($leader['quiz_percentage'] > $best_value) {
                            $best_value = $leader['quiz_percentage'];
                            $best_achievement = 'Best exam result';
                        }
                        if ($leader['assignment_percentage'] > $best_value) {
                            $best_value = $leader['assignment_percentage'];
                            $best_achievement = 'Best in assignments';
                        }
                        if (empty($best_achievement)) {
                            $best_achievement = 'Top performer';
                        }
                        
                        // Format score - convert to points (multiply by 12 to get reasonable point values similar to image)
                        $points_value = round($leader['overall_score'] * 12);
                        $score_display = number_format($points_value) . ' points';
                    ?>
                    <div class="g4g7-leaderboard-row" style="background: <?php echo $is_current_user ? 'radial-gradient(circle at center, #e0e7ff 0%, #c7d2fe 100%);' : 'white'; ?> border-bottom: 1px solid #e0e7ff; padding: 18px 20px; display: flex; align-items: center; gap: 16px; transition: background 0.2s; border-radius: 12px; margin-bottom: 8px; border: 2px solid <?php echo $is_current_user ? '#c7d2fe;' : '#f1f5f9;'; ?>">
                        <!-- Circular Profile Picture -->
                        <div style="width: 50px; height: 50px; border-radius: 50%; overflow: hidden; flex-shrink: 0; border: 2px solid #e5e7eb;">
                            <?php if ($leader['has_profile_picture']): ?>
                                <img src="<?php echo $leader['profile_picture_url']; ?>" 
                                     alt="<?php echo htmlspecialchars($leader['name']); ?>" 
                                     style="width: 100%; height: 100%; object-fit: cover;">
                            <?php else: ?>
                                <div style="width: 100%; height: 100%; background: linear-gradient(135deg, #667eea, #764ba2); display: flex; align-items: center; justify-content: center; color: white; font-weight: 700; font-size: 18px;">
                                    <?php echo strtoupper(substr($leader['name'], 0, 1)); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Student Name and Description -->
                        <div style="flex: 1; min-width: 0;">
                            <h3 style="font-size: 15px; font-weight: 700; color: #111827; margin: 0 0 3px 0; line-height: 1.3; display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                                <?php if ($is_current_user): ?>
                                    <i class="fas fa-star" style="color: #818cf8; font-size: 14px;"></i>
                                <?php endif; ?>
                                <?php echo htmlspecialchars($leader['name']); ?>
                                <?php if ($is_current_user): ?>
                                    <span style="background: #818cf8; color: white; padding: 2px 8px; border-radius: 4px; font-size: 10px; font-weight: 600;">You</span>
                                <?php endif; ?>
                            </h3>
                            <p style="font-size: 12px; color: #6b7280; margin: 0; line-height: 1.4;">
                                <?php echo htmlspecialchars($best_achievement); ?>
                            </p>
                        </div>
                        
                        <!-- Score aligned to the right -->
                        <div style="text-align: right; flex-shrink: 0; min-width: 100px;">
                            <div style="font-size: 18px; font-weight: 700; color: #111827; line-height: 1.2;">
                                <?php echo $score_display; ?>
                            </div>
                            <div style="font-size: 12px; color: #6b7280; margin-top: 2px;">
                                Score: <?php echo number_format($points_value); ?>
                            </div>
                        </div>
                    </div>
                    <?php 
                    endforeach; 
                    ?>
                <?php else: ?>
                    <div class="g4g7-empty-state" style="text-align: center; padding: 40px 20px; color: #6b7280;">
                        <i class="fas fa-chart-line" style="font-size: 48px; color: #d1d5db; margin-bottom: 16px;"></i>
                        <p style="margin: 0; font-size: 14px;">No leaderboard data available yet</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Responsive CSS for Main Content -->
<style>
@media (max-width: 768px) {
    .g4g7-main-content {
        margin-left: 0 !important;
        padding: 20px 15px !important;
    }
    
    .g4g7-welcome-section {
        padding: 25px 20px !important;
    }
    
    .g4g7-welcome-section h1 {
        font-size: 1.8rem !important;
    }
    
    .g4g7-stats-grid {
        grid-template-columns: 1fr !important;
        gap: 15px !important;
    }
    
    .g4g7-activity-section {
        padding: 20px !important;
    }
    
    .g4g7-leaderboard-section {
        padding: 20px !important;
    }
    
    .g4g7-leaderboard-row {
        flex-direction: column !important;
        align-items: flex-start !important;
        gap: 12px !important;
    }
    
    .g4g7-leaderboard-row > div:last-child {
        width: 100% !important;
        text-align: left !important;
    }
}

</style>

<?php
echo $OUTPUT->footer();
?>
