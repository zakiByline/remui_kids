<?php
/**
 * E-Book Management Page for Admin
 *
 * @package   theme_remui_kids
 * @copyright 2025 Kodeit
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lang_init.php');

global $CURRENT_LANG, $DB, $USER, $CFG, $PAGE;

require_login();

// Check if user is admin
if (!is_siteadmin()) {
    echo "<h1>Access Denied</h1>";
    echo "<p>You must be an administrator to access this page.</p>";
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

$PAGE->set_url('/theme/remui_kids/admin/ebook_management.php');
$PAGE->set_title('E-Book Management');
$PAGE->set_heading('');
$PAGE->set_pagelayout('standard');

// Get filter parameters
$selected_level = optional_param('level', '', PARAM_TEXT);
$selected_subject = optional_param('subject', '', PARAM_TEXT);
$selected_book_type = optional_param('book_type', '', PARAM_TEXT);

// Parse multiple levels and subjects if passed as arrays
$selected_levels = [];
$selected_subjects = [];
// Handle array parameters (levels[]=value&levels[]=value) or multiple query params
if (isset($_GET['levels'])) {
    if (is_array($_GET['levels'])) {
        $selected_levels = array_map('trim', $_GET['levels']);
    } else {
        $selected_levels = [trim($_GET['levels'])];
    }
} elseif ($selected_level) {
    $selected_levels = [$selected_level];
}

if (isset($_GET['subjects'])) {
    if (is_array($_GET['subjects'])) {
        $selected_subjects = array_map('trim', $_GET['subjects']);
    } else {
        $selected_subjects = [trim($_GET['subjects'])];
    }
} elseif ($selected_subject) {
    $selected_subjects = [$selected_subject];
}

// Get books based on filters - only fetch if at least one filter is selected
$books = [];
$sql_params = [];
$sql_conditions = [];
$has_filters = false;

// Check if any filter is selected
if ($selected_book_type) {
    $has_filters = true;
    $sql_conditions[] = "book_type = :book_type";
    $sql_params['book_type'] = $selected_book_type;
}

if (!empty($selected_levels)) {
    $has_filters = true;
    $level_placeholders = [];
    foreach ($selected_levels as $idx => $level) {
        $param_name = 'level_' . $idx;
        $level_placeholders[] = ":{$param_name}";
        $sql_params[$param_name] = $level;
    }
    if (!empty($level_placeholders)) {
        $sql_conditions[] = "level IN (" . implode(',', $level_placeholders) . ")";
    }
} elseif ($selected_level) {
    $sql_conditions[] = "level = :level";
    $sql_params['level'] = $selected_level;
}

if (!empty($selected_subjects)) {
    $subject_placeholders = [];
    foreach ($selected_subjects as $idx => $subject) {
        $param_name = 'subject_' . $idx;
        $subject_placeholders[] = ":{$param_name}";
        $sql_params[$param_name] = $subject;
    }
    if (!empty($subject_placeholders)) {
        $sql_conditions[] = "subject IN (" . implode(',', $subject_placeholders) . ")";
    }
} elseif ($selected_subject) {
    $has_filters = true;
    $sql_conditions[] = "subject = :subject";
    $sql_params['subject'] = $selected_subject;
}

// Only fetch books if at least one filter is selected
if ($has_filters && !empty($sql_conditions)) {
    $sql = "SELECT * FROM {theme_remui_kids_books}";
    $sql .= " WHERE " . implode(" AND ", $sql_conditions);
    $sql .= " ORDER BY timecreated DESC";
    $books = $DB->get_records_sql($sql, $sql_params);
    
    // Ensure $books is an array even if empty
    if (!$books) {
        $books = [];
    }
}

echo $OUTPUT->header();
?>

<!-- Include admin sidebar -->
<?php include(__DIR__ . '/includes/admin_sidebar.php'); ?>

            <div class="main-content">
                <!-- Filter Cards Section -->
                <div class="ebooks-filter-section">
                    <!-- SELECT LEVELS (Multiple Selection) - First level filter -->
                    <h3 class="filter-section-header">1 SELECT LEVELS (multiple)</h3>
                    <div class="filter-cards-container" id="levelFilterCards">
                        <label class="filter-card level-1 <?php echo in_array('KG Level 1', $selected_levels) || $selected_level == 'KG Level 1' ? 'selected' : ''; ?>" data-level="KG Level 1">
                            <input type="checkbox" name="ebook_levels[]" value="KG Level 1" class="filter-level-input" <?php echo in_array('KG Level 1', $selected_levels) || $selected_level == 'KG Level 1' ? 'checked' : ''; ?>>
                            <div class="filter-card-icon" style="background: #ccfbf1; color: #14b8a6;">
                                <i class="fa fa-child"></i>
                            </div>
                            <div class="filter-card-content">
                                <h4 class="filter-card-title">KG - Level 1</h4>
                                <p class="filter-card-description">Foundation skills and early learning concepts</p>
                            </div>
                            <div class="filter-card-checkbox"></div>
                        </label>
                        
                        <label class="filter-card level-2 <?php echo in_array('KG Level 2', $selected_levels) || $selected_level == 'KG Level 2' ? 'selected' : ''; ?>" data-level="KG Level 2">
                            <input type="checkbox" name="ebook_levels[]" value="KG Level 2" class="filter-level-input" <?php echo in_array('KG Level 2', $selected_levels) || $selected_level == 'KG Level 2' ? 'checked' : ''; ?>>
                            <div class="filter-card-icon" style="background: #f3e8ff; color: #a855f7;">
                                <i class="fa fa-smile-o"></i>
                            </div>
                            <div class="filter-card-content">
                                <h4 class="filter-card-title">KG - Level 2</h4>
                                <p class="filter-card-description">Building on basics with new challenges</p>
                            </div>
                            <div class="filter-card-checkbox"></div>
                        </label>
                        
                        <label class="filter-card level-3 <?php echo in_array('KG Level 3', $selected_levels) || $selected_level == 'KG Level 3' ? 'selected' : ''; ?>" data-level="KG Level 3">
                            <input type="checkbox" name="ebook_levels[]" value="KG Level 3" class="filter-level-input" <?php echo in_array('KG Level 3', $selected_levels) || $selected_level == 'KG Level 3' ? 'checked' : ''; ?>>
                            <div class="filter-card-icon" style="background: #fce7f3; color: #ec4899;">
                                <i class="fa fa-graduation-cap"></i>
                            </div>
                            <div class="filter-card-content">
                                <h4 class="filter-card-title">KG - Level 3</h4>
                                <p class="filter-card-description">Advanced concepts and school readiness</p>
                            </div>
                            <div class="filter-card-checkbox"></div>
                        </label>
                    </div>

                    <!-- SELECT SUBJECTS (Multiple Selection) - Only shown when level is selected -->
                    <div class="filter-section-wrapper" id="subjectSectionWrapper" style="<?php echo (empty($selected_levels) && !$selected_level) ? 'display: none;' : ''; ?>">
                        <h3 class="filter-section-header">2 SELECT SUBJECTS (multiple)</h3>
                        <div class="filter-cards-container" id="subjectFilterCards">
                        <label class="filter-card subject-english <?php echo in_array('English', $selected_subjects) || $selected_subject == 'English' ? 'selected' : ''; ?>" data-subject="English">
                            <input type="checkbox" name="ebook_subjects[]" value="English" class="filter-subject-input" <?php echo in_array('English', $selected_subjects) || $selected_subject == 'English' ? 'checked' : ''; ?>>
                            <div class="filter-card-icon" style="background: #fce7f3; color: #ec4899;">
                                <i class="fa fa-book"></i>
                            </div>
                            <div class="filter-card-content">
                                <h4 class="filter-card-title">English</h4>
                                <p class="filter-card-description">Reading, writing, phonics, and language arts</p>
                            </div>
                            <div class="filter-card-checkbox"></div>
                        </label>
                        
                        <label class="filter-card subject-maths <?php echo in_array('Maths', $selected_subjects) || $selected_subject == 'Maths' ? 'selected' : ''; ?>" data-subject="Maths">
                            <input type="checkbox" name="ebook_subjects[]" value="Maths" class="filter-subject-input" <?php echo in_array('Maths', $selected_subjects) || $selected_subject == 'Maths' ? 'checked' : ''; ?>>
                            <div class="filter-card-icon" style="background: #dbeafe; color: #3b82f6;">
                                <i class="fa fa-calculator"></i>
                            </div>
                            <div class="filter-card-content">
                                <h4 class="filter-card-title">Maths</h4>
                                <p class="filter-card-description">Numbers, counting, shapes, and problem solving</p>
                            </div>
                            <div class="filter-card-checkbox"></div>
                        </label>
                        
                        <label class="filter-card subject-science <?php echo in_array('Science', $selected_subjects) || $selected_subject == 'Science' ? 'selected' : ''; ?>" data-subject="Science">
                            <input type="checkbox" name="ebook_subjects[]" value="Science" class="filter-subject-input" <?php echo in_array('Science', $selected_subjects) || $selected_subject == 'Science' ? 'checked' : ''; ?>>
                            <div class="filter-card-icon" style="background: #d1fae5; color: #10b981;">
                                <i class="fa fa-flask"></i>
                            </div>
                            <div class="filter-card-content">
                                <h4 class="filter-card-title">Science</h4>
                                <p class="filter-card-description">Nature, experiments, and exploring the world</p>
                            </div>
                            <div class="filter-card-checkbox"></div>
                        </label>
                        </div>
                    </div>

                    <!-- SELECT BOOK TYPE (Single Selection) - Only shown when subject is selected -->
                    <div class="filter-section-wrapper" id="bookTypeSectionWrapper" style="<?php echo (empty($selected_subjects) && !$selected_subject) ? 'display: none;' : ''; ?>">
                        <h3 class="filter-section-header">3 SELECT BOOK TYPE</h3>
                        <div class="filter-cards-container" id="bookTypeFilterCards">
                            <label class="filter-card book-type-student <?php echo strtolower($selected_book_type) == 'student book' ? 'selected' : ''; ?>" data-book-type="Student Book">
                                <input type="radio" name="ebook_book_type" value="Student Book" class="filter-book-type-input" <?php echo strtolower($selected_book_type) == 'student book' ? 'checked' : ''; ?>>
                                <div class="filter-card-icon" style="background: #dbeafe; color: #3b82f6;">
                                    <i class="fa fa-book"></i>
                                </div>
                                <div class="filter-card-content">
                                    <h4 class="filter-card-title">Student Book</h4>
                                    <p class="filter-card-description">Primary learning materials for students</p>
                                </div>
                                <div class="filter-card-checkbox"></div>
                            </label>
                            
                            <label class="filter-card book-type-teacher <?php echo strtolower($selected_book_type) == 'teacher book' ? 'selected' : ''; ?>" data-book-type="Teacher Book">
                                <input type="radio" name="ebook_book_type" value="Teacher Book" class="filter-book-type-input" <?php echo strtolower($selected_book_type) == 'teacher book' ? 'checked' : ''; ?>>
                                <div class="filter-card-icon" style="background: #f3e8ff; color: #a855f7;">
                                    <i class="fa fa-graduation-cap"></i>
                                </div>
                                <div class="filter-card-content">
                                    <h4 class="filter-card-title">Teacher Book</h4>
                                    <p class="filter-card-description">Teaching guides and resources for educators</p>
                                </div>
                                <div class="filter-card-checkbox"></div>
                            </label>
                            
                            <label class="filter-card book-type-practice <?php echo strtolower($selected_book_type) == 'practice book' ? 'selected' : ''; ?>" data-book-type="Practice Book">
                                <input type="radio" name="ebook_book_type" value="Practice Book" class="filter-book-type-input" <?php echo strtolower($selected_book_type) == 'practice book' ? 'checked' : ''; ?>>
                                <div class="filter-card-icon" style="background: #fef3c7; color: #f59e0b;">
                                    <i class="fa fa-pencil"></i>
                                </div>
                                <div class="filter-card-content">
                                    <h4 class="filter-card-title">Practice Book</h4>
                                    <p class="filter-card-description">Exercises and practice materials for skill development</p>
                                </div>
                                <div class="filter-card-checkbox"></div>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Add Book Button (shown when filters are selected) -->
                <?php if ($has_filters): ?>
                <div class="add-book-section">
                    <button class="btn-add-book" onclick="openAddBookModal()">
                        <i class="fa fa-plus-circle"></i> Add New Book
                    </button>
                </div>
                <?php endif; ?>

                <!-- Books Display Grid - Only show when filters are applied -->
                <?php if ($has_filters): ?>
                <div class="books-grid-section">
                    <h3 class="section-title">Books Available</h3>
                    <div class="books-grid" id="booksGrid">
                        <?php if (empty($books)): ?>
                            <div class="no-books">
                                <i class="fa fa-book-open fa-3x"></i>
                                <p>No books available yet. Click "Add New Book" to upload one.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($books as $book): ?>
                            <div class="book-card" data-book-id="<?php echo $book->id; ?>">
                                <div class="book-cover">
                                    <?php if ($book->cover_image && !empty(trim($book->cover_image))): ?>
                                        <img src="<?php echo htmlspecialchars($book->cover_image); ?>" alt="<?php echo htmlspecialchars($book->title); ?>" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                        <div class="default-cover" style="display: none;">
                                            <i class="fa fa-book fa-3x"></i>
                                        </div>
                                    <?php else: ?>
                                        <div class="default-cover">
                                            <i class="fa fa-book fa-3x"></i>
                                        </div>
                                    <?php endif; ?>
                                    <div class="book-gradient-overlay"></div>
                                    <div class="book-content-overlay">
                                        <h4 class="book-title"><?php echo htmlspecialchars($book->title); ?></h4>
                                        <p class="book-description"><?php echo htmlspecialchars($book->description ?: 'No description'); ?></p>
                                        <div class="book-meta">
                                            <span class="book-pill"><?php echo htmlspecialchars($book->level); ?></span>
                                            <span class="book-pill"><?php echo htmlspecialchars($book->subject); ?></span>
                                            <span class="book-pill"><?php echo htmlspecialchars($book->book_type); ?></span>
                                        </div>
                                    </div>
                                    <div class="book-actions-overlay">
                                        <button class="btn-edit-book" onclick="event.stopPropagation(); editBook(<?php echo $book->id; ?>);" title="Edit Book">
                                            <i class="fa fa-edit"></i>
                                        </button>
                                        <button class="btn-delete-book" onclick="event.stopPropagation(); deleteBook(<?php echo $book->id; ?>);" title="Delete Book">
                                            <i class="fa fa-trash"></i>
                                        </button>
                                    </div>
                                </div>
                                <a href="<?php echo htmlspecialchars($book->book_link); ?>" target="_blank" class="btn-view-book-cta">
                                    <i class="fa fa-eye"></i> View Book
                                </a>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
</div>

<!-- Add/Edit Book Modal -->
<div id="bookModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="modalTitle">Add New Book</h2>
            <span class="close" onclick="closeBookModal()">&times;</span>
        </div>
        <form id="bookForm" enctype="multipart/form-data">
            <input type="hidden" id="bookId" name="book_id">
            <input type="hidden" id="bookLevel" name="level" value="<?php echo htmlspecialchars(!empty($selected_levels) ? $selected_levels[0] : $selected_level); ?>">
            <input type="hidden" id="bookSubject" name="subject" value="<?php echo htmlspecialchars(!empty($selected_subjects) ? $selected_subjects[0] : $selected_subject); ?>">
            <input type="hidden" id="bookType" name="book_type" value="<?php echo htmlspecialchars($selected_book_type); ?>">
            
            <div class="form-group">
                <label for="bookTitle">Book Title *</label>
                <input type="text" id="bookTitle" name="title" required>
            </div>

            <div class="form-group">
                <label for="bookDescription">Description</label>
                <textarea id="bookDescription" name="description" rows="4"></textarea>
            </div>

            <div class="form-group">
                <label for="bookLink">Book Link/URL *</label>
                <input type="url" id="bookLink" name="book_link" placeholder="https://smartbooks.kodeit.co/fdfd9ea6a1.html" required>
                <small>Enter the embedded URL link for the book (e.g., https://smartbooks.kodeit.co/fdfd9ea6a1.html)</small>
            </div>

            <div class="form-group">
                <label for="coverImage">Cover Image</label>
                <input type="file" id="coverImage" name="cover_image" accept="image/*" onchange="previewCoverImage(this)">
                <small>Upload a cover image for the book (JPG, PNG, GIF)</small>
                <div id="coverPreview" class="cover-preview"></div>
            </div>

            <div class="form-actions">
                <button type="button" class="btn-cancel" onclick="closeBookModal()">Cancel</button>
                <button type="submit" class="btn-save">Save Book</button>
            </div>
        </form>
    </div>
</div>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<style>
body {
    overflow-x: hidden;
    width: 100% !important;
    max-width: 100% !important;
}

/* Hide default Moodle page header */
#page-header,
.page-header,
#region-main > h2,
#page-header-wrapper,
#page-header-content {
    display: none !important;
}

