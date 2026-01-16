<?php
// SPDX-License-Identifier: GPL-3.0-or-later
/**
 * Shared grade data helpers for theme_remui_kids.
 *
 * @package    theme_remui_kids
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Convert a numeric percentage into a letter grade.
 *
 * @param float|null $percentage
 * @return string
 */
function remui_kids_grade_percentage_to_letter(?float $percentage): string {
    if ($percentage === null) {
        return '-';
    }

    if ($percentage >= 97) {
        return 'A+';
    }
    if ($percentage >= 93) {
        return 'A';
    }
    if ($percentage >= 90) {
        return 'A-';
    }
    if ($percentage >= 87) {
        return 'B+';
    }
    if ($percentage >= 83) {
        return 'B';
    }
    if ($percentage >= 80) {
        return 'B-';
    }
    if ($percentage >= 77) {
        return 'C+';
    }
    if ($percentage >= 73) {
        return 'C';
    }
    if ($percentage >= 70) {
        return 'C-';
    }
    if ($percentage >= 67) {
        return 'D+';
    }
    if ($percentage >= 63) {
        return 'D';
    }
    if ($percentage >= 60) {
        return 'D-';
    }

    return 'F';
}

/**
 * Build real grade data for all courses a user is enrolled in.
 *
 * @param int $userid
 * @return array{courses: array<int, array<string, mixed>>, totals: array<string, mixed>, distribution: array<string, int>}
 */
