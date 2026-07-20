<?php
/**
 * NewUI v4.0 - Unit Edit / Create
 *
 * Create or edit a responder/unit. Two-column layout:
 *   Left:  Form fields in collapsible sections
 *   Right: Map for location picking (click to set, drag marker, reverse geocode)
 *
 * If ?id=X is present, loads existing data for editing.
 * Otherwise, creates a new unit.
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
rbac_require_screen('screen.unit_edit');
require_once __DIR__ . '/inc/force-pw-change.php';
force_pw_change_redirect();


$user     = e($_SESSION['user']);
$level = current_role_name();
$theme    = $_SESSION['day_night'] ?? 'Day';
$bs_theme = ($theme === 'Night') ? 'dark' : 'light';
$csrf     = csrf_token();

// Determine if editing or creating
$editId = (int) ($_GET['id'] ?? 0);
$pageTitle = $editId > 0
    ? t('page.unit_edit', 'Edit Unit')
    : t('units.btn.new', 'New Unit');
?>
<!DOCTYPE html>
<html lang="<?php echo e(i18n_lang()); ?>" data-bs-theme="<?php echo $bs_theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?php echo e($csrf); ?>">
    <title><?php echo e($pageTitle); ?> — <?php echo e(t('login.title', 'Tickets NewUI')); ?> <?php echo NEWUI_VERSION; ?></title>

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
        <h5 class="mb-0" id="pageTitle">
            <i class="bi bi-pencil text-primary me-2"></i><?php echo e($pageTitle); ?>
        </h5>
        <div class="d-flex gap-2">
            <!-- Back to the unit list — consistent with the '← Units' button on
                 unit-detail.php (Eric 2026-07-05). -->
            <a href="units.php" class="btn btn-sm btn-outline-secondary" id="btnBackUnits">
                <i class="bi bi-arrow-left me-1"></i><?php echo e(t('units.title', 'Units')); ?>
            </a>
            <a href="units.php" class="btn btn-sm btn-outline-secondary" id="btnCancel">
                <i class="bi bi-x-lg me-1"></i><?php echo e(t('btn.cancel', 'Cancel')); ?>
            </a>
            <button type="button" class="btn btn-sm btn-success" id="btnSave">
                <i class="bi bi-check-lg me-1"></i><?php echo e(t('btn.save', 'Save')); ?>
            </button>
        </div>
    </div>

    <!-- Alert area -->
    <div id="alertArea"></div>

    <!-- Loading spinner (only for edit mode) -->
    <div class="text-center py-5 d-none" id="loadingSpinner">
        <div class="spinner-border text-primary" role="status"></div>
        <div class="mt-2 text-body-secondary">Loading unit data...</div>
    </div>

    <div class="row g-3" id="mainContent">

        <!-- Left Column: Form -->
        <div class="col-lg-7 col-xl-6">
            <form id="unitForm" novalidate>
                <input type="hidden" name="csrf_token" id="csrfTokenField" value="<?php echo e($csrf); ?>">
                <input type="hidden" name="id" id="unitId" value="<?php echo $editId; ?>">

                <!-- Identity Section -->
                <div class="card mb-3 form-section">
                    <div class="card-header d-flex align-items-center" data-bs-toggle="collapse" data-bs-target="#secIdentity" role="button">
                        <i class="bi bi-person-badge me-2 text-primary"></i>
                        <span class="fw-semibold">Identity</span>
                        <span class="badge bg-danger ms-auto required-badge">Required</span>
                        <i class="bi bi-chevron-down ms-2 collapse-icon"></i>
                    </div>
                    <div class="collapse show" id="secIdentity">
                        <div class="card-body">
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <label for="name" class="form-label">Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control form-control-sm" id="name" name="name"
                                           required placeholder="Unit or person name" tabindex="1">
                                </div>
                                <div class="col-md-6">
                                    <label for="handle" class="form-label">Handle / Radio ID</label>
                                    <input type="text" class="form-control form-control-sm" id="handle" name="handle"
                                           placeholder="Short identifier" tabindex="2">
                                </div>
                                <div class="col-md-6">
                                    <label for="callsign" class="form-label">Callsign</label>
                                    <input type="text" class="form-control form-control-sm" id="callsign" name="callsign"
                                           placeholder="Radio callsign (e.g. KD9ABC)" tabindex="3">
                                </div>
                                <div class="col-md-6">
                                    <label for="type" class="form-label">Type</label>
                                    <select class="form-select form-select-sm" id="type" name="type" tabindex="4">
                                        <option value="0">-- Select Type --</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Location Section -->
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
                                    <label for="street" class="form-label">Street Address</label>
                                    <div class="input-group input-group-sm">
                                        <input type="text" class="form-control" id="street" name="street"
                                               tabindex="5" placeholder="123 Main St">
                                        <button class="btn btn-outline-secondary" type="button" id="btnGeocode"
                                                tabindex="6" title="Lookup address on map">
                                            <i class="bi bi-search"></i> Lookup
                                        </button>
                                    </div>
                                </div>
                                <div class="col-md-5">
                                    <label for="city" class="form-label">City</label>
                                    <input type="text" class="form-control form-control-sm" id="city" name="city" tabindex="7">
                                </div>
                                <div class="col-md-3">
                                    <label for="state" class="form-label"><?php echo e(t('form.state', 'State')); ?></label>
                                    <select class="form-select form-select-sm" id="state" name="state" tabindex="8"></select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Coordinates</label>
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text">Lat</span>
                                        <input type="text" class="form-control" id="lat" name="lat" placeholder="44.9778">
                                        <span class="input-group-text">Lng</span>
                                        <input type="text" class="form-control" id="lng" name="lng" placeholder="-93.2650">
                                    </div>
                                    <div class="form-text">Click the map or use Lookup to set coordinates</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Contact & Messaging Section -->
                <div class="card mb-3 form-section">
                    <div class="card-header d-flex align-items-center" data-bs-toggle="collapse" data-bs-target="#secContact" role="button">
                        <i class="bi bi-telephone me-2 text-info"></i>
                        <span class="fw-semibold">Contact &amp; Messaging</span>
                        <i class="bi bi-chevron-down ms-2 ms-auto collapse-icon"></i>
                    </div>
                    <div class="collapse show" id="secContact">
                        <div class="card-body">
                            <div class="row g-2">
                                <div class="col-md-4">
                                    <label for="phone" class="form-label">Phone</label>
                                    <input type="tel" class="form-control form-control-sm" id="phone" name="phone"
                                           tabindex="9" placeholder="(612) 555-1234">
                                </div>
                                <div class="col-md-4">
                                    <label for="cellphone" class="form-label">Cell Phone</label>
                                    <input type="tel" class="form-control form-control-sm" id="cellphone" name="cellphone"
                                           tabindex="10" placeholder="(612) 555-5678">
                                </div>
                                <div class="col-md-4">
                                    <label for="contact_name" class="form-label">Contact Name</label>
                                    <input type="text" class="form-control form-control-sm" id="contact_name" name="contact_name"
                                           tabindex="11" placeholder="Emergency contact">
                                </div>
                                <div class="col-md-4">
                                    <label for="contact_via" class="form-label">Contact Method</label>
                                    <input type="text" class="form-control form-control-sm" id="contact_via" name="contact_via"
                                           tabindex="12" placeholder="Phone, Email, Radio, Zello...">
                                </div>
                                <div class="col-md-4">
                                    <label for="smsg_id" class="form-label">SMS Gateway ID</label>
                                    <input type="text" class="form-control form-control-sm" id="smsg_id" name="smsg_id"
                                           tabindex="13" placeholder="SMS gateway identifier">
                                </div>
                                <div class="col-md-4">
                                    <label for="send_no" class="form-label">Notification Number</label>
                                    <input type="text" class="form-control form-control-sm" id="send_no" name="send_no"
                                           tabindex="14" placeholder="Alert send number">
                                </div>
                                <div class="col-md-6">
                                    <label for="pager_p" class="form-label">Primary Pager</label>
                                    <input type="text" class="form-control form-control-sm" id="pager_p" name="pager_p"
                                           tabindex="15" placeholder="Primary pager number">
                                </div>
                                <div class="col-md-6">
                                    <label for="pager_s" class="form-label">Secondary Pager</label>
                                    <input type="text" class="form-control form-control-sm" id="pager_s" name="pager_s"
                                           tabindex="16" placeholder="Secondary pager number">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Status Section (edit mode only) -->
                <div class="card mb-3 form-section d-none" id="secStatusCard">
                    <div class="card-header d-flex align-items-center" data-bs-toggle="collapse" data-bs-target="#secStatus" role="button">
                        <i class="bi bi-activity me-2 text-success"></i>
                        <span class="fw-semibold">Status</span>
                        <span class="badge ms-auto" id="statusDispatchBadge"></span>
                        <i class="bi bi-chevron-down ms-2 collapse-icon"></i>
                    </div>
                    <div class="collapse show" id="secStatus">
                        <div class="card-body">
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <label for="un_status_id" class="form-label">Current Status</label>
                                    <select class="form-select form-select-sm" id="un_status_id" name="un_status_id" tabindex="17">
                                        <option value="">-- Select Status --</option>
                                    </select>
                                </div>
                                <div class="col-md-6 d-flex align-items-end">
                                    <div id="statusDispatchInfo" class="small text-body-secondary"></div>
                                </div>
                                <div class="col-12">
                                    <label for="status_about" class="form-label">Status Reason / Notes</label>
                                    <textarea class="form-control form-control-sm" id="status_about" name="status_about"
                                              rows="2" tabindex="18"
                                              placeholder="e.g. Getting fuel at station 3, back in 30 min..."></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Configuration Section -->
                <div class="card mb-3 form-section">
                    <div class="card-header d-flex align-items-center" data-bs-toggle="collapse" data-bs-target="#secConfig" role="button">
                        <i class="bi bi-sliders me-2 text-warning"></i>
                        <span class="fw-semibold">Configuration</span>
                        <span class="badge bg-danger ms-auto required-badge">Required</span>
                        <i class="bi bi-chevron-down ms-2 collapse-icon"></i>
                    </div>
                    <div class="collapse show" id="secConfig">
                        <div class="card-body">
                            <div class="row g-2">
                                <div class="col-md-4">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="mobile" name="mobile" tabindex="19">
                                        <label class="form-check-label" for="mobile">Mobile Unit</label>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="multi" name="multi" tabindex="20">
                                        <label class="form-check-label" for="multi">Multi-Assign</label>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="direcs" name="direcs" tabindex="21">
                                        <label class="form-check-label" for="direcs">Show Directions</label>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <label for="icon_str" class="form-label">Map Icon</label>
                                    <input type="text" class="form-control form-control-sm" id="icon_str" name="icon_str"
                                           tabindex="22" maxlength="3" placeholder="3-char icon code">
                                </div>
                                <div class="col-md-8">
                                    <label for="capab" class="form-label">Capabilities</label>
                                    <input type="text" class="form-control form-control-sm" id="capab" name="capab"
                                           tabindex="23" placeholder="e.g. EMT, HAM, Search & Rescue">
                                </div>
                                <div class="col-12">
                                    <label for="description" class="form-label">Description <span class="text-danger">*</span></label>
                                    <textarea class="form-control form-control-sm" id="description" name="description"
                                              rows="3" required tabindex="24"
                                              placeholder="Description of this unit..."></textarea>
                                </div>
                                <div class="col-md-6">
                                    <label for="at_facility" class="form-label">Home Facility</label>
                                    <select class="form-select form-select-sm" id="at_facility" name="at_facility" tabindex="25">
                                        <option value="0">-- None --</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label for="other" class="form-label">Other / Notes</label>
                                    <input type="text" class="form-control form-control-sm" id="other" name="other"
                                           tabindex="26" placeholder="Miscellaneous notes">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Assigned Personnel Section -->
                <div class="card mb-3 form-section">
                    <div class="card-header d-flex align-items-center" data-bs-toggle="collapse" data-bs-target="#secPersonnel" role="button">
                        <i class="bi bi-people me-2 text-info"></i>
                        <span class="fw-semibold">Assigned Personnel</span>
                        <span class="badge bg-primary ms-2" id="personnelCountBadge" style="font-size:0.65rem">0</span>
                        <i class="bi bi-chevron-down ms-2 ms-auto collapse-icon"></i>
                    </div>
                    <div class="collapse show" id="secPersonnel">
                        <div class="card-body p-2">
                            <p class="text-body-secondary small mb-2">
                                Personnel assigned to this unit. Their location providers are automatically
                                inherited as location sources (shown in the Location Sources section below).
                            </p>

                            <!-- Current assignments -->
                            <div id="personnelAssignmentsList">
                                <div class="text-center text-body-secondary py-2 small">No personnel assigned</div>
                            </div>

                            <!-- Assign new personnel -->
                            <div class="mt-2 border-top pt-2">
                                <div class="row g-2 align-items-end">
                                    <div class="col-md-5">
                                        <label class="form-label form-label-sm mb-0">Search Personnel</label>
                                        <input type="text" class="form-control form-control-sm" id="personnelSearchBox"
                                               placeholder="Type name or callsign..." autocomplete="off">
                                        <div id="personnelSearchDropdown" class="position-relative">
                                            <div id="personnelSearchResults" class="position-absolute w-100 bg-body border rounded shadow-sm" style="z-index:1050;max-height:200px;overflow-y:auto;display:none"></div>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label form-label-sm mb-0">Role</label>
                                        <select class="form-select form-select-sm" id="personnelRoleSelect">
                                            <option value="operator">Operator</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <button class="btn btn-sm btn-outline-primary w-100" id="btnAssignPersonnelEdit" disabled>
                                            <i class="bi bi-person-plus me-1"></i>Assign
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Location Sources Section -->
                <div class="card mb-3 form-section">
                    <div class="card-header d-flex align-items-center" data-bs-toggle="collapse" data-bs-target="#secTracking" role="button">
                        <i class="bi bi-broadcast me-2 text-danger"></i>
                        <span class="fw-semibold">Location Sources</span>
                        <span class="badge bg-secondary ms-2" id="locationSourceCount" style="font-size:0.65rem">0</span>
                        <i class="bi bi-chevron-down ms-2 ms-auto collapse-icon"></i>
                    </div>
                    <div class="collapse show" id="secTracking">
                        <div class="card-body p-2">
                            <p class="text-body-secondary small mb-2">
                                Location sources are checked in priority order. The first source with fresh data
                                (within its staleness threshold) determines this unit's position on the map.
                                Drag rows to reorder. Personnel sources appear automatically when crew are assigned.
                            </p>

                            <!-- Phase 117 (GH #84): unit-level OwnTracks device provisioning -->
                            <div id="unitOtCard" class="border rounded p-2 mb-2 bg-body-tertiary d-none">
                                <div class="d-flex align-items-center mb-1">
                                    <i class="bi bi-phone-vibrate me-2 text-danger"></i>
                                    <span class="fw-semibold small">OwnTracks device for this unit</span>
                                    <span id="unitOtTid" class="badge bg-secondary ms-2 d-none" style="font-size:0.65rem"></span>
                                </div>
                                <div id="unitOtWarn" class="alert alert-warning py-1 px-2 small mb-2 d-none"></div>
                                <p class="text-body-secondary mb-2" style="font-size:0.75rem">
                                    Give this unit its own tracked phone or tablet, independent of crew &mdash; the vehicle
                                    reports its own location. Set up a device below, then open the config on that device's
                                    OwnTracks app.
                                </p>
                                <div class="d-flex gap-1 flex-wrap mb-2">
                                    <button type="button" class="btn btn-sm btn-success" id="unitOtNewFile" title="Android-friendly: downloads an .otrc file to load in OwnTracks"><i class="bi bi-file-earmark-arrow-down me-1"></i>New: File (Android)</button>
                                    <button type="button" class="btn btn-sm btn-success" id="unitOtNewQr" title="iOS-friendly: scan from inside the OwnTracks app"><i class="bi bi-qr-code me-1"></i>New: QR (iOS)</button>
                                    <button type="button" class="btn btn-sm btn-success" id="unitOtNewUrl" title="Raw owntracks:/// config URL"><i class="bi bi-link-45deg me-1"></i>New: URL</button>
                                </div>
                                <div id="unitOtTokens" class="small"></div>
                            </div>

                            <!-- Current resolved position (Phase 64) -->
                            <div id="currentPositionCard" class="alert alert-secondary py-2 px-2 mb-2 d-none small">
                                <div class="d-flex align-items-center gap-2">
                                    <i id="currentPositionIcon" class="bi bi-geo-alt-fill fs-5"></i>
                                    <div class="flex-grow-1">
                                        <div>
                                            <span class="fw-semibold">Current position:</span>
                                            <span id="currentPositionCoords" class="font-monospace">--</span>
                                            <span id="currentPositionFreshBadge" class="badge ms-1">--</span>
                                        </div>
                                        <div class="text-body-secondary" style="font-size:0.75rem">
                                            <span id="currentPositionProvider">--</span>
                                            &middot; <span id="currentPositionAge">--</span>
                                        </div>
                                    </div>
                                    <button type="button" id="btnCenterOnCurrent" class="btn btn-sm btn-outline-primary py-0 px-2"
                                            title="Center the map on this position">
                                        <i class="bi bi-bullseye"></i> Center map
                                    </button>
                                </div>
                            </div>
                            <div id="currentPositionEmpty" class="text-body-secondary small mb-2 d-none">
                                <i class="bi bi-geo me-1"></i>No live position yet &mdash; sources will populate once a provider reports in.
                            </div>

                            <!-- Sortable provider list -->
                            <div class="table-responsive">
                                <table class="table table-sm table-hover mb-0 align-middle" style="font-size:0.8rem" id="locationSourcesTable">
                                    <thead>
                                        <tr>
                                            <th style="width:30px"></th>
                                            <th style="width:30px">Pri</th>
                                            <th>Provider</th>
                                            <th>Identifier</th>
                                            <th>Source</th>
                                            <th style="width:70px">Max Age</th>
                                            <th style="width:40px">On</th>
                                            <th style="width:30px"></th>
                                        </tr>
                                    </thead>
                                    <tbody id="locationSourcesBody">
                                        <tr><td colspan="8" class="text-center text-body-secondary py-3">
                                            <div class="spinner-border spinner-border-sm me-1"></div>Loading...
                                        </td></tr>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Add new source -->
                            <div class="mt-2 border-top pt-2">
                                <div class="row g-2 align-items-end">
                                    <div class="col-md-3">
                                        <label class="form-label form-label-sm mb-0">Provider</label>
                                        <select class="form-select form-select-sm" id="addSourceProvider">
                                            <option value="">-- add source --</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label form-label-sm mb-0">Identifier</label>
                                        <input type="text" class="form-control form-control-sm" id="addSourceIdentifier"
                                               placeholder="Callsign, device ID...">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label form-label-sm mb-0">Priority</label>
                                        <input type="number" class="form-control form-control-sm" id="addSourcePriority"
                                               value="50" min="1" max="200">
                                    </div>
                                    <div class="col-md-2">
                                        <button class="btn btn-sm btn-outline-primary w-100" id="btnAddSource">
                                            <i class="bi bi-plus-lg me-1"></i>Add
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!-- Hidden field for legacy compat -->
                            <input type="hidden" id="tracking_provider" name="tracking_provider" value="">
                        </div>
                    </div>
                </div>

                <!-- Boundaries Section -->
                <div class="card mb-3 form-section">
                    <div class="card-header d-flex align-items-center" data-bs-toggle="collapse" data-bs-target="#secBoundaries" role="button">
                        <i class="bi bi-bullseye me-2 text-secondary"></i>
                        <span class="fw-semibold">Boundaries</span>
                        <i class="bi bi-chevron-down ms-2 ms-auto collapse-icon"></i>
                    </div>
                    <div class="collapse" id="secBoundaries">
                        <div class="card-body">
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <label for="ring_fence" class="form-label">Ringfence ID</label>
                                    <input type="number" class="form-control form-control-sm" id="ring_fence" name="ring_fence"
                                           tabindex="28" min="0" placeholder="0 = none">
                                    <div class="form-text">Alert if unit leaves this geofence zone</div>
                                </div>
                                <div class="col-md-6">
                                    <label for="excl_zone" class="form-label">Exclusion Zone ID</label>
                                    <input type="number" class="form-control form-control-sm" id="excl_zone" name="excl_zone"
                                           tabindex="29" min="0" placeholder="0 = none">
                                    <div class="form-text">Alert if unit enters this restricted zone</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </form>

            <!-- Delete button (edit mode only) -->
            <div class="d-none" id="deleteSection">
                <hr>
                <div class="d-flex justify-content-end">
                    <button type="button" class="btn btn-sm btn-outline-danger" id="btnDelete">
                        <i class="bi bi-trash me-1"></i>Delete Unit
                    </button>
                </div>
            </div>
        </div>

        <!-- Right Column: Map -->
        <div class="col-lg-5 col-xl-6">
            <div class="sticky-top" style="top: 70px;">

                <!-- Map -->
                <div class="card mb-3">
                    <div class="card-header d-flex align-items-center py-1">
                        <i class="bi bi-map me-2"></i>
                        <span class="fw-semibold small">Location Map</span>
                        <span class="text-body-secondary small ms-auto">Click to set unit location</span>
                    </div>
                    <div class="card-body p-0">
                        <div id="editMap" style="height: 450px;"></div>
                    </div>
                </div>

                <!-- Bottom save (visible when scrolled) -->
                <div class="d-grid gap-2">
                    <button type="button" class="btn btn-success" id="btnSaveBottom">
                        <i class="bi bi-check-lg me-1"></i>Save Unit
                    </button>
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
<script src="assets/js/map-image-overlays.js?v=<?php echo function_exists('asset_v') ? asset_v('assets/js/map-image-overlays.js') : NEWUI_VERSION; ?>"></script>

<!-- App JS -->
<script src="assets/js/theme-manager.js"></script>
<script src="assets/js/states-select.js?v=<?php echo function_exists('asset_v') ? asset_v('assets/js/states-select.js') : NEWUI_VERSION; ?>"></script>
<script src="assets/js/owntracks-provision.js?v=<?php echo function_exists('asset_v') ? asset_v('assets/js/owntracks-provision.js') : NEWUI_VERSION; ?>"></script>
<script src="assets/js/unit-edit.js?v=<?php echo function_exists('asset_v') ? asset_v('assets/js/unit-edit.js') : NEWUI_VERSION; ?>"></script>

</body>
</html>
