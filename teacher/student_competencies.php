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
 * Student Competencies page - shows all competencies for a specific student
 *
 * @package   theme_remui_kids
 * @copyright 2025 Kodeit
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../../config.php');

require_login();
$context = context_system::instance();

// Restrict to teachers/admins.
if (!has_capability('moodle/course:update', $context) && !has_capability('moodle/site:config', $context)) {
    throw new moodle_exception('nopermissions', 'error', '', 'access student competencies page');
}

$userid = required_param('userid', PARAM_INT);
$courseid = required_param('courseid', PARAM_INT);

// Page setup.
$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/teacher/student_competencies.php', array('userid' => $userid, 'courseid' => $courseid));
$PAGE->set_pagelayout('admin');
$PAGE->set_title('Student Competencies');
$PAGE->add_body_class('quizzes-page'); // Reuse page styling

// Breadcrumb.
$PAGE->navbar->add('Competencies', new moodle_url('/theme/remui_kids/teacher/competencies.php', array('courseid' => $courseid)));
$PAGE->navbar->add('Student Competencies');

// Get user and course info
$user = $DB->get_record('user', array('id' => $userid), '*', MUST_EXIST);
$course = get_course($courseid);

// Output start.
echo $OUTPUT->header();

// Add CSS to remove the default main container
echo '<style>
/* Neutralize the default main container */
#region-main,
[role="main"] {
    background: transparent !important;
    box-shadow: none !important;
    border: 0 !important;
    padding: 0 !important;
    margin: 0 !important;
}
</style>';

// Layout wrapper and sidebar (same as other teacher pages).
echo '<div class="teacher-css-wrapper">';
echo '<div class="teacher-dashboard-wrapper">';

// Include reusable sidebar
include(__DIR__ . '/includes/sidebar.php');

echo '<div class="teacher-main-content">';
echo '<div class="students-page-wrapper">';

// Header
echo '<div class="students-page-header">';
echo '<div class="header-content">';
echo '<a href="' . $CFG->wwwroot . '/theme/remui_kids/teacher/competencies.php?courseid=' . $courseid . '" class="back-button" title="Back to Competencies">';
echo '<i class="fa fa-arrow-left"></i> Back to Competencies';
echo '</a>';
echo '<div class="header-text">';
echo '<h1 class="students-page-title">Student Competencies</h1>';
echo '<p class="students-page-subtitle">Course: ' . format_string($course->fullname) . '</p>';
echo '</div>';
echo '</div>';
echo '</div>';

// Student Info Section
echo '<div class="student-competency-overview">';
echo '<div class="student-header">';
echo '<div class="student-avatar">';
echo strtoupper(substr($user->firstname, 0, 1) . substr($user->lastname, 0, 1));
echo '</div>';
echo '<div class="student-info">';
echo '<h2>' . s($user->firstname . ' ' . $user->lastname) . '</h2>';
echo '<p class="student-email">' . s($user->email) . '</p>';
echo '</div>';
echo '</div>';
echo '</div>';

// Fetch frameworks that have competencies linked in this course (same logic as competencies.php)
$frameworks = $DB->get_records_sql(
    "SELECT DISTINCT f.id, f.shortname, f.idnumber
       FROM {competency_coursecomp} cc
       JOIN {competency} c ON c.id = cc.competencyid
       JOIN {competency_framework} f ON f.id = c.competencyframeworkid
      WHERE cc.courseid = ?
   ORDER BY f.shortname ASC",
    array($courseid)
);

// Fetch all competencies for those frameworks that are linked to this course (same logic as competencies.php)
$comps = $DB->get_records_sql(
    "SELECT DISTINCT c.id, c.shortname, c.idnumber, c.parentid, c.competencyframeworkid AS frameworkid, c.description
       FROM {competency_coursecomp} cc
       JOIN {competency} c ON c.id = cc.competencyid
      WHERE cc.courseid = ?
   ORDER BY c.sortorder, c.shortname",
    array($courseid)
);