/* Hide admin sidebar */
.admin-sidebar,
#admin-sidebar,
.sidebar {
    display: none !important;
}

/* Main content area - Full Width Display */
.main-content {
    margin-left: 0 !important;
    margin-top: 0 !important;
    padding: 20px 20px 20px 20px !important;
    min-height: 100vh;
    width: 100% !important;
    max-width: 100% !important;
    box-sizing: border-box !important;
    overflow-x: hidden !important;
    overflow-y: auto !important;
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

/* Filter Cards Section */
.ebooks-filter-section {
    background: white;
    border-radius: 16px;
    padding: 20px;
    margin-bottom: 20px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    width: 100%;
    box-sizing: border-box;
}

.filter-section-header {
    font-size: 16px;
    font-weight: 600;
    color: #1e293b;
    margin-bottom: 15px;
}

.filter-section-wrapper {
    margin-bottom: 20px;
    transition: all 0.3s ease;
    width: 100%;
    box-sizing: border-box;
}

.filter-cards-container {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 20px;
    width: 100%;
    box-sizing: border-box;
}

.filter-card {
    flex: 1 1 calc(33.333% - 10px);
    min-width: 200px;
    max-width: 100%;
    background: white;
    border: 2px solid #e2e8f0;
    border-radius: 12px;
    padding: 15px;
    cursor: pointer;
    transition: all 0.3s ease;
    position: relative;
    display: flex;
    align-items: flex-start;
    gap: 12px;
    box-sizing: border-box;
}

.filter-card:hover {
    border-color: #cbd5e1;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}

.filter-card.selected {
    border-width: 2px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

.filter-card-icon {
    width: 48px;
    height: 48px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    flex-shrink: 0;
    color: #64748b;
}

.filter-card.selected .filter-card-icon {
    color: white;
}

.filter-card-content {
    flex: 1;
    min-width: 0;
}

.filter-card-title {
    font-size: 16px;
    font-weight: 600;
    color: #1e293b;
    margin: 0 0 6px 0;
}

.filter-card.selected .filter-card-title {
    color: #1e293b;
}

.filter-card-description {
    font-size: 14px;
    color: #64748b;
    line-height: 1.5;
    margin: 0 0 8px 0;
}

.filter-card-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 500;
    margin-top: 8px;
}

.filter-card-checkbox {
    position: absolute;
    top: 16px;
    right: 16px;
    width: 24px;
    height: 24px;
    border: 2px solid #cbd5e1;
    border-radius: 4px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s ease;
    flex-shrink: 0;
}

.filter-card input[type="checkbox"],
.filter-card input[type="radio"] {
    display: none;
}

.filter-card.selected .filter-card-checkbox {
    background: currentColor;
    border-color: currentColor;
}

.filter-card.selected .filter-card-checkbox::after {
    content: '\2713';
    color: white;
    font-size: 16px;
    font-weight: bold;
}

/* Category card colors */
.filter-card.category-plan.selected {
    border-color: #a855f7;
    color: #a855f7;
}

.filter-card.category-plan.selected .filter-card-icon {
    background: #a855f7;
}

.filter-card.category-plan.selected .filter-card-badge {
    background: #f3e8ff;
    color: #a855f7;
}

.filter-card.category-teach.selected {
    border-color: #10b981;
    color: #10b981;
}

.filter-card.category-teach.selected .filter-card-icon {
    background: #10b981;
}

.filter-card.category-teach.selected .filter-card-badge {
    background: #d1fae5;
    color: #10b981;
}

.filter-card.category-assess.selected {
    border-color: #f59e0b;
    color: #f59e0b;
}

.filter-card.category-assess.selected .filter-card-icon {
    background: #f59e0b;
}

.filter-card.category-assess.selected .filter-card-badge {
    background: #fef3c7;
    color: #f59e0b;
}

/* Level card colors */
.filter-card.level-1.selected {
    border-color: #14b8a6;
    color: #14b8a6;
}

.filter-card.level-1.selected .filter-card-icon {
    background: #14b8a6;
}

.filter-card.level-2.selected {
    border-color: #a855f7;
    color: #a855f7;
}

.filter-card.level-2.selected .filter-card-icon {
    background: #a855f7;
}

.filter-card.level-3.selected {
    border-color: #ec4899;
    color: #ec4899;
}

.filter-card.level-3.selected .filter-card-icon {
    background: #ec4899;
}

/* Subject card colors */
.filter-card.subject-english.selected {
    border-color: #ec4899;
    color: #ec4899;
}

.filter-card.subject-english.selected .filter-card-icon {
    background: #ec4899;
}

.filter-card.subject-maths.selected {
    border-color: #3b82f6;
    color: #3b82f6;
}

.filter-card.subject-maths.selected .filter-card-icon {
    background: #3b82f6;
}

.filter-card.subject-science.selected {
    border-color: #10b981;
    color: #10b981;
}

.filter-card.subject-science.selected .filter-card-icon {
    background: #10b981;
}

/* Book type card colors */
.filter-card.book-type-student.selected {
    border-color: #3b82f6;
    color: #3b82f6;
}

.filter-card.book-type-student.selected .filter-card-icon {
    background: #3b82f6;
}

.filter-card.book-type-teacher.selected {
    border-color: #a855f7;
    color: #a855f7;
}

.filter-card.book-type-teacher.selected .filter-card-icon {
    background: #a855f7;
}

.filter-card.book-type-practice.selected {
    border-color: #f59e0b;
    color: #f59e0b;
}

.filter-card.book-type-practice.selected .filter-card-icon {
    background: #f59e0b;
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

.btn-add-book {
    background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
    color: white;
    border: none;
    padding: 15px 30px;
    font-size: 1.1rem;
    font-weight: 600;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.3s ease;
    margin-bottom: 30px;
    display: inline-flex;
    align-items: center;
    gap: 10px;
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
    margin-bottom: 25px;
}

.books-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
    gap: 20px;
    width: 100%;
}

.book-card {
    background: white;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
    transition: all 0.3s ease;
    position: relative;
    display: flex;
    flex-direction: column;
}

.book-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
}

.book-cover {
    position: relative;
    width: 100%;
    height: 240px;
    overflow: hidden;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
}

.book-cover img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.default-cover {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
}

.book-gradient-overlay {
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    height: 55%;
    background: linear-gradient(to top, rgba(0, 0, 0, 0.85) 0%, rgba(0, 0, 0, 0.5) 50%, transparent 100%);
    pointer-events: none;
}

.book-content-overlay {
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    padding: 16px 14px;
    z-index: 2;
}

.book-title {
    font-size: 1.1rem;
    font-weight: 700;
    color: white;
    margin: 0 0 8px 0;
    line-height: 1.3;
}

.book-description {
    font-size: 0.85rem;
    color: rgba(255, 255, 255, 0.95);
    margin: 0 0 12px 0;
    line-height: 1.5;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.book-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.book-pill {
    padding: 4px 10px;
    background: rgba(255, 255, 255, 0.15);
    backdrop-filter: blur(10px);
    border: 1px solid rgba(255, 255, 255, 0.2);
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 500;
    color: white;
}

.book-actions-overlay {
    position: absolute;
    top: 12px;
    right: 12px;
    display: flex;
    gap: 8px;
    z-index: 3;
    opacity: 0;
    transition: opacity 0.3s ease;
}

.book-card:hover .book-actions-overlay {
    opacity: 1;
}

.btn-edit-book, .btn-delete-book {
    width: 36px;
    height: 36px;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    color: white;
    font-size: 0.9rem;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    backdrop-filter: blur(10px);
    background: rgba(255, 255, 255, 0.2);
    border: 1px solid rgba(255, 255, 255, 0.3);
}

.btn-edit-book:hover {
    background: rgba(40, 167, 69, 0.9);
    transform: scale(1.1);
}

.btn-delete-book:hover {
    background: rgba(220, 53, 69, 0.9);
    transform: scale(1.1);
}

.btn-view-book-cta {
    width: 100%;
    padding: 10px 16px;
    background: white;
    color: #1e293b;
    text-align: center;
    font-size: 0.9rem;
    font-weight: 600;
    border: none;
    cursor: pointer;
    transition: all 0.3s ease;
    text-decoration: none;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
}

.btn-view-book-cta:hover {
    background: #f8f9fa;
    color: #007bff;
    text-decoration: none;
}

.btn-view-book-cta i {
    font-size: 0.9rem;
}

.no-books {
    grid-column: 1 / -1;
    text-align: center;
    padding: 60px 20px;
    color: #999;
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
    background-color: rgba(0, 0, 0, 0.5);
    overflow: auto;
}

.modal-content {
    background: white;
    margin: 5% auto;
    padding: 0;
    border-radius: 15px;
    width: 90%;
    max-width: 600px;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
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
    line-height: 20px;
}

.close:hover {
    opacity: 0.7;
}

#bookForm {
    padding: 30px;
}

.form-group {
    margin-bottom: 25px;
}

.form-group label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
    color: #333;
}

