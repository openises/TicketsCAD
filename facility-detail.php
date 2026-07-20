<?php
/**
 * NewUI v4.0 - Facility Detail
 *
 * View a facility. Two-column layout:
 *   Left:  Facility info in card sections
 *   Right: Map + stats + quick actions
 *
 * Data loaded client-side via api/facility-detail.php
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
rbac_require_screen('screen.facility_detail');
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
    <title><?php echo e(t('page.facility_detail_header', 'Facility Detail')); ?> — <?php echo e(t('login.title', 'Tickets NewUI')); ?> <?php echo NEWUI_VERSION; ?></title>

    <!-- Vendor CSS -->
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/vendor/leaflet/leaflet.css">

    <!-- App CSS -->
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?php echo asset_v('assets/css/dashboard.css'); ?>">
    <link rel="stylesheet" href="assets/css/facilities.css?v=<?php echo asset_v('assets/css/facilities.css'); ?>">
</head>
<body>

<?php include_once NEWUI_ROOT . '/inc/navbar.php'; ?>
</header>

<!-- Page Content -->
<div class="container-fluid p-3">

    <!-- Page title + action buttons -->
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h5 class="mb-0" id="pageTitle">
            <i class="bi bi-hospital text-primary me-2"></i><?php echo e(t('page.facility_detail_header', 'Facility Detail')); ?>
        </h5>
        <div class="d-flex gap-2 align-items-center">
            <a href="facilities.php" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i><?php echo e(t('facs.title', 'Facilities')); ?>
            </a>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()">
                <i class="bi bi-printer me-1"></i><?php echo e(t('roster.btn.print', 'Print')); ?>
            </button>
        </div>
    </div>

    <!-- Alert area -->
    <div id="alertArea"></div>

    <!-- Loading spinner -->
    <div class="text-center py-5" id="loadingSpinner">
        <div class="spinner-border text-primary" role="status"></div>
        <div class="mt-2 text-body-secondary">Loading facility...</div>
    </div>

    <!-- Main content (hidden until data loads) -->
    <div class="row g-3 d-none" id="mainContent">

        <!-- ═══════════ LEFT COLUMN: Facility Details ═══════════ -->
        <div class="col-lg-7 col-xl-6">

            <!-- Facility Header -->
            <div class="card mb-3" id="headerCard">
                <div class="card-body py-2">
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <span class="badge" id="typeBadge">--</span>
                        <span class="badge" id="statusBadge">--</span>
                        <span class="badge bg-secondary d-none" id="hiddenBadge">Hidden</span>
                    </div>
                    <h5 class="mb-1" id="facilityName">--</h5>
                    <div class="text-body-secondary small" id="facilityMeta">--</div>
                </div>
            </div>

            <!-- Info / Contact -->
            <div class="card mb-3">
                <div class="card-header d-flex align-items-center py-1 form-section"
                     data-bs-toggle="collapse" data-bs-target="#collapseInfo" role="button">
                    <i class="bi bi-info-circle me-2"></i>
                    <span class="fw-semibold small">Information &amp; Contact</span>
                    <i class="bi bi-chevron-down ms-auto collapse-icon"></i>
                </div>
                <div class="collapse show" id="collapseInfo">
                    <div class="card-body py-2 small">
                        <div class="mb-2" id="facilityDesc" style="white-space: pre-wrap;">--</div>
                        <hr class="my-2">
                        <div class="row g-2">
                            <div class="col-md-4">
                                <div class="text-body-secondary">Handle</div>
                                <div id="infoHandle">--</div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-body-secondary">Callsign</div>
                                <div id="infoCallsign">--</div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-body-secondary">Capabilities</div>
                                <div id="infoCapab">--</div>
                            </div>
                        </div>
                        <hr class="my-2">
                        <div class="row g-2">
                            <div class="col-md-4">
                                <div class="text-body-secondary">Contact Name</div>
                                <div id="contactName">--</div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-body-secondary">Email</div>
                                <div id="contactEmail">--</div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-body-secondary">Phone</div>
                                <div id="contactPhone">--</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Location -->
            <div class="card mb-3">
                <div class="card-header d-flex align-items-center py-1 form-section"
                     data-bs-toggle="collapse" data-bs-target="#collapseLoc" role="button">
                    <i class="bi bi-geo-alt me-2"></i>
                    <span class="fw-semibold small">Location</span>
                    <i class="bi bi-chevron-down ms-auto collapse-icon"></i>
                </div>
                <div class="collapse show" id="collapseLoc">
                    <div class="card-body py-2 small">
                        <div class="row g-2">
                            <div class="col-md-6">
                                <div class="text-body-secondary">Street</div>
                                <div id="locStreet">--</div>
                            </div>
                            <div class="col-md-3">
                                <div class="text-body-secondary">City</div>
                                <div id="locCity">--</div>
                            </div>
                            <div class="col-md-3">
                                <div class="text-body-secondary"><?php echo e(t('form.state', 'State')); ?></div>
                                <div id="locState">--</div>
                            </div>
                        </div>
                        <div class="row g-2 mt-1">
                            <div class="col-auto">
                                <span class="text-body-secondary">Lat:</span>
                                <span id="locLat">--</span>
                            </div>
                            <div class="col-auto">
                                <span class="text-body-secondary">Lng:</span>
                                <span id="locLng">--</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Beds / Capacity -->
            <div class="card mb-3">
                <div class="card-header d-flex align-items-center py-1 form-section"
                     data-bs-toggle="collapse" data-bs-target="#collapseBeds" role="button">
                    <i class="bi bi-building me-2"></i>
                    <span class="fw-semibold small">Bed Capacity</span>
                    <!-- Phase 103 (a beta tester GH #20) — surface the automation mode next
                         to the header so dispatchers know at a glance whether they
                         should be editing the counters themselves. -->
                    <span class="badge bg-secondary ms-2 small d-none" id="bedAutoModeBadge"
                          title="Bed count update mode">Manual</span>
                    <a href="help.php#topic-bed-counts" class="ms-2 text-body-secondary small"
                       title="How bed counts update" target="_blank" onclick="event.stopPropagation();">
                        <i class="bi bi-info-circle"></i>
                    </a>
                    <i class="bi bi-chevron-down ms-auto collapse-icon"></i>
                </div>
                <div class="collapse show" id="collapseBeds">
                    <div class="card-body py-2 small">
                        <div class="row g-2">
                            <div class="col-md-3">
                                <div class="text-body-secondary">Available</div>
                                <div class="fs-5 fw-bold text-success" id="bedsAvailable">0</div>
                            </div>
                            <div class="col-md-3">
                                <div class="text-body-secondary">Occupied</div>
                                <div class="fs-5 fw-bold text-warning" id="bedsOccupied">0</div>
                            </div>
                            <div class="col-md-6">
                                <div class="text-body-secondary">Capacity Bar</div>
                                <div class="progress mt-1" style="height: 20px;" id="bedProgressContainer">
                                    <div class="progress-bar bg-warning" role="progressbar" id="bedProgress"
                                         style="width: 0%;">0%</div>
                                </div>
                            </div>
                        </div>
                        <div class="mt-2 d-none" id="bedInfoRow">
                            <div class="text-body-secondary">Bed Info</div>
                            <div id="bedInfo" style="white-space: pre-wrap;">--</div>
                        </div>
                        <div class="mt-2 d-none" id="statusAboutRow">
                            <div class="text-body-secondary">Status Notes</div>
                            <div id="statusAbout" style="white-space: pre-wrap;">--</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Assigned Incidents -->
            <div class="card mb-3">
                <div class="card-header d-flex align-items-center py-1 form-section"
                     data-bs-toggle="collapse" data-bs-target="#collapseIncidents" role="button">
                    <i class="bi bi-file-earmark-text me-2"></i>
                    <span class="fw-semibold small">Related Incidents</span>
                    <span class="badge bg-secondary ms-auto" id="incidentCount">0</span>
                    <i class="bi bi-chevron-down ms-2 collapse-icon"></i>
                </div>
                <div class="collapse show" id="collapseIncidents">
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover mb-0 small">
                                <thead>
                                    <tr>
                                        <th class="ps-3">ID</th>
                                        <th>Scope</th>
                                        <th>Type</th>
                                        <th>Role</th>
                                        <th>Status</th>
                                        <th>Date</th>
                                    </tr>
                                </thead>
                                <tbody id="incidentsBody">
                                </tbody>
                            </table>
                        </div>
                        <div class="text-center text-body-secondary py-2 small d-none" id="noIncidents">
                            No incidents linked to this facility.
                        </div>
                    </div>
                </div>
            </div>

            <!-- Notes (GH #75) -->
            <div class="card mb-3">
                <div class="card-header d-flex align-items-center py-1 form-section"
                     data-bs-toggle="collapse" data-bs-target="#collapseNotes" role="button">
                    <i class="bi bi-sticky me-2"></i>
                    <span class="fw-semibold small">Notes</span>
                    <span class="badge bg-secondary ms-auto" id="noteCount">0</span>
                    <i class="bi bi-chevron-down ms-2 collapse-icon"></i>
                </div>
                <div class="collapse show" id="collapseNotes">
                    <div class="card-body p-0">
                        <ul class="list-group list-group-flush small" id="notesBody"></ul>
                        <div class="text-center text-body-secondary py-2 small d-none" id="noNotes">
                            No notes recorded for this facility.
                        </div>
                    </div>
                </div>
            </div>

        </div>

        <!-- ═══════════ RIGHT COLUMN: Map + Stats ═══════════ -->
        <div class="col-lg-5 col-xl-6">
            <div class="sticky-top" style="top: 100px;">

                <!-- Map -->
                <div class="card mb-3">
                    <div class="card-header d-flex align-items-center py-1">
                        <i class="bi bi-map me-2"></i>
                        <span class="fw-semibold small">Location Map</span>
                    </div>
                    <div class="card-body p-0">
                        <div id="facilityMap" style="height: 350px;"></div>
                    </div>
                </div>

                <!-- Stats -->
                <div class="card mb-3">
                    <div class="card-header d-flex align-items-center py-1">
                        <i class="bi bi-bar-chart me-2"></i>
                        <span class="fw-semibold small">Transport Statistics</span>
                    </div>
                    <div class="card-body py-2">
                        <div class="row g-2 text-center">
                            <div class="col-6">
                                <div class="text-body-secondary small">Total Transports</div>
                                <div class="fs-4 fw-bold" id="statTotal">0</div>
                            </div>
                            <div class="col-6">
                                <div class="text-body-secondary small">This Month</div>
                                <div class="fs-4 fw-bold" id="statMonth">0</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="card mb-3">
                    <div class="card-header d-flex align-items-center py-1">
                        <i class="bi bi-lightning me-2"></i>
                        <span class="fw-semibold small">Quick Actions</span>
                    </div>
                    <div class="card-body py-2">
                        <div class="d-flex gap-2 flex-wrap">
                            <a href="#" class="btn btn-sm btn-outline-primary" id="btnEdit">
                                <i class="bi bi-pencil me-1"></i>Edit Facility
                            </a>
                            <a href="new-incident.php" class="btn btn-sm btn-outline-success" id="btnNewIncident">
                                <i class="bi bi-plus-circle me-1"></i>New Incident Here
                            </a>
                        </div>
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
<script src="assets/js/toolbar.js?v=<?php echo asset_v('assets/js/toolbar.js'); ?>"></script>
<script src="assets/vendor/leaflet/leaflet.js"></script>
<script src="assets/js/leaflet-mobile-fit.js?v=<?php echo function_exists("asset_v")?asset_v("assets/js/leaflet-mobile-fit.js"):NEWUI_VERSION; ?>"></script>
<script src="assets/js/leaflet-quadkey.js"></script>
<script src="assets/js/map-prefs.js"></script>
<script src="assets/js/map-image-overlays.js?v=<?php echo function_exists('asset_v') ? asset_v('assets/js/map-image-overlays.js') : NEWUI_VERSION; ?>"></script>

<!-- App JS -->
<script src="assets/js/theme-manager.js"></script>
<script src="assets/js/facility-status.js?v=<?php echo asset_v('assets/js/facility-status.js'); ?>"></script>
<script src="assets/js/facility-detail.js?v=<?php echo asset_v('assets/js/facility-detail.js'); ?>"></script>

</body>
</html>
