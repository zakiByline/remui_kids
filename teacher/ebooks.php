<?php
/**
 * Hierarchical E-Books Page for Teachers
 *
 * @package   theme_remui_kids
 * @copyright 2025 Kodeit
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lang_init.php');

global $CURRENT_LANG, $DB, $USER, $CFG;

if (!isloggedin()) {
    redirect(get_login_url());
}

// Check if user is teacher
$isteacher = false;
$teacherroles = $DB->get_records_select('role', "shortname IN ('editingteacher','teacher','manager')");
$roleids = array_keys($teacherroles);

if (!empty($roleids)) {
    list($insql, $params) = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED, 'r');
    $params['userid'] = $USER->id;
    $params['ctxlevel'] = CONTEXT_COURSE;

    $teacher_courses = $DB->get_records_sql(
        "SELECT DISTINCT ctx.instanceid as courseid
         FROM {role_assignments} ra
         JOIN {context} ctx ON ra.contextid = ctx.id
         WHERE ra.userid = :userid AND ctx.contextlevel = :ctxlevel AND ra.roleid {$insql}
         LIMIT 1",
        $params
    );

    if (!empty($teacher_courses)) {
        $isteacher = true;
    }
}

if (is_siteadmin()) {
    $isteacher = true;
}

if (!$isteacher) {
    echo "<h1>Access Denied</h1>";
    echo "<p>You must be a teacher to access this page.</p>";
    echo "<p><a href='" . $CFG->wwwroot . "'>Go Back</a></p>";
    exit;
}

// Ensure books table exists
$dbman = $DB->get_manager();
$table = new xmldb_table('theme_remui_kids_books');

if (!$dbman->table_exists($table)) {
    $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
    $table->add_field('level', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
    $table->add_field('subject', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
    $table->add_field('book_type', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
    $table->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
    $table->add_field('description', XMLDB_TYPE_TEXT, null, null, null, null, null);
    $table->add_field('book_link', XMLDB_TYPE_TEXT, null, null, null, null, null);
    $table->add_field('cover_image', XMLDB_TYPE_CHAR, '255', null, null, null, null);
    $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
    $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
    $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
    $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
    $dbman->create_table($table);
}

$PAGE->set_url('/theme/remui_kids/teacher/ebooks.php');
$PAGE->set_title('E-Books Library');
$PAGE->set_heading('E-Books Library');
$PAGE->set_pagelayout('standard');

// Get filter parameters
$selected_level = optional_param('level', '', PARAM_TEXT);
$selected_subject = optional_param('subject', '', PARAM_TEXT);
$selected_book_type = optional_param('book_type', '', PARAM_TEXT);

// Get books based on filters
$books = [];
$sql_params = [];
$sql_conditions = [];

if ($selected_level) {
    $sql_conditions[] = "level = :level";
    $sql_params['level'] = $selected_level;
}

if ($selected_subject) {
    $sql_conditions[] = "subject = :subject";
    $sql_params['subject'] = $selected_subject;
}

if ($selected_book_type) {
    $sql_conditions[] = "book_type = :book_type";
    $sql_params['book_type'] = $selected_book_type;
}

$sql = "SELECT * FROM {theme_remui_kids_books}";
if (!empty($sql_conditions)) {
    $sql .= " WHERE " . implode(" AND ", $sql_conditions);
}
$sql .= " ORDER BY timecreated DESC";

$books = $DB->get_records_sql($sql, $sql_params);

echo $OUTPUT->header();
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <!-- Include teacher sidebar -->
            <?php include(__DIR__ . '/includes/sidebar.php'); ?>

            <div class="main-content">
                <div class="ebooks-page-header">
                    <h1 class="page-title">E-Books</h1>
                    <p class="page-subtitle">Browse and manage digital books by level, subject, and type</p>
                </div>

                <!-- Filter Section - Level 1 -->
                <div class="filter-section">
                    <h3 class="filter-title"><i class="fa fa-filter"></i> Select Level</h3>
                    <div class="filter-boxes level-filters">
                        <div class="filter-box <?php echo $selected_level == 'KG Level 1' ? 'active' : ''; ?>" data-filter="level" data-value="KG Level 1">
                            <i class="fa fa-graduation-cap"></i>
                            <span>KG Level 1</span>
                        </div>
                        <div class="filter-box <?php echo $selected_level == 'KG Level 2' ? 'active' : ''; ?>" data-filter="level" data-value="KG Level 2">
                            <i class="fa fa-graduation-cap"></i>
                            <span>KG Level 2</span>
                        </div>
                        <div class="filter-box <?php echo $selected_level == 'KG Level 3' ? 'active' : ''; ?>" data-filter="level" data-value="KG Level 3">
                            <i class="fa fa-graduation-cap"></i>
                            <span>KG Level 3</span>
                        </div>
                    </div>
                </div>

                <!-- Filter Section - Level 2: Subjects (shown when level is selected) -->
                <?php if ($selected_level): ?>
                <div class="filter-section">
                    <h3 class="filter-title"><i class="fa fa-book"></i> Select Subject</h3>
                    <div class="filter-boxes subject-filters">
                        <div class="filter-box <?php echo $selected_subject == 'English' ? 'active' : ''; ?>" data-filter="subject" data-value="English">
                            <i class="fa fa-font"></i>
                            <span>English</span>
                        </div>
                        <div class="filter-box <?php echo $selected_subject == 'Maths' ? 'active' : ''; ?>" data-filter="subject" data-value="Maths">
                            <i class="fa fa-calculator"></i>
                            <span>Maths</span>
                        </div>
                        <div class="filter-box <?php echo $selected_subject == 'Science' ? 'active' : ''; ?>" data-filter="subject" data-value="Science">
                            <i class="fa fa-flask"></i>
                            <span>Science</span>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Filter Section - Level 3: Book Types (shown when subject is selected) -->
                <?php if ($selected_subject): ?>
                <div class="filter-section">
                    <h3 class="filter-title"><i class="fa fa-bookmark"></i> Select Book Type</h3>
                    <div class="filter-boxes book-type-filters">
                        <div class="filter-box <?php echo $selected_book_type == 'Student Book' ? 'active' : ''; ?>" data-filter="book_type" data-value="Student Book">
                            <i class="fa fa-book"></i>
                            <span>Student Book</span>
                        </div>
                        <div class="filter-box <?php echo $selected_book_type == 'Teacher Book' ? 'active' : ''; ?>" data-filter="book_type" data-value="Teacher Book">
                            <i class="fa fa-graduation-cap"></i>
                            <span>Teacher Book</span>
                        </div>
                        <div class="filter-box <?php echo $selected_book_type == 'Practice Book' ? 'active' : ''; ?>" data-filter="book_type" data-value="Practice Book">
                            <i class="fa fa-pencil"></i>
                            <span>Practice Book</span>
                        </div>
                    </div>
                </div>
                <?php endif; ?>


                <!-- Books Display Grid -->
                <?php if ($selected_level && $selected_subject && $selected_book_type): ?>
                <div class="books-grid-section">
                    <h3 class="section-title">Books Available</h3>
                    <div class="books-grid" id="booksGrid">
                        <?php if (empty($books)): ?>
                            <div class="no-books">
                                <i class="fa fa-book-open fa-3x"></i>
                                <p>No books available yet for this selection.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($books as $book): ?>
                            <div class="book-card" data-book-id="<?php echo $book->id; ?>">
                                <div class="book-cover">
                                    <?php if ($book->cover_image): ?>
                                        <img src="<?php echo $book->cover_image; ?>" alt="<?php echo htmlspecialchars($book->title); ?>">
                                    <?php else: ?>
                                        <div class="default-cover">
                                            <i class="fa fa-book fa-3x"></i>
                                        </div>
                                    <?php endif; ?>
                                    <div class="book-overlay">
                                        <a href="<?php echo htmlspecialchars($book->book_link); ?>" target="_blank" class="btn-view-book">
                                            <i class="fa fa-eye"></i> View Book
                                        </a>
                                    </div>
                                </div>
                                <div class="book-info">
                                    <h4 class="book-title"><?php echo htmlspecialchars($book->title); ?></h4>
                                    <p class="book-description"><?php echo htmlspecialchars($book->description ?: 'No description'); ?></p>
                                    <div class="book-meta">
                                        <span class="book-level"><?php echo htmlspecialchars($book->level); ?></span>
                                        <span class="book-subject"><?php echo htmlspecialchars($book->subject); ?></span>
                                        <span class="book-type"><?php echo htmlspecialchars($book->book_type); ?></span>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>


<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<style>
body {
    overflow-x: hidden;
    width: 100% !important;
    max-width: 100% !important;
}

/* Full width page container */
#page-wrapper,
#page,
.page,
.container-fluid,
.container {
    width: 100% !important;
    max-width: 100% !important;
    padding: 0 !important;
    margin: 0 !important;
}

