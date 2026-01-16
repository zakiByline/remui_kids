<?php
/**
 * Styles for the school performance analytics dashboard.
 */
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');

.admin-analytics-page {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    background: #f3f4f6;
}

.admin-main-content {
   
    min-height: 100vh;
    background: #f3f4f6;
}

.school-analytics-wrapper {
    padding: 2.5rem;
}

.step-card {
    background: #fff;
    border-radius: 16px;
    padding: 1.75rem;
    box-shadow: 0 10px 30px rgba(15, 23, 42, 0.08);
    margin-bottom: 1.5rem;
}

.step-indicator {
    font-size: 1rem;
    font-weight: 600;
    color: #2563eb;
    margin-bottom: .75rem;
}

.step-description {
    color: #475569;
    margin: 0;
}

.school-selector-form {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 1rem 1.5rem;
    align-items: end;
}

.school-selector-form label {
    font-size: .875rem;
    font-weight: 600;
    color: #475569;
}

.school-selector-form select,
.school-selector-form input {
    width: 100%;
    margin-top: .35rem;
    border-radius: 10px;
    border: 1px solid #d4d9e3;
    padding: .65rem .85rem;
    font-size: .95rem;
}

.primary-btn {
    align-self: center;
    padding: .85rem 1.5rem;
    border-radius: 12px;
    border: none;
    font-weight: 600;
    background: linear-gradient(135deg, #2563eb, #7c3aed);
    color: #fff;
    cursor: pointer;
    transition: transform .2s ease, box-shadow .2s ease;
}

.primary-btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 12px 24px rgba(124, 58, 237, 0.25);
}

.analytics-section {
    background: #fff;
    border-radius: 16px;
    padding: 1.75rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 10px 30px rgba(15, 23, 42, 0.08);
}

.section-header {
    margin-bottom: 1rem;
}

.section-header h3 {
    margin: 0;
}

.section-header small {
    color: #94a3b8;
}

.overview-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: 1rem;
}

.stat-card {
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 1.25rem;
    text-align: center;
    background: #f8fafc;
}

.stat-card span {
    display: block;
    font-size: .85rem;
    color: #64748b;
    margin-bottom: .35rem;
}

.stat-card strong {
    font-size: 1.75rem;
    color: #111827;
}

.grid-two {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
    gap: 1.5rem;
}

.grid-three {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
}

.grid-four {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 1rem;
}

.overview-card {
    background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 1.25rem 1rem;
    text-align: center;
    transition: all 0.3s ease;
    box-shadow: 0 2px 8px rgba(15, 23, 42, 0.04);
    position: relative;
    overflow: hidden;
}

.overview-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: linear-gradient(90deg, #2563eb, #7c3aed);
    transform: scaleX(0);
    transition: transform 0.3s ease;
}

.overview-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(15, 23, 42, 0.1);
    border-color: #cbd5e1;
}

.overview-card:hover::before {
    transform: scaleX(1);
}

.overview-card.full-width {
    grid-column: 1 / -1;
}

.overview-card .stat-label {
    display: block;
    font-size: 0.75rem;
    font-weight: 600;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin-bottom: 0.5rem;
    line-height: 1.2;
}

.overview-card .stat-value {
    display: block;
    font-size: 1.75rem;
    font-weight: 700;
    color: #1e293b;
    line-height: 1.2;
    margin: 0;
}

.overview-card:nth-child(1) .stat-value {
    color: #2563eb;
}

.overview-card:nth-child(2) .stat-value {
    color: #10b981;
}

.overview-card:nth-child(3) .stat-value {
    color: #f59e0b;
}

.overview-card:nth-child(4) .stat-value {
    color: #8b5cf6;
}

.overview-card:nth-child(5) .stat-value {
    color: #ef4444;
}

.overview-card:nth-child(6) .stat-value {
    color: #06b6d4;
}

.overview-card:nth-child(7) .stat-value {
    color: #84cc16;
}

.overview-card:nth-child(8) .stat-value {
    color: #f97316;
}

.simple-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 1rem;
}

.simple-table th,
.simple-table td {
    padding: .65rem .5rem;
    border-bottom: 1px solid #e5e7eb;
    font-size: .9rem;
}

.simple-table th {
    text-transform: uppercase;
    font-size: .75rem;
    color: #94a3b8;
    letter-spacing: .05em;
}

.trend-table {
    margin-top: 1.25rem;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    overflow: hidden;
}

.trend-row {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    padding: .6rem 1rem;
    border-bottom: 1px solid #e2e8f0;
}

.trend-header {
    background: #f8fafc;
    font-weight: 600;
    color: #475569;
}

.resource-list {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 1rem;
    margin-top: 1rem;
}

.resource-card {
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 1rem;
}

.resource-row,
.attendance-row {
    display: flex;
    justify-content: space-between;
    padding: .4rem 0;
    border-bottom: 1px dashed #e2e8f0;
    font-size: .9rem;
}

.attendance-list {
    margin-top: 1rem;
}

.empty-state {
    padding: 2rem;
    text-align: center;
    background: #fffbe6;
    border: 1px solid #fde68a;
    border-radius: 14px;
    color: #92400e;
}

.chart-container {
    position: relative;
    width: 100%;
    height: 400px;
    margin: 1rem 0;
}

.chart-container canvas {
    max-width: 100% !important;
    max-height: 100% !important;
    height: 400px !important;
}

.chart-container-small {
    position: relative;
    width: 100%;
    height: 300px;
    margin: 1rem 0;
}

.chart-container-small canvas {
    max-width: 100% !important;
    max-height: 100% !important;
    height: 300px !important;
}

.chart-container-medium {
    position: relative;
    width: 100%;
    height: 350px;
    margin: 1rem 0;
}

.chart-container-medium canvas {
    max-width: 100% !important;
    max-height: 100% !important;
    height: 350px !important;
}

.section-nav {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-bottom: 1.5rem;
}

.section-nav a {
    padding: 0.4rem 0.9rem;
    border-radius: 999px;
    border: 1px solid #d4d9e3;
    font-size: 0.85rem;
    color: #475569;
    text-decoration: none;
    transition: all 0.2s ease;
}

.section-nav a:hover {
    border-color: #2563eb;
    color: #2563eb;
}

.pagination {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 0.5rem;
    margin-top: 1rem;
    padding: 1rem 0;
}

.pagination button {
    padding: 0.5rem 0.75rem;
    border: 1px solid #d4d9e3;
    border-radius: 6px;
    background: white;
    color: #475569;
    cursor: pointer;
    font-size: 0.875rem;
    transition: all 0.2s ease;
}

.pagination button:hover:not(:disabled) {
    border-color: #2563eb;
    color: #2563eb;
    background: #f0f9ff;
}

.pagination button:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.pagination button.active {
    background: #2563eb;
    color: white;
    border-color: #2563eb;
}

.pagination .page-info {
    padding: 0 0.75rem;
    color: #64748b;
    font-size: 0.875rem;
}

.paginated-list .resource-row,
.paginated-list .attendance-row {
    display: none;
}

.paginated-list .resource-row.show,
.paginated-list .attendance-row.show {
    display: flex;
}

@media (max-width: 1024px) {
    .admin-main-content {
        margin-left: 0;
    }

    .school-analytics-wrapper {
        padding: 1.5rem;
    }
}
</style>
