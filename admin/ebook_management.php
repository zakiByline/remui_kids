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
$PAGE->set_heading('E-Book Management');
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

<!-- Include admin sidebar -->
<?php include(__DIR__ . '/includes/admin_sidebar.php'); ?>

            <div class="main-content">
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

                <!-- Add Book Button (shown when all filters are selected) -->
                <?php if ($selected_level && $selected_subject && $selected_book_type): ?>
                <div class="add-book-section">
                    <button class="btn-add-book" onclick="openAddBookModal()">
                        <i class="fa fa-plus-circle"></i> Add New Book
                    </button>
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
                                    <div class="book-overlay">
                                        <a href="<?php echo htmlspecialchars($book->book_link); ?>" target="_blank" class="btn-view-book">
                                            <i class="fa fa-eye"></i> View Book
                                        </a>
                                        <button class="btn-edit-book" onclick="editBook(<?php echo $book->id; ?>)">
                                            <i class="fa fa-edit"></i>
                                        </button>
                                        <button class="btn-delete-book" onclick="deleteBook(<?php echo $book->id; ?>)">
                                            <i class="fa fa-trash"></i>
                                        </button>
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

<!-- Add/Edit Book Modal -->
<div id="bookModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="modalTitle">Add New Book</h2>
            <span class="close" onclick="closeBookModal()">&times;</span>
        </div>
        <form id="bookForm" enctype="multipart/form-data">
            <input type="hidden" id="bookId" name="book_id">
            <input type="hidden" id="bookLevel" name="level" value="<?php echo htmlspecialchars($selected_level); ?>">
            <input type="hidden" id="bookSubject" name="subject" value="<?php echo htmlspecialchars($selected_subject); ?>">
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

/* Main content area - Full Width Display */
.main-content {
    margin-left: 260px !important;
    margin-top: 0 !important;
    padding: 30px 20px 30px 0 !important;
    min-height: 100vh;
    width: calc(100vw - 260px) !important;
    max-width: calc(100vw - 260px) !important;
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

.filter-section {
    margin-bottom: 30px;
    padding: 25px 20px;
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
    width: 100% !important;
    max-width: 100% !important;
    box-sizing: border-box !important;
    overflow-x: hidden !important;
    overflow-y: visible !important;
}

.filter-title {
    font-size: 1.2rem;
    font-weight: 600;
    color: #333;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.filter-boxes {
    display: flex;
    flex-wrap: wrap;
    gap: 15px;
    width: 100% !important;
    max-width: 100% !important;
    box-sizing: border-box !important;
    margin: 0;
    padding: 0;
    justify-content: flex-start;
}

.filter-box {
    flex: 0 0 auto;
    min-width: 180px;
    width: calc(33.333% - 10px) !important;
    max-width: calc(33.333% - 10px) !important;
    padding: 25px 20px;
    background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
    border-radius: 12px;
    text-align: center;
    cursor: pointer;
    transition: all 0.3s ease;
    border: 3px solid transparent;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 10px;
    box-sizing: border-box !important;
    margin: 0;
}

.filter-box i {
    font-size: 2.2rem;
    color: #667eea;
    margin-bottom: 8px;
}

.filter-box span {
    font-size: 1rem;
    font-weight: 600;
    color: #333;
}

.filter-box:hover {
    transform: translateY(-5px);
    box-shadow: 0 5px 20px rgba(102, 126, 234, 0.3);
    border-color: #667eea;
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
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 25px;
    width: 100%;
}

.book-card {
    background: white;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
    transition: all 0.3s ease;
    position: relative;
}

.book-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
}

.book-cover {
    position: relative;
    width: 100%;
    height: 300px;
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
    font-size: 0.9rem;
    transition: all 0.3s ease;
}

.btn-view-book {
    background: #007bff;
}

.btn-edit-book {
    background: #28a745;
}

.btn-delete-book {
    background: #dc3545;
}

.btn-view-book:hover,
.btn-edit-book:hover,
.btn-delete-book:hover {
    transform: scale(1.05);
}

.book-info {
    padding: 20px;
}

.book-title {
    font-size: 1.2rem;
    font-weight: 600;
    color: #333;
    margin: 0 0 10px 0;
}

.book-description {
    font-size: 0.95rem;
    color: #666;
    margin: 0 0 15px 0;
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

.book-meta span {
    padding: 5px 12px;
    background: #f0f0f0;
    border-radius: 20px;
    font-size: 0.85rem;
    color: #666;
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

@media (max-width: 1200px) {
    .filter-box {
        width: calc(50% - 8px) !important;
        max-width: calc(50% - 8px) !important;
        min-width: 150px;
    }
}

@media (max-width: 992px) {
    .filter-box {
        width: calc(50% - 8px) !important;
        max-width: calc(50% - 8px) !important;
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
    
    .filter-section {
        padding: 15px !important;
    }
    
    .filter-boxes {
        gap: 10px;
    }
    
    .filter-box {
        min-width: 100% !important;
        max-width: 100% !important;
        flex: 0 0 100% !important;
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
// Filter handling
document.querySelectorAll('.filter-box').forEach(box => {
    box.addEventListener('click', function() {
        const filterType = this.getAttribute('data-filter');
        const filterValue = this.getAttribute('data-value');
        
        const url = new URL(window.location.href);
        
        if (filterType === 'level') {
            url.searchParams.set('level', filterValue);
            url.searchParams.delete('subject');
            url.searchParams.delete('book_type');
        } else if (filterType === 'subject') {
            url.searchParams.set('subject', filterValue);
            url.searchParams.delete('book_type');
        } else if (filterType === 'book_type') {
            url.searchParams.set('book_type', filterValue);
        }
        
        window.location.href = url.toString();
    });
});

// Modal functions
function openAddBookModal() {
    document.getElementById('bookModal').style.display = 'block';
    document.getElementById('modalTitle').textContent = 'Add New Book';
    document.getElementById('bookForm').reset();
    document.getElementById('bookId').value = '';
    document.getElementById('coverPreview').innerHTML = '';
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
