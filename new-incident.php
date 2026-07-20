<?php
/**
 * NewUI v4.0 - New Incident
 *
 * Create a new ticket/incident. Two-column layout:
 *   Left:  Form fields grouped into collapsible sections
 *   Right: Leaflet map for location picking + unit assignment panel
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/inc/field-encrypt.php';
require_once __DIR__ . '/inc/security.php';
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
rbac_require_screen('screen.new_incident');
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
    <title><?php echo e(t('newinc.title', 'New Incident')); ?> — <?php echo e(t('login.title', 'Tickets NewUI')); ?> <?php echo NEWUI_VERSION; ?></title>

    <!-- Vendor CSS -->
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/vendor/leaflet/leaflet.css">

    <!-- App CSS -->
    <link rel="stylesheet" href="assets/css/dashboard.css">
    <link rel="stylesheet" href="assets/css/new-incident.css?v=<?php echo asset_v('assets/css/new-incident.css'); ?>">
</head>
<body>

<?php include_once NEWUI_ROOT . '/inc/navbar.php'; ?>
</header>

<!-- Page Content -->
<div class="container-fluid p-3">

    <!-- Page title + action buttons -->
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h5 class="mb-0">
            <i class="bi bi-plus-circle text-primary me-2"></i><?php echo e(t('newinc.title', 'New Incident')); ?>
        </h5>
        <div class="d-flex gap-2">
            <a href="index.php" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-x-lg me-1"></i><?php echo e(t('newinc.btn.cancel', 'Cancel')); ?>
            </a>
            <button type="button" class="btn btn-sm btn-outline-warning" id="btnReset">
                <i class="bi bi-arrow-counterclockwise me-1"></i><?php echo e(t('newinc.btn.reset', 'Reset')); ?>
            </button>
            <button type="button" class="btn btn-sm btn-success" id="btnSubmit">
                <i class="bi bi-check-lg me-1"></i><?php echo e(t('newinc.btn.submit', 'Submit Incident')); ?>
            </button>
        </div>
    </div>

    <!-- Alert area for success/error messages -->
    <div id="alertArea"></div>
    <?php echo https_warning_banner(); ?>

    <div class="row g-3">

        <!-- ═══════════ LEFT COLUMN: Form ═══════════ -->
        <div class="col-lg-7 col-xl-6">
            <form id="incidentForm" novalidate data-encrypt="true">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">

                <!-- ── Section 1: Classification ── -->
                <div class="card mb-3 form-section">
                    <div class="card-header d-flex align-items-center" data-bs-toggle="collapse" data-bs-target="#secClassification" role="button">
                        <i class="bi bi-tag me-2 text-primary"></i>
                        <span class="fw-semibold"><?php echo e(t('newinc.section.classification', 'Classification')); ?></span>
                        <span class="badge bg-danger ms-auto required-badge"><?php echo e(t('newinc.required_badge', 'Required')); ?></span>
                        <i class="bi bi-chevron-down ms-2 collapse-icon"></i>
                    </div>
                    <div class="collapse show" id="secClassification">
                        <div class="card-body">
                            <div class="row g-2">
                                <div class="col-md-8">
                                    <label for="in_types_id" class="form-label"><?php echo e(t('newinc.label.incident_type', 'Incident Type')); ?> <span class="text-danger">*</span></label>
                                    <select class="form-select form-select-sm" id="in_types_id" name="in_types_id" required tabindex="1">
                                        <option value=""><?php echo e(t('newinc.option_select_type', '— Select type —')); ?></option>
                                    </select>
                                    <div class="form-text" id="protocolText"></div>
                                </div>
                                <div class="col-md-4">
                                    <label for="severity" class="form-label"><?php echo e(t('newinc.label.severity', 'Severity')); ?></label>
                                    <select class="form-select form-select-sm" id="severity" name="severity" tabindex="2">
                                        <option value="0"><?php echo e(t('newinc.severity.normal', 'Normal')); ?></option>
                                        <option value="1"><?php echo e(t('newinc.severity.elevated', 'Elevated')); ?></option>
                                        <option value="2"><?php echo e(t('newinc.severity.critical', 'Critical')); ?></option>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <label for="scope" class="form-label"><?php echo e(t('newinc.label.scope', 'Incident Name / Scope')); ?> <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control form-control-sm" id="scope" name="scope" required
                                           tabindex="3" placeholder="<?php echo e(t('newinc.placeholder.scope', 'Brief summary of the incident')); ?>" maxlength="255">
                                </div>
                                <div class="col-12">
                                    <!--
                                      Description is the primary entry point: the page auto-focuses
                                      here on load. Operator types the call narrative. On blur, the
                                      regex auto-match in new-incident.js walks the incident type
                                      list looking for a match_pattern hit; first match populates
                                      Incident Type, which triggers Severity auto-fill and Protocol
                                      panel. Tab order around Description: Shift+Tab → Scope →
                                      Severity → Type; Tab → Street → Lookup → City → State →
                                      Contact → Phone → Responder search.
                                    -->
                                    <label for="description" class="form-label"><?php echo e(t('newinc.label.description', 'Description')); ?> <span class="text-danger">*</span></label>
                                    <textarea class="form-control form-control-sm" id="description" name="description" rows="3" required
                                              tabindex="4" autofocus
                                              placeholder="<?php echo e(t('newinc.placeholder.description', 'Caller\'s report — type the call narrative; Incident Type and Severity will auto-fill on Tab if a pattern matches')); ?>"></textarea>
                                </div>
                                <div class="col-md-6">
                                    <label for="signal" class="form-label"><?php echo e(t('newinc.label.signal', 'Signal')); ?></label>
                                    <select class="form-select form-select-sm" id="signal" name="signal" tabindex="-1">
                                        <option value=""><?php echo e(t('newinc.option_none', '— None —')); ?></option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label for="major_incident" class="form-label"><?php echo e(t('newinc.label.major_incident', 'Major Incident')); ?></label>
                                    <div class="input-group input-group-sm">
                                        <select class="form-select" id="major_incident" name="major_incident" tabindex="-1">
                                            <option value=""><?php echo e(t('newinc.option_none', '— None —')); ?></option>
                                        </select>
                                        <button class="btn btn-outline-primary" type="button" id="btnNewMajor" title="<?php echo e(t('newinc.label.major_incident', 'Major Incident')); ?>" tabindex="-1">
                                            <i class="bi bi-plus-lg"></i> <?php echo e(t('newinc.btn.new_major', 'New')); ?>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── Section 2: Location ── -->
                <div class="card mb-3 form-section">
                    <div class="card-header d-flex align-items-center" data-bs-toggle="collapse" data-bs-target="#secLocation" role="button">
                        <i class="bi bi-geo-alt me-2 text-success"></i>
                        <span class="fw-semibold"><?php echo e(t('newinc.section.location', 'Location')); ?></span>
                        <i class="bi bi-chevron-down ms-2 ms-auto collapse-icon"></i>
                    </div>
                    <div class="collapse show" id="secLocation">
                        <div class="card-body">
                            <div class="row g-2">
                                <div class="col-12 position-relative">
                                    <label for="street" class="form-label"><?php echo e(t('newinc.label.street', 'Street Address')); ?></label>
                                    <div class="input-group input-group-sm">
                                        <input type="text" class="form-control" id="street" name="street" tabindex="5" placeholder="123 Main St" autocomplete="off">
                                        <button class="btn btn-outline-secondary" type="button" id="btnGeocode" tabindex="6" title="<?php echo e(t('newinc.btn.lookup', 'Lookup')); ?>">
                                            <i class="bi bi-search"></i> <?php echo e(t('newinc.btn.lookup', 'Lookup')); ?>
                                        </button>
                                    </div>
                                    <!-- Phase 41-fix (2026-06-27, a beta tester): saved-places typeahead
                                         dropdown. Populated by JS from api/places.php?action=search as
                                         user types in #street. Clicking a result fills all address
                                         fields + sets the map marker. -->
                                    <div id="streetPlacesDropdown" class="list-group position-absolute shadow-sm d-none"
                                         style="top:100%;left:0;right:0;z-index:1050;max-height:240px;overflow-y:auto;"></div>
                                </div>
                                <div class="col-md-4">
                                    <label for="city" class="form-label"><?php echo e(t('form.city', 'City')); ?></label>
                                    <input type="text" class="form-control form-control-sm" id="city" name="city" tabindex="7">
                                </div>
                                <div class="col-md-3">
                                    <label for="state" class="form-label"><?php echo e(t('form.state', 'State')); ?></label>
                                    <select class="form-select form-select-sm" id="state" name="state" tabindex="8">
                                        <option value="">—</option>
                                    </select>
                                </div>
                                <div class="col-md-2 d-none" id="zipcodeGroup">
                                    <label for="zipcode" class="form-label"><?php echo e(t('form.zip', 'Zip')); ?></label>
                                    <input type="text" class="form-control form-control-sm" id="zipcode" name="zipcode"
                                           placeholder="55401" maxlength="10">
                                </div>
                                <div class="col-md-3">
                                    <label for="address_about" class="form-label"><?php echo e(t('newinc.label.area', 'Area / About / Cross St')); ?></label>
                                    <input type="text" class="form-control form-control-sm" id="address_about" name="address_about"
                                           placeholder="<?php echo e(t('newinc.label.area', 'Area / About / Cross St')); ?>">
                                </div>
                                <div class="col-12">
                                    <div class="form-check form-check-inline form-switch small">
                                        <input class="form-check-input" type="checkbox" id="chkShowZip" role="switch">
                                        <label class="form-check-label text-body-secondary" for="chkShowZip"><?php echo e(t('newinc.show_zip', 'Show Zip Code')); ?></label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label"><?php echo e(t('newinc.label.coordinates', 'Coordinates')); ?></label>
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text">Lat</span>
                                        <input type="text" class="form-control" id="lat" name="lat" placeholder="44.9778" readonly>
                                        <span class="input-group-text">Lng</span>
                                        <input type="text" class="form-control" id="lng" name="lng" placeholder="-93.2650" readonly>
                                        <button class="btn btn-outline-secondary" type="button" id="btnClearCoords" title="Clear coordinates">
                                            <i class="bi bi-x-lg"></i>
                                        </button>
                                    </div>
                                    <div class="form-text"><?php echo e(t('newinc.coords_hint', 'Click the map or use Lookup to set coordinates')); ?></div>
                                </div>
                                <div class="col-md-6">
                                    <label for="to_address" class="form-label"><?php echo e(t('newinc.label.destination', 'Destination Address')); ?></label>
                                    <input type="text" class="form-control form-control-sm" id="to_address" name="to_address"
                                           placeholder="<?php echo e(t('newinc.placeholder.destination', 'Transport destination (if applicable)')); ?>">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── Section 3: Contact / Caller ── -->
                <div class="card mb-3 form-section">
                    <div class="card-header d-flex align-items-center" data-bs-toggle="collapse" data-bs-target="#secContact" role="button">
                        <i class="bi bi-person me-2 text-info"></i>
                        <span class="fw-semibold"><?php echo e(t('newinc.section.contact', 'Caller / Contact')); ?></span>
                        <i class="bi bi-chevron-down ms-2 ms-auto collapse-icon"></i>
                    </div>
                    <div class="collapse show" id="secContact">
                        <div class="card-body">
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <label for="contact" class="form-label"><?php echo e(t('newinc.label.reported_by', 'Reported By')); ?></label>
                                    <input type="text" class="form-control form-control-sm" id="contact" name="contact"
                                           tabindex="10" placeholder="<?php echo e(t('newinc.placeholder.caller_name', 'Caller name')); ?>">
                                </div>
                                <div class="col-md-6 position-relative">
                                    <label for="phone" class="form-label"><?php echo e(t('newinc.label.phone', 'Phone Number')); ?></label>
                                    <input type="tel" class="form-control form-control-sm" id="phone" name="phone"
                                           tabindex="11" placeholder="(612) 555-1234" autocomplete="off">
                                    <!-- Constituent multi-match picker. Hidden by default; shown by JS
                                         when api/constituents.php?phone=X returns >1 match. -->
                                    <div id="constituentPicker" class="list-group position-absolute shadow-sm d-none"
                                         style="top:100%;left:0;right:0;z-index:1050;max-height:240px;overflow-y:auto;"></div>
                                </div>
                                <div class="col-md-6">
                                    <label for="nine_one_one" class="form-label"><?php echo e(t('newinc.label.911_notes', '911 Notes')); ?></label>
                                    <input type="text" class="form-control form-control-sm" id="nine_one_one" name="nine_one_one"
                                           placeholder="<?php echo e(t('newinc.label.911_notes', '911 Notes')); ?>">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── Section 4: Facilities ── -->
                <div class="card mb-3 form-section">
                    <div class="card-header d-flex align-items-center" data-bs-toggle="collapse" data-bs-target="#secFacilities" role="button">
                        <i class="bi bi-building me-2 text-warning"></i>
                        <span class="fw-semibold"><?php echo e(t('newinc.section.facilities', 'Facilities')); ?></span>
                        <i class="bi bi-chevron-down ms-2 ms-auto collapse-icon"></i>
                    </div>
                    <div class="collapse" id="secFacilities">
                        <div class="card-body">
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <label for="facility" class="form-label"><?php echo e(t('newinc.label.fac_at', 'Incident at Facility')); ?></label>
                                    <select class="form-select form-select-sm" id="facility" name="facility">
                                        <option value="0"><?php echo e(t('newinc.option_none', '— None —')); ?></option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label for="rec_facility" class="form-label"><?php echo e(t('newinc.label.fac_recv', 'Receiving Facility')); ?></label>
                                    <select class="form-select form-select-sm" id="rec_facility" name="rec_facility">
                                        <option value="0"><?php echo e(t('newinc.option_none', '— None —')); ?></option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── Section 5: Time & Status ── -->
                <div class="card mb-3 form-section">
                    <div class="card-header d-flex align-items-center" data-bs-toggle="collapse" data-bs-target="#secTime" role="button">
                        <i class="bi bi-clock me-2 text-secondary"></i>
                        <span class="fw-semibold"><?php echo e(t('newinc.section.time_status', 'Time & Status')); ?></span>
                        <i class="bi bi-chevron-down ms-2 ms-auto collapse-icon"></i>
                    </div>
                    <div class="collapse show" id="secTime">
                        <div class="card-body">
                            <div class="row g-2">
                                <div class="col-md-4">
                                    <label for="status" class="form-label"><?php echo e(t('newinc.label.status', 'Status')); ?></label>
                                    <select class="form-select form-select-sm" id="status" name="status">
                                        <option value="2" selected><?php echo e(t('newinc.status.open', 'Open')); ?></option>
                                        <option value="3"><?php echo e(t('newinc.status.scheduled', 'Scheduled')); ?></option>
                                        <option value="1"><?php echo e(t('newinc.status.closed', 'Closed')); ?></option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label for="problemstart" class="form-label"><?php echo e(t('newinc.label.problem_start', 'Problem Start')); ?></label>
                                    <input type="datetime-local" class="form-control form-control-sm" id="problemstart" name="problemstart">
                                </div>
                                <div class="col-md-4">
                                    <label for="problemend" class="form-label"><?php echo e(t('newinc.label.problem_end', 'Problem End')); ?></label>
                                    <input type="datetime-local" class="form-control form-control-sm" id="problemend" name="problemend">
                                </div>
                                <div class="col-md-4" id="bookedDateGroup" style="display:none;">
                                    <label for="booked_date" class="form-label"><?php echo e(t('newinc.label.scheduled_date', 'Scheduled Date')); ?></label>
                                    <input type="datetime-local" class="form-control form-control-sm" id="booked_date" name="booked_date">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── Section 6: Call History ── -->
                <div class="card mb-3 form-section">
                    <div class="card-header d-flex align-items-center" data-bs-toggle="collapse" data-bs-target="#secCallHistory" role="button">
                        <i class="bi bi-telephone me-2 text-primary"></i>
                        <span class="fw-semibold"><?php echo e(t('newinc.section.call_history', 'Call History')); ?></span>
                        <span class="badge bg-secondary ms-auto" id="callHistoryCount">0</span>
                        <i class="bi bi-chevron-down ms-2 collapse-icon"></i>
                    </div>
                    <div class="collapse" id="secCallHistory">
                        <div class="card-body">
                            <div class="form-text mb-2"><?php echo e(t('newinc.call_history.hint', 'Previous calls from this phone number or address')); ?></div>
                            <div id="callHistoryResults" class="call-history-list">
                                <span class="text-body-secondary small"><?php echo e(t('newinc.call_history.empty', 'Enter a phone number or address to search call history')); ?></span>
                            </div>
                            <button class="btn btn-sm btn-outline-secondary mt-2" type="button" id="btnSearchHistory">
                                <i class="bi bi-search me-1"></i><?php echo e(t('newinc.btn.search_history', 'Search History')); ?>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- ── Section 7: Patients ── -->
                <div class="card mb-3 form-section">
                    <div class="card-header d-flex align-items-center" data-bs-toggle="collapse" data-bs-target="#secPatients" role="button">
                        <i class="bi bi-heart-pulse me-2 text-danger"></i>
                        <span class="fw-semibold"><?php echo e(t('newinc.section.patients', 'Patients')); ?></span>
                        <span class="badge bg-secondary ms-auto" id="patientCount">0</span>
                        <i class="bi bi-chevron-down ms-2 collapse-icon"></i>
                    </div>
                    <div class="collapse" id="secPatients">
                        <div class="card-body">
                            <div id="patientList"></div>
                            <button class="btn btn-sm btn-outline-success mt-2" type="button" id="btnAddPatient">
                                <i class="bi bi-plus-lg me-1"></i><?php echo e(t('newinc.btn.add_patient', 'Add Patient')); ?>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- ── Section 8: Additional Details ── -->
                <div class="card mb-3 form-section">
                    <div class="card-header d-flex align-items-center" data-bs-toggle="collapse" data-bs-target="#secDetails" role="button">
                        <i class="bi bi-card-text me-2 text-secondary"></i>
                        <span class="fw-semibold"><?php echo e(t('newinc.section.details', 'Additional Details')); ?></span>
                        <i class="bi bi-chevron-down ms-2 ms-auto collapse-icon"></i>
                    </div>
                    <div class="collapse" id="secDetails">
                        <div class="card-body">
                            <div class="row g-2">
                                <div class="col-12">
                                    <label for="affected" class="form-label"><?php echo e(t('newinc.label.affected', 'Affected Area')); ?></label>
                                    <textarea class="form-control form-control-sm" id="affected" name="affected" rows="2"
                                              placeholder="<?php echo e(t('newinc.placeholder.affected', 'Description of affected area...')); ?>"></textarea>
                                </div>
                                <div class="col-12">
                                    <label for="comments" class="form-label"><?php echo e(t('newinc.label.comments', 'Disposition / Comments')); ?></label>
                                    <textarea class="form-control form-control-sm" id="comments" name="comments" rows="2"
                                              placeholder="<?php echo e(t('newinc.placeholder.comments', 'Additional comments or disposition...')); ?>"></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </form>
        </div>

        <!-- ═══════════ RIGHT COLUMN: Map + Units ═══════════ -->
        <div class="col-lg-5 col-xl-6">
            <div class="sticky-top" style="top: 100px;">

                <!-- Caller Info panel (Phase 41-fix-2 2026-06-27, Eric).
                     Shown when api/constituents.php?phone=X returns a match.
                     Surfaces caller name, address, all phone numbers, and
                     renders the `miscellaneous` field as a warning callout
                     when non-empty (per Eric: use existing column, no schema
                     change). Lives above protocolPanel so a known
                     problem-caller is the first thing dispatcher sees. -->
                <div class="card mb-3 border-primary d-none" id="callerInfoPanel">
                    <div class="card-header d-flex align-items-center py-1 bg-primary bg-opacity-10">
                        <i class="bi bi-person-vcard me-2 text-primary"></i>
                        <span class="fw-semibold small">Caller Info</span>
                        <button type="button" class="btn btn-sm btn-outline-secondary ms-auto py-0 px-1" id="btnCloseCallerInfo" title="Dismiss">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>
                    <div class="card-body py-2 px-3" id="callerInfoContent">
                    </div>
                </div>

                <!-- Protocol Display (shown when incident type has protocol) -->
                <div class="card mb-3 border-info d-none" id="protocolPanel">
                    <div class="card-header d-flex align-items-center py-1 bg-info bg-opacity-10">
                        <i class="bi bi-journal-medical me-2 text-info"></i>
                        <span class="fw-semibold small"><?php echo e(t('newinc.panel.protocol', 'Response Protocol')); ?></span>
                        <button type="button" class="btn btn-sm btn-outline-secondary ms-auto py-0 px-1" id="btnCloseProtocol" title="Dismiss">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>
                    <div class="card-body py-2 px-3" id="protocolContent">
                    </div>
                </div>

                <!-- Location Warnings (hidden by default, shown when nearby warnings detected) -->
                <div class="card mb-3 border-danger d-none" id="proximityWarningPanel">
                    <div class="card-header d-flex align-items-center py-1 bg-danger bg-opacity-10">
                        <i class="bi bi-exclamation-triangle-fill me-2 text-danger"></i>
                        <span class="fw-semibold small text-danger"><?php echo e(t('newinc.panel.warnings', 'Location Warnings')); ?></span>
                        <span class="badge bg-danger ms-2" id="warningCount"></span>
                        <button type="button" class="btn btn-sm btn-outline-secondary ms-auto py-0 px-1" id="btnDismissWarnings" title="Dismiss">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>
                    <div class="card-body py-2 px-3" id="proximityWarningContent">
                    </div>
                </div>

                <!-- Map -->
                <div class="card mb-3">
                    <div class="card-header d-flex align-items-center py-1">
                        <i class="bi bi-map me-2"></i>
                        <span class="fw-semibold small"><?php echo e(t('newinc.panel.map', 'Location Map')); ?></span>
                        <span class="text-body-secondary small ms-auto"><?php echo e(t('newinc.panel.map_hint', 'Click to set incident location')); ?></span>
                    </div>
                    <div class="card-body p-0">
                        <div id="incidentMap" style="height: 350px;"></div>
                    </div>
                </div>

                <!-- Unit Assignment -->
                <div class="card mb-3">
                    <div class="card-header d-flex align-items-center py-1">
                        <i class="bi bi-people me-2"></i>
                        <span class="fw-semibold small"><?php echo e(t('newinc.panel.assign', 'Assign Responders')); ?></span>
                        <span class="badge bg-primary ms-auto" id="assignedCount">0 <?php echo e(t('newinc.panel.selected_count', 'selected')); ?></span>
                    </div>
                    <div class="card-body p-0">
                        <div class="p-2">
                            <input type="text" class="form-control form-control-sm" id="responderSearch"
                                   placeholder="Search units (Tab into list, Space to select, Shift+Tab back here)"
                                   tabindex="12"
                                   autocomplete="off"
                                   aria-label="Search responder units">
                        </div>
                        <div class="responder-list" id="responderList" role="listbox" aria-label="Available responders">
                            <div class="text-center text-body-secondary py-3">
                                <div class="spinner-border spinner-border-sm" role="status"></div>
                                Loading responders...
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Bottom submit (visible when scrolled) -->
                <div class="d-grid gap-2">
                    <button type="button" class="btn btn-success" id="btnSubmitBottom">
                        <i class="bi bi-check-lg me-1"></i>Submit Incident
                    </button>
                </div>

            </div>
        </div>

    </div>
</div>

<!-- Vendor JS -->
<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<?php echo fe_inject_js(); ?>
<script src="assets/js/toolbar.js?v=<?php echo asset_v('assets/js/toolbar.js'); ?>"></script>
<script src="assets/vendor/leaflet/leaflet.js"></script>
<script src="assets/js/leaflet-mobile-fit.js?v=<?php echo function_exists("asset_v")?asset_v("assets/js/leaflet-mobile-fit.js"):NEWUI_VERSION; ?>"></script>
<script src="assets/js/leaflet-quadkey.js"></script>
<script src="assets/js/map-prefs.js"></script>

<!-- App JS -->
<script src="assets/js/theme-manager.js?v=<?php echo asset_v('assets/js/theme-manager.js'); ?>"></script>
<script src="assets/js/new-incident.js?v=<?php echo asset_v('assets/js/new-incident.js'); ?>"></script>

</body>
</html>