function remui_kids_get_user_gradebook_snapshot(int $userid): array {
    global $DB, $CFG;

    require_once($CFG->libdir . '/gradelib.php');
    require_once($CFG->dirroot . '/course/lib.php');

    $courses = enrol_get_users_courses($userid, true, 'id, fullname, shortname, summary');

    $resultcourses = [];
    $gradeitemsum = 0;
    $gradevalueaccumulator = 0;
    $gradevaluecount = 0;
    $distribution = [
        'A' => 0,
        'B' => 0,
        'C' => 0,
        'D' => 0,
        'F' => 0,
    ];

    foreach ($courses as $course) {
        $coursegradeitem = grade_item::fetch_course_item($course->id);
        $coursegradevalue = null;
        $coursegrademax = null;
        $coursepercentage = null;
        $courseletter = '-';

        if ($coursegradeitem) {
            $final = $coursegradeitem->get_final($userid);
            $finalvalue = null;
            if ($final instanceof grade_grade) {
                $finalvalue = $final->finalgrade;
            } elseif (is_object($final) && isset($final->finalgrade)) {
                // get_final() returns a stdClass object from grade_grades table
                $finalvalue = $final->finalgrade;
            } elseif (is_numeric($final ?? null)) {
                $finalvalue = $final;
            }
            // Handle case where $finalvalue might be an object
            if (is_object($finalvalue)) {
                // Try to get a numeric property from the object
                if (isset($finalvalue->finalgrade)) {
                    $finalvalue = $finalvalue->finalgrade;
                } elseif (isset($finalvalue->grade)) {
                    $finalvalue = $finalvalue->grade;
                } else {
                    // If we can't extract a value, skip this course
                    $finalvalue = null;
                }
            }
            if ($finalvalue !== false && $finalvalue !== null && is_numeric($finalvalue)) {
                $coursegrademax = $coursegradeitem->grademax ?: null;
                $coursegradevalue = round((float)$finalvalue, 2);
                if (!empty($coursegrademax)) {
                    $coursepercentage = $coursegrademax > 0
                        ? round(($coursegradevalue / (float)$coursegrademax) * 100, 2)
                        : null;
                    if ($coursepercentage !== null) {
                        $courseletter = remui_kids_grade_percentage_to_letter($coursepercentage);
                    }
                }
            }
        }

        $itemsql = "
            SELECT gi.id,
                   gi.itemname,
                   gi.itemtype,
                   gi.itemmodule,
                   gi.iteminstance,
                   gi.grademax,
                   gi.gradepass,
                   gi.sortorder,
                   gi.hidden,
                   gg.finalgrade,
                   gg.rawgrade,
                   gg.feedback,
                   gg.timecreated,
                   gg.timemodified
              FROM {grade_items} gi
         LEFT JOIN {grade_grades} gg
                ON gg.itemid = gi.id AND gg.userid = :userid
             WHERE gi.courseid = :courseid
               AND gi.itemtype IN ('mod', 'manual')
          ORDER BY gi.sortorder ASC";

        $gradeitems = $DB->get_records_sql($itemsql, [
            'userid' => $userid,
            'courseid' => $course->id,
        ]);

        $courseitems = [];
        foreach ($gradeitems as $item) {
            if ((int)$item->hidden === 1) {
                continue;
            }

            $finalgrade = $item->finalgrade;
            // Ensure finalgrade is numeric (handle case where it might be an object)
            if (is_object($finalgrade)) {
                if (isset($finalgrade->finalgrade)) {
                    $finalgrade = $finalgrade->finalgrade;
                } elseif (isset($finalgrade->grade)) {
                    $finalgrade = $finalgrade->grade;
                } else {
                    $finalgrade = null;
                }
            }
            $percentage = null;
            if ($finalgrade !== null && is_numeric($finalgrade) && $item->grademax > 0) {
                $percentage = round(($finalgrade / (float)$item->grademax) * 100, 2);
            }

            // Only include items that have a grade (finalgrade is not null)
            if ($finalgrade === null) {
                continue;
            }

            if ($percentage !== null) {
                if ($percentage >= 90) {
                    $distribution['A']++;
                } elseif ($percentage >= 80) {
                    $distribution['B']++;
                } elseif ($percentage >= 70) {
                    $distribution['C']++;
                } elseif ($percentage >= 60) {
                    $distribution['D']++;
                } else {
                    $distribution['F']++;
                }

                $gradevalueaccumulator += $percentage;
                $gradevaluecount++;
            }

            $courseitems[] = [
                'itemid' => $item->id,
                'name' => $item->itemname ?: get_string('gradeitem', 'grades'),
                'itemtype' => $item->itemtype,
                'module' => $item->itemmodule,
                'instance' => $item->iteminstance,
                'grademax' => $item->grademax,
                'gradepass' => $item->gradepass,
                'finalgrade' => $finalgrade !== null ? round($finalgrade, 2) : null,
                'percentage' => $percentage,
                'letter_grade' => remui_kids_grade_percentage_to_letter($percentage),
                'feedback' => $item->feedback,
                'timegraded' => $item->timemodified,
            ];
        }

        $gradeitemsum += count($courseitems);

        $completion = $DB->get_record('course_completions', ['course' => $course->id, 'userid' => $userid]);
        $iscompleted = $completion && !empty($completion->timecompleted);

        $resultcourses[] = [
            'course_id' => $course->id,
            'course_name' => $course->fullname,
            'course_shortname' => $course->shortname,
            'course_summary' => format_string(strip_tags($course->summary ?? '')),
            'grade_items' => $courseitems,
            'course_grade_value' => $coursegradevalue,
            'course_grade_max' => $coursegrademax,
            'course_grade_percentage' => $coursepercentage,
            'course_letter_grade' => $courseletter,
            'is_completed' => $iscompleted,
            'completion_date' => $completion->timecompleted ?? null,
        ];
    }

    $averagepercentage = $gradevaluecount > 0 ? round($gradevalueaccumulator / $gradevaluecount, 2) : 0;

    return [
        'courses' => $resultcourses,
        'totals' => [
            'total_courses' => count($resultcourses),
            'courses_with_grades' => count(array_filter($resultcourses, function(array $course): bool {
                return !empty($course['grade_items']);
            })),
            'grade_item_count' => $gradeitemsum,
            'average_percentage' => $averagepercentage,
        ],
        'distribution' => $distribution,
    ];
}

/**
 * Resolve the logical category for a grade item based on its module/type/name.
 *
 * @param array<string, mixed> $item
 * @return string
 */
function remui_kids_get_grade_item_category(array $item): string {
    $module = strtolower(trim((string)($item['module'] ?? '')));
    $itemtype = strtolower(trim((string)($item['itemtype'] ?? '')));

    if ($module !== '') {
        return $module;
    }

    if ($itemtype === 'manual') {
        return 'manual';
    }

    if ($itemtype === 'course') {
        return 'course';
    }

    return 'other';
}

/**
 * Return display metadata for a grade category.
 *
 * @param string $category
 * @return array<string, string>
 */