.form-group input[type="text"],
.form-group input[type="url"],
.form-group textarea {
    width: 100%;
    padding: 12px;
    border: 2px solid #e0e0e0;
    border-radius: 8px;
    font-size: 1rem;
    transition: border-color 0.3s ease;
    box-sizing: border-box;
}

.form-group input[type="text"]:focus,
.form-group input[type="url"]:focus,
.form-group textarea:focus {
    outline: none;
    border-color: #667eea;
}

.form-group input[type="file"] {
    width: 100%;
    padding: 10px;
    border: 2px dashed #e0e0e0;
    border-radius: 8px;
    cursor: pointer;
}

.form-group small {
    display: block;
    margin-top: 5px;
    color: #666;
    font-size: 0.9rem;
}

.cover-preview {
    margin-top: 15px;
}

.cover-preview img {
    max-width: 200px;
    max-height: 200px;
    border-radius: 8px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
}

.form-actions {
    display: flex;
    justify-content: flex-end;
    gap: 15px;
    margin-top: 30px;
}

.btn-cancel,
.btn-save {
    padding: 12px 30px;
    border: none;
    border-radius: 8px;
    font-size: 1rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
}

.btn-cancel {
    background: #e0e0e0;
    color: #333;
}

.btn-cancel:hover {
    background: #d0d0d0;
}

