<?php
/**
 * NewUI v4.0 - Incident Detail
 *
 * View and manage an existing incident. Two-column layout:
 *   Left:  Incident details in card sections
 *   Right: Map + assigned responders + activity log
 *
 * Data loaded client-side via api/incident-detail.php
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
rbac_require_screen('screen.incident_detail');
require_once __DIR__ . '/inc/force-pw-change.php';
force_pw_change_redirect();

// Phase 99o (Eric beta 2026-06-29) — allow opening by case number
// (?number=26-0062) in addition to internal id (?id=217). Lookup
// via incnum_find_existing(); 302 to ?id= so the canonical URL
// shape is preserved for bookmarks + deep links.
if (empty($_GET['id']) && !empty($_GET['number'])) {
    require_once __DIR__ . '/inc/db.php';
    $resolved = incnum_find_existing(trim((string) $_GET['number']));
    if ($resolved) {
        header('Location: incident-detail.php?id=' . (int) $resolved);
        exit;
    }
    // Fall through with no id so the page can render its "not found".
}
$incNumLabel = incnum_get_label();

$user     = e($_SESSION['user']);
$level = current_role_name();
$theme    = $_SESSION['day_night'] ?? 'Day';
$bs_theme = ($theme === 'Night') ? 'dark' : 'light';
$csrf     = csrf_token();
$canManageMajor = function_exists('rbac_can') ? rbac_can('action.link_major') : false;
?>
<!DOCTYPE html>
<html lang="<?php echo e(i18n_lang()); ?>" data-bs-theme="<?php echo $bs_theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?php echo e($csrf); ?>">
    <title><?php echo e(t('incdetail.page_title', 'Incident Detail')); ?> — <?php echo e(t('login.title', 'Tickets NewUI')); ?> <?php echo newui_version(); ?></title>

    <!-- Vendor CSS -->
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/vendor/leaflet/leaflet.css">

    <!-- App CSS -->
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?php echo newui_version(); ?>">
    <link rel="stylesheet" href="assets/css/incident-detail.css?v=<?php echo newui_version(); ?>">

    <!-- Print CSS -->
    <link rel="stylesheet" href="assets/css/print.css" media="print">
</head>
<body>

<?php include_once NEWUI_ROOT . '/inc/navbar.php'; ?>
</header>

<!-- Page Content -->
<div class="container-fluid p-3">

    <!-- Page title + action buttons -->
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div class="d-flex align-items-center">
            <h5 class="mb-0 d-flex align-items-center" id="pageTitle">
                <i class="bi bi-file-earmark-text text-primary me-2"></i><?php echo e(t('incdetail.page_title', 'Incident Detail')); ?>
            </h5>
            <!-- Phase 18 — Security label badge. Populated by JS from
                 api/security-labels.php?action=resolve. Clickable for users
                 with action.set_incident_security.
                 Eric (2026-08-12) — this used to live INSIDE #pageTitle. Every
                 renderHeader() call (incident-detail.js) does
                 `pageTitle.innerHTML = ...` unconditionally to update the
                 title text, which silently destroyed this badge on every
                 single load/refresh -- the security label picker has been
                 completely unreachable in the deployed product since the
                 Phase 99m/99o/99p title-rendering rewrite. Moved to a sibling
                 of #pageTitle (still inside a shared flex wrapper so it stays
                 visually adjacent to the title rather than being pushed apart
                 by the outer row's justify-content-between) so nothing that
                 rewrites the title can touch it — matching how
                 severityBadge/statusBadge already coexist safely (updated via
                 .textContent, never a parent .innerHTML replace). -->
            <span class="badge ms-3 d-none" id="secLabelBadge"
                  style="cursor:pointer" title="Click to change security label">
            </span>
        </div>
        <div class="d-flex gap-2 align-items-center">
            <!-- 2026-06-11 — Mayday is an EMERGENCY button. It is
                 always visible regardless of par_enabled state. If
                 PAR is off, the click flow auto-enables it for the
                 duration of the cycle so safety isn't gated behind
                 configuration. -->
            <button type="button" class="btn btn-sm btn-danger d-none" id="btnMaydayTop"
                    title="Declare MAYDAY — initiates urgent PAR for all assigned units, audit-logged, and notifies dispatch chat. Use only in emergencies.">
                <i class="bi bi-exclamation-octagon-fill me-1"></i>MAYDAY
            </button>
            <!-- Status change controls (populated by JS) -->
            <div class="d-none" id="statusControls">
                <div class="input-group input-group-sm">
                    <select class="form-select form-select-sm" id="statusSelect" style="width: auto;" aria-label="Change incident status">
                        <option value="2"><?php echo e(t('newinc.status.open', 'Open')); ?></option>
                        <option value="1"><?php echo e(t('newinc.status.closed', 'Closed')); ?></option>
                        <option value="3"><?php echo e(t('newinc.status.scheduled', 'Scheduled')); ?></option>
                    </select>
                    <button type="button" class="btn btn-sm btn-warning" id="btnChangeStatus">
                        <i class="bi bi-arrow-repeat me-1"></i><?php echo e(t('incdetail.btn.update', 'Update')); ?>
                    </button>
                </div>
                <!-- Phase 132 Step 4 (GH #16) — close-flow disposition picker.
                     Shown whenever Closed is selected above (regardless of
                     whether disposition_required_on_close is on); pre-filled
                     with the incident's current value if it already has one.
                     Sent inline in update_status's body, same conditional-add
                     pattern booked_date already uses (assets/js/incident-detail.js
                     changeStatus()). Options come from the SAME picker endpoint
                     as #dispositionSelect below (api/dispositions-picker.php). -->
                <div class="input-group input-group-sm mt-1 d-none" id="closeDispositionRow">
                    <label class="input-group-text py-1" for="closeDispositionSelect">
                        <i class="bi bi-clipboard-check me-1"></i><?php echo e(t('incdetail.label.disposition', 'Disposition')); ?>
                    </label>
                    <select class="form-select form-select-sm" id="closeDispositionSelect">
                        <option value=""><?php echo e(t('incdetail.disposition.none', '— None —')); ?></option>
                    </select>
                </div>
            </div>
            <a href="#" class="btn btn-sm btn-outline-success d-none" id="btnNavigate" target="_blank" title="Open directions in Google Maps" aria-label="Navigate to incident location">
                <i class="bi bi-signpost-2 me-1"></i><?php echo e(t('incdetail.btn.navigate', 'Navigate')); ?>
            </a>
            <a href="#" class="btn btn-sm btn-outline-info d-none" id="btnWinlinkExport" title="Export as Winlink ICS-213 XML" aria-label="Export Winlink form">
                <i class="bi bi-broadcast me-1"></i>ICS-213
            </a>
            <!-- Phase 142 (GH#70 Phase 2) — manual cross-org sharing.
                 Hidden by default; incident-detail.js's renderHeader()
                 toggles d-none from data.can_manage_sharing on every
                 load/refresh (api/incident-detail.php computes it from
                 RBAC + org_ticket_is_owned_by_caller() -- unconditionally
                 true only for the ticket's own owning org, never a
                 shared-in viewer). Display-only gate; every write is
                 re-checked server-side by api/incident-share.php. -->
            <button type="button" class="btn btn-sm btn-outline-primary d-none" id="btnShareIncident"
                    title="Share this incident with another organization">
                <i class="bi bi-share me-1"></i>Share&hellip;
            </button>
            <a href="index.php" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>Dashboard
            </a>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()">
                <i class="bi bi-printer me-1"></i>Print
            </button>
        </div>
    </div>

    <!-- Alert area -->
    <div id="alertArea"></div>

    <!-- Phase 27 follow-up (2026-06-12) — sticky PAR-overdue alarm banner.
         Hidden by default; revealed by updateParHeaderBadge() when the
         next-due timestamp has passed. Full-width red strip with pulse
         animation. Click Initiate to fire a new cycle; Snooze suppresses
         the audio + banner for 5 minutes (overdue badge in header stays). -->
    <div id="parOverdueBanner" class="d-none" style="position: sticky; top: 0; z-index: 1040;">
        <div class="alert alert-danger d-flex align-items-center gap-2 mb-2 mt-0 py-2 px-3"
             style="border:2px solid #b02a37; box-shadow: 0 2px 12px rgba(176,42,55,.5); animation: par-overdue-pulse 1.5s ease-in-out infinite;">
            <i class="bi bi-exclamation-triangle-fill fs-4"></i>
            <div class="flex-grow-1">
                <strong>PAR OVERDUE — Personnel Accountability Report not started.</strong>
                <span id="parOverdueDetail" class="ms-1"></span>
            </div>
            <button type="button" class="btn btn-sm btn-warning" id="btnParOverdueInitiate">
                <i class="bi bi-megaphone me-1"></i>Initiate PAR now
            </button>
            <button type="button" class="btn btn-sm btn-outline-light" id="btnParOverdueSnooze"
                    title="Mute the alarm for 5 minutes (next PAR still shows in header)">
                <i class="bi bi-bell-slash me-1"></i>Snooze 5m
            </button>
        </div>
    </div>
    <style>
        @keyframes par-overdue-pulse {
            0%, 100% { opacity: 1; }
            50%      { opacity: 0.7; }
        }
    </style>

    <!-- Loading spinner (shown until data loads) -->
    <div class="text-center py-5" id="loadingSpinner">
        <div class="spinner-border text-primary" role="status"></div>
        <div class="mt-2 text-body-secondary">Loading incident...</div>
    </div>

    <!-- Main content (hidden until data loads) -->
    <div class="row g-3 d-none" id="mainContent">

        <!-- ═══════════ LEFT COLUMN: Incident Details ═══════════ -->
        <div class="col-lg-7 col-xl-6">

            <!-- Incident Header -->
            <div class="card mb-3" id="headerCard">
                <div class="card-body py-2">
                    <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                        <span class="badge" id="severityBadge">—</span>
                        <span class="badge" id="statusBadge">—</span>
                        <span class="badge bg-secondary" id="typeBadge">—</span>
                        <!-- Phase 141 (GH#70) — cross-org sharing indicator.
                             Hidden by default; renderHeader() (incident-detail.js)
                             shows it via .textContent (never innerHTML) when
                             the incident response carries shared_from_org_name
                             -- i.e. this session's org sees the incident via an
                             active incident_shares grant, not same-org access. -->
                        <span class="badge bg-info text-dark d-none" id="sharedFromBadge" title="This incident is shared from another organization">
                            <i class="bi bi-share"></i> <span id="sharedFromBadgeText"></span>
                        </span>
                        <!-- Phase 27B (2026-06-11) — PAR-active header badge.
                             Hidden by default; populated by refreshPAR() when
                             this incident has PAR enabled. Click to scroll to
                             the PAR card. -->
                        <a href="#parCard" id="parHeaderBadge" class="badge bg-info text-dark text-decoration-none d-none" title="PAR is active on this incident — click to jump to the PAR card">
                            <i class="bi bi-shield-check"></i>
                            <span id="parHeaderText">PAR active</span>
                        </a>
                    </div>
                    <h5 class="mb-1" id="incidentScope">—</h5>
                    <div class="text-body-secondary small" id="incidentMeta">—</div>
                </div>
            </div>

            <!-- Description -->
            <div class="card mb-3">
                <!-- Eric (2026-08-12) — deliberately NOT data-bs-toggle="collapse".
                     Bootstrap 5's collapse data-api listens on document in the
                     CAPTURE phase, which fires before a click ever reaches the
                     .edit-section-btn pencil nested inside this header -- so
                     the pencil's own e.stopPropagation() could never prevent
                     the section from also collapsing. assets/js/incident-
                     detail.js's bindManualSectionCollapse() drives the same
                     bootstrap.Collapse instance from data-bs-target instead,
                     via our own bubble-phase listener that can actually check
                     "was this click inside .edit-section-btn?" before toggling.
                     Every .form-section header in this file follows this same
                     pattern now -- don't add data-bs-toggle="collapse" back. -->
                <div class="card-header d-flex align-items-center py-1 form-section"
                     data-bs-target="#collapseDesc" role="button" tabindex="0" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();this.click();}">
                    <i class="bi bi-card-text me-2"></i>
                    <span class="fw-semibold small"><?php echo e(t('incdetail.section.description', 'Description')); ?></span>
                    <button type="button" class="btn btn-link btn-sm p-0 ms-2 edit-section-btn d-none"
                            data-section="description" title="Edit description">
                        <i class="bi bi-pencil" style="font-size: 0.7rem;"></i>
                    </button>
                    <i class="bi bi-chevron-down ms-auto collapse-icon"></i>
                </div>
                <div class="collapse show" id="collapseDesc">
                    <div class="card-body py-2 small">
                        <!-- Display mode -->
                        <div id="descDisplay">
                            <div id="incidentDesc" class="mb-2" style="white-space: pre-wrap;">—</div>
                            <div id="incidentAffected" class="text-body-secondary d-none">
                                <strong>Affected:</strong> <span id="affectedText"></span>
                            </div>
                        </div>
                        <!-- Edit mode (hidden) -->
                        <div id="descEdit" class="d-none">
                            <div class="mb-2">
                                <label class="form-label text-body-secondary mb-0" for="editScope">Scope / Title</label>
                                <input type="text" class="form-control form-control-sm" id="editScope">
                            </div>
                            <div class="mb-2">
                                <label class="form-label text-body-secondary mb-0" for="editDescription">Description</label>
                                <textarea class="form-control form-control-sm" id="editDescription" rows="3"></textarea>
                            </div>
                            <div class="mb-2">
                                <label class="form-label text-body-secondary mb-0" for="editAffected">Affected</label>
                                <input type="text" class="form-control form-control-sm" id="editAffected">
                            </div>
                            <div class="d-flex gap-1">
                                <button type="button" class="btn btn-sm btn-primary edit-save-btn" data-section="description">
                                    <i class="bi bi-check-lg me-1"></i>Save
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary edit-cancel-btn" data-section="description">Cancel</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Location -->
            <div class="card mb-3">
                <div class="card-header d-flex align-items-center py-1 form-section"
                     data-bs-target="#collapseLoc" role="button" tabindex="0" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();this.click();}">
                    <i class="bi bi-geo-alt me-2"></i>
                    <span class="fw-semibold small"><?php echo e(t('incdetail.section.location', 'Location')); ?></span>
                    <button type="button" class="btn btn-link btn-sm p-0 ms-2 edit-section-btn d-none"
                            data-section="location" title="Edit location">
                        <i class="bi bi-pencil" style="font-size: 0.7rem;"></i>
                    </button>
                    <i class="bi bi-chevron-down ms-auto collapse-icon"></i>
                </div>
                <div class="collapse show" id="collapseLoc">
                    <div class="card-body py-2 small">
                        <!-- Display mode -->
                        <div id="locDisplay">
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <div class="text-body-secondary">Street</div>
                                    <div id="locStreet">—</div>
                                </div>
                                <div class="col-md-3">
                                    <div class="text-body-secondary">City</div>
                                    <div id="locCity">—</div>
                                </div>
                                <div class="col-md-3">
                                    <div class="text-body-secondary"><?php echo e(t('form.state', 'State')); ?></div>
                                    <div id="locState">—</div>
                                </div>
                            </div>
                            <div class="row g-2 mt-1" id="locExtrasRow">
                                <div class="col-md-6 d-none" id="locCrossCol">
                                    <div class="text-body-secondary">Cross St / Area</div>
                                    <div id="locCross">—</div>
                                </div>
                                <div class="col-md-6 d-none" id="locDestCol">
                                    <div class="text-body-secondary">Destination</div>
                                    <div id="locDest">—</div>
                                </div>
                            </div>
                            <div class="row g-2 mt-1" id="locCoordsRow">
                                <div class="col-auto">
                                    <span class="text-body-secondary">Lat:</span>
                                    <span id="locLat">—</span>
                                </div>
                                <div class="col-auto">
                                    <span class="text-body-secondary">Lng:</span>
                                    <span id="locLng">—</span>
                                </div>
                            </div>
                        </div>
                        <!-- Edit mode (hidden). 2026-06-26 — added
                             address_about (Cross St / Area) and to_address
                             (Destination) so edit flow has parity with the
                             new-incident form. -->
                        <div id="locEdit" class="d-none">
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <label class="form-label text-body-secondary mb-0" for="editStreet">Street</label>
                                    <input type="text" class="form-control form-control-sm" id="editStreet">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label text-body-secondary mb-0" for="editCity">City</label>
                                    <input type="text" class="form-control form-control-sm" id="editCity">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label text-body-secondary mb-0" for="editState"><?php echo e(t('form.state', 'State')); ?></label>
                                    <input type="text" class="form-control form-control-sm" id="editState" maxlength="4">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label text-body-secondary mb-0" for="editAddressAbout">Cross St / Area</label>
                                    <input type="text" class="form-control form-control-sm" id="editAddressAbout"
                                           placeholder="Area / About / Cross St">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label text-body-secondary mb-0" for="editToAddress">Destination Address</label>
                                    <input type="text" class="form-control form-control-sm" id="editToAddress"
                                           placeholder="Transport destination (if applicable)">
                                </div>
                                <!-- 2026-06-28 (Eric beta: SAR use case) — two ways to
                                     update the incident's lat/lng while in edit mode:
                                       1. Lookup button: forward-geocode the typed
                                          street/city/state via Nominatim, drop marker.
                                       2. Click on the map: popup asks "Update Location?",
                                          confirmed click sets marker + lat/lng + reverse-
                                          geocodes any blank address fields. Map clicks
                                          are inert when not in edit mode. -->
                                <div class="col-12 mt-1">
                                    <div class="d-flex align-items-center gap-2 flex-wrap">
                                        <button type="button" class="btn btn-sm btn-outline-primary" id="btnLookupAddress"
                                                title="Geocode the street/city/state above and drop a pin">
                                            <i class="bi bi-search me-1"></i>Lookup
                                        </button>
                                        <span class="small text-body-secondary">
                                            <i class="bi bi-info-circle me-1"></i>or click anywhere on the map to drop a pin
                                        </span>
                                        <span class="small text-body-secondary ms-auto">
                                            <span class="text-body-secondary me-1">Lat:</span>
                                            <input type="number" step="any" class="form-control form-control-sm d-inline-block" id="editLat"
                                                   style="width:120px; display:inline-block;" placeholder="—" aria-label="Latitude">
                                            <span class="text-body-secondary mx-1">Lng:</span>
                                            <input type="number" step="any" class="form-control form-control-sm d-inline-block" id="editLng"
                                                   style="width:140px; display:inline-block;" placeholder="—" aria-label="Longitude">
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <div class="d-flex gap-1 mt-2">
                                <button type="button" class="btn btn-sm btn-primary edit-save-btn" data-section="location">
                                    <i class="bi bi-check-lg me-1"></i>Save
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary edit-cancel-btn" data-section="location">Cancel</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Contact -->
            <div class="card mb-3">
                <div class="card-header d-flex align-items-center py-1 form-section"
                     data-bs-target="#collapseContact" role="button" tabindex="0" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();this.click();}">
                    <i class="bi bi-person me-2"></i>
                    <span class="fw-semibold small"><?php echo e(t('incdetail.section.contact', 'Caller / Contact')); ?></span>
                    <button type="button" class="btn btn-link btn-sm p-0 ms-2 edit-section-btn d-none"
                            data-section="contact" title="Edit contact">
                        <i class="bi bi-pencil" style="font-size: 0.7rem;"></i>
                    </button>
                    <i class="bi bi-chevron-down ms-auto collapse-icon"></i>
                </div>
                <div class="collapse" id="collapseContact">
                    <div class="card-body py-2 small">
                        <!-- Display mode -->
                        <div id="contactDisplay">
                            <div class="row g-2">
                                <div class="col-md-4">
                                    <div class="text-body-secondary">Reported By</div>
                                    <div id="contactName">—</div>
                                </div>
                                <div class="col-md-4">
                                    <div class="text-body-secondary">Phone</div>
                                    <div id="contactPhone">—</div>
                                </div>
                                <div class="col-md-4">
                                    <div class="text-body-secondary">911 Notes</div>
                                    <div id="contact911">—</div>
                                </div>
                            </div>
                        </div>
                        <!-- Edit mode (hidden) -->
                        <div id="contactEdit" class="d-none">
                            <div class="row g-2">
                                <div class="col-md-4">
                                    <label class="form-label text-body-secondary mb-0" for="editContact">Reported By</label>
                                    <input type="text" class="form-control form-control-sm" id="editContact">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label text-body-secondary mb-0" for="editPhone">Phone</label>
                                    <input type="text" class="form-control form-control-sm" id="editPhone">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label text-body-secondary mb-0" for="editNineOneOne">911 Notes</label>
                                    <input type="text" class="form-control form-control-sm" id="editNineOneOne">
                                </div>
                            </div>
                            <div class="d-flex gap-1 mt-2">
                                <button type="button" class="btn btn-sm btn-primary edit-save-btn" data-section="contact">
                                    <i class="bi bi-check-lg me-1"></i>Save
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary edit-cancel-btn" data-section="contact">Cancel</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Facilities. 2026-06-26 — added edit mode (a beta tester
                 beta report: facilities couldn't be set/changed after
                 incident creation). Card always visible so the edit pencil
                 is reachable even when no facility is attached. -->
            <div class="card mb-3" id="facilitiesCard">
                <div class="card-header d-flex align-items-center py-1 form-section"
                     data-bs-target="#collapseFac" role="button" tabindex="0" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();this.click();}">
                    <i class="bi bi-hospital me-2"></i>
                    <span class="fw-semibold small"><?php echo e(t('incdetail.section.facilities', 'Facilities')); ?></span>
                    <button type="button" class="btn btn-link btn-sm p-0 ms-2 edit-section-btn d-none"
                            data-section="facilities" title="Edit facilities">
                        <i class="bi bi-pencil" style="font-size: 0.7rem;"></i>
                    </button>
                    <i class="bi bi-chevron-down ms-auto collapse-icon"></i>
                </div>
                <div class="collapse" id="collapseFac">
                    <div class="card-body py-2 small">
                        <!-- Display mode -->
                        <div id="facDisplay">
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <div class="text-body-secondary">Incident Facility</div>
                                    <div id="facIncident">—</div>
                                </div>
                                <div class="col-md-6">
                                    <div class="text-body-secondary">Receiving Facility</div>
                                    <div id="facReceiving">—</div>
                                </div>
                            </div>
                        </div>
                        <!-- Edit mode (hidden) -->
                        <div id="facEdit" class="d-none">
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <label class="form-label text-body-secondary mb-0" for="editFacility">Incident at Facility</label>
                                    <select class="form-select form-select-sm" id="editFacility">
                                        <option value="0">— None —</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label text-body-secondary mb-0" for="editRecFacility">Receiving Facility</label>
                                    <select class="form-select form-select-sm" id="editRecFacility">
                                        <option value="0">— None —</option>
                                    </select>
                                </div>
                            </div>
                            <div class="d-flex gap-1 mt-2">
                                <button type="button" class="btn btn-sm btn-primary edit-save-btn" data-section="facilities">
                                    <i class="bi bi-check-lg me-1"></i>Save
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary edit-cancel-btn" data-section="facilities">Cancel</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Time & Status -->
            <div class="card mb-3">
                <div class="card-header d-flex align-items-center py-1 form-section"
                     data-bs-target="#collapseTime" role="button" tabindex="0" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();this.click();}">
                    <i class="bi bi-clock me-2"></i>
                    <span class="fw-semibold small"><?php echo e(t('incdetail.section.time_status', 'Time & Status')); ?></span>
                    <i class="bi bi-chevron-down ms-auto collapse-icon"></i>
                </div>
                <div class="collapse" id="collapseTime">
                    <div class="card-body py-2 small">
                        <div class="row g-2">
                            <div class="col-md-3">
                                <div class="text-body-secondary">Status</div>
                                <div id="timeStatus">—</div>
                            </div>
                            <div class="col-md-3">
                                <div class="text-body-secondary">Problem Start</div>
                                <div id="timeProbStart">—</div>
                            </div>
                            <div class="col-md-3">
                                <div class="text-body-secondary">Problem End</div>
                                <div id="timeProbEnd">—</div>
                            </div>
                            <div class="col-md-3 d-none" id="timeBookedCol">
                                <div class="text-body-secondary">Scheduled</div>
                                <div id="timeBooked">—</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Call History. 2026-06-26 — added to edit flow so a dispatcher
                 reviewing an incident can re-query previous calls against the
                 CURRENT phone/address (not the originals) — same UX as the
                 new-incident search panel. -->
            <div class="card mb-3" id="callHistoryCard">
                <div class="card-header d-flex align-items-center py-1 form-section"
                     data-bs-target="#collapseCallHistory"
                     role="button" tabindex="0"
                     onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();this.click();}">
                    <i class="bi bi-telephone me-2"></i>
                    <span class="fw-semibold small">Call History</span>
                    <span class="badge bg-secondary ms-auto" id="callHistoryCount">0</span>
                    <i class="bi bi-chevron-down ms-2 collapse-icon"></i>
                </div>
                <div class="collapse" id="collapseCallHistory">
                    <div class="card-body py-2 small">
                        <div class="form-text mb-2">Previous calls from this incident's phone number or address.</div>
                        <div id="callHistoryResults" class="call-history-list">
                            <span class="text-body-secondary small">Click Search to look up prior calls for this incident.</span>
                        </div>
                        <button class="btn btn-sm btn-outline-secondary mt-2" type="button" id="btnSearchHistory">
                            <i class="bi bi-search me-1"></i>Search History
                        </button>
                    </div>
                </div>
            </div>

            <!-- Patients. 2026-06-26 — added to edit flow so a dispatcher can
                 add / update / remove patients after the incident has been
                 created. Persists to the `patient` table via api/patients.php.
                 NOTE: new-incident.php collects patient fields in the form but
                 api/incident-create.php currently does NOT persist them — that
                 is a separate follow-up bug. -->
            <div class="card mb-3" id="patientsCard">
                <div class="card-header d-flex align-items-center py-1 form-section"
                     data-bs-target="#collapsePatients"
                     role="button" tabindex="0"
                     onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();this.click();}">
                    <i class="bi bi-heart-pulse me-2 text-danger"></i>
                    <span class="fw-semibold small">Patients</span>
                    <span class="badge bg-secondary ms-auto" id="patientCount">0</span>
                    <i class="bi bi-chevron-down ms-2 collapse-icon"></i>
                </div>
                <div class="collapse" id="collapsePatients">
                    <div class="card-body py-2 small">
                        <div id="patientList">
                            <div class="text-body-secondary small">Loading patients…</div>
                        </div>
                        <button class="btn btn-sm btn-outline-success mt-2" type="button" id="btnAddPatient">
                            <i class="bi bi-plus-lg me-1"></i>Add Patient
                        </button>
                    </div>
                </div>
            </div>

            <!-- Additional Details -->
            <div class="card mb-3" id="additionalCard">
                <div class="card-header d-flex align-items-center py-1 form-section"
                     data-bs-target="#collapseAdditional" role="button" tabindex="0" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();this.click();}">
                    <i class="bi bi-journal-text me-2"></i>
                    <span class="fw-semibold small"><?php echo e(t('incdetail.section.additional', 'Additional Details')); ?></span>
                    <button type="button" class="btn btn-link btn-sm p-0 ms-2 edit-section-btn d-none"
                            data-section="additional" title="Edit details">
                        <i class="bi bi-pencil" style="font-size: 0.7rem;"></i>
                    </button>
                    <i class="bi bi-chevron-down ms-auto collapse-icon"></i>
                </div>
                <div class="collapse" id="collapseAdditional">
                    <div class="card-body py-2 small">
                        <!-- Display mode -->
                        <div id="additionalDisplay">
                            <div id="additionalComments" style="white-space: pre-wrap;">—</div>
                        </div>
                        <!-- Edit mode (hidden) -->
                        <div id="additionalEdit" class="d-none">
                            <div class="mb-2">
                                <?php /* GH TicketsCAD#16: this was the one label on this page
                                        with no t() wrapper, while 110 sibling incdetail.*
                                        captions are seeded and translated. On a non-English
                                        install it rendered in English and no admin could
                                        rename it through the caption system — which v3 could,
                                        its changelog recording "10/15/08 changed 'Comments' to
                                        'Disposition'". Same field, same wording as
                                        new-incident.php's newinc.label.comments. */ ?>
                                <label class="form-label text-body-secondary mb-0" for="editComments"><?php echo e(t('incdetail.label.comments', 'Disposition / Comments')); ?></label>
                                <textarea class="form-control form-control-sm" id="editComments" rows="4"></textarea>
                            </div>
                            <div class="d-flex gap-1">
                                <button type="button" class="btn btn-sm btn-primary edit-save-btn" data-section="additional">
                                    <i class="bi bi-check-lg me-1"></i>Save
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary edit-cancel-btn" data-section="additional">Cancel</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>

        <!-- ═══════════ RIGHT COLUMN: Map + Responders + Log ═══════════ -->
        <div class="col-lg-5 col-xl-6">
            <div class="sticky-top" style="top: 100px;">

                <!-- Protocol Display -->
                <div class="card mb-3 border-info d-none" id="protocolPanel">
                    <div class="card-header d-flex align-items-center py-1 bg-info bg-opacity-10">
                        <i class="bi bi-journal-medical me-2 text-info"></i>
                        <span class="fw-semibold small"><?php echo e(t('incdetail.section.protocol', 'Response Protocol')); ?></span>
                    </div>
                    <div class="card-body py-2 px-3 small" id="protocolContent"></div>
                </div>

                <!-- Map -->
                <div class="card mb-3">
                    <div class="card-header d-flex align-items-center py-1">
                        <i class="bi bi-map me-2"></i>
                        <span class="fw-semibold small"><?php echo e(t('incdetail.section.map', 'Location Map')); ?></span>
                    </div>
                    <div class="card-body p-0">
                        <div id="detailMap" style="height: 300px;"></div>
                    </div>
                </div>

                <!-- PAR (Personnel Accountability) — Phase 16a/d/e, 2026-06-11.
                     Hidden by default; revealed when api/par.php reports
                     PAR is enabled. -->
                <div class="card mb-3 d-none" id="parCard" data-par-enabled="0">
                    <div class="card-header d-flex align-items-center py-1 flex-wrap gap-1">
                        <i class="bi bi-shield-check me-2 text-primary"></i>
                        <span class="fw-semibold small">PAR</span>
                        <span class="badge bg-secondary ms-2 small" id="parStatusBadge">—</span>
                        <div class="ms-auto d-flex gap-1">
                            <button type="button" class="btn btn-sm btn-danger"
                                    id="btnMayday" title="Declare Mayday — initiates PAR with urgent escalation">
                                <i class="bi bi-exclamation-octagon me-1"></i>Mayday
                            </button>
                            <!-- Phase 27 hotfix (2026-06-12): cancel button for
                                 the active cycle. Visible only when a cycle is
                                 pending. Useful when a stale cycle (e.g. one
                                 created before the bug fix that captured 0 units)
                                 needs to be cleared so a fresh one can run. -->
                            <button type="button" class="btn btn-sm btn-outline-secondary d-none"
                                    id="btnCancelParCycle" title="Cancel the active PAR cycle">
                                <i class="bi bi-x-lg me-1"></i>Cancel
                            </button>
                            <button type="button" class="btn btn-sm btn-warning"
                                    id="btnInitiatePAR" title="Initiate a Personnel Accountability Report (Manual)">
                                <i class="bi bi-megaphone me-1"></i>Initiate PAR
                            </button>
                        </div>
                    </div>
                    <div class="card-body py-2 px-3 small">
                        <div class="row g-2 mb-2 text-body-secondary align-items-center">
                            <div class="col-4">Last PAR: <span id="parLastTime">—</span></div>
                            <div class="col-4 text-end">Next due: <span id="parNextDue">—</span></div>
                            <div class="col-4 d-flex align-items-center justify-content-end gap-1">
                                <label for="parOverrideMin" class="small mb-0">Cadence:</label>
                                <input type="number" min="0" max="120" id="parOverrideMin"
                                       class="form-control form-control-sm" style="width:60px;"
                                       placeholder="—" title="Override the configured cadence for THIS incident only (minutes). Blank = use default.">
                                <button type="button" class="btn btn-xs btn-outline-secondary"
                                        id="btnSaveParOverride" title="Save override">
                                    <i class="bi bi-check"></i>
                                </button>
                            </div>
                        </div>
                        <div id="parAckTable">
                            <div class="text-body-secondary small">No active cycle.</div>
                        </div>
                        <details class="mt-2">
                            <summary class="text-body-secondary small" style="cursor:pointer;">
                                <i class="bi bi-clock-history me-1"></i>PAR history (this incident)
                            </summary>
                            <div id="parHistory" class="mt-2"></div>
                        </details>
                    </div>
                </div>

                <!-- Assigned Responders -->
                <div class="card mb-3">
                    <div class="card-header d-flex align-items-center py-1">
                        <i class="bi bi-people me-2"></i>
                        <span class="fw-semibold small"><?php echo e(t('incdetail.section.assigned', 'Assigned Responders')); ?></span>
                        <span class="badge bg-primary ms-auto" id="assignedCount">0</span>
                    </div>
                    <div class="card-body p-0">
                        <!-- Assign responder control -->
                        <div class="border-bottom px-2 py-2" id="assignControl">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text"><i class="bi bi-search"></i></span>
                                <input type="text" class="form-control form-control-sm"
                                       id="responderSearch" placeholder="Search responders..."
                                       autocomplete="off" aria-label="Search responders">
                                <button type="button" class="btn btn-sm btn-primary" id="btnAssignResponder" disabled>
                                    <i class="bi bi-plus-lg me-1"></i>Assign
                                </button>
                            </div>
                            <div class="position-relative">
                                <div class="list-group list-group-flush small d-none" id="responderDropdown"
                                     style="position: absolute; z-index: 10; width: 100%; max-height: 200px; overflow-y: auto;
                                            background: var(--bs-body-bg); border: 1px solid var(--bs-border-color);
                                            border-radius: 0 0 0.375rem 0.375rem; box-shadow: 0 4px 8px rgba(0,0,0,0.15);">
                                </div>
                            </div>
                        </div>
                        <!-- Available Responders (quick-pick list) -->
                        <div class="border-bottom" id="availableRespondersPanel">
                            <button class="btn btn-sm w-100 text-start text-body-secondary py-1 px-2 d-flex align-items-center"
                                    type="button" data-bs-toggle="collapse" data-bs-target="#availableRespondersList"
                                    aria-expanded="false" aria-controls="availableRespondersList">
                                <i class="bi bi-chevron-right collapse-icon me-1" style="font-size: 0.65rem; transition: transform 0.2s;"></i>
                                <small>Available Units</small>
                                <span class="badge bg-success ms-auto" id="availableCount" style="font-size: 0.6rem;">0</span>
                            </button>
                            <div class="collapse" id="availableRespondersList">
                                <!-- 2026-06-28 (Eric beta) — rebuilt as a proper sortable
                                     table. Each <th> is clickable to switch sort key;
                                     click again to flip direction. Default sort = distance
                                     ASC. Sticky header so it stays visible while scrolling
                                     the unit list. The tbody#availableRespondersTbody is
                                     the JS render target — unique ID so the selector
                                     doesn't collide with other .px-2 wrappers. -->
                                <div style="max-height: 200px; overflow-y: auto;">
                                    <table class="table table-sm table-hover mb-0 small">
                                        <thead style="position:sticky; top:0; background:var(--bs-tertiary-bg); z-index:1;">
                                            <tr>
                                                <th class="avail-sort-hdr" data-sort="name"
                                                    style="cursor:pointer; user-select:none; font-size:0.7rem;"
                                                    title="Sort by unit name">
                                                    UNIT <span class="avail-sort-arrow"></span>
                                                </th>
                                                <th class="avail-sort-hdr text-end" data-sort="distance"
                                                    style="cursor:pointer; user-select:none; font-size:0.7rem; width:70px;"
                                                    title="Sort by distance from incident">
                                                    DIST <span class="avail-sort-arrow">&#9650;</span>
                                                </th>
                                                <th class="avail-sort-hdr text-end" data-sort="status"
                                                    style="cursor:pointer; user-select:none; font-size:0.7rem; width:90px;"
                                                    title="Sort by current status">
                                                    STATUS <span class="avail-sort-arrow"></span>
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody id="availableRespondersTbody">
                                            <tr><td colspan="3" class="text-center text-body-secondary py-2 small" id="availableRespondersLoading">Loading...</td></tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <div id="assignmentsList">
                            <div class="text-center text-body-secondary py-3 small">Loading...</div>
                        </div>
                    </div>
                </div>

                <!-- Major Incident link (2026-06 — proper dispatcher entry
                     point: attach THIS incident to a parent major incident,
                     or spin up a new major and link in one step. Distinct
                     from new-incident.php?major=1 which is the separate
                     parent-ticket creation flow. Gated on action.link_major. -->
<?php if ($canManageMajor): ?>
                <div class="card mb-3" id="majorLinkCard">
                    <div class="card-header d-flex align-items-center py-1">
                        <i class="bi bi-diagram-3 me-2"></i>
                        <span class="fw-semibold small"><?php echo e(t('incdetail.section.major', 'Major Incident')); ?></span>
                        <span class="badge bg-secondary ms-auto d-none" id="majorLinkBadge"></span>
                    </div>
                    <div class="card-body p-2">
                        <!-- Current link (shown when this incident is already linked) -->
                        <div id="majorCurrentLink" class="d-none small mb-2"></div>

                        <!-- Link control (shown when not yet linked) -->
                        <div id="majorLinkControls">
                            <label for="majorLinkSelect" class="form-label form-label-sm mb-1">
                                <?php echo e(t('incdetail.major.link_label', 'Link to an open major incident')); ?>
                            </label>
                            <div class="input-group input-group-sm">
                                <select class="form-select form-select-sm" id="majorLinkSelect">
                                    <option value=""><?php echo e(t('incdetail.major.select_ph', '— Select a major incident —')); ?></option>
                                    <option value="__new__"><?php echo e(t('incdetail.major.create_new', '+ Create new major incident…')); ?></option>
                                </select>
                                <button type="button" class="btn btn-sm btn-danger" id="btnLinkMajor" disabled>
                                    <i class="bi bi-link-45deg me-1"></i><?php echo e(t('incdetail.major.btn_link', 'Link')); ?>
                                </button>
                            </div>
                            <!-- New-major inline fields (hidden until "+ Create new" picked) -->
                            <div id="majorNewWrap" class="d-none mt-2">
                                <input type="text" class="form-control form-control-sm mb-1" id="majorNewName"
                                       placeholder="<?php echo e(t('incdetail.major.new_name_ph', 'New major incident name')); ?>" maxlength="255"
                                       aria-label="<?php echo e(t('incdetail.major.new_name_ph', 'New major incident name')); ?>">
                                <select class="form-select form-select-sm" id="majorNewSeverity" aria-label="Major incident severity">
                                    <option value="0"><?php echo e(t('major.sev.0', 'Minor')); ?></option>
                                    <option value="1" selected><?php echo e(t('major.sev.1', 'Major')); ?></option>
                                    <option value="2"><?php echo e(t('major.sev.2', 'Critical')); ?></option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
<?php endif; ?>

                <!-- Activity Log -->
                <div class="card mb-3">
                    <div class="card-header d-flex align-items-center py-1">
                        <i class="bi bi-list-ul me-2"></i>
                        <span class="fw-semibold small"><?php echo e(t('incdetail.section.activity', 'Activity Log')); ?></span>
                        <span class="badge bg-secondary ms-auto" id="actionsCount">0</span>
                    </div>
                    <div class="card-body p-0">
                        <!-- Add note form (Eric 2026-07-03) — moved
                             ABOVE the log so a note lands right next to
                             where the operator is typing. Activity log
                             renders newest-first, so form-on-top keeps
                             the new entry adjacent to the input. -->
                        <div class="border-bottom px-2 py-2" id="addNoteForm">
                            <!-- Phase 132 Step 4 (GH #16) — disposition picker,
                                 beside the comments/note box. Settable ANY TIME
                                 (spec §Resolved Q2), independent of the
                                 close-action dropdown above — writes immediately
                                 via api/incident-update.php's set_disposition
                                 action on change. Options filtered to the
                                 incident's type discipline server-side
                                 (api/dispositions-picker.php); HARD INVARIANT:
                                 never truncated to empty (plan.md §1). -->
                            <div class="input-group input-group-sm mb-2" id="dispositionRow">
                                <label class="input-group-text py-1" for="dispositionSelect">
                                    <i class="bi bi-clipboard-check me-1"></i><?php echo e(t('incdetail.label.disposition', 'Disposition')); ?>
                                </label>
                                <select class="form-select form-select-sm" id="dispositionSelect">
                                    <option value=""><?php echo e(t('incdetail.disposition.none', '— None —')); ?></option>
                                </select>
                            </div>
                            <div class="input-group input-group-sm">
                                <textarea class="form-control form-control-sm" id="noteText"
                                          rows="1" placeholder="Add a note... (Enter to send)"
                                          style="resize: vertical; min-height: 31px;" aria-label="Add a note to the activity log"></textarea>
                                <button type="button" class="btn btn-sm btn-primary" id="btnAddNote" disabled>
                                    <i class="bi bi-send"></i>
                                </button>
                            </div>
                        </div>
                        <div id="actionsList">
                            <div class="text-center text-body-secondary py-3 small">Loading...</div>
                        </div>
                    </div>
                </div>

            </div>
        </div>

    </div>
</div>

<!-- Phase 142 (GH#70 Phase 2) — manual cross-org sharing modal. Opened by
     #btnShareIncident; incident-detail.js populates it on open via
     GET api/incident-share.php?ticket_id=N. All server-authored text
     (org names, share_reason, error messages) set via .textContent, never
     .innerHTML -- same hard rule as #secLabelBadge/#sharedFromBadge. -->
<div class="modal fade" id="shareIncidentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title"><i class="bi bi-share me-2"></i>Share this incident</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body small">
                <div class="alert alert-danger d-none" id="shareModalError"></div>

                <h6 class="text-body-secondary mb-2">Currently shared with</h6>
                <div class="table-responsive mb-3">
                    <table class="table table-sm mb-0">
                        <thead>
                            <tr>
                                <th>Organization</th>
                                <th>Tier</th>
                                <th>Source</th>
                                <th>Reason</th>
                                <th>Shared</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="shareModalRows">
                            <tr><td colspan="6" class="text-center text-body-secondary py-3">Loading&hellip;</td></tr>
                        </tbody>
                    </table>
                </div>

                <div id="shareModalAddForm" class="d-none border-top pt-3">
                    <h6 class="text-body-secondary mb-2">Share with another organization</h6>
                    <div class="row g-2 align-items-end">
                        <div class="col-md-4">
                            <label class="form-label mb-0" for="shareModalTargetOrg">Organization</label>
                            <select class="form-select form-select-sm" id="shareModalTargetOrg">
                                <option value="">Select an organization&hellip;</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label mb-0 d-block">Access tier</label>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="shareModalTier" id="shareModalTierView" value="view" checked>
                                <label class="form-check-label" for="shareModalTierView">View</label>
                            </div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="shareModalTier" id="shareModalTierAssist" value="assist">
                                <label class="form-check-label" for="shareModalTierAssist">Assist</label>
                            </div>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label mb-0" for="shareModalReason">Reason</label>
                            <textarea class="form-control form-control-sm" id="shareModalReason" maxlength="255" rows="1"
                                      placeholder="e.g. mutual aid requested by IC"></textarea>
                        </div>
                    </div>
                    <div class="mt-2">
                        <button type="button" class="btn btn-sm btn-primary" id="btnShareModalSubmit">
                            <i class="bi bi-share me-1"></i>Share
                        </button>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- CSRF token for JS -->
<input type="hidden" id="csrfToken" value="<?php echo e($csrf); ?>">

<!-- Print date helper -->
<script>window.addEventListener('beforeprint', function () { document.body.setAttribute('data-print-date', new Date().toLocaleDateString() + ' ' + new Date().toLocaleTimeString()); });</script>

<!-- Vendor JS -->
<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="assets/js/toolbar.js?v=<?php echo asset_v('assets/js/toolbar.js'); ?>"></script>
<script src="assets/vendor/leaflet/leaflet.js"></script>
<script src="assets/js/leaflet-mobile-fit.js?v=<?php echo function_exists("asset_v")?asset_v("assets/js/leaflet-mobile-fit.js"):newui_version(); ?>"></script>
<script src="assets/js/leaflet-quadkey.js"></script>
<script src="assets/js/map-prefs.js?v=<?php echo asset_v('assets/js/map-prefs.js'); ?>"></script>
<script src="assets/js/map-image-overlays.js?v=<?php echo function_exists('asset_v') ? asset_v('assets/js/map-image-overlays.js') : newui_version(); ?>"></script>

<!-- App JS -->
<script src="assets/js/theme-manager.js?v=<?php echo asset_v('assets/js/theme-manager.js'); ?>"></script>
<!-- Phase 71 — navigation launcher (Apple Maps / Google Maps / Waze / web) -->
<script src="assets/js/navigate-launcher.js?v=<?php echo asset_v('assets/js/navigate-launcher.js'); ?>"></script>
<script src="assets/js/dob-helper.js?v=<?php echo asset_v('assets/js/dob-helper.js'); ?>"></script>
<script>
// Phase 99o (Eric beta 2026-06-29) — admin-configurable label
// for the rendered case number ("Incident" / "Case" / "Call" / ...)
window.INCIDENT_NUMBER_LABEL = <?php echo json_encode($incNumLabel); ?>;
// Phase 132 Step 4 (GH #16) — disposition_required_on_close, read
// server-side via get_variable() (CLAUDE.md "TWO settings stores" —
// NEVER get_setting(), GH #79) so the close-flow JS knows whether to
// block a close with no disposition BEFORE round-tripping to the API.
// The API's own refusal (inc/incident-write.php) is still the real
// enforcement; this is a courtesy client-side check only.
window.DISPOSITION_REQUIRED_ON_CLOSE = <?php echo json_encode(get_variable('disposition_required_on_close') === '1'); ?>;
window.DISPOSITION_REQUIRED_MESSAGE = <?php echo json_encode(t('incdetail.disposition.required_error', 'A disposition is required before closing this incident.')); ?>;
// applyDispositionOptions() rebuilds both <select> option lists from
// scratch (so re-opening after a change stays correct), which would
// otherwise lose the server-rendered translated "None" option text —
// thread it through the same way INCIDENT_NUMBER_LABEL is threaded above.
window.INCDETAIL_DISPOSITION_NONE = <?php echo json_encode(t('incdetail.disposition.none', '— None —')); ?>;
</script>
<?php /* GH#52 follow-up — the facility/note/numeric extra-data prompt,
         must load BEFORE incident-detail.js so window.TCADStatusExtraDataPrompt
         exists the first time a status change needs it. */ ?>
<script src="assets/js/status-extra-data-prompt.js?v=<?php echo asset_v('assets/js/status-extra-data-prompt.js'); ?>"></script>
<script src="assets/js/incident-detail.js?v=<?php echo asset_v('assets/js/incident-detail.js'); ?>"></script>
<?php /* Phase 131 — prefills the activity-log box when the operator arrived
         here from a net check-in (?net_entry=N). No-op otherwise. */ ?>
<script src="assets/js/net-prefill.js?v=<?php echo asset_v('assets/js/net-prefill.js'); ?>"></script>

</body>
</html>
