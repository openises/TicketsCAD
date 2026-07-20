<?php
/**
 * NewUI v4.0 - Facility Edit / Create
 *
 * Create or edit a facility. Two-column layout:
 *   Left:  Form fields grouped into collapsible sections
 *   Right: Leaflet map for location picking
 *
 * If ?id=X is present, loads existing facility for editing.
 * Otherwise, creates a new facility.
 *
 * Data loaded client-side via api/facility-detail.php (edit mode)
 * Saved via api/facility-save.php
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
    <title><?php echo e(t('page.facility_edit', 'Edit Facility')); ?> — <?php echo e(t('login.title', 'Tickets NewUI')); ?> <?php echo NEWUI_VERSION; ?></title>

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
        <h5 class="mb-0" id="pageTitle">
            <i class="bi bi-hospital text-primary me-2"></i><?php echo e(t('facs.btn.new', 'New Facility')); ?>
        </h5>
        <div class="d-flex gap-2">
            <a href="facilities.php" class="btn btn-sm btn-outline-secondary" id="btnCancel">
                <i class="bi bi-x-lg me-1"></i><?php echo e(t('btn.cancel', 'Cancel')); ?>
            </a>
            <button type="button" class="btn btn-sm btn-outline-danger d-none" id="btnDelete">
                <i class="bi bi-trash me-1"></i><?php echo e(t('btn.delete', 'Delete')); ?>
            </button>
            <button type="button" class="btn btn-sm btn-success" id="btnSave">
                <i class="bi bi-check-lg me-1"></i><?php echo e(t('btn.save', 'Save')); ?>
            </button>
        </div>
    </div>

    <!-- Alert area -->
    <div id="alertArea"></div>

    <!-- Loading spinner (edit mode only) -->
    <div class="text-center py-5 d-none" id="loadingSpinner">
        <div class="spinner-border text-primary" role="status"></div>
        <div class="mt-2 text-body-secondary">Loading facility...</div>
    </div>

    <div class="row g-3" id="mainContent">

        <!-- ═══════════ LEFT COLUMN: Form ═══════════ -->
        <div class="col-lg-7 col-xl-6">
            <form id="facilityForm" novalidate>
                <input type="hidden" name="csrf_token" id="csrfToken" value="<?php echo e($csrf); ?>">
                <input type="hidden" name="id" id="facilityId" value="">

                <!-- ── Section 1: Identity ── -->
                <div class="card mb-3 form-section">
                    <div class="card-header d-flex align-items-center" data-bs-toggle="collapse" data-bs-target="#secIdentity" role="button">
                        <i class="bi bi-tag me-2 text-primary"></i>
                        <span class="fw-semibold">Identity</span>
                        <span class="badge bg-danger ms-auto required-badge">Required</span>
                        <i class="bi bi-chevron-down ms-2 collapse-icon"></i>
                    </div>
                    <div class="collapse show" id="secIdentity">
                        <div class="card-body">
                            <div class="row g-2">
                                <div class="col-md-8">
                                    <label for="name" class="form-label">Facility Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control form-control-sm" id="name" name="name"
                                           required tabindex="1" placeholder="Hospital, Station, Shelter..." maxlength="255">
                                </div>
                                <div class="col-md-4">
                                    <label for="handle" class="form-label">Handle</label>
                                    <input type="text" class="form-control form-control-sm" id="handle" name="handle"
                                           tabindex="2" placeholder="Short code" maxlength="50">
                                </div>
                                <div class="col-md-6">
                                    <label for="type_id" class="form-label">Type</label>
                                    <select class="form-select form-select-sm" id="type_id" name="type_id" tabindex="3">
                                        <option value="0">-- Select Type --</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label for="status_id" class="form-label">Status</label>
                                    <select class="form-select form-select-sm" id="status_id" name="status_id" tabindex="4">
                                        <option value="0">-- Select Status --</option>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <label for="description" class="form-label">Description <span class="text-danger">*</span></label>
                                    <textarea class="form-control form-control-sm" id="description" name="description" rows="3"
                                              required tabindex="5" placeholder="Facility description..."></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── Section 2: Location ── -->
                <div class="card mb-3 form-section">
                    <div class="card-header d-flex align-items-center" data-bs-toggle="collapse" data-bs-target="#secLocation" role="button">
                        <i class="bi bi-geo-alt me-2 text-success"></i>
                        <span class="fw-semibold">Location</span>
                        <i class="bi bi-chevron-down ms-2 ms-auto collapse-icon"></i>
                    </div>
                    <div class="collapse show" id="secLocation">
                        <div class="card-body">
                            <div class="row g-2">
                                <div class="col-12">
                                    <label for="street" class="form-label"><?php echo e(t('field.street', 'Street Address')); ?></label>
                                    <div class="input-group input-group-sm">
                                        <input type="text" class="form-control" id="street" name="street"
                                               tabindex="6" placeholder="123 Main St">
                                        <button class="btn btn-outline-secondary" type="button" id="btnGeocode"
                                                tabindex="7" title="Lookup address on map">
                                            <i class="bi bi-search"></i> Lookup
                                        </button>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <label for="city" class="form-label"><?php echo e(t('form.city', 'City')); ?></label>
                                    <input type="text" class="form-control form-control-sm" id="city" name="city" tabindex="8">
                                </div>
                                <div class="col-md-4">
                                    <label for="state" class="form-label"><?php echo e(t('form.state', 'State')); ?></label>
                                    <select class="form-select form-select-sm" id="state" name="state" tabindex="9">
                                        <option value="">--</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Coordinates</label>
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text">Lat</span>
                                        <input type="text" class="form-control" id="lat" name="lat" placeholder="44.9778" readonly>
                                        <span class="input-group-text">Lng</span>
                                        <input type="text" class="form-control" id="lng" name="lng" placeholder="-93.2650" readonly>
                                    </div>
                                    <div class="form-text">Click the map or use Lookup to set coordinates</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── Section 3: Contact ── -->
                <div class="card mb-3 form-section">
                    <div class="card-header d-flex align-items-center" data-bs-toggle="collapse" data-bs-target="#secContact" role="button">
                        <i class="bi bi-person me-2 text-info"></i>
                        <span class="fw-semibold">Contact</span>
                        <i class="bi bi-chevron-down ms-2 ms-auto collapse-icon"></i>
                    </div>
                    <div class="collapse show" id="secContact">
                        <div class="card-body">
                            <div class="row g-2">
                                <div class="col-md-4">
                                    <label for="contact_name" class="form-label">Contact Name</label>
                                    <input type="text" class="form-control form-control-sm" id="contact_name" name="contact_name"
                                           tabindex="10" placeholder="Primary contact">
                                </div>
                                <div class="col-md-4">
                                    <label for="contact_email" class="form-label">Email</label>
                                    <input type="email" class="form-control form-control-sm" id="contact_email" name="contact_email"
                                           tabindex="11" placeholder="contact@example.com">
                                </div>
                                <div class="col-md-4">
                                    <label for="contact_phone" class="form-label">Phone</label>
                                    <input type="tel" class="form-control form-control-sm" id="contact_phone" name="contact_phone"
                                           tabindex="12" placeholder="(555) 123-4567">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── Section 4: Capacity ── -->
                <div class="card mb-3 form-section">
                    <div class="card-header d-flex align-items-center" data-bs-toggle="collapse" data-bs-target="#secCapacity" role="button">
                        <i class="bi bi-building me-2 text-warning"></i>
                        <span class="fw-semibold">Capacity &amp; Status</span>
                        <i class="bi bi-chevron-down ms-2 ms-auto collapse-icon"></i>
                    </div>
                    <div class="collapse show" id="secCapacity">
                        <div class="card-body">
                            <div class="row g-2">
                                <div class="col-md-3">
                                    <label for="beds_a" class="form-label">Beds Available</label>
                                    <input type="number" class="form-control form-control-sm" id="beds_a" name="beds_a"
                                           tabindex="13" min="0" value="0">
                                </div>
                                <div class="col-md-3">
                                    <label for="beds_o" class="form-label">Beds Occupied</label>
                                    <input type="number" class="form-control form-control-sm" id="beds_o" name="beds_o"
                                           tabindex="14" min="0" value="0">
                                </div>
                                <div class="col-md-6">
                                    <label for="capab" class="form-label">Capabilities</label>
                                    <input type="text" class="form-control form-control-sm" id="capab" name="capab"
                                           tabindex="15" placeholder="e.g., Trauma, ICU, Helipad">
                                </div>
                                <div class="col-12">
                                    <label for="beds_info" class="form-label">Bed Info / Notes</label>
                                    <textarea class="form-control form-control-sm" id="beds_info" name="beds_info" rows="2"
                                              tabindex="16" placeholder="Additional bed/capacity information..."></textarea>
                                </div>
                                <!-- Phase 103 (a beta tester GH #20) — bed-count automation mode. -->
                                <div class="col-md-6">
                                    <label for="bed_auto_mode" class="form-label">
                                        Bed Count Updates
                                        <i class="bi bi-info-circle text-body-secondary" data-bs-toggle="tooltip"
                                           title="Manual: only edits on this page change bed counts. Automatic: when a unit assigned to this facility as its receiving facility transitions to a delivery status (At Facility, Delivered, Arrived, Transfer of Care), beds_a drops by 1 and beds_o rises by 1. Manual release when a patient is discharged."></i>
                                    </label>
                                    <select class="form-select form-select-sm" id="bed_auto_mode" name="bed_auto_mode" tabindex="18">
                                        <option value="manual">Manual (only this page changes bed counts)</option>
                                        <option value="auto">Automatic on unit delivery</option>
                                    </select>
                                    <div class="form-text small">
                                        Automatic mode adjusts one bed per delivery (from beds_a to beds_o).
                                        Release beds manually when the patient is discharged.
                                        See <a href="help.php#topic-bed-counts" target="_blank">help.php — How bed counts update</a>.
                                    </div>
                                </div>
                                <div class="col-12">
                                    <label for="status_about" class="form-label">Status Notes</label>
                                    <textarea class="form-control form-control-sm" id="status_about" name="status_about" rows="2"
                                              tabindex="17" placeholder="Current status details..."></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </form>
        </div>

        <!-- ═══════════ RIGHT COLUMN: Map ═══════════ -->
        <div class="col-lg-5 col-xl-6">
            <div class="sticky-top" style="top: 100px;">

                <!-- Map -->
                <div class="card mb-3">
                    <div class="card-header d-flex align-items-center py-1">
                        <i class="bi bi-map me-2"></i>
                        <span class="fw-semibold small">Location Map</span>
                        <span class="text-body-secondary small ms-auto">Click to set facility location</span>
                    </div>
                    <div class="card-body p-0">
                        <div id="facilityMap" style="height: 400px;"></div>
                    </div>
                </div>

                <!-- Bottom save (visible when scrolled) -->
                <div class="d-grid gap-2">
                    <button type="button" class="btn btn-success" id="btnSaveBottom">
                        <i class="bi bi-check-lg me-1"></i>Save Facility
                    </button>
                </div>

            </div>
        </div>

    </div>
</div>

<!-- Vendor JS -->
<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="assets/js/toolbar.js?v=<?php echo NEWUI_VERSION; ?>"></script>
<script src="assets/vendor/leaflet/leaflet.js"></script>
<script src="assets/js/leaflet-mobile-fit.js?v=<?php echo function_exists("asset_v")?asset_v("assets/js/leaflet-mobile-fit.js"):NEWUI_VERSION; ?>"></script>
<script src="assets/js/leaflet-quadkey.js"></script>
<script src="assets/js/map-prefs.js"></script>

<!-- App JS -->
<script src="assets/js/theme-manager.js"></script>
<script src="assets/js/facility-edit.js?v=<?php echo NEWUI_VERSION; ?>"></script>

</body>
</html>