.row {
    margin-left: 0 !important;
    margin-right: 0 !important;
    width: 100% !important;
    max-width: 100% !important;
    padding: 0 !important;
}

.col-12 {
    padding-left: 0 !important;
    padding-right: 0 !important;
    width: 100% !important;
    max-width: 100% !important;
}

/* Teacher Sidebar - Same styling as sidebar.php */
.teacher-sidebar {
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    width: 260px !important;
    height: 100vh !important;
    background: white !important;
    border-right: 1px solid #e9ecef !important;
    z-index: 1000 !important;
    overflow-y: auto !important;
    box-shadow: 2px 0 10px rgba(0, 0, 0, 0.05) !important;
    transform: translateX(0) !important;
    transition: transform 0.3s ease !important;
}

.teacher-sidebar.sidebar-open {
    transform: translateX(0) !important;
}

.teacher-sidebar .sidebar-content {
    padding: 5rem 0 2rem 0 !important;
}

.teacher-sidebar .sidebar-section {
    margin-bottom: 1.5rem !important;
}

.teacher-sidebar .sidebar-category {
    font-size: 0.7rem !important;
    font-weight: 700 !important;
    color: #6c757d !important;
    text-transform: uppercase !important;
    letter-spacing: 1px !important;
    margin-bottom: 0.8rem !important;
    padding: 0 1.5rem !important;
    margin-top: 0 !important;
}

