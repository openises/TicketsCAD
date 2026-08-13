<?php
/**
 * NewUI v4.0 — Public Incident Board admin (Phase 138, Section G).
 *
 * Standalone admin page (same precedent as weather-alerts.php / voice-speech.php
 * — the feature's config surface is large enough to warrant its own page rather
 * than a settings.php tab). Backend: api/public-board-admin.php.
 *
 * TWO-TIER RBAC (plan.md §4 / §9) — this page is reachable by EITHER
 * permission, but the panels each holder sees differ:
 *
 *   action.manage_public_board      — install-wide (Super Admin only).
 *       Sees ALL FOUR panels: master switch + precision, organizations,
 *       incident type rules, rate limiting.
 *   action.manage_public_board_org  — org-scoped self-service (Super Admin
 *       + Org Admin). Sees ONLY the organizations panel, filtered to their
 *       own org.
 *
 * This file's gating is DISPLAY ONLY. Every write this page's JS makes goes
 * through api/public-board-admin.php, which independently re-checks both the
 * permission AND (for the organizations panel) forces the target org id from
 * the session — never from anything this page renders or the client submits.
 * See that file's docblock and pb_resolve_admin_write_org() in
 * inc/public-board.php for the actual enforcement. Do not treat hiding a
 * panel here as a security control by itself.
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

// Deliberately NOT `rbac_can('action.manage_public_board') || is_admin()`
// — see the detailed explanation in api/public-board-admin.php (the same
// computation must match on both sides: this is DISPLAY gating, that file
// is the ENFORCEMENT, and they must agree on who counts as "board admin").
// Short version: is_admin() also returns true for anyone holding
// action.manage_config (its own documented fallback), which an Org Admin
// can end up holding — verified live during this page's build — and that
// would show this page's install-wide panels to an Org Admin, exactly the
// cross-org exposure the two-permission split (security review finding #1)
// exists to prevent. rbac_can('action.manage_public_board') alone already
// reaches every real Super Admin via its own internal is_super
// short-circuit, so nothing legitimate is lost by dropping is_admin() here.
$isBoardAdmin = rbac_can('action.manage_public_board');
$isOrgSelf    = rbac_can('action.manage_public_board_org');

if (!$isBoardAdmin && !$isOrgSelf) {
    http_response_code(403);
    $theme    = $_SESSION['day_night'] ?? 'Day';
    $bs_theme = ($theme === 'Night') ? 'dark' : 'light';
    ?>
<!DOCTYPE html>
<html lang="<?php echo e(i18n_lang()); ?>" data-bs-theme="<?php echo $bs_theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Public Incident Board — Tickets NewUI</title>
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">
</head>
<body>
<main class="container py-5" style="max-width: 640px;">
    <div class="alert alert-warning">
        <h5 class="alert-heading"><i class="bi bi-shield-lock me-2"></i>Permission required</h5>
        <p class="mb-2">Public Incident Board configuration requires either the "Manage Public Board" or the
           "Manage Public Board (own org)" permission. Ask an administrator to grant your role
           <code>action.manage_public_board</code> or <code>action.manage_public_board_org</code>.</p>
        <a href="index.php" class="btn btn-sm btn-outline-secondary">Back to dashboard</a>
    </div>
</main>
</body>
</html>
    <?php
    exit;
}

$user        = e($_SESSION['user']);
$theme       = $_SESSION['day_night'] ?? 'Day';
$bs_theme    = ($theme === 'Night') ? 'dark' : 'light';
$csrf        = csrf_token();
?>
<!DOCTYPE html>
<html lang="<?php echo e(i18n_lang()); ?>" data-bs-theme="<?php echo $bs_theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?php echo e($csrf); ?>">
    <title>Public Incident Board — Tickets NewUI <?php echo newui_version(); ?></title>
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?php echo asset_v('assets/css/dashboard.css'); ?>">
</head>
<body>
<?php include_once NEWUI_ROOT . '/inc/navbar.php'; ?>

<main class="container-fluid py-3" style="max-width: 1100px;">
    <div class="d-flex align-items-center mb-3 flex-wrap gap-2">
        <h4 class="mb-0"><i class="bi bi-broadcast text-warning me-2"></i>Public Incident Board</h4>
        <span class="badge bg-secondary ms-3" id="pbMasterBadge">loading…</span>
        <a href="public-board.php" target="_blank" class="ms-auto btn btn-sm btn-outline-secondary">
            <i class="bi bi-box-arrow-up-right me-1"></i>View shared board</a>
        <a href="docs/PUBLIC-INCIDENT-BOARD.md" target="_blank" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-question-circle me-1"></i>Guide</a>
        <a href="settings.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Settings</a>
    </div>

    <div id="pbToast" class="alert d-none" role="status"></div>

    <?php if (!$isBoardAdmin && $isOrgSelf): ?>
    <div class="alert alert-info small">
        <i class="bi bi-info-circle me-1"></i>
        Your role can publish <strong>your own organization's</strong> public board URL below. Install-wide
        settings (the shared board's master switch, address precision, incident type rules, and rate limiting)
        require the "Manage Public Board" permission — ask a Super Admin.
    </div>
    <?php endif; ?>

    <?php if ($isBoardAdmin): ?>
    <!-- ══════════════════ Panel 1: Master switch + address precision ══════════════════ -->
    <div class="card mb-3" id="pbPanelMaster">
        <div class="card-header"><i class="bi bi-toggles me-2"></i>Master switch &amp; address precision</div>
        <div class="card-body">
            <p class="text-body-secondary small mb-3">
                The shared public board is <strong>off by default</strong>. Turning it on publishes eligible
                open incidents — redacted per the rules configured below and in the Incident Type Rules and
                Security Labels panels — to <code>public-board.php</code> for anyone on the internet to see,
                with no login required.
            </p>
            <div class="row g-3">
                <div class="col-md-4">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="pbEnabled">
                        <label class="form-check-label" for="pbEnabled">Enable the shared public board</label>
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label form-label-sm mb-0">Address precision ceiling</label>
                    <select class="form-select form-select-sm" id="pbPrecision">
                        <option value="exact">Exact — full street address</option>
                        <option value="block">Block — street name only (≈110m)</option>
                        <option value="city">City — city/state only (≈1.1km)</option>
                        <option value="hidden">Hidden — no address or map pin at all</option>
                    </select>
                    <div class="form-text">A Security Label on an individual incident can only make this
                        <em>coarser</em>, never finer.</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label form-label-sm mb-0">Default publish delay (seconds)</label>
                    <input type="number" min="0" class="form-control form-control-sm" id="pbDelay" value="90">
                    <div class="form-text">Applies to any incident type with no override in the Incident Type
                        Rules panel below. This is a dispatcher's only reaction window before a sensitive call
                        publishes — don't zero it out to "fix" perceived latency without understanding that
                        tradeoff.</div>
                </div>
                <div class="col-12">
                    <div class="border rounded p-2 small bg-body-tertiary">
                        <strong>Live example</strong> — a <code>123 Main St, your deployment, MN</code> incident
                        would show as: <span id="pbPrecisionPreview" class="fw-semibold"></span>
                        <div class="text-body-secondary mt-1">Preview only — the server always re-applies this
                            rule independently, and a Security Label may cap it further per incident.</div>
                    </div>
                </div>
            </div>
            <div class="mt-3">
                <button class="btn btn-sm btn-primary" id="pbSaveMaster"><i class="bi bi-save me-1"></i>Save settings</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ══════════════════ Panel 2: Organizations ══════════════════ -->
    <div class="card mb-3" id="pbPanelOrgs">
        <div class="card-header"><i class="bi bi-building me-2"></i>Organizations — per-org board URL</div>
        <div class="card-body">
            <p class="text-body-secondary small">
                Each organization can opt into its own public board URL, filtered to only that organization's
                incidents (<code>ticket.org_id</code>). This is independent of the shared board's master switch
                above — an org can publish its own URL whether or not the shared board is enabled.
            </p>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>Organization</th><th>Publish</th><th>URL slug</th><th>Public URL</th><th></th><th></th></tr></thead>
                    <tbody id="pbOrgRows"><tr><td colspan="6" class="text-body-secondary">Loading…</td></tr></tbody>
                </table>
            </div>
        </div>
    </div>

    <?php if ($isBoardAdmin): ?>
    <!-- ══════════════════ Panel 3: Incident type rules ══════════════════ -->
    <div class="card mb-3" id="pbPanelTypes">
        <div class="card-header"><i class="bi bi-list-check me-2"></i>Incident type rules</div>
        <div class="card-body">
            <p class="text-body-secondary small mb-2">
                Rows highlighted <span class="badge bg-warning text-dark">amber</span> matched the
                medical/sensitive keyword list at migration time (or still match it) and are still set to
                Full visibility — review these before enabling the shared board.
            </p>
            <div class="row g-2 align-items-end mb-3">
                <div class="col-md-8">
                    <label class="form-label form-label-sm mb-0">Excluded groups (hard-excluded regardless of a type's own flag)</label>
                    <select multiple class="form-select form-select-sm" id="pbExcludedGroups" size="4"></select>
                    <div class="form-text">Ctrl/Cmd-click to select multiple groups.</div>
                </div>
                <div class="col-md-4">
                    <button class="btn btn-sm btn-primary" id="pbSaveExcluded"><i class="bi bi-save me-1"></i>Save excluded groups</button>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Type</th>
                            <th class="text-center">Never publish</th>
                            <th>Delay override (s)</th>
                            <th>Visibility</th>
                            <th>Stub label</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="pbTypeRows"><tr><td colspan="6" class="text-body-secondary">Loading…</td></tr></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ══════════════════ Panel 4: Rate limiting ══════════════════ -->
    <div class="card mb-4" id="pbPanelRate">
        <div class="card-header"><i class="bi bi-speedometer me-2"></i>Rate limiting</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label form-label-sm mb-0">Requests</label>
                    <input type="number" min="1" class="form-control form-control-sm" id="pbRlRequests" value="30">
                </div>
                <div class="col-md-4">
                    <label class="form-label form-label-sm mb-0">Per window (seconds)</label>
                    <input type="number" min="1" class="form-control form-control-sm" id="pbRlWindow" value="60">
                </div>
            </div>
            <div class="form-text mt-2">
                If the rate limiter itself can't be reached, requests are allowed rather than the board going
                dark — the same fail-open behavior every other rate-limited endpoint in this app uses.
            </div>
            <div class="alert alert-warning small mt-3 mb-0">
                <i class="bi bi-exclamation-triangle me-1"></i>
                Configure <strong>Trusted Proxies</strong> under Network settings before enabling this board
                behind any reverse proxy or CDN — otherwise every visitor may resolve to the proxy's own IP
                address and share a single rate-limit bucket.
            </div>
            <div class="mt-3">
                <button class="btn btn-sm btn-primary" id="pbSaveRate"><i class="bi bi-save me-1"></i>Save rate limits</button>
            </div>
        </div>
    </div>
    <?php endif; ?>
</main>

<!-- ══════════════════ Pre-enable warning modal (tasks.md G1b / §0b) ══════════════════
     Required-checkbox gate, not a dismissible banner and not a hard block (decided
     2026-08-13, tasks.md §0b). The server independently re-checks this same condition
     in api/public-board-admin.php's save_settings handler — this modal is a UX
     convenience, never the authority. -->
<div class="modal fade" id="pbSensitiveModal" tabindex="-1" aria-hidden="true" aria-labelledby="pbSensitiveModalLabel">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="pbSensitiveModalLabel">
                    <i class="bi bi-exclamation-triangle text-warning me-2"></i>Review before enabling</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p id="pbSensitiveIntro" class="mb-2"></p>
                <ul id="pbSensitiveList" class="small mb-3"></ul>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="pbSensitiveAck">
                    <label class="form-check-label" for="pbSensitiveAck">
                        I have reviewed the incident types listed above and confirm they should show full
                        detail publicly.
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="pbSensitiveConfirm" disabled>Enable board</button>
            </div>
        </div>
    </div>
</div>

<input type="hidden" id="csrfToken" value="<?php echo e($csrf); ?>">
<script>
    // Display-only flags (see this file's own docblock) — the JS uses these
    // to decide which panels to fetch/wire up, never as the authority for
    // whether a write is allowed. The real check is server-side on every
    // request to api/public-board-admin.php.
    window.PB_IS_BOARD_ADMIN = <?php echo $isBoardAdmin ? 'true' : 'false'; ?>;
    window.PB_IS_ORG_SELF = <?php echo $isOrgSelf ? 'true' : 'false'; ?>;
</script>
<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="assets/js/theme-manager.js?v=<?php echo asset_v('assets/js/theme-manager.js'); ?>"></script>
<script src="assets/js/public-board-admin.js?v=<?php echo asset_v('assets/js/public-board-admin.js'); ?>"></script>
</body>
</html>
