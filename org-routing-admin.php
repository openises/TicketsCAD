<?php
/**
 * NewUI v4.0 — Cross-Org Ticket Routing admin (Phase 141, GH#70).
 *
 * Standalone admin page (same precedent as ics-form-type-admin.php /
 * public-board-admin.php). Backend: api/org-routing.php.
 *
 * TWO-TIER RBAC:
 *   action.manage_org_routing      — install-wide (Super Admin only in
 *       Phase 1's shipped default).
 *   action.manage_org_routing_org  — org-scoped self-service. Excluded from
 *       Org Admin's default grant in Phase 1 (plan.md open-question-1) --
 *       present only so a Super Admin can hand-grant it per-install.
 *
 * This file's gating is DISPLAY ONLY. Every write goes through
 * api/org-routing.php, which independently re-checks both permissions and
 * forces owning_org_id from the caller's real grants (never from anything
 * this page renders or the client submits).
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

// Deliberately NOT `rbac_can(...) || is_admin()` -- see api/org-routing.php's
// identical, already-documented reasoning: is_admin()'s action.manage_config
// fallback can be true for a correctly-scoped Org Admin, and `||`-ing it in
// here would show this page's install-wide controls (and every org's rules)
// to that Org Admin -- exactly the leak the two-permission split, and this
// project's own standing rule against `rbac_can() || is_admin()` on a
// narrower-tier permission, both exist to prevent.
$canAuthorGlobal = rbac_can('action.manage_org_routing');
$canAuthorOrg    = rbac_can('action.manage_org_routing_org');

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
    <title>Cross-Org Ticket Routing — Tickets NewUI</title>
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">
</head>
<body>
<main class="container py-5" style="max-width: 640px;">
    <div class="alert alert-warning">
        <h5 class="alert-heading"><i class="bi bi-shield-lock me-2"></i>Permission required</h5>
        <p class="mb-2">Cross-org ticket routing configuration requires either the "Manage Cross-Org Ticket
           Routing Rules" or the "Manage Own Org's Cross-Org Ticket Routing Rules" permission. Ask an
           administrator to grant your role <code>action.manage_org_routing</code> or
           <code>action.manage_org_routing_org</code>.</p>
        <a href="settings.php" class="btn btn-sm btn-outline-secondary">Back to Settings</a>
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
    <title>Cross-Org Ticket Routing — Tickets NewUI <?php echo newui_version(); ?></title>
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?php echo asset_v('assets/css/dashboard.css'); ?>">
</head>
<body>
<?php include_once NEWUI_ROOT . '/inc/navbar.php'; ?>

<main class="container-fluid py-3" style="max-width: 1100px;">
    <div class="d-flex align-items-center mb-3 flex-wrap gap-2">
        <h4 class="mb-0"><i class="bi bi-signpost-split text-primary me-2"></i>Cross-Org Ticket Routing</h4>
        <a href="settings.php" class="ms-auto btn btn-sm btn-outline-secondary">
            <i class="bi bi-gear me-1"></i>Settings</a>
    </div>

    <div id="orToast" class="alert d-none" role="status"></div>

    <p class="text-body-secondary small">
        Auto-share tickets with another organization the moment they're created, based on incident type.
        A routed org sees the ticket on its own board, list, and search results as soon as it's dispatched
        -- no manual forwarding. <strong>View</strong> tier is read-only (with sensitive fields redacted);
        <strong>Assist</strong> tier lets the responding org add notes, update status, and assign their own
        units, the same as a same-org dispatcher. Deactivating a rule only stops <em>future</em> matches --
        tickets already shared stay shared.
    </p>

    <!-- ══════════════════ List panel ══════════════════ -->
    <div class="card mb-3" id="orListPanel">
        <div class="card-header d-flex align-items-center justify-content-between">
            <span><i class="bi bi-list-ul me-2"></i>Routing Rules</span>
            <button class="btn btn-sm btn-primary" id="orBtnNew"><i class="bi bi-plus-lg me-1"></i>New Rule</button>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Owning Org</th>
                            <th></th>
                            <th>Target Org</th>
                            <th>Matches</th>
                            <th style="width:90px">Tier</th>
                            <th style="width:90px">Status</th>
                            <th>Created by</th>
                            <th style="width:80px" class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="orListRows"><tr><td colspan="8" class="text-body-secondary">Loading…</td></tr></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ══════════════════ Editor panel ══════════════════ -->
    <div class="card mb-3 d-none" id="orEditorPanel">
        <div class="card-header d-flex align-items-center justify-content-between">
            <span id="orEditorTitle"><i class="bi bi-pencil-square me-2"></i>New Routing Rule</span>
            <button class="btn btn-sm btn-outline-secondary" id="orBtnCancel"><i class="bi bi-x-lg me-1"></i>Cancel</button>
        </div>
        <div class="card-body">
            <input type="hidden" id="orId" value="0">

            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label form-label-sm mb-0" for="orOwningOrg">Owning organization (routes FROM)</label>
                    <select class="form-select form-select-sm" id="orOwningOrg"></select>
                    <div class="form-text">Tickets created under this organization are the ones this rule can auto-share.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label form-label-sm mb-0" for="orTargetOrg">Target organization (shares TO)</label>
                    <select class="form-select form-select-sm" id="orTargetOrg"></select>
                    <div class="form-text">The organization that gains visibility into matching tickets.</div>
                </div>
            </div>

            <hr>

            <div class="mb-3">
                <label class="form-label form-label-sm mb-1 d-block">Match on</label>
                <div class="btn-group btn-group-sm" role="group" aria-label="Match scope">
                    <input type="radio" class="btn-check" name="orMatchScope" id="orMatchScopeGroup" value="group" checked>
                    <label class="btn btn-outline-primary" for="orMatchScopeGroup">Incident type group</label>
                    <input type="radio" class="btn-check" name="orMatchScope" id="orMatchScopeType" value="type">
                    <label class="btn btn-outline-primary" for="orMatchScopeType">Specific incident type</label>
                </div>
            </div>
            <div class="row g-3 mb-3">
                <div class="col-md-6" id="orMatchGroupWrap">
                    <label class="form-label form-label-sm mb-0" for="orMatchGroup">Incident type group</label>
                    <select class="form-select form-select-sm" id="orMatchGroup"></select>
                </div>
                <div class="col-md-6 d-none" id="orMatchTypeWrap">
                    <label class="form-label form-label-sm mb-0" for="orMatchType">Specific incident type</label>
                    <select class="form-select form-select-sm" id="orMatchType"></select>
                    <div class="form-text">A specific-type rule always takes precedence over a group rule for the same target org.</div>
                </div>
            </div>

            <hr>

            <div class="mb-3">
                <label class="form-label form-label-sm mb-1 d-block">Access tier</label>
                <div class="btn-group btn-group-sm" role="group" aria-label="Access tier">
                    <input type="radio" class="btn-check" name="orAccessTier" id="orTierView" value="view" checked>
                    <label class="btn btn-outline-primary" for="orTierView">View (read-only, redacted)</label>
                    <input type="radio" class="btn-check" name="orAccessTier" id="orTierAssist" value="assist">
                    <label class="btn btn-outline-primary" for="orTierAssist">Assist (can add notes, update status, assign units)</label>
                </div>
                <div class="form-text">A rule's org pair and match target are permanent once saved -- only the
                    tier can be changed later. To change the org pair or what it matches, deactivate this rule
                    and create a new one.</div>
            </div>

            <div class="mt-3 d-flex gap-2">
                <button class="btn btn-primary btn-sm" id="orBtnSave"><i class="bi bi-save me-1"></i>Save Rule</button>
                <button class="btn btn-outline-secondary btn-sm" id="orBtnCancel2">Cancel</button>
            </div>
            <div id="orSaveError" class="alert alert-danger small mt-2 d-none"></div>
        </div>
    </div>

    <!-- ══════════════════ Deactivate confirmation ══════════════════ -->
    <div class="modal fade" id="orDeactivateModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title"><i class="bi bi-slash-circle me-2"></i>Deactivate routing rule?</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body small">
                    <p class="mb-1" id="orDeactivateDesc"></p>
                    <p class="text-body-secondary mb-0">Tickets already shared under this rule <strong>stay shared</strong>
                        -- only future matching tickets stop being routed. This cannot be undone from here; to
                        route again, create a new rule.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-sm btn-danger" id="orBtnConfirmDeactivate">Deactivate</button>
                </div>
            </div>
        </div>
    </div>
</main>

<input type="hidden" id="csrfToken" value="<?php echo e($csrf); ?>">
<script>
    // Display-only flags (see this file's own docblock) — never the
    // authority for whether a write is allowed. Every write is re-checked
    // server-side by api/org-routing.php.
    window.ORTR_CAN_AUTHOR_GLOBAL = <?php echo $canAuthorGlobal ? 'true' : 'false'; ?>;
    window.ORTR_CAN_AUTHOR_ORG = <?php echo $canAuthorOrg ? 'true' : 'false'; ?>;
</script>
<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="assets/js/theme-manager.js?v=<?php echo asset_v('assets/js/theme-manager.js'); ?>"></script>
<script src="assets/js/org-routing-admin.js?v=<?php echo asset_v('assets/js/org-routing-admin.js'); ?>"></script>
</body>
</html>
