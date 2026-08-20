<?php
/**
 * NewUI v4.0 — Custom ICS Form Type authoring (Phase 140, GH#69).
 *
 * Standalone admin page (same precedent as public-board-admin.php /
 * weather-alerts.php / voice-speech.php). Backend: api/ics-form-types.php.
 *
 * TWO-TIER RBAC:
 *   action.manage_ics_form_types      — install-wide (Super Admin only).
 *   action.manage_ics_form_types_org  — org-scoped self-service (Super
 *       Admin + Org Admin), scoped to the caller's own organization.
 *
 * This file's gating is DISPLAY ONLY. Every write goes through
 * api/ics-form-types.php, which independently re-checks both permissions
 * and forces org scope from the caller's real grants (never from anything
 * this page renders or the client submits) -- see that file's docblock and
 * ics_form_types_resolve_create_org() / ics_form_custom_template() in
 * inc/ics-form-types.php for the actual enforcement.
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

// Deliberately NOT `rbac_can(...) || is_admin()` -- see
// api/ics-form-types.php's identical documented reasoning (matching
// api/public-board-admin.php's precedent): is_admin()'s action.manage_config
// fallback can be true for a correctly-scoped Org Admin, and `||`-ing it in
// here would show this page's install-wide controls to that Org Admin --
// exactly the leak the two-permission split exists to prevent.
$canAuthorGlobal = rbac_can('action.manage_ics_form_types');
$canAuthorOrg    = rbac_can('action.manage_ics_form_types_org');

if (!$canAuthorGlobal && !$canAuthorOrg) {
    http_response_code(403);
    $theme    = $_SESSION['day_night'] ?? 'Day';
    $bs_theme = ($theme === 'Night') ? 'dark' : 'light';
    ?>
<!DOCTYPE html>
<html lang="<?php echo e(i18n_lang()); ?>" data-bs-theme="<?php echo $bs_theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Custom ICS Form Types — Tickets NewUI</title>
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">
</head>
<body>
<main class="container py-5" style="max-width: 640px;">
    <div class="alert alert-warning">
        <h5 class="alert-heading"><i class="bi bi-shield-lock me-2"></i>Permission required</h5>
        <p class="mb-2">Custom ICS form type authoring requires either the "Manage Custom ICS Form Types" or the
           "Manage Own Org's Custom ICS Form Types" permission. Ask an administrator to grant your role
           <code>action.manage_ics_form_types</code> or <code>action.manage_ics_form_types_org</code>.</p>
        <a href="ics-forms.php" class="btn btn-sm btn-outline-secondary">Back to ICS Forms</a>
    </div>
</main>
</body>
</html>
    <?php
    exit;
}

$user     = e($_SESSION['user']);
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
    <title>Custom ICS Form Types — Tickets NewUI <?php echo newui_version(); ?></title>
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?php echo asset_v('assets/css/dashboard.css'); ?>">
</head>
<body>
<?php include_once NEWUI_ROOT . '/inc/navbar.php'; ?>

<main class="container-fluid py-3" style="max-width: 1100px;">
    <div class="d-flex align-items-center mb-3 flex-wrap gap-2">
        <h4 class="mb-0"><i class="bi bi-file-earmark-plus text-primary me-2"></i>Custom ICS Form Types</h4>
        <a href="ics-forms.php" class="ms-auto btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Back to ICS Forms</a>
        <a href="settings.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-gear me-1"></i>Settings</a>
    </div>

    <div id="ftToast" class="alert d-none" role="status"></div>

    <p class="text-body-secondary small">
        Define agency-specific ICS-style forms alongside the nine built-in ICS forms (213, 214, 202, 205,
        205A, 213RR, 206, 214A, 221). Editing a type's fields only affects <em>new</em> submissions -- every
        already-saved form keeps rendering exactly as it looked at its own first save.
    </p>

    <!-- ══════════════════ List panel ══════════════════ -->
    <div class="card mb-3" id="ftListPanel">
        <div class="card-header d-flex align-items-center justify-content-between">
            <span><i class="bi bi-list-ul me-2"></i>Form Types</span>
            <button class="btn btn-sm btn-primary" id="ftBtnNew"><i class="bi bi-plus-lg me-1"></i>New Type</button>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="width:60px"></th>
                            <th>Number / Title</th>
                            <th style="width:140px">Scope</th>
                            <th style="width:90px">Status</th>
                            <th style="width:80px" class="text-end">Instances</th>
                            <th style="width:140px" class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="ftListRows"><tr><td colspan="6" class="text-body-secondary">Loading…</td></tr></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ══════════════════ Editor panel ══════════════════ -->
    <div class="card mb-3 d-none" id="ftEditorPanel">
        <div class="card-header d-flex align-items-center justify-content-between">
            <span id="ftEditorTitle"><i class="bi bi-pencil-square me-2"></i>New Form Type</span>
            <button class="btn btn-sm btn-outline-secondary" id="ftBtnCancel"><i class="bi bi-x-lg me-1"></i>Cancel</button>
        </div>
        <div class="card-body">
            <input type="hidden" id="ftId" value="0">

            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label class="form-label form-label-sm mb-0" for="ftSlug">Slug (permanent once created)</label>
                    <input type="text" class="form-control form-control-sm" id="ftSlug"
                        placeholder="medical-triage" pattern="[a-z][a-z0-9_-]{2,59}">
                    <div class="form-text">Lowercase letters, digits, underscore, or hyphen. Cannot be changed after saving.</div>
                </div>
                <div class="col-md-3">
                    <label class="form-label form-label-sm mb-0" for="ftFormNumber">Form number</label>
                    <input type="text" class="form-control form-control-sm" id="ftFormNumber" placeholder="MED-1" maxlength="40">
                </div>
                <div class="col-md-5">
                    <label class="form-label form-label-sm mb-0" for="ftFormTitle">Form title</label>
                    <input type="text" class="form-control form-control-sm" id="ftFormTitle" placeholder="Medical Triage Log" maxlength="255">
                </div>

                <div class="col-md-6">
                    <label class="form-label form-label-sm mb-0" for="ftDescription">Description</label>
                    <input type="text" class="form-control form-control-sm" id="ftDescription" maxlength="500"
                        placeholder="Shown on the hub card under the title">
                </div>
                <div class="col-md-3">
                    <label class="form-label form-label-sm mb-0" for="ftIcon">Icon</label>
                    <input type="text" class="form-control form-control-sm" id="ftIcon" value="bi-file-earmark-text"
                        pattern="bi-[a-z0-9-]+">
                    <div class="form-text">A <a href="https://icons.getbootstrap.com/" target="_blank" rel="noopener">Bootstrap Icons</a> class name.</div>
                </div>
                <div class="col-md-3">
                    <label class="form-label form-label-sm mb-0" for="ftBadgeColor">Badge color</label>
                    <select class="form-select form-select-sm" id="ftBadgeColor">
                        <option value="primary">Primary</option>
                        <option value="secondary" selected>Secondary</option>
                        <option value="success">Success</option>
                        <option value="danger">Danger</option>
                        <option value="warning">Warning</option>
                        <option value="info">Info</option>
                        <option value="dark">Dark</option>
                    </select>
                </div>

                <div class="col-md-6" id="ftOrgScopeWrap">
                    <label class="form-label form-label-sm mb-0" for="ftOrgScope">Scope</label>
                    <select class="form-select form-select-sm" id="ftOrgScope"></select>
                    <div class="form-text">Install-wide types are visible to every organization. Org-scoped
                        types are visible only within that organization.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label form-label-sm mb-0" for="ftRestrictTo">Restrict use to permission code (optional)</label>
                    <input type="text" class="form-control form-control-sm" id="ftRestrictTo"
                        placeholder="e.g. field.view_patient">
                    <div class="form-text">A caller must ALSO hold this exact permission code to use the
                        type -- beyond the normal ICS-forms permission every type already requires. See
                        Settings &rarr; Roles &amp; Permissions for the full code list. Leave blank for no
                        extra restriction.</div>
                </div>
            </div>

            <hr>

            <div class="d-flex align-items-center justify-content-between mb-2">
                <h6 class="mb-0"><i class="bi bi-input-cursor-text me-2"></i>Fields</h6>
                <div class="dropdown">
                    <button class="btn btn-sm btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                        <i class="bi bi-plus-lg me-1"></i>Add Field</button>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="#" data-add-field="text">Text</a></li>
                        <li><a class="dropdown-item" href="#" data-add-field="textarea">Text area</a></li>
                        <li><a class="dropdown-item" href="#" data-add-field="number">Number</a></li>
                        <li><a class="dropdown-item" href="#" data-add-field="date">Date</a></li>
                        <li><a class="dropdown-item" href="#" data-add-field="time">Time</a></li>
                        <li><a class="dropdown-item" href="#" data-add-field="datetime-local">Date &amp; time</a></li>
                        <li><a class="dropdown-item" href="#" data-add-field="select">Select (dropdown)</a></li>
                        <li><a class="dropdown-item" href="#" data-add-field="checkbox">Checkbox</a></li>
                        <li><a class="dropdown-item" href="#" data-add-field="section_header">Section header</a></li>
                        <li><a class="dropdown-item" href="#" data-add-field="table">Table</a></li>
                    </ul>
                </div>
            </div>
            <div id="ftFieldsList"></div>
            <p class="text-body-secondary small mb-0" id="ftFieldsEmpty">No fields yet -- use "Add Field" above.</p>

            <div class="mt-3 d-flex gap-2">
                <button class="btn btn-primary btn-sm" id="ftBtnSave"><i class="bi bi-save me-1"></i>Save Type</button>
                <button class="btn btn-outline-secondary btn-sm" id="ftBtnCancel2">Cancel</button>
            </div>
            <div id="ftSaveError" class="alert alert-danger small mt-2 d-none"></div>
        </div>
    </div>
</main>

<input type="hidden" id="csrfToken" value="<?php echo e($csrf); ?>">
<script>
    // Display-only flags (see this file's own docblock) — never the
    // authority for whether a write is allowed. Every write is re-checked
    // server-side by api/ics-form-types.php.
    window.ICSFT_CAN_AUTHOR_GLOBAL = <?php echo $canAuthorGlobal ? 'true' : 'false'; ?>;
    window.ICSFT_CAN_AUTHOR_ORG = <?php echo $canAuthorOrg ? 'true' : 'false'; ?>;
</script>
<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="assets/js/theme-manager.js?v=<?php echo asset_v('assets/js/theme-manager.js'); ?>"></script>
<script src="assets/js/ics-form-type-admin.js?v=<?php echo asset_v('assets/js/ics-form-type-admin.js'); ?>"></script>
</body>
</html>