if (empty($frameworks)) {
    echo '<div class="empty-state">';
    echo '<div class="empty-state-icon"><i class="fa fa-sitemap"></i></div>';
    echo '<div class="empty-state-title">No Competencies Assigned</div>';
    echo '<div class="empty-state-text">This course has no competencies assigned yet.</div>';
    echo '</div>';
} else {
    // Build hierarchy: framework -> parents -> children (same logic as competencies.php)
    $byframework = array();
    foreach ($frameworks as $f) { 
        $byframework[$f->id] = array('framework' => $f, 'nodes' => array(), 'children' => array()); 
    }
    foreach ($comps as $c) {
        if (!isset($byframework[$c->frameworkid])) { continue; }
        $byframework[$c->frameworkid]['nodes'][$c->id] = $c;
        $byframework[$c->frameworkid]['children'][$c->parentid ?? 0][] = $c->id;
    }
    
    echo '<div class="competencies-section">';
    echo '<div class="section-header">';
    echo '<h3><i class="fa fa-sitemap"></i> Assigned Competencies</h3>';
    
    // View Toggle
    echo '<div class="view-toggle-wrapper">';
    echo '<label class="view-toggle-label">View:</label>';
    echo '<div class="view-toggle">';
    echo '<input type="radio" id="view-cards" name="competency-view-type" value="cards" checked>';
    echo '<label for="view-cards" class="toggle-option">';
    echo '<i class="fa fa-th"></i>';
    echo '<span>Cards</span>';
    echo '</label>';
    echo '<input type="radio" id="view-tree" name="competency-view-type" value="tree">';
    echo '<label for="view-tree" class="toggle-option">';
    echo '<i class="fa fa-sitemap"></i>';
    echo '<span>Tree</span>';
    echo '</label>';
    echo '</div>';
    echo '</div>';
    echo '</div>';
    
    // Cards View Container
    echo '<div id="cardsView" class="view-content">';
    
    foreach ($byframework as $fwid => $bundle) {
        $f = $bundle['framework'];
        echo '<div class="framework-group">';
        echo '<h4 class="framework-title">' . s($f->shortname) . '</h4>';
        
        echo '<div class="competencies-grid">';
        
        // Flatten all competencies for this framework (including nested ones)
        $allComps = array();
        $flattenComps = function($parentid, $bundle, $flattenComps) use (&$allComps) {
            $children = $bundle['children'][$parentid ?? 0] ?? array();
            foreach ($children as $cid) {
                $c = $bundle['nodes'][$cid];
                $allComps[] = $c;
                $flattenComps($c->id, $bundle, $flattenComps);
            }
        };
        
        // Get all competencies for this framework
        $topLevelIds = array_merge(
            $bundle['children'][0] ?? array(),
            $bundle['children'][null] ?? array()
        );
        $topLevelIds = array_unique($topLevelIds);
        
        foreach ($topLevelIds as $cid) {
            $c = $bundle['nodes'][$cid];
            $allComps[] = $c;
            $flattenComps($c->id, $bundle, $flattenComps);
        }
        
        foreach ($allComps as $comp) {
            // Get student's competency status
            $usercomp = $DB->get_record('competency_usercompcourse', array(
                'userid' => $userid,
                'competencyid' => $comp->id,
                'courseid' => $courseid
            ));
            
            // If not found in course table, check global table as fallback
            if (!$usercomp) {
                $usercomp = $DB->get_record('competency_usercomp', array(
                    'userid' => $userid,
                    'competencyid' => $comp->id
                ));
            }
            
            $status = 'Not Yet Competent';
            $statusclass = 'status-not-competent';
            $statusicon = 'fa-times-circle';
            
            if ($usercomp) {
                if ($usercomp->proficiency) {
                    $status = 'Competent';
                    $statusclass = 'status-competent';
                    $statusicon = 'fa-check-circle';
                } elseif ($usercomp->status == 1) {
                    $status = 'In Progress';
                    $statusclass = 'status-in-progress';
                    $statusicon = 'fa-clock';
                }
            }
            
            echo '<div class="competency-card">';
            echo '<div class="competency-header">';
            echo '<div class="competency-status ' . $statusclass . '">';
            echo '<i class="fa ' . $statusicon . '"></i>';
            echo '<span>' . $status . '</span>';
            echo '</div>';
            echo '</div>';
            
            echo '<div class="competency-content">';
            echo '<h5 class="competency-name">' . s($comp->shortname) . '</h5>';
            if (!empty($comp->description)) {
                echo '<p class="competency-description">' . format_text($comp->description, FORMAT_HTML) . '</p>';
            }
            echo '</div>';
            
            echo '<div class="competency-actions">';
            echo '<a href="' . new moodle_url('/theme/remui_kids/teacher/student_competency_evidence.php', array('userid' => $userid, 'competencyid' => $comp->id, 'courseid' => $courseid)) . '" class="btn btn-primary">';
            echo '<i class="fa fa-eye"></i> View Evidence';
            echo '</a>';
            echo '</div>';
            echo '</div>';
        }
        echo '</div>';
        echo '</div>';
    }
    echo '</div>'; // end cardsView
    
    // Tree View Container
    echo '<div id="treeView" class="view-content" style="display: none;">';
    echo '<div class="comp-tree">';
    
    foreach ($byframework as $fwid => $bundle) {
        $f = $bundle['framework'];
        echo '<div class="tree-framework">';
        echo '<div class="tree-header" onclick="toggleNode(this)">';
        echo '<span class="caret">▶</span> ' . s($f->shortname);
        echo '</div>';
        echo '<ul class="tree-level" style="display:none">';

        // Render nodes recursively starting at parentid 0/null (same logic as competencies.php)
        $render = function($parentid, $bundle, $render) use ($userid, $courseid, $DB) {
            $children = $bundle['children'][$parentid ?? 0] ?? array();
            foreach ($children as $cid) {
                $c = $bundle['nodes'][$cid];
                
                // Get student's competency status
                $usercomp = $DB->get_record('competency_usercompcourse', array(
                    'userid' => $userid,
                    'competencyid' => $c->id,
                    'courseid' => $courseid
                ));
                
                // If not found in course table, check global table as fallback
                if (!$usercomp) {
                    $usercomp = $DB->get_record('competency_usercomp', array(
                        'userid' => $userid,
                        'competencyid' => $c->id
                    ));
                }
                
                $status = 'Not Yet Competent';
                $statusclass = 'status-not-competent';
                
                if ($usercomp) {
                    if ($usercomp->proficiency) {
                        $status = 'Competent';
                        $statusclass = 'status-competent';
                    } elseif ($usercomp->status == 1) {
                        $status = 'In Progress';
                        $statusclass = 'status-in-progress';
                    }
                }
                
                echo '<li class="tree-item">';
                $hasgrand = !empty($bundle['children'][$c->id]);
                echo '<div class="tree-row"' . ($hasgrand ? ' onclick="toggleNode(this)"' : '') . '>';
                if ($hasgrand) { echo '<span class="caret">▶</span> '; }
                echo '<span class="tree-name">' . s($c->shortname) . '</span>';
                echo '<span class="tree-meta">' . $status . '</span>';
                echo '<span class="tree-actions">';
                echo '<a href="' . new moodle_url('/theme/remui_kids/teacher/student_competency_evidence.php', array('userid' => $userid, 'competencyid' => $c->id, 'courseid' => $courseid)) . '" class="filter-btn" title="View Evidence"><i class="fa fa-eye"></i> Evidence</a>';
                echo '</span>';
                echo '</div>';
                if ($hasgrand) {
                    echo '<ul class="tree-level" style="display:none">';
                    $render($c->id, $bundle, $render);
                    echo '</ul>';
                }
                echo '</li>';
            }
        };

        // Render top-level competencies (both parentid = 0 and parentid = null)
        $topLevelIds = array_merge(
            $bundle['children'][0] ?? array(),
            $bundle['children'][null] ?? array()
        );
        $topLevelIds = array_unique($topLevelIds); // Remove duplicates
        
        foreach ($topLevelIds as $cid) {
            $c = $bundle['nodes'][$cid];
            
            // Get student's competency status
            $usercomp = $DB->get_record('competency_usercompcourse', array(
                'userid' => $userid,
                'competencyid' => $c->id,
                'courseid' => $courseid
            ));
            
            // If not found in course table, check global table as fallback
            if (!$usercomp) {
                $usercomp = $DB->get_record('competency_usercomp', array(
                    'userid' => $userid,
                    'competencyid' => $c->id
                ));
            }
            
            $status = 'Not Yet Competent';
            $statusclass = 'status-not-competent';
            
            if ($usercomp) {
                if ($usercomp->proficiency) {
                    $status = 'Competent';
                    $statusclass = 'status-competent';
                } elseif ($usercomp->status == 1) {
                    $status = 'In Progress';
                    $statusclass = 'status-in-progress';
                }
            }
            
            echo '<li class="tree-item">';
            $hasgrand = !empty($bundle['children'][$c->id]);
            echo '<div class="tree-row"' . ($hasgrand ? ' onclick="toggleNode(this)"' : '') . '>';
            if ($hasgrand) { echo '<span class="caret">▶</span> '; }
            echo '<span class="tree-name">' . s($c->shortname) . '</span>';
            echo '<span class="tree-meta">' . $status . '</span>';
            echo '<span class="tree-actions">';
            echo '<a href="' . new moodle_url('/theme/remui_kids/teacher/student_competency_evidence.php', array('userid' => $userid, 'competencyid' => $c->id, 'courseid' => $courseid)) . '" class="filter-btn" title="View Evidence"><i class="fa fa-eye"></i> Evidence</a>';
            echo '</span>';
            echo '</div>';
            if ($hasgrand) {
                echo '<ul class="tree-level" style="display:none">';
                $render($c->id, $bundle, $render);
                echo '</ul>';
            }
            echo '</li>';
        }

        echo '</ul>';
        echo '</div>';
    }
    
    echo '</div>'; // end comp-tree
    echo '</div>'; // end treeView
    echo '</div>';
}

