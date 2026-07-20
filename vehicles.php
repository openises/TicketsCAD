<?php
/**
 * NewUI v4.0 - Vehicles (Fleet Management)
 *
 * Two-column layout:
 *   Left:  Searchable/filterable vehicle list
 *   Right: Detail panel / edit form
 *
 * Privacy model: personal vehicle plate/VIN/insurance redacted for non-owners.
 * Data loaded client-side via api/vehicles.php
 */

require_once __DIR__ . '/config.php';

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
require_once __DIR__ . '/inc/rbac.php';
rbac_require_screen('screen.vehicles');

$user     = e($_SESSION['user']);
$level = current_role_name();
$theme    = $_SESSION['day_night'] ?? 'Day';
$bs_theme = ($theme === 'Night') ? 'dark' : 'light';
$csrf     = csrf_token();
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="<?php echo $bs_theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?php echo e($csrf); ?>">
    <title>Vehicles — Tickets NewUI <?php echo NEWUI_VERSION; ?></title>

    <!-- Vendor CSS -->
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">

    <!-- App CSS -->
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?php echo asset_v('assets/css/dashboard.css'); ?>">
    <link rel="stylesheet" href="assets/css/vehicles.css?v=<?php echo asset_v('assets/css/vehicles.css'); ?>">
</head>
<body>

<?php include_once NEWUI_ROOT . '/inc/navbar.php'; ?>
</header>

