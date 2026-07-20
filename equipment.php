<?php
/**
 * NewUI v4.0 - Equipment Management
 *
 * Two-column layout:
 *   Left:  Searchable/filterable equipment list
 *   Right: Detail panel / edit form / checkout/checkin
 *
 * Supports checkout/checkin tracking with activity log.
 * Data loaded client-side via api/equipment.php
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
rbac_require_screen('screen.equipment');

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
    <title>Equipment — Tickets NewUI <?php echo NEWUI_VERSION; ?></title>

    <!-- Vendor CSS -->
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">

    <!-- App CSS -->
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?php echo asset_v('assets/css/dashboard.css'); ?>">
    <link rel="stylesheet" href="assets/css/equipment.css?v=<?php echo asset_v('assets/css/equipment.css'); ?>">
</head>
<body>

<?php include_once NEWUI_ROOT . '/inc/navbar.php'; ?>
</header>

<!-- Page Content -->
<div class="container-fluid p-3">

    <?php $personnel_active = 'equipment'; include_once __DIR__ . '/inc/personnel-nav.php'; ?>

    <!-- Page title + action buttons -->
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h5 class="mb-0" id="pageTitle">
            <i class="bi bi-box-seam text-primary me-2"></i>Equipment
            <span class="badge bg-secondary ms-2" id="equipmentCount" style="font-size: 0.65rem;">0</span>
        </h5>
        <div class="d-flex gap-2 align-items-center">
            <button type="button" class="btn btn-sm btn-outline-secondary" id="btnEquipmentCols" title="Customize visible columns">
                <i class="bi bi-layout-three-columns"></i>
            </button>
            <button type="button" class="btn btn-sm btn-primary" id="btnNewEquipment">
                <i class="bi bi-plus-lg me-1"></i>Add Equipment
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
                               placeholder="Search by name, serial, asset tag, location..." autocomplete="off">
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
                        <button type="button" class="btn btn-sm btn-outline-success filter-btn"
                                data-filter="status" data-value="Available">Available</button>
                        <button type="button" class="btn btn-sm btn-outline-warning filter-btn"
                                data-filter="status" data-value="Checked Out">Checked Out</button>
                        <button type="button" class="btn btn-sm btn-outline-info filter-btn"
                                data-filter="status" data-value="In Repair">In Repair</button>

                        <span class="text-body-secondary small ms-2 me-1 align-self-center">Ownership:</span>
                        <button type="button" class="btn btn-sm btn-outline-secondary filter-btn active"
                                data-filter="ownership" data-value="all">All</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary filter-btn"
                                data-filter="ownership" data-value="organization"><i class="bi bi-building me-1"></i>Org</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary filter-btn"
                                data-filter="ownership" data-value="personal"><i class="bi bi-person me-1"></i>Personal</button>

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
        <div class="mt-2 text-body-secondary">Loading equipment...</div>
    </div>

    <!-- Main content (hidden until data loads) -->
    <div class="row g-3 d-none" id="mainContent">

        <!-- ═══════════ LEFT COLUMN: Equipment Table ═══════════ -->
        <div class="col-lg-7">
            <div class="card">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0" id="equipmentTable">
                            <thead>
                                <tr>
                                    <th class="sortable" data-sort="name"      data-col-id="item"      data-col-label="Item">Item <i class="bi bi-arrow-down-up sort-icon"></i></th>
                                    <th class="sortable" data-sort="type"      data-col-id="type"      data-col-label="Type">Type <i class="bi bi-arrow-down-up sort-icon"></i></th>
                                    <th class="sortable" data-sort="asset_tag" data-col-id="asset_tag" data-col-label="Asset Tag">Asset Tag <i class="bi bi-arrow-down-up sort-icon"></i></th>
                                    <th class="sortable" data-sort="assigned"  data-col-id="assigned"  data-col-label="Assigned To">Assigned To <i class="bi bi-arrow-down-up sort-icon"></i></th>
                                    <th class="sortable" data-sort="status"    data-col-id="status"    data-col-label="Status">Status <i class="bi bi-arrow-down-up sort-icon"></i></th>
                                    <th class="sortable" data-sort="condition" data-col-id="condition" data-col-label="Condition">Condition <i class="bi bi-arrow-down-up sort-icon"></i></th>
                                </tr>
                            </thead>
                            <tbody id="equipmentBody">
                            </tbody>
                        </table>
                    </div>
                    <div class="text-center text-body-secondary py-3 small d-none" id="noResults">
                        No equipment matches your search or filters.
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
                        <i class="bi bi-box-seam" style="font-size: 3rem;"></i>
                        <p class="mt-2 mb-0">Select an item from the table to view details.</p>
                    </div>
                </div>

                <!-- Detail view -->
                <div class="d-none" id="detailView">
                    <div class="card mb-3" id="detailHeader">
                        <div class="card-body py-2">
                            <div class="d-flex align-items-center justify-content-between">
                                <div>
                                    <h5 class="mb-0" id="detailItemName">—</h5>
                                    <div class="small text-body-secondary" id="detailSubtitle">—</div>
                                </div>
                                <div class="d-flex gap-1">
                                    <button type="button" class="btn btn-sm btn-outline-primary d-none" id="btnCheckout" title="Check Out">
                                        <i class="bi bi-box-arrow-right"></i> Check Out
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-success d-none" id="btnCheckin" title="Check In">
                                        <i class="bi bi-box-arrow-in-left"></i> Check In
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-warning" id="btnEditEquipment" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-danger" id="btnDeleteEquipment" title="Delete">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="d-flex gap-2 mt-1" id="detailBadges"></div>
                        </div>
                    </div>

                    <!-- Equipment Info -->
                    <div class="card mb-3">
                        <div class="card-header d-flex align-items-center py-1 form-section"
                             data-bs-toggle="collapse" data-bs-target="#collapseEquipInfo" role="button">
                            <i class="bi bi-info-circle me-2"></i>
                            <span class="fw-semibold small">Equipment Details</span>
                            <i class="bi bi-chevron-down ms-auto collapse-icon"></i>
                        </div>
                        <div class="collapse show" id="collapseEquipInfo">
                            <div class="card-body py-2 small" id="detailEquipInfo"></div>
                        </div>
                    </div>

                    <!-- Assignment & Location -->
                    <div class="card mb-3">
                        <div class="card-header d-flex align-items-center py-1 form-section"
                             data-bs-toggle="collapse" data-bs-target="#collapseEquipAssign" role="button">
                            <i class="bi bi-person-check me-2"></i>
                            <span class="fw-semibold small">Assignment &amp; Location</span>
                            <i class="bi bi-chevron-down ms-auto collapse-icon"></i>
                        </div>
                        <div class="collapse show" id="collapseEquipAssign">
                            <div class="card-body py-2 small" id="detailEquipAssign"></div>
                        </div>
                    </div>

                    <!-- Activity Log -->
                    <div class="card mb-3">
                        <div class="card-header d-flex align-items-center py-1 form-section"
                             data-bs-toggle="collapse" data-bs-target="#collapseEquipLog" role="button">
                            <i class="bi bi-clock-history me-2"></i>
                            <span class="fw-semibold small">Activity Log</span>
                            <span class="badge bg-secondary ms-2" id="logCount" style="font-size: 0.6rem;">0</span>
                            <i class="bi bi-chevron-down ms-auto collapse-icon"></i>
                        </div>
                        <div class="collapse" id="collapseEquipLog">
                            <div class="card-body py-2 small" id="detailEquipLog"></div>
                        </div>
                    </div>

                    <!-- Notes -->
                    <div class="card mb-3">
                        <div class="card-header d-flex align-items-center py-1 form-section"
                             data-bs-toggle="collapse" data-bs-target="#collapseEquipNotes" role="button">
                            <i class="bi bi-sticky me-2"></i>
                            <span class="fw-semibold small">Notes</span>
                            <i class="bi bi-chevron-down ms-auto collapse-icon"></i>
                        </div>
                        <div class="collapse" id="collapseEquipNotes">
                            <div class="card-body py-2 small" id="detailEquipNotes"></div>
                        </div>
                    </div>
                </div>

                <!-- Edit form -->
                <div class="d-none" id="editView">
                    <div class="card">
                        <div class="card-header d-flex align-items-center justify-content-between py-1">
                            <span class="fw-semibold small" id="editFormTitle">
                                <i class="bi bi-pencil-square me-1"></i>Edit Equipment
                            </span>
                            <div class="d-flex gap-1">
                                <button type="button" class="btn btn-sm btn-primary" id="btnSaveEquipment">
                                    <i class="bi bi-check-lg me-1"></i>Save
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="btnCancelEdit">
                                    <i class="bi bi-x-lg me-1"></i>Cancel
                                </button>
                            </div>
                        </div>
                        <div class="card-body py-2" id="editFormBody">
                            <input type="hidden" id="editEquipmentId" value="">

                            <!-- Item section -->
                            <div class="mb-2">
                                <div class="fw-semibold small text-body-secondary mb-1">
                                    <i class="bi bi-box me-1"></i>Item
                                </div>
                                <div class="row g-2">
                                    <div class="col-8">
                                        <label class="form-label form-label-sm mb-0">Name *</label>
                                        <input type="text" class="form-control form-control-sm" id="editName" placeholder="e.g. Motorola XPR 7550" required>
                                    </div>
                                    <div class="col-4">
                                        <label class="form-label form-label-sm mb-0">Type</label>
                                        <select class="form-select form-select-sm" id="editType"></select>
                                    </div>
                                    <div class="col-4">
                                        <label class="form-label form-label-sm mb-0">Make</label>
                                        <input type="text" class="form-control form-control-sm" id="editMake">
                                    </div>
                                    <div class="col-4">
                                        <label class="form-label form-label-sm mb-0">Model</label>
                                        <input type="text" class="form-control form-control-sm" id="editModel">
                                    </div>
                                    <div class="col-4">
                                        <label class="form-label form-label-sm mb-0">Serial #</label>
                                        <input type="text" class="form-control form-control-sm" id="editSerial">
                                    </div>
                                    <div class="col-4" id="sizeGroup" style="display:none;">
                                        <label class="form-label form-label-sm mb-0">Size</label>
                                        <select class="form-select form-select-sm" id="editSize">
                                            <option value="">— None —</option>
                                            <option value="XS">XS</option>
                                            <option value="S">S</option>
                                            <option value="M">M</option>
                                            <option value="L">L</option>
                                            <option value="XL">XL</option>
                                            <option value="2XL">2XL</option>
                                            <option value="3XL">3XL</option>
                                        </select>
                                    </div>
                                    <div class="col-4">
                                        <label class="form-label form-label-sm mb-0">Asset Tag</label>
                                        <input type="text" class="form-control form-control-sm" id="editAssetTag">
                                    </div>
                                    <div class="col-4">
                                        <label class="form-label form-label-sm mb-0">Condition</label>
                                        <select class="form-select form-select-sm" id="editCondition">
                                            <option value="New">New</option>
                                            <option value="Good" selected>Good</option>
                                            <option value="Fair">Fair</option>
                                            <option value="Poor">Poor</option>
                                            <option value="Out of Service">Out of Service</option>
                                            <option value="Disposed">Disposed</option>
                                        </select>
                                    </div>
                                    <div class="col-4">
                                        <label class="form-label form-label-sm mb-0">Status</label>
                                        <select class="form-select form-select-sm" id="editStatus">
                                            <option value="Available">Available</option>
                                            <option value="Checked Out">Checked Out</option>
                                            <option value="In Repair">In Repair</option>
                                            <option value="Lost">Lost</option>
                                            <option value="Disposed">Disposed</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <!-- Ownership -->
                            <div class="mb-2">
                                <div class="fw-semibold small text-body-secondary mb-1">
                                    <i class="bi bi-building me-1"></i>Ownership
                                </div>
                                <div class="row g-2">
                                    <div class="col-4">
                                        <label class="form-label form-label-sm mb-0">Ownership</label>
                                        <select class="form-select form-select-sm" id="editOwnership">
                                            <option value="organization">Organization</option>
                                            <option value="personal">Personal</option>
                                        </select>
                                    </div>
                                    <div class="col-5" id="ownerMemberGroup">
                                        <label class="form-label form-label-sm mb-0">Owner (volunteer)</label>
                                        <select class="form-select form-select-sm" id="editOwnerMember"></select>
                                    </div>
                                    <div class="col-3" id="availableGroup">
                                        <div class="form-check form-check-sm mt-4">
                                            <input class="form-check-input" type="checkbox" id="editAvailable">
                                            <label class="form-check-label small" for="editAvailable">Available for events</label>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Assignment & Location -->
                            <div class="mb-2">
                                <div class="fw-semibold small text-body-secondary mb-1">
                                    <i class="bi bi-person-check me-1"></i>Assignment &amp; Location
                                </div>
                                <div class="row g-2">
                                    <div class="col-6">
                                        <label class="form-label form-label-sm mb-0">Assigned Member</label>
                                        <select class="form-select form-select-sm" id="editMember"></select>
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label form-label-sm mb-0">Assigned Team</label>
                                        <select class="form-select form-select-sm" id="editTeam"></select>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label form-label-sm mb-0">Storage Location</label>
                                        <input type="text" class="form-control form-control-sm" id="editLocation" placeholder="e.g. Radio cabinet, Bay 2">
                                    </div>
                                </div>
                            </div>

                            <!-- Purchase & Warranty -->
                            <div class="mb-2">
                                <div class="fw-semibold small text-body-secondary mb-1">
                                    <i class="bi bi-receipt me-1"></i>Purchase &amp; Warranty
                                </div>
                                <div class="row g-2">
                                    <div class="col-4">
                                        <label class="form-label form-label-sm mb-0">Purchase Date</label>
                                        <input type="date" class="form-control form-control-sm" id="editPurchaseDate">
                                    </div>
                                    <div class="col-4">
                                        <label class="form-label form-label-sm mb-0">Cost</label>
                                        <input type="number" class="form-control form-control-sm" id="editPurchaseCost" step="0.01" min="0">
                                    </div>
                                    <div class="col-4">
                                        <label class="form-label form-label-sm mb-0">Warranty Exp.</label>
                                        <input type="date" class="form-control form-control-sm" id="editWarrantyExp">
                                    </div>
                                </div>
                            </div>

                            <!-- Notes -->
                            <div class="mb-2">
                                <label class="form-label form-label-sm mb-0">Notes</label>
                                <textarea class="form-control form-control-sm" id="editNotes" rows="2"></textarea>
                            </div>

                        </div>
                    </div>
                </div>

                <!-- Checkout modal form (inline) -->
                <div class="d-none" id="checkoutView">
                    <div class="card">
                        <div class="card-header d-flex align-items-center justify-content-between py-1">
                            <span class="fw-semibold small">
                                <i class="bi bi-box-arrow-right me-1 text-primary"></i>Check Out Equipment
                            </span>
                            <div class="d-flex gap-1">
                                <button type="button" class="btn btn-sm btn-primary" id="btnConfirmCheckout">
                                    <i class="bi bi-check-lg me-1"></i>Check Out
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="btnCancelCheckout">
                                    <i class="bi bi-x-lg me-1"></i>Cancel
                                </button>
                            </div>
                        </div>
                        <div class="card-body py-2">
                            <div class="small fw-semibold mb-2" id="checkoutItemName"></div>
                            <input type="hidden" id="checkoutEquipmentId" value="">
                            <div class="mb-2">
                                <label class="form-label form-label-sm mb-0">Assign To *</label>
                                <select class="form-select form-select-sm" id="checkoutMember"></select>
                            </div>
                            <div class="mb-2">
                                <label class="form-label form-label-sm mb-0">Notes</label>
                                <input type="text" class="form-control form-control-sm" id="checkoutNotes" placeholder="Optional notes...">
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
<script src="assets/js/equipment.js?v=<?php echo asset_v('assets/js/equipment.js'); ?>"></script>
<script src="assets/js/screen-prefs.js?v=<?php echo asset_v('assets/js/screen-prefs.js'); ?>"></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        if (window.ScreenPrefs && window.ScreenPrefs.applyToTable) {
            window.ScreenPrefs.applyToTable('equipment', '#equipmentTable', { openerSelector: '#btnEquipmentCols' });
        }
    });
</script>

</body>
</html>
