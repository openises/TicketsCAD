<?php
/**
 * NewUI v4.0 — Cross-Org Standing Relationships admin (Phase 143, GH#70 Phase 3).
 *
 * Standalone admin page (same precedent as org-routing-admin.php / Phase
 * 140/141/142's admin pages). Backend: api/org-relationships.php.
 *
 * THREE-CODE RBAC:
 *   action.manage_org_relationships       — install-wide (Super Admin only
 *       in this phase's shipped default). Full CRUD over any relationship.
 *   action.manage_org_relationships_org   — org-scoped propose/administer.
 *       Granted to Org Admin and Dispatcher by default (deliberate
 *       departure from Phase 141's own narrower `_org` precedent — see
 *       plan.md's "RBAC" section for why).
 *   action.activate_org_relationship      — activate/deactivate a
 *       relationship the caller's own org is an approved member of.
 *       Granted to Org Admin and Dispatcher by default.
 *
 * This file's gating is DISPLAY ONLY. Every write goes through
 * api/org-relationships.php, which independently re-checks RBAC and
 * re-derives every row-level authorization via
 * org_relationship_can_act_for_org() — never from anything this page
 * renders or the client submits.
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

// Deliberately NOT `rbac_can(...) || is_admin()` -- see api/org-relationships.php's
// identical, already-documented reasoning: is_admin()'s action.manage_config
// fallback can be true for a correctly-scoped Org Admin, and `||`-ing it in
// here would show this page's install-wide controls (and every org's
// relationships) to that Org Admin -- exactly the leak the three-permission
// split, and this project's own standing rule against
// `rbac_can() || is_admin()` on a narrower-tier permission, both exist to
// prevent.
$canActGlobal    = rbac_can('action.manage_org_relationships');
$canManageOrg    = rbac_can('action.manage_org_relationships_org');
$canActivateCode = rbac_can('action.activate_org_relationship');

if (!$canActGlobal && !$canManageOrg && !$canActivateCode) {
    http_response_code(403);
    $theme    = $_SESSION['day_night'] ?? 'Day';
    $bs_theme = ($theme === 'Night') ? 'dark' : 'light';
    ?>
<!DOCTYPE html>
<html lang="<?php echo e(i18n_lang()); ?>" data-bs-theme="<?php echo $bs_theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cross-Org Standing Relationships — Tickets NewUI</title>
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">
</head>
<body>
<main class="container py-5" style="max-width: 640px;">
    <div class="alert alert-warning">
        <h5 class="alert-heading"><i class="bi bi-shield-lock me-2"></i>Permission required</h5>
        <p class="mb-2">Cross-org standing relationships require one of "Manage Cross-Org Standing Relationships",
           "Manage Own Org's Cross-Org Standing Relationships", or "Activate/Deactivate a Cross-Org Standing
           Relationship". Ask an administrator to grant your role <code>action.manage_org_relationships</code>,
           <code>action.manage_org_relationships_org</code>, or <code>action.activate_org_relationship</code>.</p>
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
    <title>Cross-Org Standing Relationships — Tickets NewUI <?php echo newui_version(); ?></title>
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?php echo asset_v('assets/css/dashboard.css'); ?>">
</head>
<body>
<?php include_once NEWUI_ROOT . '/inc/navbar.php'; ?>

<main class="container-fluid py-3" style="max-width: 1200px;">
    <div class="d-flex align-items-center mb-3 flex-wrap gap-2">
        <h4 class="mb-0"><i class="bi bi-diagram-3 text-primary me-2"></i>Cross-Org Standing Relationships</h4>
        <a href="settings.php" class="ms-auto btn btn-sm btn-outline-secondary">
            <i class="bi bi-gear me-1"></i>Settings</a>
    </div>

    <div id="orrToast" class="alert d-none" role="status"></div>

    <p class="text-body-secondary small">
        A standing relationship is a named group of two or more organizations who have all consented (each org's
        own authorized approver signs off, independently) to see each other's tickets while it is active.
        A relationship that <strong>requires activation</strong> grants no visibility on its own -- it must be
        explicitly turned on for a bounded window (a declared event, a mutual-aid callout), and expires
        automatically the instant that window elapses, whether or not anyone is watching.
    </p>

    <!-- ══════════════════ List panel ══════════════════ -->
    <div class="card mb-3" id="orrListPanel">
        <div class="card-header d-flex align-items-center justify-content-between">
            <span><i class="bi bi-list-ul me-2"></i>Relationships</span>
            <button class="btn btn-sm btn-primary" id="orrBtnNew"><i class="bi bi-plus-lg me-1"></i>Propose Relationship</button>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Type</th>
                            <th>Members</th>
                            <th style="width:150px">Tier (access / redact)</th>
                            <th style="width:90px">Status</th>
                            <th style="width:170px">Activation</th>
                            <th style="width:80px" class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="orrListRows"><tr><td colspan="7" class="text-body-secondary">Loading…</td></tr></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ══════════════════ Detail / manage panel ══════════════════ -->
    <div class="card mb-3 d-none" id="orrDetailPanel">
        <div class="card-header d-flex align-items-center justify-content-between">
            <span id="orrDetailTitle"><i class="bi bi-diagram-3 me-2"></i>Relationship</span>
            <button class="btn btn-sm btn-outline-secondary" id="orrBtnBack"><i class="bi bi-arrow-left me-1"></i>Back to list</button>
        </div>
        <div class="card-body">
            <input type="hidden" id="orrDetailId" value="0">

            <div class="row g-2 mb-3 small" id="orrDetailMeta"></div>

            <hr>

            <div class="mb-2 d-flex align-items-center justify-content-between">
                <h6 class="mb-0"><i class="bi bi-people me-1"></i>Member Organizations</h6>
            </div>
            <div class="table-responsive mb-3">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Organization</th>
                            <th style="width:110px">Status</th>
                            <th>Detail</th>
                            <th style="width:170px" class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="orrMemberRows"></tbody>
                </table>
            </div>

            <div class="row g-2 align-items-end mb-3" id="orrAddMemberRow">
                <div class="col-auto">
                    <label class="form-label form-label-sm mb-0" for="orrAddMemberOrg">Add organization</label>
                    <select class="form-select form-select-sm" id="orrAddMemberOrg" style="min-width:220px;"></select>
                </div>
                <div class="col-auto">
                    <button class="btn btn-sm btn-outline-primary" id="orrBtnAddMember"><i class="bi bi-person-plus me-1"></i>Add</button>
                </div>
            </div>

            <hr>

            <div class="mb-2" id="orrActivationBlock"></div>

            <div id="orrDetailError" class="alert alert-danger small mt-2 d-none"></div>
        </div>
    </div>

    <!-- ══════════════════ Propose modal ══════════════════ -->
    <div class="modal fade" id="orrProposeModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title"><i class="bi bi-plus-lg me-2"></i>Propose a Standing Relationship</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label form-label-sm mb-0" for="orrPropName">Name</label>
                            <input type="text" class="form-control form-control-sm" id="orrPropName" maxlength="128" placeholder="e.g. County Mutual Aid Zone 3">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label form-label-sm mb-0" for="orrPropType">Relationship type</label>
                            <select class="form-select form-select-sm" id="orrPropType">
                                <option value="mutual_aid">Mutual aid</option>
                                <option value="escalation">Escalation</option>
                                <option value="backup_dispatch">Backup dispatch</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label form-label-sm mb-1 d-block">Initial member organizations (at least 2)</label>
                        <div class="border rounded p-2" id="orrPropOrgList" style="max-height:220px;overflow-y:auto;"></div>
                        <div class="form-text">If you are proposing on behalf of your own organization, include it here --
                            your own org's row auto-approves immediately; every other named org's row starts pending until
                            that org's own authorized approver consents.</div>
                    </div>

                    <hr>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label form-label-sm mb-1 d-block">Access tier (write capability)</label>
                            <div class="btn-group btn-group-sm w-100" role="group">
                                <input type="radio" class="btn-check" name="orrPropAccessTier" id="orrPropAccessView" value="view" checked>
                                <label class="btn btn-outline-primary" for="orrPropAccessView">View</label>
                                <input type="radio" class="btn-check" name="orrPropAccessTier" id="orrPropAccessAssist" value="assist">
                                <label class="btn btn-outline-primary" for="orrPropAccessAssist">Assist</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label form-label-sm mb-1 d-block">Redaction profile (field visibility)</label>
                            <div class="btn-group btn-group-sm w-100" role="group">
                                <input type="radio" class="btn-check" name="orrPropRedaction" id="orrPropRedactView" value="view" checked>
                                <label class="btn btn-outline-primary" for="orrPropRedactView">View</label>
                                <input type="radio" class="btn-check" name="orrPropRedaction" id="orrPropRedactAssist" value="assist">
                                <label class="btn btn-outline-primary" for="orrPropRedactAssist">Assist</label>
                            </div>
                        </div>
                    </div>
                    <div class="form-text mb-3">These two are independent. A trusted mutual-aid partner might get
                        <strong>Assist</strong> write access (can add notes, assign units) while staying at
                        <strong>View</strong> redaction (patient/caller detail still hidden) -- or any other combination.</div>

                    <div class="row g-3 align-items-center mb-2">
                        <div class="col-auto form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="orrPropRequiresActivation" checked>
                            <label class="form-check-label small" for="orrPropRequiresActivation">Requires explicit activation (time-boxed)</label>
                        </div>
                        <div class="col-auto">
                            <label class="form-label form-label-sm mb-0" for="orrPropCeiling">Max activation minutes (ceiling, optional)</label>
                            <input type="number" min="1" class="form-control form-control-sm" id="orrPropCeiling" style="width:140px;" placeholder="no ceiling">
                        </div>
                    </div>

                    <div id="orrProposeError" class="alert alert-danger small mt-2 d-none"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-sm btn-primary" id="orrBtnSubmitPropose"><i class="bi bi-send me-1"></i>Propose</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ══════════════════ Named-confirmation withdraw/remove modal ══════════════════ -->
    <div class="modal fade" id="orrWithdrawModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title"><i class="bi bi-exclamation-triangle text-danger me-2"></i>Remove organization from relationship?</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body small">
                    <p class="mb-2" id="orrWithdrawDesc"></p>
                    <p class="text-body-secondary mb-2">Removing an approved member ends that org's part in this
                        relationship immediately. Rejecting a pending invitation blocks the whole named group from
                        ever becoming active until it is re-proposed. This cannot be undone from here.</p>
                    <label class="form-label form-label-sm mb-0" for="orrWithdrawConfirmInput">
                        Type <strong id="orrWithdrawOrgNameEcho"></strong> to confirm</label>
                    <input type="text" class="form-control form-control-sm" id="orrWithdrawConfirmInput" autocomplete="off">
                    <label class="form-label form-label-sm mb-0 mt-2" for="orrWithdrawReason">Reason (optional)</label>
                    <input type="text" class="form-control form-control-sm" id="orrWithdrawReason" maxlength="255">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-sm btn-danger" id="orrBtnConfirmWithdraw" disabled>Remove</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ══════════════════ Activate modal ══════════════════ -->
    <div class="modal fade" id="orrActivateModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title"><i class="bi bi-lightning-charge me-2"></i>Activate relationship</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body small">
                    <label class="form-label form-label-sm mb-0" for="orrActivateReason">Reason</label>
                    <input type="text" class="form-control form-control-sm mb-2" id="orrActivateReason" maxlength="255" placeholder="e.g. declared mutual-aid callout">
                    <label class="form-label form-label-sm mb-0" for="orrActivateMinutes">Duration (minutes)</label>
                    <input type="number" min="1" class="form-control form-control-sm" id="orrActivateMinutes">
                    <div class="form-text" id="orrActivateCeilingNote"></div>
                    <div id="orrActivateError" class="alert alert-danger small mt-2 d-none"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-sm btn-success" id="orrBtnConfirmActivate"><i class="bi bi-lightning-charge me-1"></i>Activate</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ══════════════════ Deactivate confirmation ══════════════════ -->
    <div class="modal fade" id="orrDeactivateModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title"><i class="bi bi-slash-circle me-2"></i>Deactivate now?</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body small">
                    <p class="mb-0">The other org(s) will immediately lose visibility into this org's tickets. This is
                        logged in the audit trail.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-sm btn-danger" id="orrBtnConfirmDeactivate">Deactivate</button>
                </div>
            </div>
        </div>
    </div>
</main>

<input type="hidden" id="csrfToken" value="<?php echo e($csrf); ?>">
<script>
    // Display-only flags (see this file's own docblock) — never the
    // authority for whether a write is allowed. Every write is re-checked
    // server-side by api/org-relationships.php.
    window.ORR_CAN_ACT_GLOBAL = <?php echo $canActGlobal ? 'true' : 'false'; ?>;
    window.ORR_CAN_MANAGE_ORG = <?php echo $canManageOrg ? 'true' : 'false'; ?>;
    window.ORR_CAN_ACTIVATE   = <?php echo $canActivateCode ? 'true' : 'false'; ?>;
</script>
<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="assets/js/theme-manager.js?v=<?php echo asset_v('assets/js/theme-manager.js'); ?>"></script>
<script src="assets/js/org-relationships-admin.js?v=<?php echo asset_v('assets/js/org-relationships-admin.js'); ?>"></script>
</body>
</html>
