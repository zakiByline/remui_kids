<?php
require_once('../../../config.php');

require_login();
$quizid = required_param('quizid', PARAM_INT);

$quiz = $DB->get_record('quiz', array('id' => $quizid), '*', MUST_EXIST);
$course = $DB->get_record('course', array('id' => $quiz->course), '*', MUST_EXIST);
$context = context_course::instance($course->id);

if (!has_capability('moodle/course:update', $context) && !has_capability('moodle/site:config', $context)) {
    throw new moodle_exception('nopermissions', 'error', '', 'access quiz attempts');
}

$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/teacher/quiz_attempts.php', array('quizid' => $quizid));
$PAGE->set_pagelayout('admin');
$PAGE->set_title('Quiz Attempts - ' . format_string($quiz->name));
$PAGE->add_body_class('quizzes-page');

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

echo '<div class="teacher-css-wrapper">';
echo '<div class="teacher-dashboard-wrapper">';

// Include reusable sidebar
include(__DIR__ . '/includes/sidebar.php');

echo '<div class="teacher-main-content">';
echo '<div class="students-page-wrapper">';

echo '<div class="students-page-header">';
echo '<div class="header-content">';
echo '<a href="' . $CFG->wwwroot . '/theme/remui_kids/teacher/quizzes.php" class="back-button" title="Back to Quizzes">';
echo '<i class="fa fa-arrow-left"></i> Back to Quizzes';
echo '</a>';
echo '<div class="header-text">';
echo '<h1 class="students-page-title">Attempts - ' . format_string($quiz->name) . '</h1>';
echo '<p class="students-page-subtitle">Course: ' . format_string($course->fullname) . '</p>';
echo '</div>';
echo '</div>';
echo '</div>';

// Calculate quiz-specific statistics
$totalattempts = $DB->count_records('quiz_attempts', array('quiz' => $quizid));
$finishedattempts = $DB->count_records('quiz_attempts', array('quiz' => $quizid, 'state' => 'finished'));
$uniquestudents = $DB->count_records_sql('SELECT COUNT(DISTINCT userid) FROM {quiz_attempts} WHERE quiz = ?', array($quizid));

// Get average grade from quiz_grades table
$avggrade = $DB->get_field_sql('SELECT AVG(grade) FROM {quiz_grades} WHERE quiz = ?', array($quizid));
$avgdisplay = '-';
if ($avggrade !== false && $avggrade !== null) {
    $avgdisplay = format_float((float)$avggrade, 2) . ' / ' . format_float((float)$quiz->grade, 2);
}

// Get highest grade
$highestgrade = $DB->get_field_sql('SELECT MAX(grade) FROM {quiz_grades} WHERE quiz = ?', array($quizid));
$highestdisplay = '-';
if ($highestgrade !== false && $highestgrade !== null) {
    $highestdisplay = format_float((float)$highestgrade, 2) . ' / ' . format_float((float)$quiz->grade, 2);
}

// Get completion rate
$completionrate = ($totalattempts > 0) ? round(($finishedattempts / $totalattempts) * 100, 1) : 0;

// Display quiz statistics cards
echo '<div class="stats-grid">';

// Card 1: Total Attempts
echo '<div class="stat-card">';
echo '<div class="stat-icon"><i class="fa fa-edit"></i></div>';
echo '<div class="stat-content">';
echo '<div class="stat-value">' . $totalattempts . '</div>';
echo '<div class="stat-label">Total Attempts</div>';
echo '</div>';
echo '</div>';

// Card 2: Average Grade
echo '<div class="stat-card">';
echo '<div class="stat-icon"><i class="fa fa-chart-line"></i></div>';
echo '<div class="stat-content">';
echo '<div class="stat-value">' . $avgdisplay . '</div>';
echo '<div class="stat-label">Average Grade</div>';
echo '</div>';
echo '</div>';

