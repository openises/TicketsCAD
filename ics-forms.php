<?php
/**
 * NewUI v4.0 - ICS Forms Hub
 *
 * Unified ICS (Incident Command System) forms page.
 * Browse available form types, create new forms, view/edit saved forms.
 * Supports: ICS-213, ICS-214, ICS-202, ICS-205, ICS-205A, ICS-213RR
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
    <title><?php echo e(t('page.ics_forms', 'ICS Forms')); ?> — <?php echo e(t('login.title', 'Tickets NewUI')); ?> <?php echo NEWUI_VERSION; ?></title>

    <!-- Vendor CSS -->
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">

    <!-- App CSS -->
    <link rel="stylesheet" href="assets/css/dashboard.css">
    <link rel="stylesheet" href="assets/css/ics-forms.css">
</head>
<body>

<?php
$active_page = 'ics-forms';
include_once NEWUI_ROOT . '/inc/navbar.php';
?>
</header>

<input type="hidden" id="csrfToken" value="<?php echo e($csrf); ?>">

<!-- Page Content -->
<div class="container-fluid p-3">

    <!-- Page title -->
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h5 class="mb-0">
            <i class="bi bi-file-earmark-text text-primary me-2"></i>ICS Forms
        </h5>
    </div>

    <!-- Alert area -->
    <div id="alertArea"></div>

    <!-- ═══════════ HUB SECTION ═══════════ -->
    <div id="hubSection">

        <!-- Form Type Cards -->
        <div class="row g-3 mb-4">

            <!-- ICS-213: General Message -->
            <div class="col-md-6 col-lg-4 col-xl-3">
                <div class="card ics-type-card h-100">
                    <div class="card-body">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span class="form-number text-primary">ICS-213</span>
                            <span class="badge bg-primary">Message</span>
                        </div>
                        <h6 class="card-title mb-1">General Message</h6>
                        <p class="form-desc text-body-secondary mb-2">
                            Standard ICS general message form for sending/receiving communications.
                            Supports Winlink XML export for radio transmission.
                        </p>
                        <button class="btn btn-sm btn-primary" data-new-form="213">
                            <i class="bi bi-plus-lg me-1"></i>New
                        </button>
                    </div>
                </div>
            </div>

            <!-- ICS-214: Activity Log -->
            <div class="col-md-6 col-lg-4 col-xl-3">
                <div class="card ics-type-card h-100">
                    <div class="card-body">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span class="form-number text-success">ICS-214</span>
                            <span class="badge bg-success">Log</span>
                        </div>
                        <h6 class="card-title mb-1">Activity Log</h6>
                        <p class="form-desc text-body-secondary mb-2">
                            Record significant activities, events, and decisions during an
                            operational period. Maintained by each unit leader.
                        </p>
                        <button class="btn btn-sm btn-success" data-new-form="214">
                            <i class="bi bi-plus-lg me-1"></i>New
                        </button>
                    </div>
                </div>
            </div>

            <!-- ICS-202: Incident Objectives -->
            <div class="col-md-6 col-lg-4 col-xl-3">
                <div class="card ics-type-card h-100">
                    <div class="card-body">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span class="form-number text-info">ICS-202</span>
                            <span class="badge bg-info">Objectives</span>
                        </div>
                        <h6 class="card-title mb-1">Incident Objectives</h6>
                        <p class="form-desc text-body-secondary mb-2">
                            Document overall incident objectives and current actions summary
                            for the operational period. Part of the Incident Action Plan (IAP).
                        </p>
                        <button class="btn btn-sm btn-info" data-new-form="202">
                            <i class="bi bi-plus-lg me-1"></i>New
                        </button>
                    </div>
                </div>
            </div>

            <!-- ICS-205: Radio Communications Plan -->
            <div class="col-md-6 col-lg-4 col-xl-3">
                <div class="card ics-type-card h-100">
                    <div class="card-body">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span class="form-number text-warning">ICS-205</span>
                            <span class="badge bg-warning text-dark">Radio</span>
                        </div>
                        <h6 class="card-title mb-1">Radio Communications Plan</h6>
                        <p class="form-desc text-body-secondary mb-2">
                            Assign radio frequencies, channels, and tones for incident
                            communications. Essential for amateur radio deployments.
                        </p>
                        <button class="btn btn-sm btn-warning" data-new-form="205">
                            <i class="bi bi-plus-lg me-1"></i>New
                        </button>
                    </div>
                </div>
            </div>

            <!-- ICS-205A: Communications List -->
            <div class="col-md-6 col-lg-4 col-xl-3">
                <div class="card ics-type-card h-100">
                    <div class="card-body">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span class="form-number text-secondary">ICS-205A</span>
                            <span class="badge bg-secondary">Contacts</span>
                        </div>
                        <h6 class="card-title mb-1">Communications List</h6>
                        <p class="form-desc text-body-secondary mb-2">
                            Contact directory listing ICS positions, names, phone numbers,
                            radio frequencies, and email addresses.
                        </p>
                        <button class="btn btn-sm btn-secondary" data-new-form="205a">
                            <i class="bi bi-plus-lg me-1"></i>New
                        </button>
                    </div>
                </div>
            </div>

            <!-- ICS-213RR: Resource Request Message -->
            <div class="col-md-6 col-lg-4 col-xl-3">
                <div class="card ics-type-card h-100">
                    <div class="card-body">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span class="form-number text-danger">ICS-213RR</span>
                            <span class="badge bg-danger">Resources</span>
                        </div>
                        <h6 class="card-title mb-1">Resource Request Message</h6>
                        <p class="form-desc text-body-secondary mb-2">
                            Request personnel, equipment, or supplies through the logistics
                            section. Tracks quantities, priorities, and estimated costs.
                        </p>
                        <button class="btn btn-sm btn-danger" data-new-form="213rr">
                            <i class="bi bi-plus-lg me-1"></i>New
                        </button>
                    </div>
                </div>
            </div>

            <!-- ICS-206: Medical Plan -->
            <div class="col-md-6 col-lg-4 col-xl-3">
                <div class="card ics-type-card h-100">
                    <div class="card-body">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span class="form-number" style="color:var(--bs-teal)">ICS-206</span>
                            <span class="badge" style="background-color:var(--bs-teal)">Medical</span>
                        </div>
                        <h6 class="card-title mb-1">Medical Plan</h6>
                        <p class="form-desc text-body-secondary mb-2">
                            Document medical aid stations, ambulance services, hospitals,
                            and emergency medical procedures for the incident.
                        </p>
                        <button class="btn btn-sm" style="background-color:var(--bs-teal);color:#fff" data-new-form="206">
                            <i class="bi bi-plus-lg me-1"></i>New
                        </button>
                    </div>
                </div>
            </div>

            <!-- ICS-214a: Individual Activity Log -->
            <div class="col-md-6 col-lg-4 col-xl-3">
                <div class="card ics-type-card h-100">
                    <div class="card-body">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span class="form-number" style="color:var(--bs-indigo)">ICS-214a</span>
                            <span class="badge" style="background-color:var(--bs-indigo)">Individual Log</span>
                        </div>
                        <h6 class="card-title mb-1">Individual Activity Log</h6>
                        <p class="form-desc text-body-secondary mb-2">
                            Record individual personnel activities and notable events
                            during an operational period. Similar to ICS-214 but for one person.
                        </p>
                        <button class="btn btn-sm" style="background-color:var(--bs-indigo);color:#fff" data-new-form="214a">
                            <i class="bi bi-plus-lg me-1"></i>New
                        </button>
                    </div>
                </div>
            </div>

            <!-- ICS-221: Demobilization Check-Out -->
            <div class="col-md-6 col-lg-4 col-xl-3">
                <div class="card ics-type-card h-100">
                    <div class="card-body">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span class="form-number" style="color:var(--bs-orange)">ICS-221</span>
                            <span class="badge" style="background-color:var(--bs-orange)">Demob</span>
                        </div>
                        <h6 class="card-title mb-1">Demobilization Check-Out</h6>
                        <p class="form-desc text-body-secondary mb-2">
                            Track resource demobilization with section check-outs,
                            reassignment details, and travel information.
                        </p>
                        <button class="btn btn-sm" style="background-color:var(--bs-orange);color:#fff" data-new-form="221">
                            <i class="bi bi-plus-lg me-1"></i>New
                        </button>
                    </div>
                </div>
            </div>

        </div><!-- /row -->

        <!-- Saved Forms Table -->
        <div class="card">
            <div class="card-header d-flex align-items-center justify-content-between py-2">
                <span class="fw-semibold"><i class="bi bi-clock-history me-2"></i>Saved Forms</span>
                <div class="d-flex align-items-center gap-2">
                    <label class="form-label mb-0 small">Filter:</label>
                    <select class="form-select form-select-sm" id="hubFilterType" style="width:140px">
                        <option value="">All Types</option>
                        <option value="213">ICS-213</option>
                        <option value="214">ICS-214</option>
                        <option value="202">ICS-202</option>
                        <option value="205">ICS-205</option>
                        <option value="205a">ICS-205A</option>
                        <option value="206">ICS-206</option>
                        <option value="213rr">ICS-213RR</option>
                        <option value="214a">ICS-214a</option>
                        <option value="221">ICS-221</option>
                    </select>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead>
                            <tr>
                                <th style="width:90px">Type</th>
                                <th>Title</th>
                                <th style="width:80px">Incident</th>
                                <th style="width:70px">Status</th>
                                <th style="width:120px">Created By</th>
                                <th style="width:140px">Last Updated</th>
                            </tr>
                        </thead>
                        <tbody id="savedFormsBody">
                            <tr><td colspan="6" class="text-center text-body-secondary py-3">Loading...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div><!-- /hubSection -->

    <!-- ═══════════ EDITOR SECTION (hidden by default) ═══════════ -->
    <div id="editorSection" style="display:none">

        <!-- Editor toolbar -->
        <div class="editor-toolbar d-flex align-items-center justify-content-between mb-3 no-print">
            <div class="d-flex align-items-center gap-2">
                <button class="btn btn-sm btn-outline-secondary" id="btnBackToHub">
                    <i class="bi bi-arrow-left me-1"></i>Back
                </button>
                <h6 class="mb-0" id="editorTitle">ICS Form</h6>
            </div>
            <div class="d-flex gap-2">
                <button class="btn btn-sm btn-outline-info" id="btnExportXml" style="display:none">
                    <i class="bi bi-filetype-xml me-1"></i>Export XML
                </button>
                <button class="btn btn-sm btn-outline-secondary" id="btnPrint">
                    <i class="bi bi-printer me-1"></i>Print
                </button>
                <button class="btn btn-sm btn-outline-primary" id="btnSave">
                    <i class="bi bi-save me-1"></i>Save Draft
                </button>
                <button class="btn btn-sm btn-success" id="btnFinalize">
                    <i class="bi bi-check-lg me-1"></i>Finalize
                </button>
            </div>
        </div>

        <!-- Metadata row -->
        <div class="row g-3 mb-3 no-print">
            <div class="col-md-4">
                <label class="form-label" for="formTitle">Form Title <span class="text-danger">*</span></label>
                <input type="text" class="form-control form-control-sm" id="formTitle"
                       placeholder="e.g., Shelter Deployment Message" tabindex="0">
            </div>
            <div class="col-md-3">
                <label class="form-label" for="formStatus">Status</label>
                <select class="form-select form-select-sm" id="formStatus" disabled>
                    <option value="draft">Draft</option>
                    <option value="final">Final</option>
                    <option value="sent">Sent</option>
                </select>
            </div>
            <div class="col-md-5">
                <label class="form-label">Link to Incident (optional)</label>
                <div class="position-relative">
                    <div class="input-group input-group-sm incident-link-bar">
                        <span class="input-group-text"><i class="bi bi-link-45deg"></i></span>
                        <input type="text" class="form-control form-control-sm" id="linkIncidentSearch"
                               placeholder="Search by #, address, type, or description..." autocomplete="off">
                        <input type="hidden" id="linkIncidentId" value="">
                        <button class="btn btn-outline-secondary" type="button" id="btnClearIncidentLink" title="Clear link" style="display:none">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>
                    <div class="incident-search-results d-none" id="incidentSearchResults"></div>
                </div>
            </div>
        </div>

        <!-- Dynamic form fields rendered here by JS -->
        <div class="card">
            <div class="card-body" id="formFieldsContainer">
                <p class="text-body-secondary">Loading form fields...</p>
            </div>
        </div>

    </div><!-- /editorSection -->

</div><!-- /container-fluid -->

<!-- Vendor JS -->
<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>

<!-- App JS -->
<script src="assets/js/ics-forms.js?v=<?php echo asset_v('assets/js/ics-forms.js'); ?>"></script>

</body>
</html>