.teacher-sidebar .sidebar-menu {
    list-style: none !important;
    padding: 0 !important;
    margin: 0 !important;
}

.teacher-sidebar .sidebar-item {
    margin-bottom: 0.2rem !important;
}

.teacher-sidebar .sidebar-link {
    display: flex !important;
    align-items: center !important;
    padding: 0.7rem 1.5rem !important;
    color: #495057 !important;
    text-decoration: none !important;
    transition: all 0.3s ease !important;
    border-left: 3px solid transparent !important;
}

.teacher-sidebar .sidebar-link:hover {
    background-color: #f8f9fa !important;
    color: #4361ee !important;
    text-decoration: none !important;
    border-left-color: #4361ee !important;
}

.teacher-sidebar .sidebar-item.active .sidebar-link {
    background-color: #eef1ff !important;
    color: #4361ee !important;
    border-left-color: #4361ee !important;
    font-weight: 600 !important;
}

.teacher-sidebar .sidebar-icon {
    width: 18px !important;
    height: 18px !important;
    margin-right: 0.7rem !important;
    text-align: center !important;
    color: inherit !important;
}

.teacher-sidebar .sidebar-text {
    font-weight: 500 !important;
    font-size: 0.9rem !important;
    color: inherit !important;
}

/* Mobile Sidebar Toggle */
.sidebar-toggle {
    display: none !important;
    position: fixed !important;
    top: 15px !important;
    left: 15px !important;
    z-index: 1001 !important;
    background: #4361ee !important;
    color: white !important;
    border: none !important;
    padding: 10px 15px !important;
    border-radius: 5px !important;
    cursor: pointer !important;
    box-shadow: 0 2px 5px rgba(0,0,0,0.2) !important;
}

.sidebar-toggle:hover {
    background: #3248d8 !important;
}

/* Main content area - Full Width Display */
.main-content {
    margin-left: 260px !important;
    margin-top: 0 !important;
    padding: 20px 50px 30px 60px !important;
    min-height: 100vh;
    width: calc(100vw - 260px) !important;
    max-width: none !important;
    box-sizing: border-box !important;
}

/* Ensure all content sections use full width */
.main-content > *,
.ebooks-page-header,
.filter-section,
.add-book-section,
.books-grid-section,
.ebooks-content {
    width: 100% !important;
    max-width: 100% !important;
    box-sizing: border-box !important;
}

