<?php
/**
 * NewUI v4.0 — Console Positions Admin (Phase 152, thin position layer)
 *
 * Standalone admin page (same precedent as matrix-admin.php / sip-trunks-
 * admin.php) for `console_positions` — named console seats with a working
 * channel set. Backend: api/console-positions.php; business logic in
 * inc/console-positions.php.
 *
 * Single-tier RBAC — action.manage_positions (admin_only, per the standing
 * RBAC-exclusion-leak lesson). Positions carry no org_id split like the
 * two-tier permissions elsewhere in this codebase — this thin slice has no
 * narrower per-org scope to offer yet (plan.md section 3 explicitly frames
 * org_id as reserved-for-later on the table itself).
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

if (!rbac_can('action.manage_positions')) {
    http_response_code(403);
    $theme    = $_SESSION['day_night'] ?? 'Day';
    $bs_theme = ($theme === 'Night') ? 'dark' : 'light';
    ?>
<!DOCTYPE html>
<html lang="<?php echo e(i18n_lang()); ?>" data-bs-theme="<?php echo $bs_theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Console Positions — Tickets NewUI</title>
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">
</head>
<body>
<main class="container py-5" style="max-width: 640px;">
    <div class="alert alert-warning">
        <h5 class="alert-heading"><i class="bi bi-shield-lock me-2"></i>Permission required</h5>
        <p class="mb-2">Console position management requires the "Manage Console Positions"
           permission. Ask an administrator to grant your role <code>action.manage_positions</code>.</p>
        <a href="console.php" class="btn btn-sm btn-outline-secondary">Back to Console</a>
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
    <title>Console Positions — Tickets NewUI <?php echo newui_version(); ?></title>
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?php echo asset_v('assets/css/dashboard.css'); ?>">
    <link rel="stylesheet" href="assets/css/console.css?v=<?php echo asset_v('assets/css/console.css'); ?>">
</head>
<body>
<?php include_once NEWUI_ROOT . '/inc/navbar.php'; ?>

<main class="container-fluid py-3" style="max-width: 1000px;">
    <div class="d-flex align-items-center mb-3 flex-wrap gap-2">
        <h4 class="mb-0"><i class="bi bi-person-badge text-primary me-2"></i>Console Positions</h4>
        <a href="console.php" class="ms-auto btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Console</a>
        <a href="settings.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-gear me-1"></i>Settings</a>
    </div>

    <div id="cpaToast" class="alert d-none" role="status"></div>

    <div class="alert alert-secondary small mb-3">
        <i class="bi bi-info-circle me-1"></i>
        A <strong>position</strong> is a named seat with its own working channel set — "Dispatch
        1", "Net Control", "Overnight". Any operator with console access can log into any
        position (no reservation, no forced takeover); the console shows who's currently
        seated where. This is a thin presence layer, not full multi-operator supervision —
        there's no admin mute/takeover of another position's audio.
    </div>

    <?php if (rbac_can('action.manage_config')): ?>
    <div class="card mb-3" id="cpaDiscoveryPanel">
        <div class="card-header"><i class="bi bi-broadcast me-2"></i>Acoustic Proximity Auto-Discovery</div>
        <div class="card-body">
            <p class="small text-body-secondary mb-2">
                Lets a dispatcher find a nearby workstation automatically (a brief, near-inaudible
                tone each active desk plays and listens for) instead of picking one by name from a
                list. Install-wide — when off, the search control is simply absent; manual pairing
                on the Console page is never affected either way.
            </p>
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" id="cpaDiscoveryToggle"
                       <?php echo ((string) get_variable('console_beacon_discovery_enabled') !== '0') ? 'checked' : ''; ?>>
                <label class="form-check-label" for="cpaDiscoveryToggle">Enable acoustic proximity auto-discovery</label>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="card mb-3" id="cpaListPanel">
        <div class="card-header d-flex align-items-center justify-content-between">
            <span><i class="bi bi-list-ul me-2"></i>Positions</span>
            <button class="btn btn-sm btn-primary" id="cpaBtnNew"><i class="bi bi-plus-lg me-1"></i>New Position</button>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Label</th>
                            <th>Working Channels</th>
                            <th class="text-end">Sort</th>
                            <th style="width:90px" class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="cpaRows"><tr><td colspan="4" class="text-body-secondary">Loading&hellip;</td></tr></tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<!-- ══════════════════ Create/edit modal ══════════════════ -->
<div class="modal fade" id="cpaModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="cpaModalTitle"><i class="bi bi-plus-lg me-2"></i>New Position</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="cpaId" value="0">
        <div class="mb-2">
            <label class="form-label form-label-sm mb-0" for="cpaLabel">Label</label>
            <input type="text" class="form-control form-control-sm" id="cpaLabel" maxlength="64" placeholder="e.g. Dispatch 1">
        </div>
        <div class="mb-2">
            <label class="form-label form-label-sm mb-0" for="cpaChannels">Working channels</label>
            <select class="form-select form-select-sm" id="cpaChannels" multiple size="8"></select>
            <div class="form-text">Ctrl/Cmd-click to select more than one.</div>
        </div>
        <div class="mb-2">
            <label class="form-label form-label-sm mb-0" for="cpaSort">Sort order</label>
            <input type="number" class="form-control form-control-sm" id="cpaSort" value="0" step="1">
        </div>
        <div id="cpaModalError" class="alert alert-danger small mt-2 d-none"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-danger btn-sm me-auto d-none" id="cpaBtnDelete">
            <i class="bi bi-trash me-1"></i>Delete Position</button>
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary btn-sm" id="cpaBtnSave"><i class="bi bi-save me-1"></i>Save Position</button>
      </div>
    </div>
  </div>
</div>

<input type="hidden" id="csrfToken" value="<?php echo e($csrf); ?>">
<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="assets/js/theme-manager.js?v=<?php echo asset_v('assets/js/theme-manager.js'); ?>"></script>
<script src="assets/js/console-positions-admin.js?v=<?php echo asset_v('assets/js/console-positions-admin.js'); ?>"></script>
</body>
</html>
