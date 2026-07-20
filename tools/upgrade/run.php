<?php
/**
 * Legacy v3.44 → NewUI v4 — upgrade orchestrator.
 *
 * Single-command upgrade. Runs preflight, takes a backup, applies all
 * NewUI schema migrations, migrates legacy levels to RBAC roles,
 * translates settings, runs a smoke test, and prints a verification
 * report. Stops on first failure.
 *
 * Usage:
 *   php tools/upgrade/run.php                  # interactive (default)
 *   php tools/upgrade/run.php --skip-backup    # don't take a backup (testing only)
 *   php tools/upgrade/run.php --no-confirm     # don't prompt before destructive step
 *
 * Each step writes to tools/upgrade/upgrade-<timestamp>.log. The
 * orchestrator quotes the log path on failure so the operator can
 * attach it to support tickets.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config.php';

$skipBackup = in_array('--skip-backup', $argv, true);
$noConfirm  = in_array('--no-confirm',  $argv, true);

$ts        = date('Ymd-His');
$logPath   = __DIR__ . '/upgrade-' . $ts . '.log';
$logHandle = fopen($logPath, 'w');

function logmsg(string $line): void {
    global $logHandle;
    $stamped = '[' . date('H:i:s') . '] ' . $line;
    echo $stamped . "\n";
    if ($logHandle) fwrite($logHandle, $stamped . "\n");
}

function step(int $n, int $total, string $name, callable $fn): void {
    logmsg(sprintf("Step %d/%d — %s", $n, $total, $name));
    $start = microtime(true);
    try {
        $fn();
        $ms = (int) ((microtime(true) - $start) * 1000);
        logmsg(sprintf("  done in %dms", $ms));
    } catch (Throwable $e) {
        logmsg("  FAILED: " . $e->getMessage());
        logmsg("  See log: $GLOBALS[logPath]");
        logmsg("  Rollback procedure: tools/upgrade/ROLLBACK.md");
        exit(1);
    }
}

$total = 8;

logmsg("=== TicketsCAD upgrade orchestrator ===");
logmsg("Log file: " . realpath($logPath));
logmsg('');

// Step 1 — Preflight
step(1, $total, 'preflight', function () {
    $rc = 0;
    passthru('"' . PHP_BINARY . '" "' . __DIR__ . '/preflight.php"', $rc);
    if ($rc !== 0) throw new RuntimeException('preflight reported failure');
});

// Step 2 — Backup (unless skipped)
if (!$skipBackup) {
    step(2, $total, 'database backup', function () {
        $stamp = date('Ymd-His');
        $dir   = __DIR__ . '/backups';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $sqlPath = $dir . "/{$stamp}.sql";

        // Use mysqldump if available; fall back to PHP-based dump otherwise.
        $cmd = sprintf(
            'mysqldump -h%s -u%s %s %s > %s',
            escapeshellarg($GLOBALS['db_host'] ?? 'localhost'),
            escapeshellarg($GLOBALS['db_user'] ?? 'root'),
            !empty($GLOBALS['db_pass']) ? '-p' . escapeshellarg($GLOBALS['db_pass']) : '',
            escapeshellarg($GLOBALS['db_name'] ?? ''),
            escapeshellarg($sqlPath)
        );
        $rc = 0;
        @system($cmd, $rc);
        if ($rc !== 0 || !file_exists($sqlPath) || filesize($sqlPath) < 100) {
            // Fallback: PHP-based dump via the existing backup helper.
            require_once __DIR__ . '/../../inc/backup.php';
            if (function_exists('newui_backup_dump_sql')) {
                $sql = newui_backup_dump_sql();
                file_put_contents($sqlPath, $sql);
            }
        }
        if (!file_exists($sqlPath) || filesize($sqlPath) < 100) {
            throw new RuntimeException('backup produced empty file: ' . $sqlPath);
        }
        logmsg("  backup -> $sqlPath (" . number_format(filesize($sqlPath)) . " bytes)");
    });
} else {
    logmsg("Step 2/$total — backup SKIPPED (--skip-backup) — testing only");
}

// Step 3 — Confirm
if (!$noConfirm && !$skipBackup) {
    logmsg('');
    logmsg('About to APPLY schema migrations to the live database.');
    logmsg('Press Enter to continue, Ctrl+C to abort.');
    if (php_sapi_name() === 'cli') @fgets(STDIN);
}

// Step 4 — install_fresh.php (idempotent ALTERs)
step(4, $total, 'install_fresh.php (column patches)', function () {
    require __DIR__ . '/../install_fresh.php';
});

// Step 5 — settings_migrate
step(5, $total, 'settings translator', function () {
    require __DIR__ . '/settings_migrate.php';
});

// Step 6 — migrate legacy users to RBAC roles
step(6, $total, 'level → role migration', function () {
    if (file_exists(__DIR__ . '/../migrate_rbac.php')) {
        require __DIR__ . '/../migrate_rbac.php';
    } else {
        logmsg('  (legacy migrate_rbac.php not present — already covered by run_rbac_v2.php step A9)');
    }
});

// Step 7 — smoke test
step(7, $total, 'smoke test', function () {
    $rc = 0;
    passthru('"' . PHP_BINARY . '" "' . __DIR__ . '/smoke_test.php"', $rc);
    if ($rc !== 0) throw new RuntimeException('smoke test failed');
});

// Step 8 — postcheck
step(8, $total, 'postcheck', function () {
    $rc = 0;
    passthru('"' . PHP_BINARY . '" "' . __DIR__ . '/postcheck.php"', $rc);
    if ($rc !== 0 && $rc !== 2) throw new RuntimeException('postcheck failed');
});

logmsg('');
logmsg('=== Upgrade complete ===');
logmsg('Log: ' . realpath($logPath));
logmsg('Next: review the postcheck report above. Open the NewUI URL and verify.');
logmsg('');
fclose($logHandle);
exit(0);