function remui_kids_get_grade_category_metadata(string $category): array {
    $manager = get_string_manager();
    $normalized = trim($category);

    $iconmap = [
        'assign' => 'fa-clipboard-check',
        'quiz' => 'fa-question-circle',
        'lesson' => 'fa-book-reader',
        'forum' => 'fa-comments',
        'feedback' => 'fa-comment-dots',
        'choice' => 'fa-list-check',
        'chat' => 'fa-message',
        'data' => 'fa-database',
        'glossary' => 'fa-spell-check',
        'h5pactivity' => 'fa-shapes',
        'scorm' => 'fa-cubes',
        'lti' => 'fa-plug',
        'book' => 'fa-book',
        'page' => 'fa-file-lines',
        'url' => 'fa-link',
        'folder' => 'fa-folder-open',
        'imscp' => 'fa-box-archive',
        'survey' => 'fa-poll',
        'workshop' => 'fa-lightbulb',
        'subsection' => 'fa-diagram-project',
        'resource' => 'fa-file',
        'trainingevent' => 'fa-calendar-check',
        'edwiservideoactivity' => 'fa-video',
        'wokwi' => 'fa-microchip',
        'wick' => 'fa-film',
        'mix' => 'fa-flask',
        'photopea' => 'fa-image',
        'scratch' => 'fa-code',
        'codeeditor' => 'fa-laptop-code',
        'webdev' => 'fa-globe',
        'sql' => 'fa-database',
    ];

    if ($normalized === 'manual') {
        $label = $manager->string_exists('gradeitems', 'grades') ? get_string('gradeitems', 'grades') : 'Manual';
        return ['key' => $normalized, 'label' => $label, 'icon' => 'fa-pen'];
    }

    if ($normalized === 'course') {
        $label = $manager->string_exists('course', 'moodle') ? get_string('course', 'moodle') : 'Course';
        return ['key' => $normalized, 'label' => $label, 'icon' => 'fa-school'];
    }

    if ($normalized === 'other' || $normalized === '') {
        $label = $manager->string_exists('other', 'moodle') ? get_string('other', 'moodle') : 'Other';
        return ['key' => $normalized ?: 'other', 'label' => $label, 'icon' => 'fa-shapes'];
    }

    $component = 'mod_' . $normalized;
    if ($manager->string_exists('modulenameplural', $component)) {
        $label = get_string('modulenameplural', $component);
    } elseif ($manager->string_exists('modulename', $component)) {
        $label = get_string('modulename', $component);
    } else {
        $label = ucwords(str_replace('_', ' ', $normalized));
    }

    $icon = $iconmap[$normalized] ?? 'fa-shapes';

    return ['key' => $normalized, 'label' => $label, 'icon' => $icon];
}

/**
 * Calculate performance metadata from an overall grade percentage.
 *
 * @param float $percentage
 * @return array<string, string>
 */
function remui_kids_get_grade_performance_meta(float $percentage): array {
    $manager = get_string_manager();
    $safe = function(string $identifier, string $component, string $fallback) use ($manager): string {
        return $manager->string_exists($identifier, $component) ? get_string($identifier, $component) : $fallback;
    };

    if ($percentage >= 90) {
        return [
            'label' => $safe('excellent', 'grades', 'Excellent'),
            'description' => $safe('excellentperformance', 'grades', 'Excellent performance'),
            'class' => 'performance-excellent',
            'icon' => 'fa-arrow-trend-up',
        ];
    }

    if ($percentage >= 80) {
        return [
            'label' => $safe('good', 'grades', 'Good'),
            'description' => $safe('goodperformance', 'grades', 'Good performance'),
            'class' => 'performance-good',
            'icon' => 'fa-arrow-up-right-dots',
        ];
    }

    if ($percentage >= 70) {
        return [
            'label' => $safe('average', 'grades', 'Average'),
            'description' => $safe('averageperformance', 'grades', 'Average performance'),
            'class' => 'performance-average',
            'icon' => 'fa-wave-square',
        ];
    }

    return [
        'label' => $safe('needsimprovement', 'grades', 'Needs Improvement'),
        'description' => $safe('needsimprovementperformance', 'grades', 'Needs improvement'),
        'class' => 'performance-watch',
        'icon' => 'fa-arrow-trend-down',
    ];
}

/**
 * Transform course grade data for card-based presentation.
 *
 * @param array<string, mixed> $course
 * @param bool $isfirst
 * @return array<string, mixed>
 */