<!-- Page Content -->
<div class="container-fluid p-3">

    <?php $personnel_active = 'vehicles'; include_once __DIR__ . '/inc/personnel-nav.php'; ?>

    <!-- Page title + action buttons -->
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h5 class="mb-0" id="pageTitle">
            <i class="bi bi-truck text-primary me-2"></i>Vehicles
            <span class="badge bg-secondary ms-2" id="vehicleCount" style="font-size: 0.65rem;">0</span>
        </h5>
        <div class="d-flex gap-2 align-items-center">
            <button type="button" class="btn btn-sm btn-outline-secondary" id="btnVehiclesCols" title="Customize visible columns">
                <i class="bi bi-layout-three-columns"></i>
            </button>
            <button type="button" class="btn btn-sm btn-primary" id="btnNewVehicle">
                <i class="bi bi-plus-lg me-1"></i>Add Vehicle
            </button>
            <a href="index.php" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>Dashboard
            </a>
        </div>
    </div>

    <!-- Search & Filters -->
    <div class="card mb-3">
        <div class="card-body py-2">
            <div class="row g-2 align-items-center">
                <div class="col-md-4">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input type="text" class="form-control form-control-sm" id="searchInput"
                               placeholder="Search by make, model, callsign, owner..." autocomplete="off">
                        <button type="button" class="btn btn-sm btn-outline-secondary d-none" id="btnClearSearch">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>
                </div>
                <div class="col-md-8">
                    <div class="d-flex flex-wrap gap-1">
                        <span class="text-body-secondary small me-1 align-self-center">Status:</span>
                        <button type="button" class="btn btn-sm btn-outline-secondary filter-btn active"
                                data-filter="status" data-value="all">All</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary filter-btn"
                                data-filter="status" data-value="Active">Active</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary filter-btn"
                                data-filter="status" data-value="Out of Service">Out of Svc</button>

                        <span class="text-body-secondary small ms-2 me-1 align-self-center">Ownership:</span>
                        <button type="button" class="btn btn-sm btn-outline-secondary filter-btn active"
                                data-filter="agency" data-value="all">All</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary filter-btn"
                                data-filter="agency" data-value="1">Agency</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary filter-btn"
                                data-filter="agency" data-value="0">Personal</button>

                        <span class="text-body-secondary small ms-2 me-1 align-self-center">Type:</span>
                        <span id="typeFilters">
                            <button type="button" class="btn btn-sm btn-outline-secondary filter-btn active"
                                    data-filter="type" data-value="all">All</button>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Alert area -->
    <div id="alertArea"></div>

    <!-- Loading spinner -->
    <div class="text-center py-5" id="loadingSpinner">
        <div class="spinner-border text-primary" role="status"></div>
        <div class="mt-2 text-body-secondary">Loading vehicles...</div>
    </div>

    <!-- Main content (hidden until data loads) -->
    <div class="row g-3 d-none" id="mainContent">

        <!-- ═══════════ LEFT COLUMN: Vehicle Table ═══════════ -->
        <div class="col-lg-7">
            <div class="card">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0" id="vehicleTable">
                            <thead>
                                <tr>
                                    <th class="sortable" data-sort="callsign" data-col-id="unit"    data-col-label="Unit">Unit <i class="bi bi-arrow-down-up sort-icon"></i></th>
                                    <th class="sortable" data-sort="vehicle"  data-col-id="vehicle" data-col-label="Vehicle">Vehicle <i class="bi bi-arrow-down-up sort-icon"></i></th>
                                    <th class="sortable" data-sort="type"     data-col-id="type"    data-col-label="Type">Type <i class="bi bi-arrow-down-up sort-icon"></i></th>
                                    <th class="sortable" data-sort="owner"    data-col-id="owner"   data-col-label="Owner">Owner <i class="bi bi-arrow-down-up sort-icon"></i></th>
                                    <th class="sortable" data-sort="status"   data-col-id="status"  data-col-label="Status">Status <i class="bi bi-arrow-down-up sort-icon"></i></th>
                                    <th                                       data-col-id="privacy" data-col-label="Privacy">Privacy</th>
                                </tr>
                            </thead>
                            <tbody id="vehicleBody">
                            </tbody>
                        </table>
                    </div>
                    <div class="text-center text-body-secondary py-3 small d-none" id="noResults">
                        No vehicles match your search or filters.
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══════════ RIGHT COLUMN: Detail / Edit Panel ═══════════ -->
        <div class="col-lg-5">
            <div class="sticky-top" style="top: 100px;">

                <!-- Empty state -->
                <div class="card" id="detailEmpty">
                    <div class="card-body text-center text-body-secondary py-5">
                        <i class="bi bi-truck" style="font-size: 3rem;"></i>
                        <p class="mt-2 mb-0">Select a vehicle from the table to view details.</p>
                    </div>
                </div>

                <!-- Detail view -->
                <div class="d-none" id="detailView">
                    <div class="card mb-3" id="detailHeader">
                        <div class="card-body py-2">
                            <div class="d-flex align-items-center justify-content-between">
                                <div>
                                    <h5 class="mb-0" id="detailVehicleName">—</h5>
                                    <div class="small text-body-secondary" id="detailSubtitle">—</div>
                                </div>
                                <div class="d-flex gap-1">
                                    <button type="button" class="btn btn-sm btn-outline-warning" id="btnEditVehicle" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-danger" id="btnDeleteVehicle" title="Delete">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="d-flex gap-2 mt-1" id="detailBadges"></div>
                        </div>
                    </div>

                    <!-- Vehicle Info -->
                    <div class="card mb-3">
                        <div class="card-header d-flex align-items-center py-1 form-section"
                             data-bs-toggle="collapse" data-bs-target="#collapseVehicleInfo" role="button">
                            <i class="bi bi-car-front me-2"></i>
                            <span class="fw-semibold small">Vehicle Information</span>
                            <i class="bi bi-chevron-down ms-auto collapse-icon"></i>
                        </div>
                        <div class="collapse show" id="collapseVehicleInfo">
                            <div class="card-body py-2 small" id="detailVehicleInfo"></div>
                        </div>
                    </div>

                    <!-- Registration & Insurance (may be redacted) -->
                    <div class="card mb-3">
                        <div class="card-header d-flex align-items-center py-1 form-section"
                             data-bs-toggle="collapse" data-bs-target="#collapseVehicleReg" role="button">
                            <i class="bi bi-file-earmark-text me-2"></i>
                            <span class="fw-semibold small">Registration &amp; Insurance</span>
                            <i class="bi bi-chevron-down ms-auto collapse-icon"></i>
                        </div>
                        <div class="collapse show" id="collapseVehicleReg">
                            <div class="card-body py-2 small" id="detailVehicleReg"></div>
                        </div>
                    </div>

                    <!-- Notes -->
                    <div class="card mb-3">
                        <div class="card-header d-flex align-items-center py-1 form-section"
                             data-bs-toggle="collapse" data-bs-target="#collapseVehicleNotes" role="button">
                            <i class="bi bi-sticky me-2"></i>
                            <span class="fw-semibold small">Notes</span>
                            <i class="bi bi-chevron-down ms-auto collapse-icon"></i>
                        </div>
                        <div class="collapse" id="collapseVehicleNotes">
                            <div class="card-body py-2 small" id="detailVehicleNotes"></div>
                        </div>
                    </div>
                </div>

                <!-- Edit form -->
                <div class="d-none" id="editView">
                    <div class="card">
                        <div class="card-header d-flex align-items-center justify-content-between py-1">
                            <span class="fw-semibold small" id="editFormTitle">
                                <i class="bi bi-pencil-square me-1"></i>Edit Vehicle
                            </span>
                            <div class="d-flex gap-1">
                                <button type="button" class="btn btn-sm btn-primary" id="btnSaveVehicle">
                                    <i class="bi bi-check-lg me-1"></i>Save
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="btnCancelEdit">
                                    <i class="bi bi-x-lg me-1"></i>Cancel
                                </button>
                            </div>
                        </div>
                        <div class="card-body py-2" id="editFormBody">
                            <input type="hidden" id="editVehicleId" value="">

                            <!-- Vehicle section -->
                            <div class="mb-2">
                                <div class="fw-semibold small text-body-secondary mb-1">
                                    <i class="bi bi-car-front me-1"></i>Vehicle
                                </div>
                                <div class="row g-2">
                                    <div class="col-3">
                                        <label class="form-label form-label-sm mb-0">Year</label>
                                        <input type="number" class="form-control form-control-sm" id="editYear" min="1950" max="2040">
                                    </div>
                                    <div class="col-4">
                                        <label class="form-label form-label-sm mb-0">Make</label>
                                        <input type="text" class="form-control form-control-sm" id="editMake" placeholder="e.g. Ford">
                                    </div>
                                    <div class="col-5">
                                        <label class="form-label form-label-sm mb-0">Model</label>
                                        <input type="text" class="form-control form-control-sm" id="editModel" placeholder="e.g. Explorer">
                                    </div>
                                    <div class="col-4">
                                        <label class="form-label form-label-sm mb-0">Color</label>
                                        <input type="text" class="form-control form-control-sm" id="editColor">
                                    </div>
                                    <div class="col-4">
                                        <label class="form-label form-label-sm mb-0">Callsign/Unit #</label>
                                        <input type="text" class="form-control form-control-sm" id="editCallsign">
                                    </div>
                                    <div class="col-4">
                                        <label class="form-label form-label-sm mb-0">Type</label>
                                        <select class="form-select form-select-sm" id="editVehicleType"></select>
                                    </div>
                                </div>
                            </div>

                            <!-- Owner & Status -->
                            <div class="mb-2">
                                <div class="fw-semibold small text-body-secondary mb-1">
                                    <i class="bi bi-person me-1"></i>Owner &amp; Status
                                </div>
                                <div class="row g-2">
                                    <div class="col-6">
                                        <label class="form-label form-label-sm mb-0">Owner</label>
                                        <select class="form-select form-select-sm" id="editOwner"></select>
                                    </div>
                                    <div class="col-3">
                                        <label class="form-label form-label-sm mb-0">Status</label>
                                        <select class="form-select form-select-sm" id="editStatus">
                                            <option value="Active">Active</option>
                                            <option value="Out of Service">Out of Service</option>
                                            <option value="Disposed">Disposed</option>
                                        </select>
                                    </div>
                                    <div class="col-3">
                                        <div class="form-check form-check-sm mt-4">
                                            <input class="form-check-input" type="checkbox" id="editAgency">
                                            <label class="form-check-label small" for="editAgency">Agency Vehicle</label>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Registration -->
                            <div class="mb-2">
                                <div class="fw-semibold small text-body-secondary mb-1">
                                    <i class="bi bi-file-earmark-text me-1"></i>Registration
                                </div>
                                <div class="row g-2">
                                    <div class="col-4">
                                        <label class="form-label form-label-sm mb-0">Plate #</label>
                                        <input type="text" class="form-control form-control-sm" id="editPlate">
                                    </div>
                                    <div class="col-2">
                                        <label class="form-label form-label-sm mb-0"><?php echo e(t('form.state', 'State')); ?></label>
                                        <select class="form-select form-select-sm" id="editPlateState"></select>
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label form-label-sm mb-0">VIN</label>
                                        <input type="text" class="form-control form-control-sm" id="editVin" maxlength="17">
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label form-label-sm mb-0">Reg. Expiry</label>
                                        <input type="date" class="form-control form-control-sm" id="editRegExp">
                                    </div>
                                </div>
                            </div>

                            <!-- Insurance -->
                            <div class="mb-2">
                                <div class="fw-semibold small text-body-secondary mb-1">
                                    <i class="bi bi-shield-check me-1"></i>Insurance
                                </div>
                                <div class="row g-2">
                                    <div class="col-6">
                                        <label class="form-label form-label-sm mb-0">Carrier</label>
                                        <input type="text" class="form-control form-control-sm" id="editInsCarrier">
                                    </div>
                                    <div class="col-3">
                                        <label class="form-label form-label-sm mb-0">Policy #</label>
                                        <input type="text" class="form-control form-control-sm" id="editInsPolicy">
                                    </div>
                                    <div class="col-3">
                                        <label class="form-label form-label-sm mb-0">Expiry</label>
                                        <input type="date" class="form-control form-control-sm" id="editInsExp">
                                    </div>
                                </div>
                            </div>

                            <!-- Privacy & Notes -->
                            <div class="mb-2">
                                <div class="fw-semibold small text-body-secondary mb-1">
                                    <i class="bi bi-lock me-1"></i>Privacy &amp; Notes
                                </div>
                                <div class="row g-2">
                                    <div class="col-12">
                                        <div class="form-check form-check-sm">
                                            <input class="form-check-input" type="checkbox" id="editPrivate" checked>
                                            <label class="form-check-label small" for="editPrivate">
                                                <i class="bi bi-lock-fill text-warning me-1"></i>Private — hide plate, VIN, insurance from non-owners
                                            </label>
                                        </div>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label form-label-sm mb-0">Notes</label>
                                        <textarea class="form-control form-control-sm" id="editNotes" rows="2"></textarea>
                                    </div>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>

            </div>
        </div>

    </div>
</div>

<!-- CSRF token for JS -->
<input type="hidden" id="csrfToken" value="<?php echo e($csrf); ?>">
<input type="hidden" id="userLevel" value="<?php echo (int) $_SESSION['level']; ?>">

<!-- Vendor JS -->
<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="assets/js/toolbar.js?v=<?php echo asset_v('assets/js/toolbar.js'); ?>"></script>

<!-- App JS -->
<script src="assets/js/theme-manager.js"></script>
<script src="assets/js/states-select.js?v=<?php echo asset_v('assets/js/states-select.js'); ?>"></script>
<script src="assets/js/vehicles.js?v=<?php echo asset_v('assets/js/vehicles.js'); ?>"></script>
<script src="assets/js/screen-prefs.js?v=<?php echo asset_v('assets/js/screen-prefs.js'); ?>"></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        if (window.ScreenPrefs && window.ScreenPrefs.applyToTable) {
            window.ScreenPrefs.applyToTable('vehicles', '#vehicleTable', { openerSelector: '#btnVehiclesCols' });
        }
    });
</script>

</body>
</html>
