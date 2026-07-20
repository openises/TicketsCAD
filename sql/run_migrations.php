<?php
/**
 * NewUI v4.0 — Master Migration Runner (Phase 13)
 *
 * One command to apply every pending database migration. Replaces the
 * "run each sql/run_*.php by hand" pattern that left training and
 * your deployment with empty location_providers / captions_i18n tables
 * because nobody remembered to invoke the seeds.
 *
 *   $ php sql/run_migrations.php          # apply pending
 *   $ php sql/run_migrations.php --list   # show status only
 *   $ php sql/run_migrations.php --force  # re-run all
 *
 * How it works:
 *   1. Creates a `_migrations` tracking table on first run
 *   2. Discovers all `sql/run_*.php` files (excludes this orchestrator)
 *   3. Sorts them lexicographically — phase-prefixed names line up
 *      naturally (run_phase08_*, run_phase08b_*, run_phase09_*, ...)
 *      and pre-phase scripts (run_00_rbac.php, run_01_tfa.php, run_02_organizations.php, ...)
 *      sort before them, which is the order they were originally
 *      authored in. Idempotency (INSERT IGNORE / CREATE IF NOT EXISTS)
 *      protects against ordering surprises.
 *   4. For each migration:
 *      - Compute SHA-256 of the file
 *      - If the (script_name, hash) tuple is already in `_migrations`,
 *        skip — already applied with this exact content.
 *      - Otherwise run it (via inline require) and record the result.
 *   5. Halts on first hard failure with diagnostic output. The admin
 *      fixes the issue and re-runs; previously-applied migrations are
 *      skipped automatically.
 *
 * The web app calls /api/migrations-check.php on each page load by an
 * admin to detect "you upgraded the code but haven't run the new
 * migrations yet" and shows a Settings banner with a Run button.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$listOnly = in_array('--list', $argv ?? [], true);
$force    = in_array('--force', $argv ?? [], true);

echo "NewUI Master Migration Runner\n";
echo "=============================\n\n";

// ── 1. Ensure tracking table exists ──────────────────────────────────────
try {
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}_migrations` (
        `id`           INT AUTO_INCREMENT PRIMARY KEY,
        `script_name`  VARCHAR(128) NOT NULL,
        `script_hash`  CHAR(64) NOT NULL,
        `applied_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `applied_by`   VARCHAR(64) NULL,
        `duration_ms`  INT NULL,
        `status`       ENUM('ok','failed') NOT NULL DEFAULT 'ok',
        `notes`        TEXT NULL,
        UNIQUE KEY `uk_script_hash` (`script_name`, `script_hash`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "[OK] _migrations tracking table ready\n\n";
} catch (Exception $e) {
    echo "[FATAL] cannot create _migrations table: " . $e->getMessage() . "\n";
    exit(1);
}

// ── 2. Discover migrations ──────────────────────────────────────────────
$dir = __DIR__;
$files = glob($dir . '/run_*.php');
// Exclude run_migrations.php (this script — don't recurse into ourselves).
$skip = ['run_migrations.php'];
$migrations = [];
foreach ($files as $path) {
    $name = basename($path);
    if (in_array($name, $skip, true)) continue;
    $migrations[$name] = [
        'path' => $path,
        'hash' => hash_file('sha256', $path),
    ];
}
ksort($migrations);

if (empty($migrations)) {
    echo "[--] No migrations found in sql/\n";
    exit(0);
}

echo "Found " . count($migrations) . " migration script(s).\n\n";

// ── 3. Cross-reference against tracking table ───────────────────────────
$already = [];
try {
    foreach (db_fetch_all("SELECT script_name, script_hash, applied_at, status FROM `{$prefix}_migrations`") as $row) {
        $already[$row['script_name'] . '|' . $row['script_hash']] = $row;
    }
} catch (Exception $e) {
    echo "[WARN] could not read _migrations: " . $e->getMessage() . "\n";
}

$pending = [];
$applied = 0;
foreach ($migrations as $name => $m) {
    $key = $name . '|' . $m['hash'];
    // GH #72 follow-on: only an 'ok' record counts as applied. Failed
    // attempts are recorded with the REAL hash now (see below) so the
    // same script re-runs after the admin fixes the underlying issue.
    if (!$force && isset($already[$key]) && ($already[$key]['status'] ?? 'ok') === 'ok') {
        $applied++;
    } else {
        $pending[$name] = $m;
    }
}

echo sprintf("Already applied: %d   Pending: %d\n\n", $applied, count($pending));

if ($listOnly) {
    echo "Status report (--list):\n";
    foreach ($migrations as $name => $m) {
        $key = $name . '|' . $m['hash'];
        if (isset($already[$key])) {
            $when = $already[$key]['applied_at'];
            $status = $already[$key]['status'];
            printf("  [%s] %s  (applied %s)\n", strtoupper($status), $name, $when);
        } else {
            printf("  [PENDING] %s\n", $name);
        }
    }
    exit(0);
}

if (empty($pending)) {
    echo "Everything is up to date. No action needed.\n";
    exit(0);
}

// ── 4. Apply pending migrations ─────────────────────────────────────────
// posix_* doesn't exist on Windows (XAMPP dev boxes) — fall back cleanly.
$me = function_exists('posix_getpwuid')
    ? (posix_getpwuid(posix_geteuid())['name'] ?? 'cli')
    : (get_current_user() ?: 'cli');
$failed = 0;

// Run each migration as a SUBPROCESS so that an exit(0) in a no-op
// migration (run_equipment_personal.php does this) doesn't terminate
// our orchestrator. Isolation also avoids leaked globals, function
// redefinitions across migrations, and PDO connection-state surprises.
$phpBin = PHP_BINARY ?: 'php';
foreach ($pending as $name => $m) {
    echo "── Running {$name} ──\n";
    $start = microtime(true);

    // Quote the path correctly for the shell. proc_open with an array
    // argv would be cleaner but isn't universally available on the
    // PHP-CLI versions we target. escapeshellarg covers spaces / quotes.
    $cmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($m['path']) . ' 2>&1';
    $outLines = [];
    $exitCode = 0;
    exec($cmd, $outLines, $exitCode);
    $logTail = implode("\n", $outLines);
    if ($logTail === '') $logTail = '(no output captured)';

    $durMs = (int) ((microtime(true) - $start) * 1000);

    // Failure detection, two signals:
    //   1. The child's exit code — every migration that detects its own
    //      failure exits non-zero. This is the authoritative signal
    //      (GH #72 follow-on, 2026-07-07: shell_exec() discarded it).
    //   2. Error-shaped strings, for old migrations that catch + echo
    //      "ERR: ..." but still exit 0.
    // The string regex deliberately does NOT match a bare "FAILED":
    // run_99k's SUCCESS summary is "Done. Widened 12, failed 0." and
    // the old case-insensitive \bFAILED\b match flagged that success
    // as a failure — halting every fresh install at 99k.
    $looksFailed = ($exitCode !== 0)
        || preg_match('/\b(Fatal error|Uncaught [A-Za-z\\\\]*(Exception|Error)|ERR:)\b|\[ERR\]/', $logTail);

    echo $logTail;
    if (substr($logTail, -1) !== "\n") echo "\n";

    if ($looksFailed) {
        echo "[FAILED] {$name} after {$durMs}ms (exit code {$exitCode})\n\n";
        // Record the failure with the REAL hash so the admin can see
        // what broke. (The old code appended ':fail:xxxx' to the hash,
        // which overflowed script_hash CHAR(64) — the INSERT itself
        // failed silently and no failure was ever recorded.) The
        // pending-check above only treats status='ok' as applied, so
        // a failed record never blocks a retry.
        try {
            db_query(
                "INSERT INTO `{$prefix}_migrations`
                 (script_name, script_hash, applied_by, duration_ms, status, notes)
                 VALUES (?, ?, ?, ?, 'failed', ?)
                 ON DUPLICATE KEY UPDATE `status` = 'failed', `applied_at` = NOW(),
                     `duration_ms` = VALUES(`duration_ms`), `notes` = VALUES(`notes`)",
                [$name, $m['hash'], $me, $durMs, mb_substr($logTail, 0, 2000)]
            );
        } catch (Exception $e2) { /* tracking table itself dead — keep going */ }
        $failed++;
        echo "Stopping. Fix the issue above and re-run; previously-applied migrations will be skipped.\n";
        break;
    }

    echo "[APPLIED] {$name} in {$durMs}ms\n\n";

    // Record success. ON DUPLICATE KEY UPDATE flips an earlier failed
    // record for the same (name, hash) to ok once the retry succeeds.
    try {
        db_query(
            "INSERT INTO `{$prefix}_migrations`
             (script_name, script_hash, applied_by, duration_ms, status, notes)
             VALUES (?, ?, ?, ?, 'ok', ?)
             ON DUPLICATE KEY UPDATE `status` = 'ok', `applied_at` = NOW(),
                 `duration_ms` = VALUES(`duration_ms`), `notes` = VALUES(`notes`)",
            [$name, $m['hash'], $me, $durMs, mb_substr($logTail, 0, 2000)]
        );
    } catch (Exception $e3) {
        echo "[WARN] could not record success for {$name}: " . $e3->getMessage() . "\n";
    }
}

echo "\n";
echo "Summary: " . (count($pending) - $failed) . " applied, {$failed} failed.\n";

if ($failed > 0) exit(1);

// Final integrity probe: do the most-load-bearing seed tables now have rows?
$probes = [
    'location_providers' => 'Location Providers panel',
    'captions_i18n'      => 'Translations / i18n',
    'roles'              => 'Roles & Permissions',
];
echo "\nSanity probe:\n";
foreach ($probes as $tbl => $label) {
    try {
        $n = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}{$tbl}`");
        $flag = $n > 0 ? '✓' : '⚠';
        echo "  {$flag} {$tbl}: {$n} rows  ({$label})\n";
    } catch (Exception $e) {
        echo "  ✗ {$tbl}: not present  ({$label})\n";
    }
}