// Card 3: Highest Grade
echo '<div class="stat-card">';
echo '<div class="stat-icon"><i class="fa fa-trophy"></i></div>';
echo '<div class="stat-content">';
echo '<div class="stat-value">' . $highestdisplay . '</div>';
echo '<div class="stat-label">Highest Grade</div>';
echo '</div>';
echo '</div>';

// Card 4: Completion Rate
echo '<div class="stat-card">';
echo '<div class="stat-icon"><i class="fa fa-check-circle"></i></div>';
echo '<div class="stat-content">';
echo '<div class="stat-value">' . $completionrate . '%</div>';
echo '<div class="stat-label">Completion Rate</div>';
echo '</div>';
echo '</div>';

echo '</div>'; // stats-grid

// Fetch attempts (finished) with user info.
$sql = "SELECT qa.id, qa.userid, qa.state, qa.sumgrades, qa.timestart, qa.timefinish, u.firstname, u.lastname, u.email
        FROM {quiz_attempts} qa
        JOIN {user} u ON u.id = qa.userid
        WHERE qa.quiz = ? AND qa.state = ?
        ORDER BY qa.timefinish DESC";
$records = $DB->get_records_sql($sql, array($quizid, 'finished'));

echo '<div class="students-container">';

// Filter and search controls
echo '<div class="students-controls">';
echo '<div class="search-box">';
echo '<i class="fa fa-search search-icon"></i>';
echo '<input type="text" id="studentSearch" class="search-input" placeholder="Search students..." onkeyup="filterStudents()">';
echo '</div>';
echo '<div class="filter-buttons">';
echo '<button class="filter-btn active" onclick="filterByScore(\'all\')">All Scores</button>';
echo '<button class="filter-btn" onclick="filterByScore(\'high\')">High Scores (80%+)</button>';
echo '<button class="filter-btn" onclick="filterByScore(\'medium\')">Medium Scores (50-79%)</button>';
echo '<button class="filter-btn" onclick="filterByScore(\'low\')">Low Scores (<50%)</button>';
echo '</div>';
echo '<div class="sort-controls">';
echo '<label for="sortSelect" class="sort-label">Sort by:</label>';
echo '<select id="sortSelect" class="sort-dropdown" onchange="sortTable()">';
echo '<option value="name">Student Name</option>';
echo '<option value="score">Score (High to Low)</option>';
echo '<option value="score-low">Score (Low to High)</option>';
echo '<option value="date">Date (Recent First)</option>';
echo '<option value="date-old">Date (Oldest First)</option>';
echo '</select>';
echo '</div>';
echo '</div>';

echo '<div class="students-table-wrapper">';
echo '<table class="students-table" id="attemptsTable">';
echo '<thead><tr><th>Student</th><th>Email</th><th>Score</th><th>Finished</th><th>Actions</th></tr></thead>';
echo '<tbody>';
foreach ($records as $r) {
    $user = (object)array('firstname' => $r->firstname, 'lastname' => $r->lastname);
    // Convert raw sumgrades to quiz total scale (quiz->grade), respecting original out-of scale.
    $score = '-';
    if (!is_null($r->sumgrades)) {
        if ((float)$quiz->sumgrades > 0) {
            $scaled = ($r->sumgrades / (float)$quiz->sumgrades) * (float)$quiz->grade;
            $score = format_float($scaled, 2) . ' / ' . format_float((float)$quiz->grade, 2);
        } else {
            $score = format_float($r->sumgrades, 2);
        }
    }
    $finished = $r->timefinish ? userdate($r->timefinish) : '-';
    $initials = strtoupper(substr($r->firstname, 0, 1) . substr($r->lastname, 0, 1));
    
    // Calculate percentage for filtering
    $percentage = 0;
    if (!is_null($r->sumgrades) && (float)$quiz->sumgrades > 0) {
        $percentage = (($r->sumgrades / (float)$quiz->sumgrades) * 100);
    }
    
    // Determine score category
    $scoreCategory = 'low';
    if ($percentage >= 80) $scoreCategory = 'high';
    else if ($percentage >= 50) $scoreCategory = 'medium';
    
    echo '<tr data-name="' . strtolower(fullname($user)) . '" data-email="' . strtolower($r->email) . '" data-score="' . $percentage . '" data-date="' . $r->timefinish . '" data-category="' . $scoreCategory . '">';
    echo '<td class="student-name"><div class="student-avatar">' . $initials . '</div>' . fullname($user) . '</td>';
    echo '<td class="student-email">' . s($r->email) . '</td>';
    echo '<td>' . $score . '</td>';
    echo '<td>' . $finished . '</td>';
    echo '<td>';
    $reviewurl = new moodle_url('/theme/remui_kids/teacher/quiz_review.php', array('attemptid' => $r->id));
    echo '<a class="filter-btn" href="' . $reviewurl->out() . '" title="Review attempt"><i class="fa fa-eye"></i></a>';
    echo '</td>';
    echo '</tr>';
}
echo '</tbody></table></div>';
echo '</div>'; // students-container

