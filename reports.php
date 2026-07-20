<?php
/**
 * NewUI v4.0 - Reports
 *
 * Reporting page with multiple report types, period filtering,
 * sortable tables, summary cards, CSV export, and print view.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/inc/i18n.php';
require_once __DIR__ . '/inc/rbac.php';

// 2026-07-04 (GH #13) — pick the session profile matching the
// client's cookie (TCADMOBILE vs PHPSESSID). Without this, a
// browser holding a mobile cookie opens an empty desktop session
// here and bounces to login -> redirect loop.
require_once __DIR__ . '/inc/session-bootstrap.php';
sess_bootstrap_auto();
session_start();

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
rbac_require_screen('screen.reports');
require_once __DIR__ . '/inc/force-pw-change.php';
force_pw_change_redirect();


$user     = e($_SESSION['user']);
$level = current_role_name();
$theme    = $_SESSION['day_night'] ?? 'Day';
$bs_theme = ($theme === 'Night') ? 'dark' : 'light';
$csrf     = csrf_token();
?>
<!DOCTYPE html>
<html lang="<?php echo e(i18n_lang()); ?>" data-bs-theme="<?php echo $bs_theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?php echo e($csrf); ?>">
    <title><?php echo e(t('page.reports', 'Reports')); ?> — <?php echo e(t('login.title', 'Tickets NewUI')); ?> <?php echo NEWUI_VERSION; ?></title>

    <!-- Vendor CSS -->
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">

    <!-- App CSS -->
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?php echo NEWUI_VERSION; ?>">
    <link rel="stylesheet" href="assets/css/reports.css?v=<?php echo NEWUI_VERSION; ?>">
</head>
<body>

<?php include_once NEWUI_ROOT . '/inc/navbar.php'; ?>
</header>

<!-- Page Content -->
<div class="container-fluid p-3">

    <!-- Page title + export buttons -->
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h5 class="mb-0">
            <i class="bi bi-bar-chart-line text-primary me-2"></i><?php echo e(t('page.reports', 'Reports')); ?>
        </h5>
        <div class="d-flex gap-2 align-items-center">
            <button type="button" class="btn btn-sm btn-outline-success" id="btnExportCSV" disabled>
                <i class="bi bi-filetype-csv me-1"></i>Export CSV
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="btnPrint" disabled>
                <i class="bi bi-printer me-1"></i>Print
            </button>
            <a href="index.php" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>Dashboard
            </a>
        </div>
    </div>

    <!-- Report Type Tabs -->
    <div class="card mb-3">
        <div class="card-body py-2">
            <div class="d-flex flex-wrap align-items-center gap-2">
                <span class="text-body-secondary small fw-semibold me-1">Report:</span>
                <div class="btn-group btn-group-sm" role="group" id="reportTypeBtns">
                    <button type="button" class="btn btn-primary" data-report="incident_report">
                        <i class="bi bi-file-earmark-text me-1"></i>Incidents
                    </button>
                    <button type="button" class="btn btn-outline-primary" data-report="incident_summary">
                        <i class="bi bi-graph-up me-1"></i>Summary
                    </button>
                    <button type="button" class="btn btn-outline-primary" data-report="dispatch_log">
                        <i class="bi bi-broadcast me-1"></i>Dispatch Log
                    </button>
                    <button type="button" class="btn btn-outline-primary" data-report="unit_log">
                        <i class="bi bi-truck me-1"></i>Unit Log
                    </button>
                    <button type="button" class="btn btn-outline-primary" data-report="facility_log">
                        <i class="bi bi-hospital me-1"></i>Facility Log
                    </button>
                    <button type="button" class="btn btn-outline-primary" data-report="notes_log">
                        <i class="bi bi-sticky me-1"></i>Notes Log
                    </button>
                    <button type="button" class="btn btn-outline-primary" data-report="after_action">
                        <i class="bi bi-journal-text me-1"></i>After Action
                    </button>
                    <a href="par-reports.php" class="btn btn-outline-primary" title="PAR (Personnel Accountability) compliance + per-incident timeline">
                        <i class="bi bi-shield-check me-1"></i>PAR
                    </a>
                </div>
                <span class="text-body-secondary small fw-semibold ms-2 me-1">Personnel:</span>
                <div class="btn-group btn-group-sm" role="group" id="personnelReportBtns">
                    <button type="button" class="btn btn-outline-primary" data-report="roster_snapshot">
                        <i class="bi bi-people me-1"></i>Roster
                    </button>
                    <button type="button" class="btn btn-outline-primary" data-report="time_summary">
                        <i class="bi bi-clock-history me-1"></i>Time Summary
                    </button>
                    <button type="button" class="btn btn-outline-primary" data-report="license_expirations">
                        <i class="bi bi-card-checklist me-1"></i>Expirations
                    </button>
                    <button type="button" class="btn btn-outline-primary" data-report="membership_due">
                        <i class="bi bi-cash-coin me-1"></i>Dues Due
                    </button>
                    <button type="button" class="btn btn-outline-primary" data-report="inactive_members">
                        <i class="bi bi-person-dash me-1"></i>Inactive
                    </button>
                    <button type="button" class="btn btn-outline-primary" data-report="dmr_inventory">
                        <i class="bi bi-broadcast-pin me-1"></i>DMR IDs
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters Row -->
    <div class="card mb-3">
        <div class="card-body py-2">
            <div class="row g-2 align-items-end">
                <!-- Period Selector -->
                <div class="col-auto">
                    <label class="form-label small mb-1">Period</label>
                    <select class="form-select form-select-sm" id="periodSelect" style="width: auto;">
                        <option value="today">Today</option>
                        <option value="this_week">This Week</option>
                        <option value="last_week">Last Week</option>
                        <option value="this_month" selected>This Month</option>
                        <option value="last_month">Last Month</option>
                        <option value="this_year">This Year</option>
                        <option value="last_year">Last Year</option>
                        <option value="custom">Custom Range</option>
                    </select>
                </div>

                <!-- Custom Date Range (hidden by default) -->
                <div class="col-auto d-none" id="customDateRange">
                    <label class="form-label small mb-1">From</label>
                    <input type="date" class="form-control form-control-sm" id="startDate">
                </div>
                <div class="col-auto d-none" id="customDateRange2">
                    <label class="form-label small mb-1">To</label>
                    <input type="date" class="form-control form-control-sm" id="endDate">
                </div>

                <!-- Responder Filter -->
                <div class="col-auto" id="responderFilterCol">
                    <label class="form-label small mb-1">Responder</label>
                    <select class="form-select form-select-sm" id="responderFilter" style="width: auto;">
                        <option value="0">All Responders</option>
                    </select>
                </div>

                <!-- Incident Filter (for after-action) -->
                <div class="col-auto d-none" id="incidentFilterCol">
                    <label class="form-label small mb-1">Incident #</label>
                    <input type="number" class="form-control form-control-sm" id="incidentFilter"
                           placeholder="Incident ID" min="1" style="width: 130px;">
                </div>

                <!-- Run Button -->
                <div class="col-auto">
                    <button type="button" class="btn btn-sm btn-primary" id="btnRunReport">
                        <i class="bi bi-play-fill me-1"></i>Run Report
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="row g-2 mb-3 d-none" id="summaryCards">
        <!-- Populated by JS -->
    </div>

    <!-- Report Title & Period Label -->
    <div class="d-flex align-items-center justify-content-between mb-2 d-none" id="reportHeader">
        <div>
            <h6 class="mb-0" id="reportTitle">—</h6>
            <small class="text-body-secondary" id="periodLabel">—</small>
        </div>
        <small class="text-body-secondary" id="rowCount">—</small>
    </div>

    <!-- Results Table -->
    <div class="card">
        <div class="card-body p-0">
            <!-- Loading spinner -->
            <div class="text-center py-5 d-none" id="loadingSpinner">
                <div class="spinner-border text-primary" role="status"></div>
                <div class="mt-2 text-body-secondary">Loading report...</div>
            </div>

            <!-- Empty state -->
            <div class="text-center py-5" id="emptyState">
                <i class="bi bi-bar-chart-line" style="font-size: 2rem; opacity: 0.3;"></i>
                <div class="mt-2 text-body-secondary">Select a report type and click Run Report</div>
            </div>

            <!-- Table -->
            <div class="table-responsive d-none" id="reportTableWrap">
                <table class="table table-sm table-striped table-hover mb-0" id="reportTable">
                    <thead class="sticky-top">
                        <tr id="reportTableHead"></tr>
                    </thead>
                    <tbody id="reportTableBody"></tbody>
                </table>
            </div>

            <!-- No data -->
            <div class="text-center py-4 d-none" id="noDataState">
                <i class="bi bi-inbox" style="font-size: 2rem; opacity: 0.3;"></i>
                <div class="mt-2 text-body-secondary">No data found for the selected period</div>
            </div>
        </div>
    </div>

    <!-- After Action: Incident Info Panel (shown only for after_action report) -->
    <div class="card mt-3 d-none" id="afterActionPanel">
        <div class="card-header py-1">
            <i class="bi bi-info-circle me-2"></i>
            <span class="fw-semibold small">Incident Information</span>
        </div>
        <div class="card-body py-2 small" id="afterActionInfo">
        </div>
    </div>

</div>

<!-- CSRF token for JS -->
<input type="hidden" id="csrfToken" value="<?php echo e($csrf); ?>">

<!-- Vendor JS -->
<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="assets/js/toolbar.js?v=<?php echo NEWUI_VERSION; ?>"></script>

<!-- App JS -->
<script src="assets/js/theme-manager.js"></script>
<script src="assets/js/reports.js?v=<?php echo NEWUI_VERSION; ?>"></script>

</body>
</html>
