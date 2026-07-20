<?php
/**
 * NewUI v4.0 — Migrations Status (Phase 14b, 2026-06-11)
 *
 * READ-ONLY dashboard of the install's database-migration state.
 * Shows every sql/run_*.php on disk + each row in the _migrations
 * tracking table + per-row preview of pending scripts (first 60 lines).
 *
 * Deliberate non-feature: there is NO Run button. Triggering schema
 * changes from a web request opens up an attack surface (a hijacked
 * admin session could alter the DB) and runs into PHP timeout limits
 * that don't apply to CLI. Apply happens via:
 *
 *   sudo -u www-data php sql/run_migrations.php
 *
 * This page exists to (a) make pending migrations DISCOVERABLE without
 * needing shell access just to check, and (b) let an admin preview
 * what each pending script does before SSHing to run it.
 *
 * Super-admin only.
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

// Super-admin gate — Org Admin should not see DB internals.
if (!is_admin()) {
    header('Location: index.php?err=admin_required');
    exit;
}

$user     = e($_SESSION['user']);
$level    = current_role_name();
$theme    = $_SESSION['day_night'] ?? 'Day';
$bs_theme = ($theme === 'Night') ? 'dark' : 'light';
$csrf     = csrf_token();
$active_page = 'migrations';
$prefix   = $GLOBALS['db_prefix'] ?? '';

// Build the table data server-side so the page renders fully even with
// JS disabled. The /api/migrations-check.php endpoint exists but does
// less detail than what's useful here.
$migDir = __DIR__ . '/sql';
$files = glob($migDir . '/run_*.php');
$skip = ['run_migrations.php'];

$onDisk = [];
foreach ($files as $path) {
    $name = basename($path);
    if (in_array($name, $skip, true)) continue;
    $onDisk[$name] = [
        'path' => $path,
        'hash' => hash_file('sha256', $path),
        'size' => filesize($path),
        'mtime' => filemtime($path),
    ];
}
ksort($onDisk);

// What's recorded as applied?
$alreadyApplied = [];
$trackingTablePresent = false;
try {
    $rows = db_fetch_all(
        "SELECT script_name, script_hash, status, applied_at,
                applied_by, duration_ms, notes
         FROM `{$prefix}_migrations`
         ORDER BY applied_at DESC"
    );
    $trackingTablePresent = true;
    foreach ($rows as $r) {
        $key = $r['script_name'] . '|' . $r['script_hash'];
        // Most-recent wins (we ORDER BY applied_at DESC above so the
        // first occurrence is the latest).
        if (!isset($alreadyApplied[$key])) {
            $alreadyApplied[$key] = $r;
        }
    }
} catch (Exception $e) {
    $trackingTablePresent = false;
}

// Classify each migration.
$entries = [];
$counts = ['applied' => 0, 'pending' => 0, 'failed' => 0, 'changed' => 0];
foreach ($onDisk as $name => $m) {
    $key = $name . '|' . $m['hash'];
    $entry = [
        'name'  => $name,
        'path'  => $m['path'],
        'hash'  => $m['hash'],
        'size'  => $m['size'],
        'mtime' => $m['mtime'],
        'status'      => 'pending',  // default
        'applied_at'  => null,
        'applied_by'  => null,
        'duration_ms' => null,
        'note'        => '',
    ];
    if (!$trackingTablePresent) {
        $entry['status'] = 'pending';
        $entry['note']   = 'tracking table missing — orchestrator has never run';
        $counts['pending']++;
    } elseif (isset($alreadyApplied[$key])) {
        if ($alreadyApplied[$key]['status'] === 'failed') {
            $entry['status'] = 'failed';
            $counts['failed']++;
        } else {
            $entry['status'] = 'applied';
            $counts['applied']++;
        }
        $entry['applied_at']  = $alreadyApplied[$key]['applied_at'];
        $entry['applied_by']  = $alreadyApplied[$key]['applied_by'];
        $entry['duration_ms'] = $alreadyApplied[$key]['duration_ms'];
    } else {
        // Not applied with current hash. Was a prior hash applied?
        $priorHashes = [];
        foreach ($alreadyApplied as $k => $r) {
            if (strpos($k, $name . '|') === 0) $priorHashes[] = $r;
        }
        if (!empty($priorHashes)) {
            $entry['status'] = 'changed';
            $entry['note']   = 'script edited since last apply';
            $counts['changed']++;
            $entry['applied_at'] = $priorHashes[0]['applied_at'] ?? null;
        } else {
            $entry['status'] = 'pending';
            $entry['note']   = 'never applied';
            $counts['pending']++;
        }
    }
    $entries[] = $entry;
}
?>
<!DOCTYPE html>
<html lang="<?php echo e(i18n_lang()); ?>" data-bs-theme="<?php echo $bs_theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?php echo e($csrf); ?>">
    <title>Database Migrations — <?php echo e(t('login.title', 'Tickets NewUI')); ?> <?php echo NEWUI_VERSION; ?></title>
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?php echo asset_v('assets/css/dashboard.css'); ?>">
    <link rel="stylesheet" href="assets/css/config.css?v=<?php echo asset_v('assets/css/config.css'); ?>">
    <style>
        .mig-status-applied { color: var(--bs-success); }
        .mig-status-pending { color: var(--bs-warning); }
        .mig-status-failed  { color: var(--bs-danger); }
        .mig-status-changed { color: var(--bs-info); }
        .mig-preview pre {
            max-height: 360px;
            overflow: auto;
            background: var(--bs-tertiary-bg);
            padding: 0.5rem;
            font-size: 0.78rem;
            border-radius: 0.25rem;
        }
        .mig-hash { font-family: var(--bs-font-monospace); font-size: 0.72rem; color: var(--bs-secondary); }
    </style>
</head>
<body>

<?php include_once NEWUI_ROOT . '/inc/navbar.php'; ?>
</header>

<div class="config-layout">
    <?php $configActivePage = 'migrations'; include_once NEWUI_ROOT . '/inc/config-sidebar.php'; ?>

    <main class="config-content" id="configContent" style="padding:1rem 1.5rem;">

    <div class="d-flex align-items-center justify-content-between mb-3">
        <h5 class="mb-0">
            <i class="bi bi-database-gear text-primary me-2"></i>Database Migrations
        </h5>
        <div class="d-flex gap-2">
            <a href="docs/INSTALL.md" target="_blank" class="btn btn-sm btn-outline-info">
                <i class="bi bi-book me-1"></i>Install Guide
            </a>
        </div>
    </div>

    <!-- Status summary -->
    <div class="row g-2 mb-3">
        <div class="col-md-3">
            <div class="card border-success">
                <div class="card-body py-2">
                    <div class="small text-body-secondary">Applied</div>
                    <div class="display-6 text-success"><?php echo $counts['applied']; ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-warning">
                <div class="card-body py-2">
                    <div class="small text-body-secondary">Pending</div>
                    <div class="display-6 text-warning"><?php echo $counts['pending']; ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-info">
                <div class="card-body py-2">
                    <div class="small text-body-secondary">Edited since last apply</div>
                    <div class="display-6 text-info"><?php echo $counts['changed']; ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-danger">
                <div class="card-body py-2">
                    <div class="small text-body-secondary">Failed</div>
                    <div class="display-6 text-danger"><?php echo $counts['failed']; ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- This is the load-bearing explanation. By design, this page is
         read-only — admins cannot trigger migrations from the web. The
         tradeoff is documented at the top of this file. -->
    <div class="alert alert-info small">
        <div class="d-flex">
            <i class="bi bi-shield-lock-fill me-2 fs-5"></i>
            <div>
                <strong>This page is read-only by design.</strong>
                Applying migrations from a web request opens up an attack
                surface that web sessions shouldn't have (a hijacked admin
                session could change the database schema), and runs into
                PHP timeout limits that don't apply to the CLI.
                <br>
                To <strong>apply</strong> pending migrations, SSH to the
                server and run:
                <code class="ms-1 px-1 bg-body-secondary">sudo -u www-data php sql/run_migrations.php</code>
                <br>
                The full install + upgrade procedure is in
                <a href="docs/INSTALL.md" target="_blank">docs/INSTALL.md</a>.
            </div>
        </div>
    </div>

    <?php if (!$trackingTablePresent): ?>
    <div class="alert alert-warning">
        <strong>The <code>_migrations</code> tracking table is missing.</strong>
        This install has never been run through the master migration
        orchestrator. SSH and run <code>php sql/run_migrations.php</code> once
        to bring the database up to date and start tracking.
    </div>
    <?php endif; ?>

    <!-- The full migration list. Each row's preview button uncollapses
         the first 60 lines of the script so admins can read what it does
         before deciding to SSH. -->
    <div class="card mb-3">
        <div class="card-header py-2">
            <strong>All migrations on disk</strong>
            <span class="text-body-secondary small ms-2">(<?php echo count($entries); ?> files)</span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead>
                    <tr>
                        <th style="width:30px"></th>
                        <th>Script</th>
                        <th class="text-center" style="width:90px">Status</th>
                        <th style="width:160px">Applied</th>
                        <th style="width:100px" class="text-end">Duration</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($entries as $i => $e):
                        $statusLabel = ucfirst($e['status']);
                        $statusIcon  = [
                            'applied' => 'bi-check-circle-fill',
                            'pending' => 'bi-hourglass-split',
                            'failed'  => 'bi-x-octagon-fill',
                            'changed' => 'bi-arrow-clockwise',
                        ][$e['status']] ?? 'bi-question-circle';
                    ?>
                    <tr class="<?php echo $e['status'] === 'pending' || $e['status'] === 'failed' || $e['status'] === 'changed' ? 'table-warning' : ''; ?>">
                        <td>
                            <button class="btn btn-sm btn-link p-0" type="button"
                                    data-bs-toggle="collapse" data-bs-target="#preview-<?php echo $i; ?>"
                                    title="Preview script content">
                                <i class="bi bi-chevron-right"></i>
                            </button>
                        </td>
                        <td>
                            <div class="font-monospace small"><?php echo e($e['name']); ?></div>
                            <div class="mig-hash">sha256: <?php echo substr($e['hash'], 0, 24); ?>… &middot; <?php echo number_format($e['size']); ?> bytes</div>
                            <?php if ($e['note']): ?>
                                <div class="small text-body-secondary"><?php echo e($e['note']); ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <span class="mig-status-<?php echo $e['status']; ?>">
                                <i class="bi <?php echo $statusIcon; ?>"></i>
                                <?php echo $statusLabel; ?>
                            </span>
                        </td>
                        <td class="small">
                            <?php if ($e['applied_at']): ?>
                                <?php echo e($e['applied_at']); ?>
                                <?php if ($e['applied_by']): ?>
                                    <div class="text-body-secondary">by <?php echo e($e['applied_by']); ?></div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-body-secondary">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end small">
                            <?php if ($e['duration_ms'] !== null): ?>
                                <?php echo number_format($e['duration_ms']); ?> ms
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr class="collapse mig-preview" id="preview-<?php echo $i; ?>">
                        <td colspan="5" class="bg-body-tertiary">
                            <div class="small mb-1 text-body-secondary">
                                <?php echo e($e['path']); ?> — first 60 lines:
                            </div>
                            <pre><?php
                                // First 60 lines for the preview. Don't read
                                // the whole file — some migrations bundle
                                // big seed payloads.
                                $fh = @fopen($e['path'], 'r');
                                if ($fh) {
                                    $lineNum = 0;
                                    while (!feof($fh) && $lineNum < 60) {
                                        $line = fgets($fh);
                                        if ($line === false) break;
                                        echo str_pad((string)(++$lineNum), 3, ' ', STR_PAD_LEFT) . '  ' . e(rtrim($line, "\n")) . "\n";
                                    }
                                    fclose($fh);
                                    if (!feof($fh)) echo "... (truncated)\n";
                                } else {
                                    echo '(unable to read file)';
                                }
                            ?></pre>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    </main>
</div>

<input type="hidden" id="csrfToken" value="<?php echo e($csrf); ?>">
<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script>
// Rotate the chevron when a preview opens / closes. Bootstrap fires
// shown.bs.collapse on expand, hidden.bs.collapse on collapse.
document.querySelectorAll('.mig-preview').forEach(function (row) {
    row.addEventListener('shown.bs.collapse', function () {
        var trigger = document.querySelector('[data-bs-target="#' + row.id + '"] i');
        if (trigger) trigger.classList.replace('bi-chevron-right', 'bi-chevron-down');
    });
    row.addEventListener('hidden.bs.collapse', function () {
        var trigger = document.querySelector('[data-bs-target="#' + row.id + '"] i');
        if (trigger) trigger.classList.replace('bi-chevron-down', 'bi-chevron-right');
    });
});
</script>
</body>
</html>
