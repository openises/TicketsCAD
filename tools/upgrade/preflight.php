<?php
/**
 * Legacy v3.44 → NewUI v4 — preflight checker.
 *
 * Pure read; no mutations. Reports go/no-go for the upgrade.
 * Companion: specs/legacy-upgrade-2026-05/plan.md.
 *
 * Exit codes:
 *   0  All checks pass or warn — safe to proceed.
 *   1  At least one check failed — DO NOT proceed.
 *
 * Usage:
 *   php tools/upgrade/preflight.php
 *   php tools/upgrade/preflight.php --json    (machine-readable output)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config.php';

$json = in_array('--json', $argv, true);

// Capture results — array of [check, status, detail, action].
$results = [];

function check(string $name, callable $fn): void {
    global $results;
    try {
        $r = $fn();
        $results[] = [
            'check'  => $name,
            'status' => $r['status'] ?? 'fail',
            'detail' => $r['detail'] ?? '',
            'action' => $r['action'] ?? '',
        ];
    } catch (Throwable $e) {
        $results[] = [
            'check'  => $name,
            'status' => 'fail',
            'detail' => 'exception: ' . $e->getMessage(),
            'action' => 'See PHP error log',
        ];
    }
}

// 1. PHP version
check('PHP version', function () {
    $ver = PHP_VERSION;
    $major = PHP_MAJOR_VERSION;
    $minor = PHP_MINOR_VERSION;
    if ($major < 8) {
        return ['status' => 'fail', 'detail' => $ver,
                'action' => 'Upgrade PHP to 8.0+ before proceeding (8.2+ recommended)'];
    }
    if ($major === 8 && $minor < 2) {
        return ['status' => 'warn', 'detail' => $ver,
                'action' => 'Works, but PHP 8.2+ is recommended for performance'];
    }
    return ['status' => 'pass', 'detail' => $ver, 'action' => ''];
});

// 2. Required PHP extensions
check('PHP extensions', function () {
    $required = ['pdo_mysql','openssl','mbstring','json','zip'];
    $missing  = array_filter($required, fn($e) => !extension_loaded($e));
    if ($missing) {
        return ['status' => 'fail',
                'detail' => 'missing: ' . implode(', ', $missing),
                'action' => 'Enable the missing extensions in php.ini'];
    }
    return ['status' => 'pass', 'detail' => 'all required loaded', 'action' => ''];
});

// 3. DB connection
check('Database connection', function () {
    if (!function_exists('db_query')) {
        return ['status' => 'fail', 'detail' => 'db helpers not loaded',
                'action' => 'Verify config.php is correct'];
    }
    $row = db_fetch_one("SELECT VERSION() AS v");
    return ['status' => 'pass', 'detail' => 'MariaDB/MySQL ' . ($row['v'] ?? '?'), 'action' => ''];
});

// 4. DB version
check('DB engine version', function () {
    $row = db_fetch_one("SELECT VERSION() AS v");
    $v = (string) ($row['v'] ?? '');
    // Accept MariaDB 10.3+ or MySQL 5.7+
    if (preg_match('/^(\d+)\.(\d+)/', $v, $m)) {
        $maj = (int) $m[1]; $min = (int) $m[2];
        $ok = ($maj >= 10 && $min >= 3) || ($maj >= 8) || ($maj === 5 && $min >= 7);
        if (!$ok) {
            return ['status' => 'fail', 'detail' => $v,
                    'action' => 'Upgrade to MariaDB 10.3+ or MySQL 5.7+'];
        }
        return ['status' => 'pass', 'detail' => $v, 'action' => ''];
    }
    return ['status' => 'warn', 'detail' => $v, 'action' => 'Could not parse — proceed with caution'];
});

// 5. Required legacy tables
check('Legacy tables present', function () {
    $required = ['user','member','ticket','responder','facilities','action','assigns','allocates','log','settings'];
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $missing = [];
    foreach ($required as $t) {
        try {
            $row = db_fetch_one(
                "SELECT TABLE_NAME FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
                [$prefix . $t]
            );
            if (empty($row)) $missing[] = $t;
        } catch (Throwable $e) { $missing[] = $t; }
    }
    if ($missing) {
        return ['status' => 'fail',
                'detail' => 'missing: ' . implode(', ', $missing),
                'action' => 'Restore from a recent backup or contact support'];
    }
    return ['status' => 'pass', 'detail' => count($required) . ' tables found', 'action' => ''];
});

// 6. Estimate data volume — informational only
check('Data volume', function () {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $members = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}member`");
        $tickets = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}ticket`");
        $users   = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}user`");
        $est     = ((int) (($tickets + $members + $users) / 1000)) * 5 + 30;  // crude seconds estimate
        return ['status' => 'pass',
                'detail' => "{$members} members, {$tickets} tickets, {$users} users",
                'action' => "Estimated migration time: ~{$est} seconds"];
    } catch (Throwable $e) {
        return ['status' => 'warn', 'detail' => 'count failed', 'action' => 'Tables may be partially missing'];
    }
});

// 7. Disk space (rough — check newui/ free space if we can)
check('Disk free for backup', function () {
    $base = realpath(__DIR__ . '/../..') ?: __DIR__ . '/../..';
    $free = @disk_free_space($base);
    $used = 0;
    if (is_dir($base . '/uploads')) {
        $cmd = PHP_OS_FAMILY === 'Windows' ? null : "du -sb " . escapeshellarg($base . '/uploads') . " 2>/dev/null | cut -f1";
        if ($cmd) {
            $out = trim((string) @shell_exec($cmd));
            if ($out !== '') $used = (int) $out;
        }
    }
    if ($free === false) {
        return ['status' => 'warn', 'detail' => 'could not measure', 'action' => 'Verify backup target has 2× uploads space'];
    }
    $needed = max($used * 2, 100 * 1024 * 1024);  // at least 100MB
    if ($free < $needed) {
        return ['status' => 'fail',
                'detail' => sprintf('free %.1fMB, need %.1fMB', $free / 1048576, $needed / 1048576),
                'action' => 'Free up disk space before proceeding'];
    }
    return ['status' => 'pass',
            'detail' => sprintf('free %.1fMB', $free / 1048576),
            'action' => ''];
});

// 8. RBAC v2 schema state — informational
check('RBAC v2 schema state', function () {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $row = db_fetch_one(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'scope_kind'",
            [$prefix . 'user_roles']
        );
        if (!empty($row)) {
            return ['status' => 'pass', 'detail' => 'already applied — re-running is idempotent', 'action' => ''];
        }
        return ['status' => 'pass', 'detail' => 'will be applied during upgrade', 'action' => ''];
    } catch (Throwable $e) {
        return ['status' => 'warn', 'detail' => 'could not query', 'action' => 'Will be installed during upgrade'];
    }
});

// 9. Time zone alignment between PHP and DB
check('Timezone alignment', function () {
    $phpTz = date_default_timezone_get();
    $dbTz  = '?';
    try {
        $row = db_fetch_one("SELECT @@session.time_zone AS tz");
        $dbTz = (string) ($row['tz'] ?? '?');
    } catch (Throwable $e) {}
    // SYSTEM means DB uses host clock; that may or may not match PHP.
    // We just inform — RBAC stores expires_at via FROM_UNIXTIME so works either way.
    return ['status' => 'pass',
            'detail' => "PHP={$phpTz}, DB={$dbTz}",
            'action' => 'Mismatch is OK — RBAC stores via FROM_UNIXTIME'];
});

// ── Render ─────────────────────────────────────────────────────────
$any_fail = false;
$any_warn = false;
foreach ($results as $r) {
    if ($r['status'] === 'fail') $any_fail = true;
    elseif ($r['status'] === 'warn') $any_warn = true;
}

if ($json) {
    echo json_encode([
        'overall' => $any_fail ? 'fail' : ($any_warn ? 'warn' : 'pass'),
        'checks'  => $results,
    ], JSON_PRETTY_PRINT) . "\n";
} else {
    echo "\n=== TicketsCAD upgrade preflight ===\n\n";
    foreach ($results as $r) {
        $icon = ['pass' => '✓', 'warn' => '!', 'fail' => '✗'][$r['status']] ?? '?';
        echo sprintf("  [%s] %-28s %s\n", $icon, $r['check'], $r['detail']);
        if ($r['action'] !== '') echo "       → " . $r['action'] . "\n";
    }
    echo "\n";
    if ($any_fail) {
        echo "OVERALL: FAIL — do not run the upgrade until the failures above are resolved.\n";
    } elseif ($any_warn) {
        echo "OVERALL: WARN — review warnings; safe to proceed if you accept them.\n";
    } else {
        echo "OVERALL: PASS — safe to proceed with the upgrade.\n";
    }
}

exit($any_fail ? 1 : 0);