.btn-save {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
}

.btn-save:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
}

@media (max-width: 1400px) {
    .filter-card {
        flex: 1 1 calc(33.333% - 10px);
        min-width: 220px;
    }
}

@media (max-width: 1200px) {
    .filter-card {
        flex: 1 1 calc(50% - 8px);
        min-width: 200px;
    }
}

@media (max-width: 992px) {
    .filter-card {
        flex: 1 1 calc(50% - 8px);
        min-width: 180px;
    }
    
    .main-content {
        padding: 15px 15px 15px 15px !important;
    }
}

@media (max-width: 768px) {
    .main-content {
        margin-left: 0 !important;
        width: 100% !important;
        max-width: 100% !important;
        padding: 20px 15px !important;
        box-sizing: border-box !important;
    }
    
    .ebooks-filter-section {
        padding: 15px;
    }
    
    .filter-cards-container {
        gap: 10px;
    }
    
    .filter-card {
        flex: 1 1 100%;
        min-width: 100%;
        max-width: 100%;
    }
    
    .filter-section-header {
        font-size: 14px;
    }
    
    .filter-section {
        padding: 15px !important;
    }
    
    .books-grid {
        grid-template-columns: 1fr;
        gap: 15px;
    }
    
    .modal-content {
        width: 95% !important;
        max-width: 95% !important;
        margin: 10% auto;
        box-sizing: border-box !important;
    }
}

