<?php
/**
 * NewUI v4.0 - Major Incidents
 *
 * One page, two modes (mode is read from the URL by major-incidents.js):
 *   LIST mode  (no ?id)   — all major incidents, open first, + "New Major Incident"
 *   DETAIL mode (?id=X)    — header + edit, linked incidents, link/unlink/close
 *
 * Data is loaded client-side via api/major-incidents.php (already complete).
 * This page only renders the shell + static modals; all fetch/CSRF/render
 * logic lives in assets/js/major-incidents.js.
 *
 * Gating (Phase 86, 2026-09-02 revision — three tiers, not one):
 *   - Whole page requires a logged-in user (mirrors incident-list.php).
 *   - link / unlink / update are gated on rbac_can('action.link_major')
 *     ($canManage) — routine dispatch work.
 *   - create / escalate are gated on rbac_can('action.create_major_event')
 *     ($canCreate) — supervisor-tier by default.
 *   - close / unified-command roster edits are gated on
 *     rbac_can('action.manage_major_event_command') ($canManageCommand) —
 *     command-tier by default.
 *   The API enforces all three server-side; here we hide/disable the
 *   controls so a user never sees a button that would 403. All three
 *   booleans are surfaced to JS via data attributes.
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

$user      = e($_SESSION['user']);
$level     = current_role_name();
$theme     = $_SESSION['day_night'] ?? 'Day';
$bs_theme  = ($theme === 'Night') ? 'dark' : 'light';
$csrf      = csrf_token();
$canManage = function_exists('rbac_can') ? rbac_can('action.link_major') : false;
$canCreate = function_exists('rbac_can') ? rbac_can('action.create_major_event') : false;
$canManageCommand = function_exists('rbac_can') ? rbac_can('action.manage_major_event_command') : false;
?>
<!DOCTYPE html>
<html lang="<?php echo e(i18n_lang()); ?>" data-bs-theme="<?php echo $bs_theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?php echo e($csrf); ?>">
    <title><?php echo e(t('major.title', 'Major Incidents')); ?> — <?php echo e(t('login.title', 'Tickets NewUI')); ?> <?php echo newui_version(); ?></title>

    <!-- Vendor CSS -->
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">

    <!-- App CSS -->
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?php echo newui_version(); ?>">
</head>
<body data-can-manage-major="<?php echo $canManage ? '1' : '0'; ?>"
      data-can-create-major="<?php echo $canCreate ? '1' : '0'; ?>"
      data-can-manage-command="<?php echo $canManageCommand ? '1' : '0'; ?>">

<?php include_once NEWUI_ROOT . '/inc/navbar.php'; ?>
</header>

<!-- Page Content -->
<main id="main-content" class="container-fluid p-3">

    <!-- ─────────── LIST MODE ─────────── -->
    <div id="listMode" class="d-none">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <h5 class="mb-0">
                <i class="bi bi-diagram-3 text-danger me-2"></i><?php echo e(t('major.title', 'Major Incidents')); ?>
            </h5>
            <div class="d-flex gap-2 align-items-center">
<?php if ($canCreate): ?>
                <button type="button" class="btn btn-sm btn-danger" id="btnNewMajorIncident"
                        data-bs-toggle="modal" data-bs-target="#newMajorModal">
                    <i class="bi bi-plus-lg me-1"></i><?php echo e(t('major.btn.new', 'New Major Incident')); ?>
                </button>
<?php endif; ?>
                <a href="index.php" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i><?php echo e(t('profile.back_to_dashboard', 'Dashboard')); ?>
                </a>
            </div>
        </div>

        <div id="listAlertArea"></div>

        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0 small" id="majorTable">
                        <thead>
                            <tr>
                                <th class="ps-3" style="width:55px;">#</th>
                                <th><?php echo e(t('major.col.name', 'Name')); ?></th>
                                <th style="width:90px;"><?php echo e(t('major.col.severity', 'Severity')); ?></th>
                                <th style="width:80px;"><?php echo e(t('major.col.status', 'Status')); ?></th>
                                <th style="width:70px;" class="text-center"><?php echo e(t('major.col.linked', 'Linked')); ?></th>
                                <th><?php echo e(t('major.col.commander', 'Commander')); ?></th>
                                <th><?php echo e(t('major.col.started', 'Started')); ?></th>
                                <th class="text-end pe-3"></th>
                            </tr>
                        </thead>
                        <tbody id="majorListBody">
                            <tr>
                                <td colspan="8" class="text-center py-4">
                                    <div class="spinner-border spinner-border-sm text-primary me-2"></div><?php echo e(t('common.loading', 'Loading...')); ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- ─────────── DETAIL MODE ─────────── -->
    <div id="detailMode" class="d-none">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <h5 class="mb-0">
                <i class="bi bi-diagram-3 text-danger me-2"></i>
                <span id="detailHeading"><?php echo e(t('major.detail.title', 'Major Incident')); ?></span>
            </h5>
            <div class="d-flex gap-2 align-items-center">
<?php if ($canManage): ?>
                <button type="button" class="btn btn-sm btn-outline-secondary d-none" id="btnEditMajor">
                    <i class="bi bi-pencil me-1"></i><?php echo e(t('major.btn.edit', 'Edit')); ?>
                </button>
<?php endif; ?>
<?php if ($canManageCommand): ?>
                <button type="button" class="btn btn-sm btn-danger d-none" id="btnCloseMajor">
                    <i class="bi bi-x-octagon me-1"></i><?php echo e(t('major.btn.close', 'Close Major Incident')); ?>
                </button>
<?php endif; ?>
                <a href="major-incidents.php" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i><?php echo e(t('major.btn.back_to_list', 'All Majors')); ?>
                </a>
            </div>
        </div>

        <div id="detailAlertArea"></div>

        <div class="row g-3">
            <!-- Header / summary card -->
            <div class="col-lg-5 col-xl-4">
                <div class="card mb-3">
                    <div class="card-header d-flex align-items-center py-1">
                        <i class="bi bi-info-circle me-2"></i>
                        <span class="fw-semibold small"><?php echo e(t('major.section.overview', 'Overview')); ?></span>
                    </div>
                    <div class="card-body">
                        <h6 class="mb-2" id="majorName">—</h6>
                        <dl class="row small mb-0">
                            <dt class="col-5 text-body-secondary"><?php echo e(t('major.label.severity', 'Severity')); ?></dt>
                            <dd class="col-7"><span class="badge" id="majorSeverityBadge">—</span></dd>

                            <dt class="col-5 text-body-secondary"><?php echo e(t('major.label.status', 'Status')); ?></dt>
                            <dd class="col-7"><span class="badge" id="majorStatusBadge">—</span></dd>

                            <dt class="col-5 text-body-secondary"><?php echo e(t('major.label.commander', 'Commander')); ?></dt>
                            <dd class="col-7" id="majorCommander">—</dd>

                            <dt class="col-5 text-body-secondary"><?php echo e(t('major.label.started', 'Started')); ?></dt>
                            <dd class="col-7" id="majorStarted">—</dd>

                            <dt class="col-5 text-body-secondary d-none" id="majorClosedLabel"><?php echo e(t('major.label.closed', 'Closed')); ?></dt>
                            <dd class="col-7 d-none" id="majorClosedRow">—</dd>

                            <dt class="col-5 text-body-secondary"><?php echo e(t('major.label.linked', 'Linked')); ?></dt>
                            <dd class="col-7" id="majorLinkedCount">0</dd>

                            <dt class="col-5 text-body-secondary"><?php echo e(t('major.label.event_type', 'Event Type')); ?></dt>
                            <dd class="col-7" id="majorEventType">—</dd>

                            <dt class="col-5 text-body-secondary d-none" id="majorParentLabel"><?php echo e(t('major.label.escalated_from', 'Escalated From')); ?></dt>
                            <dd class="col-7 d-none" id="majorParentRow">—</dd>

                            <dt class="col-5 text-body-secondary"><?php echo e(t('major.label.units', 'Units (active/assigned)')); ?></dt>
                            <dd class="col-7" id="majorRollup">0 / 0</dd>
                        </dl>
                        <div class="mt-3 small" id="majorDescriptionWrap">
                            <div class="text-body-secondary"><?php echo e(t('major.label.description', 'Description')); ?></div>
                            <div id="majorDescription" class="border rounded p-2 mt-1" style="white-space:pre-wrap;">—</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Linked incidents -->
            <div class="col-lg-7 col-xl-8">
                <div class="card mb-3">
                    <div class="card-header d-flex align-items-center py-1">
                        <i class="bi bi-link-45deg me-2"></i>
                        <span class="fw-semibold small"><?php echo e(t('major.section.linked', 'Linked Incidents')); ?></span>
                        <span class="badge bg-primary ms-auto" id="linkedBadge">0</span>
                    </div>
                    <div class="card-body p-0">
<?php if ($canManage): ?>
                        <!-- Link-an-incident control (open tickets not already linked) -->
                        <div class="border-bottom px-2 py-2" id="linkControl">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text"><i class="bi bi-search"></i></span>
                                <input type="text" class="form-control form-control-sm" id="ticketSearch"
                                       placeholder="<?php echo e(t('major.link.search_ph', 'Search open incidents to link…')); ?>"
                                       autocomplete="off"
                                       aria-label="<?php echo e(t('major.link.search_ph', 'Search open incidents to link')); ?>">
                            </div>
                            <div class="position-relative">
                                <div class="list-group list-group-flush small d-none" id="ticketDropdown"
                                     style="position:absolute;z-index:10;width:100%;max-height:240px;overflow-y:auto;
                                            background:var(--bs-body-bg);border:1px solid var(--bs-border-color);
                                            border-radius:0 0 .375rem .375rem;box-shadow:0 4px 8px rgba(0,0,0,.15);"></div>
                            </div>
                        </div>
<?php endif; ?>
                        <div id="linkedIncidentsList">
                            <div class="text-center text-body-secondary py-3 small"><?php echo e(t('common.loading', 'Loading...')); ?></div>
                        </div>
                    </div>
                </div>

                <!-- Unified Command roster (Phase 86, 2026-09-02) — a real
                     table, not a JSON blob (see specs/phase-86-major-events/
                     changes.md). External-agency members (no TicketsCAD
                     account) are shown with a distinct badge so the roster
                     never implies more completeness than it has. -->
                <div class="card mb-3">
                    <div class="card-header d-flex align-items-center py-1">
                        <i class="bi bi-people me-2"></i>
                        <span class="fw-semibold small"><?php echo e(t('major.section.command', 'Unified Command')); ?></span>
                        <span class="badge bg-secondary ms-auto" id="commandBadge">0</span>
                    </div>
                    <div class="card-body p-0">
<?php if ($canManageCommand): ?>
                        <div class="border-bottom px-2 py-2" id="commandAddControl">
                            <div class="row g-1">
                                <div class="col-4">
                                    <input type="text" class="form-control form-control-sm" id="commandAgency"
                                           placeholder="<?php echo e(t('major.command.agency_ph', 'Agency')); ?>"
                                           aria-label="<?php echo e(t('major.command.agency_ph', 'Agency')); ?>">
                                </div>
                                <div class="col-4">
                                    <input type="text" class="form-control form-control-sm" id="commandName"
                                           placeholder="<?php echo e(t('major.command.name_ph', 'Name (if no TicketsCAD account)')); ?>"
                                           aria-label="<?php echo e(t('major.command.name_ph', 'Name (if no TicketsCAD account)')); ?>">
                                </div>
                                <div class="col-3">
                                    <input type="text" class="form-control form-control-sm" id="commandRole"
                                           placeholder="<?php echo e(t('major.command.role_ph', 'Role')); ?>" value="incident_commander"
                                           aria-label="<?php echo e(t('major.command.role_ph', 'Role')); ?>">
                                </div>
                                <div class="col-1">
                                    <button type="button" class="btn btn-sm btn-outline-primary w-100" id="btnAddCommand" title="<?php echo e(t('major.btn.add_command', 'Add')); ?>">
                                        <i class="bi bi-plus-lg"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
<?php endif; ?>
                        <div id="commandList">
                            <div class="text-center text-body-secondary py-3 small"><?php echo e(t('major.command.none', 'No unified command roster recorded.')); ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

</main>

<!-- New Major Incident modal -->
<?php if ($canCreate): ?>
<div class="modal fade" id="newMajorModal" tabindex="-1" aria-labelledby="newMajorModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title" id="newMajorModalLabel">
                    <i class="bi bi-diagram-3 text-danger me-1"></i><?php echo e(t('major.modal.new_title', 'New Major Incident')); ?>
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-2">
                    <label for="newMajorName" class="form-label form-label-sm"><?php echo e(t('major.field.name', 'Name')); ?> <span class="text-danger">*</span></label>
                    <input type="text" class="form-control form-control-sm" id="newMajorName" maxlength="255" required>
                </div>
                <div class="mb-2">
                    <label for="newMajorSeverity" class="form-label form-label-sm"><?php echo e(t('major.field.severity', 'Severity')); ?></label>
                    <select class="form-select form-select-sm" id="newMajorSeverity">
                        <option value="0"><?php echo e(t('major.sev.0', 'Minor')); ?></option>
                        <option value="1" selected><?php echo e(t('major.sev.1', 'Major')); ?></option>
                        <option value="2"><?php echo e(t('major.sev.2', 'Critical')); ?></option>
                    </select>
                </div>
                <div class="mb-2">
                    <label for="newMajorCommander" class="form-label form-label-sm"><?php echo e(t('major.field.commander', 'Incident Commander')); ?></label>
                    <select class="form-select form-select-sm" id="newMajorCommander">
                        <option value=""><?php echo e(t('major.field.no_commander', '— None —')); ?></option>
                    </select>
                </div>
                <div class="mb-2">
                    <label for="newMajorEventType" class="form-label form-label-sm"><?php echo e(t('major.field.event_type', 'Event Type')); ?></label>
                    <select class="form-select form-select-sm" id="newMajorEventType">
                        <option value=""><?php echo e(t('major.event_type.none', '— Unspecified —')); ?></option>
                        <option value="structure-fire"><?php echo e(t('major.event_type.structure_fire', 'Structure Fire')); ?></option>
                        <option value="mci"><?php echo e(t('major.event_type.mci', 'Mass-Casualty Incident')); ?></option>
                        <option value="hazmat"><?php echo e(t('major.event_type.hazmat', 'Hazmat')); ?></option>
                        <option value="severe-weather"><?php echo e(t('major.event_type.severe_weather', 'Severe Weather')); ?></option>
                        <option value="planned-event"><?php echo e(t('major.event_type.planned_event', 'Planned Event')); ?></option>
                        <option value="other"><?php echo e(t('major.event_type.other', 'Other')); ?></option>
                    </select>
                </div>
                <div class="mb-2 form-check">
                    <input type="checkbox" class="form-check-input" id="newMajorMutualAid">
                    <label class="form-check-label small" for="newMajorMutualAid"><?php echo e(t('major.field.mutual_aid', 'Mutual aid requested')); ?></label>
                </div>
                <div class="mb-0">
                    <label for="newMajorDescription" class="form-label form-label-sm"><?php echo e(t('major.field.description', 'Description')); ?></label>
                    <textarea class="form-control form-control-sm" id="newMajorDescription" rows="3"></textarea>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal"><?php echo e(t('btn.cancel', 'Cancel')); ?></button>
                <button type="button" class="btn btn-sm btn-danger" id="btnCreateMajorSubmit">
                    <i class="bi bi-plus-lg me-1"></i><?php echo e(t('major.btn.create', 'Create')); ?>
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Edit Major Incident modal -->
<?php if ($canManage): ?>
<div class="modal fade" id="editMajorModal" tabindex="-1" aria-labelledby="editMajorModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title" id="editMajorModalLabel">
                    <i class="bi bi-pencil me-1"></i><?php echo e(t('major.modal.edit_title', 'Edit Major Incident')); ?>
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-2">
                    <label for="editMajorName" class="form-label form-label-sm"><?php echo e(t('major.field.name', 'Name')); ?> <span class="text-danger">*</span></label>
                    <input type="text" class="form-control form-control-sm" id="editMajorName" maxlength="255" required>
                </div>
                <div class="mb-2">
                    <label for="editMajorSeverity" class="form-label form-label-sm"><?php echo e(t('major.field.severity', 'Severity')); ?></label>
                    <select class="form-select form-select-sm" id="editMajorSeverity">
                        <option value="0"><?php echo e(t('major.sev.0', 'Minor')); ?></option>
                        <option value="1"><?php echo e(t('major.sev.1', 'Major')); ?></option>
                        <option value="2"><?php echo e(t('major.sev.2', 'Critical')); ?></option>
                    </select>
                </div>
                <div class="mb-2">
                    <label for="editMajorCommander" class="form-label form-label-sm"><?php echo e(t('major.field.commander', 'Incident Commander')); ?></label>
                    <select class="form-select form-select-sm" id="editMajorCommander">
                        <option value=""><?php echo e(t('major.field.no_commander', '— None —')); ?></option>
                    </select>
                </div>
                <div class="mb-2">
                    <label for="editMajorEventType" class="form-label form-label-sm"><?php echo e(t('major.field.event_type', 'Event Type')); ?></label>
                    <select class="form-select form-select-sm" id="editMajorEventType">
                        <option value=""><?php echo e(t('major.event_type.none', '— Unspecified —')); ?></option>
                        <option value="structure-fire"><?php echo e(t('major.event_type.structure_fire', 'Structure Fire')); ?></option>
                        <option value="mci"><?php echo e(t('major.event_type.mci', 'Mass-Casualty Incident')); ?></option>
                        <option value="hazmat"><?php echo e(t('major.event_type.hazmat', 'Hazmat')); ?></option>
                        <option value="severe-weather"><?php echo e(t('major.event_type.severe_weather', 'Severe Weather')); ?></option>
                        <option value="planned-event"><?php echo e(t('major.event_type.planned_event', 'Planned Event')); ?></option>
                        <option value="other"><?php echo e(t('major.event_type.other', 'Other')); ?></option>
                    </select>
                </div>
                <div class="mb-2 form-check">
                    <input type="checkbox" class="form-check-input" id="editMajorMutualAid">
                    <label class="form-check-label small" for="editMajorMutualAid"><?php echo e(t('major.field.mutual_aid', 'Mutual aid requested')); ?></label>
                </div>
                <div class="mb-0">
                    <label for="editMajorDescription" class="form-label form-label-sm"><?php echo e(t('major.field.description', 'Description')); ?></label>
                    <textarea class="form-control form-control-sm" id="editMajorDescription" rows="3"></textarea>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal"><?php echo e(t('btn.cancel', 'Cancel')); ?></button>
                <button type="button" class="btn btn-sm btn-primary" id="btnUpdateMajorSubmit">
                    <i class="bi bi-check-lg me-1"></i><?php echo e(t('btn.save', 'Save')); ?>
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- CSRF token for JS -->
<input type="hidden" id="csrfToken" value="<?php echo e($csrf); ?>">

<!-- Vendor JS -->
<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="assets/js/toolbar.js?v=<?php echo asset_v('assets/js/toolbar.js'); ?>"></script>

<!-- App JS -->
<script src="assets/js/theme-manager.js?v=<?php echo asset_v('assets/js/theme-manager.js'); ?>"></script>
<script src="assets/js/major-incidents.js?v=<?php echo asset_v('assets/js/major-incidents.js'); ?>"></script>

</body>
</html>