echo '</div>'; // students-page-wrapper
echo '</div>'; // teacher-main-content
echo '</div>'; // teacher-dashboard-wrapper 
echo '</div>'; // teacher-css-wrapper
// Sidebar + page JS
echo '<script>
function toggleTeacherSidebar() {
  const sidebar = document.querySelector(".teacher-sidebar");
  sidebar.classList.toggle("sidebar-open");
}
document.addEventListener("click", function(event) {
  const sidebar = document.querySelector(".teacher-sidebar");
  const toggleButton = document.querySelector(".sidebar-toggle");
  if (!sidebar || !toggleButton) return;
  if (window.innerWidth <= 768 && !sidebar.contains(event.target) && !toggleButton.contains(event.target)) {
    sidebar.classList.remove("sidebar-open");
  }
});
window.addEventListener("resize", function() {
  const sidebar = document.querySelector(".teacher-sidebar");
  if (!sidebar) return;
  if (window.innerWidth > 768) {
    sidebar.classList.remove("sidebar-open");
  }
});

// Competency view toggle functionality
function toggleCompetencyView() {
  const cardsView = document.getElementById("cardsView");
  const treeView = document.getElementById("treeView");
  const cardsRadio = document.getElementById("view-cards");
  const treeRadio = document.getElementById("view-tree");
  
  if (cardsRadio.checked) {
    cardsView.style.display = "block";
    treeView.style.display = "none";
  } else if (treeRadio.checked) {
    cardsView.style.display = "none";
    treeView.style.display = "block";
  }
}

// Tree node toggle functionality
function toggleNode(el) {
  const row = el.classList.contains("tree-row") ? el : el.nextElementSibling;
  const list = el.classList.contains("tree-row") ? el.nextElementSibling : el.parentElement.querySelector(".tree-level");
  if (!list) return;
  const caret = (el.querySelector && el.querySelector(".caret")) || (el.previousElementSibling && el.previousElementSibling.querySelector && el.previousElementSibling.querySelector(".caret"));
  const isOpen = list.style.display !== "none";
  list.style.display = isOpen ? "none" : "block";
  if (caret) caret.textContent = isOpen ? "▶" : "▼";
}

// Add event listeners to radio buttons
document.addEventListener("DOMContentLoaded", function() {
  const cardsRadio = document.getElementById("view-cards");
  const treeRadio = document.getElementById("view-tree");
  
  if (cardsRadio) {
    cardsRadio.addEventListener("change", toggleCompetencyView);
  }
  if (treeRadio) {
    treeRadio.addEventListener("change", toggleCompetencyView);
  }
  
  // Initialize view on page load
  toggleCompetencyView();
});
</script>';

echo $OUTPUT->footer();
?>
