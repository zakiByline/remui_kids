<?php
/**
 * Analytics Maintenance Page
 *
 * @package    theme_remui_kids
 */

require_once('../../../config.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_url('/theme/remui_kids/admin/analytics_maintenance.php');
$PAGE->set_title('Analytics Dashboard');
$PAGE->set_heading('Analytics Dashboard');

echo $OUTPUT->header();

require_once(__DIR__ . '/includes/admin_sidebar.php');

echo "<style>
    .maintenance-wrapper {
        position: fixed;
        top: 0;
        left: 280px;
        width: calc(100vw - 280px);
        height: 100vh;
        background: radial-gradient(circle at top, #eef2ff 0%, #f8fafc 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        text-align: center;
        padding: 40px 20px;
        box-sizing: border-box;
    }
    .maintenance-card {
        background: #ffffff;
        border-radius: 24px;
        box-shadow: 0 35px 80px rgba(15, 23, 42, 0.15);
        padding: 50px 60px;
        max-width: 560px;
        border: 1px solid rgba(148, 163, 184, 0.35);
    }
    .maintenance-icon {
        width: 90px;
        height: 90px;
        border-radius: 26px;
        margin: 0 auto 25px auto;
        background: linear-gradient(135deg, #60a5fa 0%, #2563eb 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        color: #fff;
        font-size: 40px;
        box-shadow: 0 18px 30px rgba(37, 99, 235, 0.35);
    }
    .maintenance-title {
        font-size: 2rem;
        margin-bottom: 12px;
        color: #0f172a;
        font-weight: 700;
    }
    .maintenance-text {
        color: #475569;
        font-size: 1rem;
        margin-bottom: 32px;
        line-height: 1.6;
    }
    .maintenance-actions {
        display: flex;
        justify-content: center;
        gap: 16px;
        flex-wrap: wrap;
    }
    .maintenance-btn {
        padding: 12px 22px;
        border-radius: 999px;
        font-weight: 600;
        border: none;
        cursor: pointer;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    .maintenance-btn.primary {
        background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
        color: #fff;
        box-shadow: 0 15px 30px rgba(37, 99, 235, 0.3);
    }
    .maintenance-btn.secondary {
        background: #f1f5f9;
        color: #0f172a;
        border: 1px solid #cbd5f5;
    }
    .maintenance-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 18px 35px rgba(15, 23, 42, 0.15);
    }
    @media (max-width: 768px) {
        .maintenance-wrapper {
            left: 0;
            width: 100vw;
            padding: 30px 15px;
        }
        .maintenance-card {
            padding: 35px 30px;
        }
    }
</style>";

echo "<div class='maintenance-wrapper'>";
echo "  <div class='maintenance-card'>";
echo "      <div class='maintenance-icon'><i class='fa fa-chart-line'></i></div>";
echo "      <h1 class='maintenance-title'>Analytics Dashboard</h1>";
echo "      <p class='maintenance-text'>We're revamping the analytics experience to give you richer insights. The dashboard is temporarily unavailable while we finish up the upgrades.</p>";
echo "      <div class='maintenance-actions'>";
echo "          <button class='maintenance-btn primary' onclick=\"window.location.href='{$CFG->wwwroot}/theme/remui_kids/admin/courses.php'\">Back to Courses</button>";
echo "          <button class='maintenance-btn secondary' onclick='history.back()'>Go Back</button>";
echo "      </div>";
echo "  </div>";
echo "</div>";

echo $OUTPUT->footer();