function remui_kids_prepare_course_grade_cards(array $course, bool $isfirst = false): array {
    $gradeitems = $course['grade_items'] ?? [];

    $categoryaggregates = [];
    $detaileditems = [];
    $categorymetadata = [];

    foreach ($gradeitems as $item) {
        // Only process items that have a grade (finalgrade is not null)
        if ($item['finalgrade'] === null) {
            continue;
        }

        $categorykey = remui_kids_get_grade_item_category($item);
        if (!isset($categorymetadata[$categorykey])) {
            $categorymetadata[$categorykey] = remui_kids_get_grade_category_metadata($categorykey);
        }
        $metadata = $categorymetadata[$categorykey];

        if (!isset($categoryaggregates[$categorykey])) {
            $categoryaggregates[$categorykey] = [
                'key' => $metadata['key'],
                'label' => $metadata['label'],
                'icon' => $metadata['icon'],
                'count' => 0,
                'total_percentage' => 0,
            ];
        }

        $percentage = $item['percentage'];
        if ($percentage !== null) {
            $categoryaggregates[$categorykey]['total_percentage'] += $percentage;
            $categoryaggregates[$categorykey]['count']++;
        }

        $detaileditems[] = [
            'name' => $item['name'],
            'percentage' => $percentage !== null ? round($percentage, 1) : null,
            'percentage_display' => $percentage !== null ? round($percentage, 1) . '%' : get_string('nograde'),
            'letter_grade' => $item['letter_grade'],
            'grade_fraction' => ($item['finalgrade'] !== null && $item['grademax'] > 0)
                ? round($item['finalgrade'], 2) . ' / ' . round($item['grademax'], 2)
                : '-',
            'feedback' => $item['feedback'],
            'timegraded' => $item['timegraded'],
            'category_key' => $metadata['key'],
            'category_label' => $metadata['label'],
        ];
    }

    $categoryorder = ['assign', 'quiz', 'workshop', 'lesson', 'forum', 'manual', 'other'];
    usort($categoryaggregates, function(array $a, array $b) use ($categoryorder): int {
        $apos = array_search($a['key'], $categoryorder, true);
        $bpos = array_search($b['key'], $categoryorder, true);
        if ($apos === false && $bpos === false) {
            return strcasecmp($a['label'], $b['label']);
        }
        if ($apos === false) {
            return 1;
        }
        if ($bpos === false) {
            return -1;
        }
        return $apos <=> $bpos;
    });

    $manager = get_string_manager();
    $singular = $manager->string_exists('gradeitem', 'grades') ? get_string('gradeitem', 'grades') : 'item';
    $plural = $manager->string_exists('gradeitems', 'grades') ? get_string('gradeitems', 'grades') : 'items';

    $categories = array_map(function(array $category) use ($singular, $plural) {
        $average = $category['count'] > 0 ? round($category['total_percentage'] / $category['count'], 1) : null;
        $countlabel = $category['count'] === 1 ? $singular : $plural;
        return [
            'key' => $category['key'],
            'label' => $category['label'],
            'icon' => $category['icon'],
            'count' => $category['count'],
            'average' => $average,
        'average_display' => $average !== null ? $average . '%' : get_string('nograde'),
            'count_text' => $category['count'] . ' ' . $countlabel,
        ];
    }, $categoryaggregates);

    $overall = $course['course_grade_percentage'];
    if ($overall === null) {
        $validpercentages = array_filter(array_column($gradeitems, 'percentage'), function($value) {
            return $value !== null;
        });
        if (!empty($validpercentages)) {
            $overall = round(array_sum($validpercentages) / count($validpercentages), 1);
        } else {
            $overall = 0;
        }
    } else {
        $overall = round($overall, 1);
    }

    $performance = remui_kids_get_grade_performance_meta((float)$overall);

    return [
        'course_id' => $course['course_id'],
        'course_name' => $course['course_name'],
        'course_shortname' => $course['course_shortname'],
        'course_summary' => $course['course_summary'] ?? '',
        'course_letter_grade' => $course['course_letter_grade'],
        'overall_grade' => $overall,
        'overall_grade_display' => $overall !== null ? $overall . '%' : get_string('nograde'),
        'performance_label' => $performance['label'],
        'performance_description' => $performance['description'],
        'performance_class' => $performance['class'],
        'performance_icon' => $performance['icon'],
        'categories' => $categories,
        'has_categories' => !empty($categories),
        'grade_items' => $detaileditems,
        'has_items' => !empty($detaileditems),
        'grade_count' => count($detaileditems),
        'collapse_id' => 'course-card-' . $course['course_id'],
        'is_first' => $isfirst,
    ];
}