/* Filter boxes container */
.filter-boxes {
    width: 100% !important;
    max-width: 100% !important;
}

/* Books grid container */
.books-grid {
    width: 100% !important;
    max-width: 100% !important;
}

/* Ensure page layout doesn't interfere with sidebar - Full Width */
#page-wrapper,
#page,
.page {
    width: 100% !important;
    max-width: 100% !important;
    padding: 0 !important;
    margin: 0 !important;
}

#region-main,
#region-main-box,
#maincontent {
    width: 100% !important;
    max-width: 100% !important;
    padding: 0 !important;
    margin: 0 !important;
}

/* Remove any Bootstrap/Moodle container max-width restrictions */
.container-fluid > .row,
.container-fluid > .row > .col-12,
[class*="container"] > .row,
[class*="container"] > .row > [class*="col-"] {
    max-width: 100% !important;
    width: 100% !important;
}

/* Override Moodle default container styles */
#region-main,
.region-main-content,
#page-content {
    max-width: 100% !important;
    width: 100% !important;
}

@media (max-width: 768px) {
    .sidebar-toggle {
        display: block !important;
    }
    
    .teacher-sidebar {
        transform: translateX(-100%) !important;
    }
    
    .teacher-sidebar.sidebar-open {
        transform: translateX(0) !important;
    }
    
    .main-content {
        margin-left: 0 !important;
        width: 100% !important;
        padding: 15px !important;
    }
}

@media (min-width: 769px) {
    .teacher-sidebar {
        transform: translateX(0) !important;
        display: block !important;
        visibility: visible !important;
    }
    
    .sidebar-toggle {
        display: none !important;
    }
}

/* Page Header - Clean Style */
.ebooks-page-header {
    background: white;
    padding: 30px 0;
    margin: 0 0 30px 0 !important;
    border-bottom: 1px solid #e9ecef;
    width: 100% !important;
    max-width: 100% !important;
}

.ebooks-page-header .page-title {
    font-size: 2.5rem;
    font-weight: 700;
    color: #1e40af;
    margin: 0 0 10px 0;
    line-height: 1.2;
}

.ebooks-page-header .page-subtitle {
    font-size: 1.1rem;
    font-weight: 400;
    color: #6b7280;
    margin: 0;
    line-height: 1.6;
}

.filter-section {
    margin-bottom: 30px;
    padding: 20px;
    background: white;
    border-radius: 10px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
}

.filter-title {
    font-size: 1.3rem;
    font-weight: 600;
    color: #333;
    margin-bottom: 20px;
}

.filter-boxes {
    display: flex;
    gap: 20px;
    flex-wrap: wrap;
}

.filter-box {
    flex: 1;
    min-width: 200px;
    padding: 25px;
    background: #f8f9fa;
    border: 2px solid #e9ecef;
    border-radius: 10px;
    text-align: center;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 10px;
}

.filter-box i {
    font-size: 2rem;
    color: #667eea;
}

.filter-box span {
    font-size: 1.1rem;
    font-weight: 600;
    color: #333;
}

.filter-box:hover {
    background: #e3f2fd;
    border-color: #667eea;
    transform: translateY(-5px);
    box-shadow: 0 5px 15px rgba(102, 126, 234, 0.2);
}

.filter-box.active {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-color: #667eea;
    color: white;
}

.filter-box.active i,
.filter-box.active span {
    color: white;
}

.add-book-section {
    margin: 30px 0;
    text-align: center;
}

.btn-add-book {
    background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
    color: white;
    border: none;
    padding: 15px 30px;
    font-size: 1.1rem;
    font-weight: 600;
    border-radius: 10px;
    cursor: pointer;
    transition: all 0.3s ease;
}

.btn-add-book:hover {
    transform: translateY(-3px);
    box-shadow: 0 5px 20px rgba(79, 172, 254, 0.4);
}

.books-grid-section {
    margin-top: 30px;
}

.section-title {
    font-size: 1.5rem;
    font-weight: 600;
    color: #333;
    margin-bottom: 20px;
}

.books-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    gap: 25px;
}

