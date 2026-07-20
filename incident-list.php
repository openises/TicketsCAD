<?php
/**
 * NewUI v4.0 - Incident List
 *
 * Sortable, filterable table of all incidents.
 * Status tabs, type group filter, auto-refresh for live operations.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/inc/i18n.php';
require_once __DIR__ . '/inc/rbac.php';
require_once __DIR__ . '/inc/incident-number.php';

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
rbac_require_screen('screen.incidents');
require_once __DIR__ . '/inc/force-pw-change.php';
force_pw_change_redirect();

// Phase 99p (Eric beta 2026-06-29) — admin-configured label for
// the rendered case number ("Incident" / "Case" / "Call" / ...).
$incNumLabel = incnum_get_label();

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
    <title><?php echo e(t('inclist.title', 'Incidents')); ?> — <?php echo e(t('login.title', 'Tickets NewUI')); ?> <?php echo NEWUI_VERSION; ?></title>

    <!-- Vendor CSS -->
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">

    <!-- App CSS -->
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?php echo NEWUI_VERSION; ?>">
    <link rel="stylesheet" href="assets/css/incident-list.css?v=<?php echo NEWUI_VERSION; ?>">
</head>
<body>

<?php include_once NEWUI_ROOT . '/inc/navbar.php'; ?>
</header>

<!-- Page Content -->
<div class="container-fluid p-3">

    <!-- Page title + controls -->
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h5 class="mb-0">
            <i class="bi bi-list-ul text-primary me-2"></i><?php echo e(t('inclist.title', 'Incidents')); ?>
        </h5>
        <div class="d-flex gap-2 align-items-center">
            <!-- Auto-refresh toggle -->
            <div class="form-check form-switch mb-0">
                <input class="form-check-input" type="checkbox" id="autoRefreshToggle">
                <label class="form-check-label small text-body-secondary" for="autoRefreshToggle">
                    <?php echo e(t('inclist.auto_refresh', 'Auto-refresh')); ?> <span class="d-none" id="refreshCountdown"></span>
                </label>
            </div>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="btnIncidentCols" title="Customize visible columns">
                <i class="bi bi-layout-three-columns"></i>
            </button>
            <a href="new-incident.php" class="btn btn-sm btn-primary">
                <i class="bi bi-plus-lg me-1"></i><?php echo e(t('newinc.title', 'New Incident')); ?>
            </a>
            <a href="search.php" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-search me-1"></i><?php echo e(t('btn.search', 'Search')); ?>
            </a>
            <a href="index.php" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i><?php echo e(t('profile.back_to_dashboard', 'Dashboard')); ?>
            </a>
        </div>
    </div>

    <!-- Alert area -->
    <div id="alertArea"></div>

    <!-- Filter bar -->
    <div class="d-flex align-items-center gap-3 mb-3">
        <!-- Status tabs -->
        <ul class="nav nav-pills nav-pills-sm" id="statusTabs">
            <li class="nav-item">
                <button class="nav-link active btn-sm py-1 px-2 small status-tab" data-status="0">
                    <?php echo e(t('inclist.filter.all', 'All')); ?> <span class="badge bg-secondary ms-1" id="countAll">--</span>
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link btn-sm py-1 px-2 small status-tab" data-status="2">
                    <?php echo e(t('newinc.status.open', 'Open')); ?> <span class="badge bg-success ms-1" id="countOpen">--</span>
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link btn-sm py-1 px-2 small status-tab" data-status="1">
                    <?php echo e(t('newinc.status.closed', 'Closed')); ?> <span class="badge bg-secondary ms-1" id="countClosed">--</span>
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link btn-sm py-1 px-2 small status-tab" data-status="3">
                    <?php echo e(t('newinc.status.scheduled', 'Scheduled')); ?> <span class="badge bg-info ms-1" id="countScheduled">--</span>
                </button>
            </li>
        </ul>

        <!-- Group filter -->
        <select class="form-select form-select-sm" id="groupFilter" style="width:auto;max-width:200px;">
            <option value=""><?php echo e(t('inclist.filter.all_groups', 'All Groups')); ?></option>
        </select>

        <!-- Severity filter -->
        <select class="form-select form-select-sm" id="sevFilter" style="width:auto;max-width:120px;">
            <option value=""><?php echo e(t('inclist.filter.all_sev', 'All Sev')); ?></option>
            <option value="0"><?php echo e(t('inclist.sev.low', 'Low')); ?></option>
            <option value="1"><?php echo e(t('inclist.sev.medium', 'Medium')); ?></option>
            <option value="2"><?php echo e(t('inclist.sev.high', 'High')); ?></option>
        </select>

        <div class="ms-auto small text-body-secondary" id="listSummary"><?php echo e(t('common.loading', 'Loading...')); ?></div>
    </div>

    <!-- Incidents table -->
    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 small" id="incidentTable">
                    <thead>
                        <tr>
                            <!-- Phase 99p — was the internal id column;
                                 now shows the admin-configured case
                                 number, sorted by it instead of the
                                 internal id. Dispatchers don't care
                                 about the internal id. -->
                            <th class="sortable ps-3" data-sort="incident_number" data-col-id="incident_number" data-col-label="<?php echo e($incNumLabel); ?> #" style="width:80px;"><?php echo e($incNumLabel); ?> #</th>
                            <th class="sortable"      data-sort="severity" data-col-id="severity" data-col-label="Severity" style="width:40px;" title="<?php echo e(t('newinc.label.severity', 'Severity')); ?>"><?php echo e(t('inclist.col.severity', 'Sev')); ?></th>
                            <th class="sortable"      data-sort="date"     data-col-id="date"     data-col-label="Date"><?php echo e(t('inclist.col.opened', 'Date')); ?></th>
                            <th class="sortable"      data-sort="scope"    data-col-id="scope"    data-col-label="Scope"><?php echo e(t('inclist.col.scope', 'Scope / Title')); ?></th>
                            <th class="sortable"      data-sort="type"     data-col-id="type"     data-col-label="Type"><?php echo e(t('inclist.col.type', 'Type')); ?></th>
                            <th class="sortable"      data-sort="city"     data-col-id="location" data-col-label="Location"><?php echo e(t('inclist.col.location', 'Location')); ?></th>
                            <th class="sortable"      data-sort="status"   data-col-id="status"   data-col-label="Status" style="width:80px;"><?php echo e(t('inclist.col.status', 'Status')); ?></th>
                            <th                                            data-col-id="units"    data-col-label="Units" style="width:50px;"><?php echo e(t('dash.table.units', 'Units')); ?></th>
                            <th class="sortable"      data-sort="updated"  data-col-id="updated"  data-col-label="Updated"><?php echo e(t('inclist.col.updated', 'Updated')); ?></th>
                        </tr>
                    </thead>
                    <tbody id="incidentBody">
                        <tr>
                            <td colspan="9" class="text-center py-4">
                                <div class="spinner-border spinner-border-sm text-primary me-2"></div>Loading incidents...
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Pagination -->
    <div class="d-flex align-items-center justify-content-between mt-2">
        <div class="small text-body-secondary" id="pageInfo"></div>
        <nav>
            <ul class="pagination pagination-sm mb-0" id="paginationList"></ul>
        </nav>
    </div>

</div>

<!-- CSRF token for JS -->
<input type="hidden" id="csrfToken" value="<?php echo e($csrf); ?>">

<!-- Vendor JS -->
<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="assets/js/toolbar.js?v=<?php echo asset_v('assets/js/toolbar.js'); ?>"></script>

<!-- App JS -->
<script src="assets/js/theme-manager.js?v=<?php echo asset_v('assets/js/theme-manager.js'); ?>"></script>
<script src="assets/js/screen-prefs.js?v=<?php echo asset_v('assets/js/screen-prefs.js'); ?>"></script>
<script src="assets/js/incident-list.js?v=<?php echo asset_v('assets/js/incident-list.js'); ?>"></script>
<script>
    // Phase 17 follow-on (2026-06-11): incidents list column customization.
    document.addEventListener('DOMContentLoaded', function () {
        if (window.ScreenPrefs && window.ScreenPrefs.applyToTable) {
            window.ScreenPrefs.applyToTable('incidents', '#incidentTable', { openerSelector: '#btnIncidentCols' });
        }
    });
</script>

</body>
</html>
