<?php
/**
 * This file contains only the table and pagination HTML
 * Used for AJAX requests to update content without page refresh
 */

// This file should be included from student_report_grade_students.php
// All variables should already be defined
?>

            <div class="grade-students-table-wrapper">
                <table class="grade-students-table">
                    <thead>
                        <tr>
                            <th style="min-width: 200px;">Student Name</th>
                            <th style="min-width: 200px;">Email</th>
                            <th class="center" style="min-width: 120px;">Performance Score</th>
                            <th class="center" style="min-width: 100px;">Highest Score</th>
                            <th class="center" style="min-width: 100px;">Lowest Score</th>
                            <th class="center" style="min-width: 120px;">Completion Rate</th>
                            <th class="center" style="min-width: 120px;">Courses Enrolled</th>
                            <th class="center" style="min-width: 120px;">Courses Completed</th>
                        </tr>
                    </thead>
                    <tbody id="grade-students-table-body">
                        <?php if (count($all_students_data) > 0): ?>
                        <?php foreach ($all_students_data as $student): ?>
                        <tr>
                            <td style="font-weight: 600; color: #1f2937;"><?php echo htmlspecialchars($student['name']); ?></td>
                            <td style="color: #6b7280;"><?php echo htmlspecialchars($student['email']); ?></td>
                            <td class="center">
                                <?php if ($student['avg_grade'] !== null): ?>
                                    <span class="grade-badge <?php echo $student['avg_grade'] >= 50 ? 'success' : 'warning'; ?>">
                                        <?php echo number_format($student['avg_grade'], 1); ?>%
                                    </span>
                                <?php else: ?>
                                    <span style="color: #6b7280;">No grades</span>
                                <?php endif; ?>
                            </td>
                            <td class="center">
                                <?php if ($student['highest_score'] > 0): ?>
                                    <span class="grade-badge success"><?php echo number_format($student['highest_score'], 1); ?>%</span>
                                <?php else: ?>
                                    <span style="color: #6b7280;">0%</span>
                                <?php endif; ?>
                            </td>
                            <td class="center">
                                <?php if ($student['lowest_score'] > 0): ?>
                                    <span class="grade-badge warning"><?php echo number_format($student['lowest_score'], 1); ?>%</span>
                                <?php else: ?>
                                    <span style="color: #6b7280;">0%</span>
                                <?php endif; ?>
                            </td>
                            <td class="center">
                                <span class="grade-badge <?php echo $student['completion_rate'] >= 50 ? 'success' : 'warning'; ?>" style="<?php echo $student['completion_rate'] < 50 ? 'background: #fee2e2; color: #991b1b;' : ''; ?>">
                                    <?php echo number_format($student['completion_rate'], 1); ?>%
                                </span>
                            </td>
                            <td class="center" style="color: #1e40af; font-weight: 600;"><?php echo $student['total_courses']; ?></td>
                            <td class="center" style="color: #10b981; font-weight: 600;"><?php echo $student['completed_courses']; ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php else: ?>
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 40px; color: #6b7280;">
                                <i class="fa fa-info-circle"></i> No students found
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div class="grade-students-pagination" id="grade-students-pagination">
                <div class="grade-students-show-entries">
                    <span>Show:</span>
                    <select id="grade-students-per-page">
                        <option value="10" <?php echo $per_page == 10 ? 'selected' : ''; ?>>10 entries</option>
                        <option value="25" <?php echo $per_page == 25 ? 'selected' : ''; ?>>25 entries</option>
                        <option value="50" <?php echo $per_page == 50 ? 'selected' : ''; ?>>50 entries</option>
                        <option value="100" <?php echo $per_page == 100 ? 'selected' : ''; ?>>100 entries</option>
                    </select>
                </div>
                <div class="grade-students-pagination-info">
                    Showing <?php echo $start_record; ?> to <?php echo $end_record; ?> of <?php echo $total_count; ?> entries
                </div>
                <div class="grade-students-pagination-controls">
                    <?php if ($page > 1): ?>
                        <a href="<?php echo (new moodle_url('/theme/remui_kids/school_manager/student_report_grade_students.php', ['grade' => $grade, 'search' => $search, 'per_page' => $per_page, 'page' => $page - 1]))->out(false); ?>" 
                           class="grade-students-pagination-btn">
                            &lt; Previous
                        </a>
                    <?php else: ?>
                        <span class="grade-students-pagination-btn" style="opacity: 0.5; cursor: not-allowed;">&lt; Previous</span>
                    <?php endif; ?>
                    
                    <div class="grade-students-page-numbers">
                        <?php
                        $max_buttons = 5;
                        $start_page = max(1, $page - floor($max_buttons / 2));
                        $end_page = min($start_page + $max_buttons - 1, $total_pages);
                        
                        if ($start_page > 1): ?>
                            <a href="<?php echo (new moodle_url('/theme/remui_kids/school_manager/student_report_grade_students.php', ['grade' => $grade, 'search' => $search, 'per_page' => $per_page, 'page' => 1]))->out(false); ?>" 
                               class="grade-students-page-number">1</a>
                            <?php if ($start_page > 2): ?>
                                <span style="padding: 8px; color: #6b7280;">...</span>
                            <?php endif; ?>
                        <?php endif; ?>
                        
                        <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                            <a href="<?php echo (new moodle_url('/theme/remui_kids/school_manager/student_report_grade_students.php', ['grade' => $grade, 'search' => $search, 'per_page' => $per_page, 'page' => $i]))->out(false); ?>" 
                               class="grade-students-page-number <?php echo $i == $page ? 'active' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                        
                        <?php if ($end_page < $total_pages): ?>
                            <?php if ($end_page < $total_pages - 1): ?>
                                <span style="padding: 8px; color: #6b7280;">...</span>
                            <?php endif; ?>
                            <a href="<?php echo (new moodle_url('/theme/remui_kids/school_manager/student_report_grade_students.php', ['grade' => $grade, 'search' => $search, 'per_page' => $per_page, 'page' => $total_pages]))->out(false); ?>" 
                               class="grade-students-page-number">
                                <?php echo $total_pages; ?>
                            </a>
                        <?php endif; ?>
                    </div>
                    
                    <?php if ($page < $total_pages): ?>
                        <a href="<?php echo (new moodle_url('/theme/remui_kids/school_manager/student_report_grade_students.php', ['grade' => $grade, 'search' => $search, 'per_page' => $per_page, 'page' => $page + 1]))->out(false); ?>" 
                           class="grade-students-pagination-btn">
                            Next &gt;
                        </a>
                    <?php else: ?>
                        <span class="grade-students-pagination-btn" style="opacity: 0.5; cursor: not-allowed;">Next &gt;</span>
                    <?php endif; ?>
                </div>
            </div>