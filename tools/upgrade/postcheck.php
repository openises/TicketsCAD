<?php
/**
 * Legacy v3.44 → NewUI v4 — post-upgrade verification report.
 *
 * Pure read; produces a diagnostic snapshot suitable for support
 * tickets / change-management documentation. Optional --pre file
 * lets the operator compare row counts vs a snapshot taken before
 * the upgrade (run with --snapshot to write that file).
 *
 * Usage:
 *   php tools/upgrade/postcheck.php                # pretty report
 *   php tools/upgrade/postcheck.php --json         # machine-readable
 *   php tools/upgrade/postcheck.php --snapshot pre.json   # write a baseline
 *   php tools/upgrade/postcheck.php --compare pre.json    # compare to baseline
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../inc/rbac.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

$json     = in_array('--json', $argv, true);
$snapshot = null;
$compare  = null;
foreach ($argv as $i => $arg) {
    if ($arg === '--snapshot' && isset($argv[$i + 1])) $snapshot = $argv[$i + 1];
    if ($arg === '--compare'  && isset($argv[$i + 1])) $compare  = $argv[$i + 1];
}

$counts = [];
foreach (['member','ticket','responder','facilities','user','user_roles',
          'newui_audit_log','permissions','roles','role_permissions',
          'member_time_entries','time_activity_types'] as $t) {
    try {
        $counts[$t] = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}{$t}`");
    } catch (Throwable $e) {
        $counts[$t] = null;
    }
}

if ($snapshot) {
    file_put_contents($snapshot, json_encode([
        'timestamp' => date('c'),
        'counts'    => $counts,
    ], JSON_PRETTY_PRINT));
    echo "Snapshot written: $snapshot\n";
    exit(0);
}

$baseline = [];
if ($compare && file_exists($compare)) {
    $baseline = json_decode((string) file_get_contents($compare), true)['counts'] ?? [];
}

// Schema state
$schema = [];
foreach ([
    ['user_roles', 'scope_kind',          'RBAC v2 schema present'],
    ['user_roles_pre_v2_backup', null,    'Migration backup table'],
    ['permissions', 'deprecated_alias_of','Alias column on permissions'],
    ['roles', 'is_super',                 'is_super flag on roles'],
    ['member_time_entries', 'hours',      'Time tracking schema'],
    ['time_activity_types', 'auto_approve','Auto-approve column'],
] as $check) {
    [$tbl, $col, $label] = $check;
    if ($col === null) {
        try {
            $r = db_fetch_one("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?", [$prefix . $tbl]);
            $schema[$label] = !empty($r);
        } catch (Throwable $e) { $schema[$label] = false; }
    } else {
        try {
            $r = db_fetch_one("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?", [$prefix . $tbl, $col]);
            $schema[$label] = !empty($r);
        } catch (Throwable $e) { $schema[$label] = false; }
    }
}

// Permission matrix
$perm = [];
try {
    $userTotal  = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}user`");
    $usersWithRole = (int) db_fetch_value(
        "SELECT COUNT(DISTINCT user_id) FROM `{$prefix}user_roles`"
    );
    $perm['users_with_role'] = "$usersWithRole / $userTotal";
    $perm['orphan_users'] = $userTotal - $usersWithRole;
} catch (Throwable $e) {
    $perm['error'] = $e->getMessage();
}

// Settings snapshot
$rbacSettings = [];
try {
    $rows = db_fetch_all(
        "SELECT name, value FROM `{$prefix}settings`
         WHERE name LIKE 'rbac.%' OR name = 'tile_mode' OR name LIKE 'smtp.%'"
    );
    foreach ($rows as $r) {
        $rbacSettings[$r['name']] = preg_match('/pass|key|secret/i', $r['name']) ? '(set)' : $r['value'];
    }
} catch (Throwable $e) {}

if ($json) {
    echo json_encode([
        'timestamp' => date('c'),
        'counts'    => $counts,
        'baseline'  => $baseline,
        'schema'    => $schema,
        'perm'      => $perm,
        'settings'  => $rbacSettings,
    ], JSON_PRETTY_PRINT) . "\n";
    exit(0);
}

// Pretty render
echo "\n=== Upgrade verification report — " . date('Y-m-d H:i:s') . " ===\n\n";

echo "Counts" . ($baseline ? " (post / pre)" : "") . ":\n";
foreach ($counts as $tbl => $n) {
    $base = $baseline[$tbl] ?? null;
    if ($n === null) { echo sprintf("  %-22s  (missing)\n", $tbl); continue; }
    if ($base !== null) {
        $delta = $n - $base;
        $marker = $delta === 0 ? '✓' : ($delta > 0 ? '+' . $delta : (string) $delta);
        echo sprintf("  %-22s  %6d  (pre %d)  %s\n", $tbl, $n, $base, $marker);
    } else {
        echo sprintf("  %-22s  %6d\n", $tbl, $n);
    }
}

echo "\nSchema state:\n";
foreach ($schema as $label => $ok) {
    echo sprintf("  %-44s  %s\n", $label, $ok ? '✓' : '✗ MISSING');
}

echo "\nPermission matrix:\n";
foreach ($perm as $k => $v) {
    echo sprintf("  %-22s  %s\n", $k, (string) $v);
}

echo "\nKey settings:\n";
foreach ($rbacSettings as $k => $v) {
    echo sprintf("  %-32s  = %s\n", $k, $v);
}

$problem = $perm['orphan_users'] ?? 0;
foreach ($schema as $ok) if (!$ok) { $problem = max($problem, 1); }

echo "\n";
if ($problem) {
    echo "OVERALL: PROBLEMS DETECTED — investigate before declaring upgrade complete.\n";
    exit(2);
}
echo "OVERALL: HEALTHY — upgrade looks complete.\n";
echo "\nRecommended next steps:\n";
echo "  1. Open https://<your-host>/newui/ and verify the dashboard loads\n";
echo "  2. Schedule the cron job from tools/expire_grants.php (nightly)\n";
echo "  3. Review docs/UPGRADING-FROM-V3.md for cut-over checklist\n";
exit(0);
