<?php
require_once('../../../config.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->dirroot . '/question/engine/lib.php');

require_login();
$attemptid = required_param('attemptid', PARAM_INT);

// Load attempt and related objects.
$attempt = $DB->get_record('quiz_attempts', array('id' => $attemptid), '*', MUST_EXIST);
$quiz = $DB->get_record('quiz', array('id' => $attempt->quiz), '*', MUST_EXIST);
$course = $DB->get_record('course', array('id' => $quiz->course), '*', MUST_EXIST);
$context = context_course::instance($course->id);

if (!has_capability('moodle/course:update', $context) && !has_capability('moodle/site:config', $context)) {
    throw new moodle_exception('nopermissions', 'error', '', 'access quiz review');
}

$PAGE->set_context($context);
$PAGE->set_url('/theme/remui_kids/teacher/quiz_review.php', array('attemptid' => $attemptid));
$PAGE->set_pagelayout('admin');
$PAGE->set_title('Review - ' . format_string($quiz->name));
$PAGE->add_body_class('quizzes-page');

// Start output
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
// Shared sidebar
include(__DIR__ . '/includes/sidebar.php');

echo '<div class="teacher-main-content">';
echo '<div class="students-page-wrapper">';

echo '<div class="students-page-header">';
$backurl = new moodle_url('/theme/remui_kids/teacher/quiz_attempts.php', array('quizid' => $quiz->id));
echo '<div style="display:flex;align-items:center;gap:12px;">';
echo '<a class="filter-btn" href="' . $backurl->out() . '"><i class="fa fa-arrow-left"></i> Back</a>';
echo '<h1 class="students-page-title" style="margin:0;">Review - ' . format_string($quiz->name) . '</h1>';
echo '</div>';
echo '<p class="students-page-subtitle">Attempt finished: ' . ($attempt->timefinish ? userdate($attempt->timefinish) : '-') . '</p>';
echo '</div>';

// Container
echo '<div class="students-container">';

// Filter and search controls
echo '<div class="students-controls">';
echo '<div class="search-box">';
echo '<i class="fa fa-search search-icon"></i>';
echo '<input type="text" id="questionSearch" class="search-input" placeholder="Search questions..." onkeyup="filterQuestions()">';
echo '</div>';
echo '<div class="filter-buttons">';
echo '<button class="filter-btn active" onclick="filterByStatus(\'all\')">All Questions</button>';
echo '<button class="filter-btn" onclick="filterByStatus(\'correct\')">Correct</button>';
echo '<button class="filter-btn" onclick="filterByStatus(\'wrong\')">Incorrect</button>';
echo '<button class="filter-btn" onclick="filterByStatus(\'partial\')">Partial</button>';
echo '</div>';
echo '</div>';

// Use the question engine to load and render question summaries.
$quba = question_engine::load_questions_usage_by_activity($attempt->uniqueid);
$slots = $quba->get_slots();

echo '<div class="students-table-wrapper">';
echo '<table class="students-table" id="questionsTable">';
echo '<thead><tr><th>#</th><th>Question</th><th>Submitted answer</th><th>Correct answer</th><th>Mark</th></tr></thead>'; 
echo '<tbody>';

$number = 1;
foreach ($slots as $slot) {
    $question = $quba->get_question($slot);
    $qa = $quba->get_question_attempt($slot);

    // Question text
    $qtext = format_text($question->questiontext, $question->questiontextformat, array('context' => $context));
    // Student response summary (engine API)
    $studentsummary = $qa->get_response_summary();
    $studentsummary = is_array($studentsummary) ? implode(', ', $studentsummary) : (string)$studentsummary;

    // Right answer summary (if available)
    $right = '';
    if (method_exists($question, 'get_right_answer_summary')) {
        $right = $question->get_right_answer_summary();
    }

    // Mark and status
    $fraction = $qa->get_fraction(); // 0..1 or null
    $statusclass = 'answer-unknown';
    if ($fraction === null) {
        $mark = '-';
    } else if ((float)$question->defaultmark > 0) {
        $score = $fraction * (float)$question->defaultmark;
        $mark = format_float($score, 2) . ' / ' . format_float((float)$question->defaultmark, 2);
        if ($fraction >= 0.9999) { $statusclass = 'answer-correct'; }
        else if ($fraction > 0) { $statusclass = 'answer-partial'; }
        else { $statusclass = 'answer-wrong'; }
    } else {
        $mark = format_float($fraction, 2);
        if ($fraction >= 0.9999) { $statusclass = 'answer-correct'; }
        else if ($fraction > 0) { $statusclass = 'answer-partial'; }
        else { $statusclass = 'answer-wrong'; }
    }

    echo '<tr class="' . $statusclass . '">';
    echo '<td>' . $number . '</td>';
    echo '<td>' . $qtext . '</td>';
    echo '<td>' . s($studentsummary) . '</td>';
    echo '<td>' . s($right) . '</td>';
    echo '<td>' . $mark . '</td>';
    echo '</tr>';

    $number++;
}

echo '</tbody></table>';
echo '</div>'; // table wrapper
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

// Question filtering and search functionality
function filterQuestions() {
  const searchTerm = document.getElementById("questionSearch").value.toLowerCase();
  const table = document.getElementById("questionsTable");
  const rows = table.getElementsByTagName("tbody")[0].getElementsByTagName("tr");
  
  for (let i = 0; i < rows.length; i++) {
    const row = rows[i];
    const questionText = row.cells[1].textContent.toLowerCase();
    const studentAnswer = row.cells[2].textContent.toLowerCase();
    const correctAnswer = row.cells[3].textContent.toLowerCase();
    
    if (questionText.includes(searchTerm) || studentAnswer.includes(searchTerm) || correctAnswer.includes(searchTerm)) {
      row.style.display = "";
    } else {
      row.style.display = "none";
    }
  }
}

function filterByStatus(status) {
  // Update active button
  const buttons = document.querySelectorAll(".filter-btn");
  buttons.forEach(btn => btn.classList.remove("active"));
  event.target.classList.add("active");
  
  const table = document.getElementById("questionsTable");
  const rows = table.getElementsByTagName("tbody")[0].getElementsByTagName("tr");
  
  for (let i = 0; i < rows.length; i++) {
    const row = rows[i];
    
    if (status === "all") {
      row.style.display = "";
    } else if (status === "correct" && row.classList.contains("answer-correct")) {
      row.style.display = "";
    } else if (status === "wrong" && row.classList.contains("answer-wrong")) {
      row.style.display = "";
    } else if (status === "partial" && row.classList.contains("answer-partial")) {
      row.style.display = "";
    } else if (status !== "all") {
      row.style.display = "none";
    }
  }
}
</script>';

echo $OUTPUT->footer();


