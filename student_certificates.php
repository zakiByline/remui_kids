<?php
/**
 * Student Certificates Page
 * 
 * Displays all certificates assigned, viewed, or available to the student
 * across all their enrolled courses.
 *
 * @package    theme_remui_kids
 * @copyright  2025 Kodeit
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/theme/remui_kids/lib/certificate_completion.php');

// Require login
require_login();

global $USER, $DB, $PAGE, $OUTPUT, $CFG;

// Set up the page
$PAGE->set_context(context_system::instance());
$PAGE->set_url('/theme/remui_kids/student_certificates.php');
$PAGE->set_title('My Certificates');
$PAGE->set_heading('My Certificates');
$PAGE->set_pagelayout('base');

// Get all certificates for the current user
$certificates = theme_remui_kids_get_student_certificates($USER->id, true);
$certificate_counts = theme_remui_kids_get_student_certificates_count($USER->id);

// Start output
echo $OUTPUT->header();
?>

<style>
.certificates-container {
    max-width: 1200px;
    margin: 20px auto;
    padding: 20px;
}

.certificates-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 30px;
    border-radius: 12px;
    margin-bottom: 30px;
    box-shadow: 0 4px 20px rgba(102, 126, 234, 0.3);
}

.certificates-header h1 {
    margin: 0 0 10px 0;
    font-size: 28px;
    font-weight: 700;
}

.certificates-header p {
    margin: 0;
    opacity: 0.9;
    font-size: 16px;
}

.certificates-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.stat-card {
    background: white;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    border-left: 4px solid #667eea;
}

.stat-card h3 {
    margin: 0 0 10px 0;
    font-size: 14px;
    color: #6b7280;
    text-transform: uppercase;
    font-weight: 600;
}

.stat-card .stat-value {
    font-size: 32px;
    font-weight: 700;
    color: #667eea;
    margin: 0;
}

.certificates-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.certificate-card {
    background: white;
    border-radius: 12px;
    padding: 24px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    transition: transform 0.2s, box-shadow 0.2s;
    border-top: 4px solid #667eea;
}

.certificate-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 4px 16px rgba(0,0,0,0.15);
}

.certificate-card-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    margin-bottom: 16px;
}

.certificate-icon {
    width: 56px;
    height: 56px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    color: white;
    flex-shrink: 0;
}

.certificate-type-badge {
    background: #f3f4f6;
    color: #6b7280;
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
}

.certificate-title {
    font-size: 20px;
    font-weight: 700;
    color: #111827;
    margin: 0 0 8px 0;
}

.certificate-course {
    font-size: 14px;
    color: #6b7280;
    margin: 0 0 16px 0;
}

.certificate-meta {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin-bottom: 20px;
    padding-top: 16px;
    border-top: 1px solid #e5e7eb;
}

.certificate-meta-item {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 14px;
    color: #6b7280;
}

.certificate-meta-item i {
    width: 16px;
    color: #9ca3af;
}

.certificate-actions {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
}

.certificate-btn {
    flex: 1;
    min-width: 120px;
    padding: 10px 20px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    text-decoration: none;
    text-align: center;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

.certificate-btn-primary {
    background: #667eea;
    color: white;
}

.certificate-btn-primary:hover {
    background: #5568d3;
    color: white;
    text-decoration: none;
    transform: translateY(-1px);
}

.certificate-btn-secondary {
    background: #f3f4f6;
    color: #667eea;
}

.certificate-btn-secondary:hover {
    background: #e5e7eb;
    color: #5568d3;
    text-decoration: none;
}

.no-certificates {
    text-align: center;
    padding: 60px 20px;
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.no-certificates-icon {
    font-size: 64px;
    color: #d1d5db;
    margin-bottom: 20px;
}

.no-certificates h2 {
    color: #6b7280;
    margin-bottom: 10px;
}

.no-certificates p {
    color: #9ca3af;
    margin: 0;
}

.filter-tabs {
    display: flex;
    gap: 12px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}

.filter-tab {
    padding: 10px 20px;
    background: white;
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    color: #6b7280;
    text-decoration: none;
    font-weight: 600;
    transition: all 0.2s;
    cursor: pointer;
}

.filter-tab:hover {
    border-color: #667eea;
    color: #667eea;
    text-decoration: none;
}

.filter-tab.active {
    background: #667eea;
    border-color: #667eea;
    color: white;
}

@media (max-width: 768px) {
    .certificates-grid {
        grid-template-columns: 1fr;
    }
    
    .certificates-stats {
        grid-template-columns: 1fr;
    }
    
    .certificate-actions {
        flex-direction: column;
    }
    
    .certificate-btn {
        width: 100%;
    }
}
</style>

<div class="certificates-container">
    <div class="certificates-header">
        <h1><i class="fa fa-certificate"></i> My Certificates</h1>
        <p>View and download all your certificates from completed courses</p>
    </div>

    <?php if (!empty($certificates)): ?>
        <div class="certificates-stats">
            <div class="stat-card">
                <h3>Total Certificates</h3>
                <p class="stat-value"><?php echo $certificate_counts['total']; ?></p>
            </div>
            <?php if ($certificate_counts['customcert'] > 0): ?>
            <div class="stat-card">
                <h3>CustomCert</h3>
                <p class="stat-value"><?php echo $certificate_counts['customcert']; ?></p>
            </div>
            <?php endif; ?>
            <?php if ($certificate_counts['approval'] > 0): ?>
            <div class="stat-card">
                <h3>Approval System</h3>
                <p class="stat-value"><?php echo $certificate_counts['approval']; ?></p>
            </div>
            <?php endif; ?>
            <?php if ($certificate_counts['iomadcertificate'] > 0): ?>
            <div class="stat-card">
                <h3>IOMad</h3>
                <p class="stat-value"><?php echo $certificate_counts['iomadcertificate']; ?></p>
            </div>
            <?php endif; ?>
            <?php if ($certificate_counts['track'] > 0): ?>
            <div class="stat-card">
                <h3>Track</h3>
                <p class="stat-value"><?php echo $certificate_counts['track']; ?></p>
            </div>
            <?php endif; ?>
        </div>

        <div class="certificates-grid">
            <?php foreach ($certificates as $cert): ?>
                <div class="certificate-card">
                    <div class="certificate-card-header">
                        <div class="certificate-icon">
                            <i class="fa fa-certificate"></i>
                        </div>
                        <span class="certificate-type-badge"><?php echo htmlspecialchars($cert['type']); ?></span>
                    </div>
                    
                    <h3 class="certificate-title"><?php echo htmlspecialchars($cert['certificate_name']); ?></h3>
                    <p class="certificate-course">
                        <i class="fa fa-book"></i> <?php echo htmlspecialchars($cert['course_name']); ?>
                    </p>
                    
                    <div class="certificate-meta">
                        <?php if (!empty($cert['issued_date'])): ?>
                        <div class="certificate-meta-item">
                            <i class="fa fa-calendar"></i>
                            <span>Issued: <?php echo userdate($cert['issued_date'], '%B %d, %Y'); ?></span>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($cert['certificate_code'])): ?>
                        <div class="certificate-meta-item">
                            <i class="fa fa-key"></i>
                            <span>Code: <?php echo htmlspecialchars($cert['certificate_code']); ?></span>
                        </div>
                        <?php endif; ?>
                        
                        <div class="certificate-meta-item">
                            <i class="fa fa-check-circle"></i>
                            <span>Status: <?php echo ucfirst($cert['status']); ?></span>
                        </div>
                    </div>
                    
                    <div class="certificate-actions">
                        <?php if (!empty($cert['view_url'])): ?>
                        <a href="<?php echo $cert['view_url']; ?>" 
                           class="certificate-btn certificate-btn-primary" 
                           target="_blank">
                            <i class="fa fa-eye"></i> View
                        </a>
                        <?php endif; ?>
                        
                        <?php if (!empty($cert['download_url'])): ?>
                        <?php
                        $download_url = $cert['download_url'];
                        // Add sesskey if needed for approval certificates
                        if (!empty($cert['download_url_needs_sesskey'])) {
                            $download_url->param('sesskey', sesskey());
                        }
                        ?>
                        <a href="<?php echo $download_url; ?>" 
                           class="certificate-btn certificate-btn-secondary" 
                           target="_blank">
                            <i class="fa fa-download"></i> Download
                        </a>
                        <?php endif; ?>
                        
                        <?php if (!empty($cert['course_id'])): ?>
                        <a href="<?php echo new moodle_url('/course/view.php', array('id' => $cert['course_id'])); ?>" 
                           class="certificate-btn certificate-btn-secondary">
                            <i class="fa fa-arrow-right"></i> Course
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="no-certificates">
            <div class="no-certificates-icon">
                <i class="fa fa-certificate"></i>
            </div>
            <h2>No Certificates Yet</h2>
            <p>Complete courses to earn certificates. Your certificates will appear here once they are issued.</p>
            <a href="<?php echo new moodle_url('/my'); ?>" class="certificate-btn certificate-btn-primary" style="margin-top: 20px; display: inline-block;">
                <i class="fa fa-arrow-left"></i> Go to My Courses
            </a>
        </div>
    <?php endif; ?>
</div>

<?php
echo $OUTPUT->footer();