/* Prevent any element from overflowing */
* {
    box-sizing: border-box;
}

.main-content * {
    max-width: 100% !important;
    word-wrap: break-word;
}
</style>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
// Filter Cards Interaction
document.addEventListener('DOMContentLoaded', function() {
    // Handle level filter cards (multiple selection - checkbox)
    const levelCards = document.querySelectorAll('#levelFilterCards .filter-card');
    levelCards.forEach(card => {
        const checkbox = card.querySelector('input[type="checkbox"]');
        
        card.addEventListener('click', function(e) {
            if (e.target.tagName === 'INPUT') return;
            
            if (checkbox) {
                checkbox.checked = !checkbox.checked;
                if (checkbox.checked) {
                    card.classList.add('selected');
                } else {
                    card.classList.remove('selected');
                }
                
                // Update URL with multiple levels
                const url = new URL(window.location.href);
                const selectedLevels = [];
                document.querySelectorAll('#levelFilterCards input[type="checkbox"]:checked').forEach(cb => {
                    selectedLevels.push(cb.value);
                });
                
                if (selectedLevels.length > 0) {
                    // Remove old level param if exists
                    url.searchParams.delete('level');
                    // Add multiple levels
                    selectedLevels.forEach(level => {
                        url.searchParams.append('levels[]', level);
                    });
                } else {
                    url.searchParams.delete('level');
                    url.searchParams.delete('levels[]');
                    // Clear dependent filters when levels are cleared
                    url.searchParams.delete('subject');
                    url.searchParams.delete('subjects[]');
                    url.searchParams.delete('book_type');
                }
                
                window.location.href = url.toString();
            }
        });
    });
    
    // Handle subject filter cards (multiple selection - checkbox)
    const subjectCards = document.querySelectorAll('#subjectFilterCards .filter-card');
    subjectCards.forEach(card => {
        const checkbox = card.querySelector('input[type="checkbox"]');
        
        card.addEventListener('click', function(e) {
            if (e.target.tagName === 'INPUT') return;
            
            if (checkbox) {
                checkbox.checked = !checkbox.checked;
                if (checkbox.checked) {
                    card.classList.add('selected');
                } else {
                    card.classList.remove('selected');
                }
                
                // Update URL with multiple subjects
                const url = new URL(window.location.href);
                const selectedSubjects = [];
                document.querySelectorAll('#subjectFilterCards input[type="checkbox"]:checked').forEach(cb => {
                    selectedSubjects.push(cb.value);
                });
                
                if (selectedSubjects.length > 0) {
                    // Remove old subject param if exists
                    url.searchParams.delete('subject');
                    // Add multiple subjects
                    selectedSubjects.forEach(subject => {
                        url.searchParams.append('subjects[]', subject);
                    });
                } else {
                    url.searchParams.delete('subject');
                    url.searchParams.delete('subjects[]');
                    // Clear dependent filter when subjects are cleared
                    url.searchParams.delete('book_type');
                }
                
                window.location.href = url.toString();
            }
        });
    });
    
    // Handle book type filter cards (single selection - radio with deselection)
    const bookTypeCards = document.querySelectorAll('#bookTypeFilterCards .filter-card');
    bookTypeCards.forEach(card => {
        const radio = card.querySelector('input[type="radio"]');
        
        card.addEventListener('click', function(e) {
            if (e.target.tagName === 'INPUT') return;
            
            const url = new URL(window.location.href);
            const isCurrentlySelected = card.classList.contains('selected');
            
            // If clicking the same card that's already selected, deselect it
            if (isCurrentlySelected && radio && radio.checked) {
                card.classList.remove('selected');
                if (radio) radio.checked = false;
                url.searchParams.delete('book_type');
            } else {
                // Uncheck all book type cards
                bookTypeCards.forEach(c => {
                    c.classList.remove('selected');
                    const r = c.querySelector('input[type="radio"]');
                    if (r) r.checked = false;
                });
                
                // Check clicked card
                card.classList.add('selected');
                if (radio) radio.checked = true;
                url.searchParams.set('book_type', radio.value);
            }
            
            window.location.href = url.toString();
        });
    });
    
    // Handle cascading filter logic
    function updateFilterVisibility() {
        const selectedLevels = document.querySelectorAll('#levelFilterCards input[type="checkbox"]:checked');
        const selectedSubjects = document.querySelectorAll('#subjectFilterCards input[type="checkbox"]:checked');
        const subjectSection = document.getElementById('subjectSectionWrapper');
        const bookTypeSection = document.getElementById('bookTypeSectionWrapper');
        
        // Show/hide subject section based on level selection
        if (selectedLevels.length > 0) {
            if (subjectSection) subjectSection.style.display = 'block';
        } else {
            if (subjectSection) subjectSection.style.display = 'none';
            // Clear subject selections when levels are cleared
            document.querySelectorAll('#subjectFilterCards input[type="checkbox"]').forEach(cb => {
                cb.checked = false;
            });
            document.querySelectorAll('#subjectFilterCards .filter-card').forEach(card => {
                card.classList.remove('selected');
            });
        }
        
        // Show/hide book type section based on subject selection
        if (selectedSubjects.length > 0) {
            if (bookTypeSection) bookTypeSection.style.display = 'block';
        } else {
            if (bookTypeSection) bookTypeSection.style.display = 'none';
            // Clear book type selection when subjects are cleared
            document.querySelectorAll('#bookTypeFilterCards input[type="radio"]').forEach(radio => {
                radio.checked = false;
            });
            document.querySelectorAll('#bookTypeFilterCards .filter-card').forEach(card => {
                card.classList.remove('selected');
            });
        }
    }
    
    // Update visibility on level changes
    levelCards.forEach(card => {
        const checkbox = card.querySelector('input[type="checkbox"]');
        if (checkbox) {
            checkbox.addEventListener('change', function() {
                updateFilterVisibility();
            });
        }
    });
    
    // Update visibility on subject changes
    subjectCards.forEach(card => {
        const checkbox = card.querySelector('input[type="checkbox"]');
        if (checkbox) {
            checkbox.addEventListener('change', function() {
                updateFilterVisibility();
            });
        }
    });
    
    // Initial visibility update
    updateFilterVisibility();
    
    // Prevent label from triggering input twice
    document.querySelectorAll('.filter-card input').forEach(input => {
        input.addEventListener('click', function(e) {
            e.stopPropagation();
        });
    });
});