echo '</div>'; // students-page-wrapper
echo '</div>'; // teacher-main-content
echo '</div>'; // teacher-dashboard-wrapper
echo '</div>'; // teacher-css-wrapper
// Sidebar JS
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

// Student filtering and search functionality
function filterStudents() {
  const searchTerm = document.getElementById("studentSearch").value.toLowerCase();
  const table = document.getElementById("attemptsTable");
  const rows = table.getElementsByTagName("tbody")[0].getElementsByTagName("tr");
  
  for (let i = 0; i < rows.length; i++) {
    const row = rows[i];
    const name = row.getAttribute("data-name");
    const email = row.getAttribute("data-email");
    
    if (name.includes(searchTerm) || email.includes(searchTerm)) {
      row.style.display = "";
    } else {
      row.style.display = "none";
    }
  }
}

function filterByScore(category) {
  // Update active button
  const buttons = document.querySelectorAll(".filter-btn");
  buttons.forEach(btn => btn.classList.remove("active"));
  event.target.classList.add("active");
  
  const table = document.getElementById("attemptsTable");
  const rows = table.getElementsByTagName("tbody")[0].getElementsByTagName("tr");
  
  for (let i = 0; i < rows.length; i++) {
    const row = rows[i];
    const rowCategory = row.getAttribute("data-category");
    
    if (category === "all" || rowCategory === category) {
      row.style.display = "";
    } else {
      row.style.display = "none";
    }
  }
}

function sortTable() {
  const sortBy = document.getElementById("sortSelect").value;
  const table = document.getElementById("attemptsTable");
  const tbody = table.getElementsByTagName("tbody")[0];
  const rows = Array.from(tbody.getElementsByTagName("tr"));
  
  rows.sort((a, b) => {
    switch(sortBy) {
      case "name":
        const nameA = a.getAttribute("data-name");
        const nameB = b.getAttribute("data-name");
        return nameA.localeCompare(nameB);
        
      case "score":
        const scoreA = parseFloat(a.getAttribute("data-score"));
        const scoreB = parseFloat(b.getAttribute("data-score"));
        return scoreB - scoreA;
        
      case "score-low":
        const scoreALow = parseFloat(a.getAttribute("data-score"));
        const scoreBLow = parseFloat(b.getAttribute("data-score"));
        return scoreALow - scoreBLow;
        
      case "date":
        const dateA = parseInt(a.getAttribute("data-date"));
        const dateB = parseInt(b.getAttribute("data-date"));
        return dateB - dateA;
        
      case "date-old":
        const dateAOld = parseInt(a.getAttribute("data-date"));
        const dateBOld = parseInt(b.getAttribute("data-date"));
        return dateAOld - dateBOld;
        
      default:
        return 0;
    }
  });
  
  // Re-append sorted rows
  rows.forEach(row => tbody.appendChild(row));
}
</script>';

echo $OUTPUT->footer();