.book-card {
    background: white;
    border-radius: 15px;
    overflow: hidden;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
    transition: transform 0.3s ease;
}

.book-card:hover {
    transform: translateY(-10px);
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
}

.book-cover {
    position: relative;
    height: 300px;
    overflow: hidden;
    background: #f0f0f0;
}

.book-cover img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.default-cover {
    display: flex;
    align-items: center;
    justify-content: center;
    height: 100%;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
}

.book-overlay {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.7);
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    opacity: 0;
    transition: opacity 0.3s ease;
}

.book-card:hover .book-overlay {
    opacity: 1;
}

.btn-view-book, .btn-edit-book, .btn-delete-book {
    padding: 10px 15px;
    border: none;
    border-radius: 5px;
    cursor: pointer;
    color: white;
    font-size: 14px;
}

.btn-view-book {
    background: #4facfe;
}

.btn-edit-book {
    background: #28a745;
}

.btn-delete-book {
    background: #dc3545;
}

.book-info {
    padding: 20px;
}

.book-title {
    font-size: 1.2rem;
    font-weight: 600;
    margin-bottom: 10px;
    color: #333;
}

.book-description {
    color: #6c757d;
    font-size: 0.9rem;
    margin-bottom: 15px;
    line-height: 1.5;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.book-meta {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.book-meta span {
    padding: 5px 10px;
    background: #f0f0f0;
    border-radius: 5px;
    font-size: 0.85rem;
    color: #667eea;
}

.no-books {
    grid-column: 1 / -1;
    text-align: center;
    padding: 60px 20px;
    color: #6c757d;
}

.no-books i {
    margin-bottom: 20px;
    color: #ccc;
}

/* Modal Styles */
.modal {
    display: none;
    position: fixed;
    z-index: 2000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
}

.modal-content {
    background: white;
    margin: 5% auto;
    padding: 0;
    border-radius: 15px;
    width: 90%;
    max-width: 600px;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
}

.modal-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 20px 30px;
    border-radius: 15px 15px 0 0;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-header h2 {
    margin: 0;
}

.close {
    color: white;
    font-size: 28px;
    font-weight: bold;
    cursor: pointer;
}

.close:hover {
    opacity: 0.7;
}

#bookForm {
    padding: 30px;
}

.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
    color: #333;
}

.form-group input,
.form-group textarea {
    width: 100%;
    padding: 12px;
    border: 2px solid #e9ecef;
    border-radius: 8px;
    font-size: 14px;
}

.form-group input:focus,
.form-group textarea:focus {
    outline: none;
    border-color: #667eea;
}

.form-group small {
    display: block;
    margin-top: 5px;
    color: #6c757d;
    font-size: 0.85rem;
}

.cover-preview {
    margin-top: 10px;
}

.cover-preview img {
    max-width: 200px;
    max-height: 300px;
    border-radius: 8px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
}

.form-actions {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
    margin-top: 30px;
}

.btn-cancel, .btn-save {
    padding: 12px 25px;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 600;
}

.btn-cancel {
    background: #e9ecef;
    color: #333;
}

.btn-save {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
}

@media (max-width: 768px) {
    .main-content {
        margin-left: 0 !important;
        width: 100% !important;
        padding: 15px !important;
    }
}
</style>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
// Filter handling
document.querySelectorAll('.filter-box').forEach(box => {
    box.addEventListener('click', function() {
        const filterType = this.getAttribute('data-filter');
        const filterValue = this.getAttribute('data-value');
        
        const url = new URL(window.location.href);
        url.searchParams.set(filterType, filterValue);
        
        // Clear dependent filters
        if (filterType === 'level') {
            url.searchParams.delete('subject');
            url.searchParams.delete('book_type');
        } else if (filterType === 'subject') {
            url.searchParams.delete('book_type');
        }
        
        window.location.href = url.toString();
    });
});

// Cover image preview
document.getElementById('coverImage')?.addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (file) {
        const reader = new FileReader();
        reader.onload = function(e) {
            const preview = document.getElementById('coverPreview');
            preview.innerHTML = '<img src="' + e.target.result + '" alt="Cover Preview">';
        };
        reader.readAsDataURL(file);
    }
});

</script>

<?php
echo $OUTPUT->footer();
?>