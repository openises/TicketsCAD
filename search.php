<?php
/**
 * NewUI v4.0 - Incident Search
 *
 * Search past and current incidents by multiple criteria.
 * Results displayed in a sortable table with links to incident detail.
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
rbac_require_screen('screen.search');
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
    <title><?php echo e(t('page.incident_search', 'Incident Search')); ?> — <?php echo e(t('login.title', 'Tickets NewUI')); ?> <?php echo NEWUI_VERSION; ?></title>

    <!-- Vendor CSS -->
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">

    <!-- App CSS -->
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?php echo NEWUI_VERSION; ?>">
    <link rel="stylesheet" href="assets/css/search.css?v=<?php echo NEWUI_VERSION; ?>">
</head>
<body>

<?php include_once NEWUI_ROOT . '/inc/navbar.php'; ?>
</header>

<!-- Page Content -->
<div class="container-fluid p-3">

    <!-- Page title -->
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h5 class="mb-0">
            <i class="bi bi-search text-primary me-2"></i><?php echo e(t('page.incident_search', 'Incident Search')); ?>
        </h5>
        <div class="d-flex gap-2">
            <a href="index.php" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i><?php echo e(t('profile.back_to_dashboard', 'Dashboard')); ?>
            </a>
        </div>
    </div>

    <!-- Search Form -->
    <div class="card mb-3">
        <div class="card-body py-2">
            <form id="searchForm" autocomplete="off">
                <div class="row g-2 align-items-end">
                    <!-- Text search -->
                    <div class="col-md-4 col-lg-3">
                        <label class="form-label text-body-secondary mb-0 small">Search</label>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text"><i class="bi bi-search"></i></span>
                            <input type="text" class="form-control form-control-sm" id="searchText"
                                   placeholder="Scope, description, address, caller..." autofocus>
                        </div>
                    </div>

                    <!-- Type filter -->
                    <div class="col-md-2">
                        <label class="form-label text-body-secondary mb-0 small">Type</label>
                        <select class="form-select form-select-sm" id="searchType">
                            <option value="">All Types</option>
                        </select>
                    </div>

                    <!-- Status filter -->
                    <div class="col-md-2 col-lg-1">
                        <label class="form-label text-body-secondary mb-0 small">Status</label>
                        <select class="form-select form-select-sm" id="searchStatus">
                            <option value="">All</option>
                            <option value="2">Open</option>
                            <option value="1">Closed</option>
                            <option value="3">Scheduled</option>
                        </select>
                    </div>

                    <!-- Severity filter -->
                    <div class="col-md-2 col-lg-1">
                        <label class="form-label text-body-secondary mb-0 small">Severity</label>
                        <select class="form-select form-select-sm" id="searchSeverity">
                            <option value="">All</option>
                            <option value="0">Low</option>
                            <option value="1">Medium</option>
                            <option value="2">High</option>
                        </select>
                    </div>

                    <!-- Date from -->
                    <div class="col-md-2 col-lg-1">
                        <label class="form-label text-body-secondary mb-0 small">From</label>
                        <input type="date" class="form-control form-control-sm" id="searchDateFrom">
                    </div>

                    <!-- Date to -->
                    <div class="col-md-2 col-lg-1">
                        <label class="form-label text-body-secondary mb-0 small">To</label>
                        <input type="date" class="form-control form-control-sm" id="searchDateTo">
                    </div>

                    <!-- City -->
                    <div class="col-md-2 col-lg-1">
                        <label class="form-label text-body-secondary mb-0 small">City</label>
                        <input type="text" class="form-control form-control-sm" id="searchCity" placeholder="City...">
                    </div>

                    <!-- Buttons -->
                    <div class="col-auto">
                        <button type="submit" class="btn btn-sm btn-primary">
                            <i class="bi bi-search me-1"></i>Search
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="btnClear">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Alert area -->
    <div id="alertArea"></div>

    <!-- Results summary -->
    <div class="d-flex align-items-center justify-content-between mb-2">
        <div class="small text-body-secondary" id="resultsSummary">Enter search criteria above.</div>
        <div class="d-flex gap-2 align-items-center">
            <select class="form-select form-select-sm" id="pageSize" style="width:auto;">
                <option value="25">25</option>
                <option value="50" selected>50</option>
                <option value="100">100</option>
                <option value="200">200</option>
            </select>
            <span class="small text-body-secondary">per page</span>
        </div>
    </div>

    <!-- Results table -->
    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 small" id="resultsTable">
                    <thead>
                        <tr>
                            <th class="sortable ps-3" data-sort="id" style="width:60px;">#</th>
                            <th class="sortable" data-sort="date">Date</th>
                            <th class="sortable" data-sort="scope">Scope / Title</th>
                            <th class="sortable" data-sort="type">Type</th>
                            <th class="sortable" data-sort="severity" style="width:70px;">Sev</th>
                            <th class="sortable" data-sort="status" style="width:80px;">Status</th>
                            <th class="sortable" data-sort="city">City</th>
                            <th>Contact</th>
                            <th style="width:50px;">Units</th>
                        </tr>
                    </thead>
                    <tbody id="resultsBody">
                        <tr>
                            <td colspan="9" class="text-center text-body-secondary py-4">
                                <i class="bi bi-search me-1"></i>Use the form above to search for incidents.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Pagination -->
    <div class="d-flex align-items-center justify-content-between mt-2" id="paginationRow">
        <div class="small text-body-secondary" id="pageInfo"></div>
        <nav>
            <ul class="pagination pagination-sm mb-0" id="paginationList">
            </ul>
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
<script src="assets/js/search.js?v=<?php echo asset_v('assets/js/search.js'); ?>"></script>

</body>
</html>