// Modal functions
function openAddBookModal() {
    document.getElementById('bookModal').style.display = 'block';
    document.getElementById('modalTitle').textContent = 'Add New Book';
    document.getElementById('bookForm').reset();
    document.getElementById('bookId').value = '';
    document.getElementById('coverPreview').innerHTML = '';
    
    // Sync hidden fields with current filter selections
    const selectedLevels = document.querySelectorAll('#levelFilterCards input[type="checkbox"]:checked');
    const selectedSubjects = document.querySelectorAll('#subjectFilterCards input[type="checkbox"]:checked');
    const selectedBookType = document.querySelector('#bookTypeFilterCards input[type="radio"]:checked');
    
    if (selectedLevels.length > 0) {
        document.getElementById('bookLevel').value = selectedLevels[0].value;
    }
    if (selectedSubjects.length > 0) {
        document.getElementById('bookSubject').value = selectedSubjects[0].value;
    }
    if (selectedBookType) {
        document.getElementById('bookType').value = selectedBookType.value;
    }
}

function closeBookModal() {
    document.getElementById('bookModal').style.display = 'none';
}

function previewCoverImage(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            document.getElementById('coverPreview').innerHTML = '<img src="' + e.target.result + '" alt="Cover Preview">';
        };
        reader.readAsDataURL(input.files[0]);
    }
}

