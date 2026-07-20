<?php
/**
 * NewUI v4.0 - Units (Responders) List
 *
 * Two-column layout:
 *   Left:  Filterable table of all responders with status badges
 *   Right: Leaflet map showing responder locations colored by status
 *
 * Data loaded client-side via api/responders.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/inc/i18n.php';
require_once __DIR__ . '/inc/rbac.php';
require_once __DIR__ . '/inc/session-bootstrap.php';

sess_bootstrap_auto();
session_start();

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
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
    <title><?php echo e(t('units.title', 'Units')); ?> — <?php echo e(t('login.title', 'Tickets NewUI')); ?> <?php echo NEWUI_VERSION; ?></title>

    <!-- Vendor CSS -->
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/vendor/leaflet/leaflet.css">

    <!-- App CSS -->
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?php echo NEWUI_VERSION; ?>">
    <link rel="stylesheet" href="assets/css/units.css?v=<?php echo NEWUI_VERSION; ?>">
</head>
<body>

<?php include_once NEWUI_ROOT . '/inc/navbar.php'; ?>
</header>

<!-- Page Content -->
<div class="container-fluid p-3">

    <!-- Page title + action buttons -->
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h5 class="mb-0">
            <i class="bi bi-people text-primary me-2"></i><?php echo e(t('units.title', 'Units')); ?>
            <span class="badge bg-secondary ms-2" id="unitCount">0</span>
            <span class="badge bg-primary ms-2 d-none" id="selectedUnitBadge"></span>
        </h5>
        <!-- 2026-07-03 (Eric) — situation-view-style per-unit actions.
             Buttons are disabled until a row is selected. Clicking a
             row selects it (highlight); double-click opens detail. The
             per-row "open" chevron on the name column keeps the
             single-click-navigates escape hatch for existing muscle
             memory. -->
        <div class="d-flex gap-2 flex-wrap">
            <a href="index.php" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i><?php echo e(t('profile.back_to_dashboard', 'Dashboard')); ?>
            </a>
            <button type="button" class="btn btn-sm btn-outline-primary" id="btnUnitView" disabled
                    title="<?php echo e(t('unit.btn.view.title', 'Open the selected unit\'s detail page')); ?>">
                <i class="bi bi-eye me-1"></i><?php echo e(t('unit.btn.view', 'View')); ?>
            </button>
            <button type="button" class="btn btn-sm btn-outline-primary" id="btnUnitEdit" disabled
                    title="<?php echo e(t('unit.btn.edit.title', 'Edit the selected unit')); ?>">
                <i class="bi bi-pencil me-1"></i><?php echo e(t('btn.edit', 'Edit')); ?>
            </button>
            <button type="button" class="btn btn-sm btn-outline-warning" id="btnUnitDispatch" disabled
                    title="<?php echo e(t('unit.btn.dispatch.title', 'Dispatch selected unit to an open incident')); ?>">
                <i class="bi bi-send me-1"></i><?php echo e(t('unit.btn.dispatch', 'Dispatch')); ?>
            </button>
            <button type="button" class="btn btn-sm btn-outline-info" id="btnUnitStatus" disabled
                    title="<?php echo e(t('unit.btn.status.title', 'Change selected unit\'s status')); ?>">
                <i class="bi bi-toggles me-1"></i><?php echo e(t('unit.btn.status', 'Status')); ?>
            </button>
            <button type="button" class="btn btn-sm btn-outline-success" id="btnUnitNote" disabled
                    title="<?php echo e(t('unit.btn.note.title', 'Record a note about selected unit')); ?>">
                <i class="bi bi-journal-plus me-1"></i><?php echo e(t('unit.btn.note', 'Note')); ?>
            </button>
            <a href="unit-edit.php" class="btn btn-sm btn-success">
                <i class="bi bi-plus-lg me-1"></i><?php echo e(t('units.btn.new', 'New Unit')); ?>
            </a>
        </div>
    </div>

    <!-- Alert area -->
    <div id="alertArea"></div>

    <!-- Loading spinner -->
    <div class="text-center py-5" id="loadingSpinner">
        <div class="spinner-border text-primary" role="status"></div>
        <div class="mt-2 text-body-secondary"><?php echo e(t('common.loading', 'Loading...')); ?></div>
    </div>

    <!-- Main content -->
    <div class="row g-3 d-none" id="mainContent">

        <!-- Left Column: Unit Table -->
        <div class="col-lg-7">

            <!-- Search and filter controls -->
            <div class="card mb-3">
                <div class="card-body py-2">
                    <div class="row g-2 align-items-center">
                        <div class="col-md-6">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text"><i class="bi bi-search"></i></span>
                                <input type="text" class="form-control form-control-sm" id="unitSearch"
                                       placeholder="<?php echo e(t('units.filter.search', 'Search units...')); ?>" autocomplete="off">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="btn-group btn-group-sm w-100" role="group" id="statusFilter">
                                <button type="button" class="btn btn-outline-secondary active" data-filter="all">
                                    All
                                </button>
                                <button type="button" class="btn btn-outline-success" data-filter="available">
                                    Available
                                </button>
                                <button type="button" class="btn btn-outline-warning" data-filter="in_service">
                                    In Service
                                </button>
                                <button type="button" class="btn btn-outline-danger" data-filter="unavailable">
                                    Unavailable
                                </button>
                            </div>
                            <!-- Phase 115 (#64) — filter the unit list by event zone.
                                 Hidden until JS finds units that are in a zone. -->
                            <select id="unitZoneFilter" class="form-select form-select-sm mt-2 d-none"
                                    aria-label="<?php echo e(t('units.filter.zone', 'Filter by zone')); ?>">
                                <option value="all"><?php echo e(t('units.filter.all_zones', 'All zones')); ?></option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Units table -->
            <div class="card">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <!-- Phase 17 (2026-06-11) — Customize-columns
                             button. Header + body rendered dynamically
                             by units.js from the user's screen prefs. -->
                        <div class="d-flex justify-content-end px-2 py-1 border-bottom">
                            <button type="button" class="btn btn-xs btn-outline-secondary" id="btnCustomizeCols">
                                <i class="bi bi-layout-three-columns me-1"></i>Customize columns
                            </button>
                        </div>
                        <table class="table table-sm table-striped table-hover mb-0 small" id="unitsTable">
                            <caption class="visually-hidden">Units list — columns chosen by the user, populated by units.js</caption>
                            <thead><tr id="unitsTableHead"><th scope="col">Unit</th></tr></thead>
                            <tbody id="unitsTableBody">
                                <tr><td class="text-center text-body-secondary py-3" id="unitsLoadingRow">Loading...</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <!-- Phase 118 (#89) — client-side pagination footer.
                         Page size defaults to the admin setting (page_size,
                         exposed as window.LIST_PAGE_SIZE); the Rows selector
                         overrides it for this view only. Populated by units.js. -->
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 px-2 py-1 border-top small d-none" id="unitsPager">
                        <div class="text-body-secondary" id="unitsPageInfo"></div>
                        <div class="d-flex align-items-center gap-2">
                            <label class="text-body-secondary mb-0" for="unitsPageSize">Rows:</label>
                            <select class="form-select form-select-sm w-auto py-0" id="unitsPageSize" title="Rows per page (this view only)"></select>
                            <nav aria-label="Units pages"><ul class="pagination pagination-sm mb-0" id="unitsPageNav"></ul></nav>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Column: Map -->
        <div class="col-lg-5">
            <div class="sticky-top" style="top: 70px;">
                <div class="card">
                    <div class="card-header d-flex align-items-center py-1">
                        <i class="bi bi-map me-2"></i>
                        <span class="fw-semibold small">Unit Locations</span>
                        <span class="text-body-secondary small ms-auto" id="mapMarkerCount">0 on map</span>
                    </div>
                    <div class="card-body p-0">
                        <div id="unitsMap" style="height: 500px;"></div>
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

<!-- Shared unit-action modal (Dispatch / Status / Note) -->
<?php include_once NEWUI_ROOT . '/inc/unit-actions-modal.php'; ?>

<!-- App JS -->
<script src="assets/js/theme-manager.js"></script>
<script src="assets/js/screen-prefs.js?v=<?php echo NEWUI_VERSION; ?>"></script>
<script src="assets/js/unit-actions.js?v=<?php echo function_exists("asset_v")?asset_v("assets/js/unit-actions.js"):NEWUI_VERSION; ?>"></script>
<script src="assets/js/units.js?v=<?php echo NEWUI_VERSION; ?>"></script>

<!-- 2026-07-03 (Eric) — title-bar action wiring. Rows are selectable
     via single click (highlight, enable buttons); double-click opens
     the unit's detail page. Buttons act on the currently selected
     row. Nothing here reaches into units.js internals — we key off
     .unit-row[data-unit-id] which units.js already emits, and read
     the visible name cell for the modal handle. -->
<style>
    .unit-row.selected { background: rgba(13, 110, 253, 0.12); }
    [data-bs-theme="dark"] .unit-row.selected { background: rgba(13, 110, 253, 0.28); }
</style>
<script>
(function () {
    'use strict';
    var selectedId = 0;
    var selectedHandle = '';

    function updateBadge() {
        var b = document.getElementById('selectedUnitBadge');
        if (!b) return;
        if (selectedId && selectedHandle) {
            b.textContent = selectedHandle;
            b.classList.remove('d-none');
        } else {
            b.classList.add('d-none');
        }
    }
    function setEnabled(on) {
        ['btnUnitView','btnUnitEdit','btnUnitDispatch','btnUnitStatus','btnUnitNote']
            .forEach(function (id) {
                var el = document.getElementById(id);
                if (el) el.disabled = !on;
            });
    }
    function selectRow(row) {
        if (!row) return;
        var prev = document.querySelector('.unit-row.selected');
        if (prev) prev.classList.remove('selected');
        row.classList.add('selected');
        selectedId = parseInt(row.getAttribute('data-unit-id'), 10) || 0;
        // Best-effort handle: first cell text (units.js renders name
        // in the first visible column by default). If the config has
        // reordered columns we still get *something* useful for the
        // modal title; the id is what actually drives the endpoints.
        var firstCell = row.querySelector('td');
        selectedHandle = firstCell ? (firstCell.textContent || '').trim() : '';
        if (!selectedHandle) selectedHandle = 'unit #' + selectedId;
        updateBadge();
        setEnabled(selectedId > 0);
    }

    // Single capture-phase handler on document — runs BEFORE the
    // per-row navigate listener units.js binds. It both selects the
    // row and stops the units.js listener from firing (which would
    // redirect the page). Double-click still opens detail via the
    // dblclick listener below.
    document.addEventListener('click', function (ev) {
        var row = ev.target.closest && ev.target.closest('.unit-row');
        if (!row) return;
        // Map-zoom icon — let units.js handle it; don't select or
        // block. (Its own handler returns after zooming.)
        if (ev.target.closest('.unit-loc-btn')) return;
        selectRow(row);
        // Prevent units.js's per-row click listener from redirecting.
        ev.stopPropagation();
        // stopImmediatePropagation isn't needed — units.js's listener
        // is on the row, not the document, and stopPropagation halts
        // the capture-to-target-to-bubble journey before it reaches
        // the row's own bubble-phase handler.
    }, true);
    // Double-click opens detail — preserves the "click to go" muscle
    // memory for dispatchers who don't want the selection dance.
    document.addEventListener('dblclick', function (ev) {
        var row = ev.target.closest && ev.target.closest('.unit-row');
        if (!row) return;
        var id = parseInt(row.getAttribute('data-unit-id'), 10) || 0;
        if (id) window.location.href = 'unit-detail.php?id=' + id;
    });

    function wireButton(id, fn) {
        var el = document.getElementById(id);
        if (el) el.addEventListener('click', fn);
    }
    wireButton('btnUnitView', function () {
        if (selectedId && window.UnitActions) window.UnitActions.view(selectedId);
    });
    wireButton('btnUnitEdit', function () {
        if (selectedId && window.UnitActions) window.UnitActions.edit(selectedId);
    });
    wireButton('btnUnitDispatch', function () {
        if (selectedId && window.UnitActions) window.UnitActions.dispatch(selectedId, selectedHandle);
    });
    wireButton('btnUnitStatus', function () {
        if (selectedId && window.UnitActions) window.UnitActions.status(selectedId, selectedHandle);
    });
    wireButton('btnUnitNote', function () {
        if (selectedId && window.UnitActions) window.UnitActions.note(selectedId, selectedHandle);
    });

    // After a mutation, refresh the units.js load so status badges
    // update. units.js's Boot loads via loadUnits(); we call it if
    // exposed, else fall back to a page reload.
    if (window.UnitActions) {
        window.UnitActions.onMutate = function () {
            if (typeof window.loadUnits === 'function') window.loadUnits();
            else location.reload();
        };
    }
})();
</script>

</body>
</html>
