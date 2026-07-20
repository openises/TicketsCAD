<?php
/**
 * NewUI v4.0 - Facilities List
 *
 * Browse all facilities in a two-column layout:
 *   Left:  Searchable/filterable table of all facilities
 *   Right: Leaflet map with facility markers
 *
 * Data loaded client-side via api/facilities.php
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
rbac_require_screen('screen.facilities');
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
    <title><?php echo e(t('facs.title', 'Facilities')); ?> — <?php echo e(t('login.title', 'Tickets NewUI')); ?> <?php echo NEWUI_VERSION; ?></title>

    <!-- Vendor CSS -->
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/vendor/leaflet/leaflet.css">

    <!-- App CSS -->
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?php echo NEWUI_VERSION; ?>">
    <link rel="stylesheet" href="assets/css/facilities.css?v=<?php echo NEWUI_VERSION; ?>">
</head>
<body>

<?php include_once NEWUI_ROOT . '/inc/navbar.php'; ?>
</header>

<!-- Page Content -->
<div class="container-fluid p-3">

    <!-- Page title + action buttons -->
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h5 class="mb-0">
            <i class="bi bi-hospital text-primary me-2"></i><?php echo e(t('facs.title', 'Facilities')); ?>
            <span class="badge bg-secondary ms-2" id="facilityCount">0</span>
        </h5>
        <div class="d-flex gap-2">
            <a href="index.php" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i><?php echo e(t('profile.back_to_dashboard', 'Dashboard')); ?>
            </a>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="btnFacilitiesCols" title="Customize visible columns">
                <i class="bi bi-layout-three-columns"></i>
            </button>
            <a href="facility-edit.php" class="btn btn-sm btn-success">
                <i class="bi bi-plus-lg me-1"></i><?php echo e(t('facs.btn.new', 'New Facility')); ?>
            </a>
        </div>
    </div>

    <!-- Alert area -->
    <div id="alertArea"></div>

    <!-- Search + filter bar -->
    <div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
        <div class="input-group input-group-sm" style="max-width: 320px;">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input type="text" class="form-control form-control-sm" id="facilitySearch"
                   placeholder="<?php echo e(t('facs.filter.search', 'Search facilities...')); ?>" autocomplete="off">
        </div>
        <div class="btn-group btn-group-sm" role="group" id="typeFilters">
            <button type="button" class="btn btn-outline-secondary active" data-filter="all"><?php echo e(t('inclist.filter.all', 'All')); ?></button>
            <!-- Type filter buttons populated by JS -->
        </div>
        <div class="form-check form-switch small ms-2">
            <input class="form-check-input" type="checkbox" id="chkShowHidden" role="switch">
            <label class="form-check-label text-body-secondary" for="chkShowHidden"><?php echo e(t('facs.show_hidden', 'Show Hidden')); ?></label>
        </div>
    </div>

    <!-- Loading spinner -->
    <div class="text-center py-5" id="loadingSpinner">
        <div class="spinner-border text-primary" role="status"></div>
        <div class="mt-2 text-body-secondary"><?php echo e(t('common.loading', 'Loading...')); ?></div>
    </div>

    <!-- Main content -->
    <div class="row g-3 d-none" id="mainContent">

        <!-- ═══════════ LEFT COLUMN: Facilities Table ═══════════ -->
        <div class="col-lg-7">
            <div class="card">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0 align-middle" id="facilitiesTable">
                            <thead>
                                <tr>
                                    <th class="ps-3" data-col-id="name"    data-col-label="Name">Name</th>
                                    <th             data-col-id="type"    data-col-label="Type">Type</th>
                                    <th             data-col-id="status"  data-col-label="Status">Status</th>
                                    <th class="text-center" data-col-id="beds" data-col-label="Beds A/O">Beds A/O</th>
                                    <th             data-col-id="city"    data-col-label="City">City</th>
                                    <th             data-col-id="updated" data-col-label="Updated">Updated</th>
                                </tr>
                            </thead>
                            <tbody id="facilitiesBody">
                                <!-- Populated by JS -->
                            </tbody>
                        </table>
                    </div>
                    <div class="text-center text-body-secondary py-3 d-none" id="noResults">
                        No facilities match your search criteria.
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══════════ RIGHT COLUMN: Map ═══════════ -->
        <div class="col-lg-5">
            <div class="sticky-top" style="top: 100px;">
                <div class="card">
                    <div class="card-header d-flex align-items-center py-1">
                        <i class="bi bi-map me-2"></i>
                        <span class="fw-semibold small">Facility Map</span>
                        <span class="text-body-secondary small ms-auto" id="mapMarkerCount">0 markers</span>
                    </div>
                    <div class="card-body p-0">
                        <div id="facilitiesMap" style="height: 500px;"></div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- CSRF token for JS -->
<input type="hidden" id="csrfToken" value="<?php echo e($csrf); ?>">

<!-- Vendor JS -->
<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="assets/js/toolbar.js?v=<?php echo NEWUI_VERSION; ?>"></script>
<script src="assets/vendor/leaflet/leaflet.js"></script>
<script src="assets/js/leaflet-mobile-fit.js?v=<?php echo function_exists("asset_v")?asset_v("assets/js/leaflet-mobile-fit.js"):NEWUI_VERSION; ?>"></script>
<script src="assets/js/leaflet-quadkey.js"></script>
<script src="assets/js/map-prefs.js"></script>

<!-- App JS -->
<script src="assets/js/theme-manager.js"></script>
<script src="assets/js/facility-status.js?v=<?php echo function_exists('asset_v') ? asset_v('assets/js/facility-status.js') : NEWUI_VERSION; ?>"></script>
<script src="assets/js/facilities.js?v=<?php echo NEWUI_VERSION; ?>"></script>
<script src="assets/js/screen-prefs.js?v=<?php echo NEWUI_VERSION; ?>"></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        if (window.ScreenPrefs && window.ScreenPrefs.applyToTable) {
            window.ScreenPrefs.applyToTable('facilities', '#facilitiesTable', { openerSelector: '#btnFacilitiesCols' });
        }
    });
</script>

</body>
</html>