function editBook(bookId) {
    fetch('<?php echo $CFG->wwwroot; ?>/theme/remui_kids/admin/ajax/get_book.php?id=' + bookId)
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                alert('Error: ' + data.error);
                return;
            }
            document.getElementById('bookId').value = data.id;
            document.getElementById('bookTitle').value = data.title;
            document.getElementById('bookDescription').value = data.description || '';
            document.getElementById('bookLink').value = data.book_link;
            if (data.cover_image) {
                document.getElementById('coverPreview').innerHTML = '<img src="' + data.cover_image + '" alt="Cover Preview">';
            }
            document.getElementById('modalTitle').textContent = 'Edit Book';
            document.getElementById('bookModal').style.display = 'block';
        })
        .catch(error => {
            alert('Error fetching book data: ' + error.message);
        });
}

function deleteBook(bookId) {
    if (confirm('Are you sure you want to delete this book?')) {
        fetch('<?php echo $CFG->wwwroot; ?>/theme/remui_kids/admin/ajax/delete_book.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'id=' + bookId + '&sesskey=<?php echo sesskey(); ?>'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert('Error deleting book: ' + data.message);
            }
        })
        .catch(error => {
            alert('Error: ' + error.message);
        });
    }
}

// Form submission
document.getElementById('bookForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    formData.append('sesskey', '<?php echo sesskey(); ?>');
    
    fetch('<?php echo $CFG->wwwroot; ?>/theme/remui_kids/admin/ajax/save_book.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Error saving book: ' + data.message);
        }
    })
    .catch(error => {
        alert('Error: ' + error.message);
    });
});

// Close modal when clicking outside
window.onclick = function(event) {
    const modal = document.getElementById('bookModal');
    if (event.target == modal) {
        closeBookModal();
    }
}
</script>

<?php
echo $OUTPUT->footer();
?>
