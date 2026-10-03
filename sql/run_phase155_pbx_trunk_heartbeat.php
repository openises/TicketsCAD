<?php
/**
 * Phase 155 (2026-10-02) — Inbound-call bridge heartbeat columns.
 *
 * A beta tester minted a trunk token, then had no way to tell whether the
 * bridge process was running, mis-configured, or simply idle: a quiet phone
 * line and a dead bridge look identical because `pbx_trunks` only ever
 * learned about a bridge when a CALL arrived. The same "quiet is not dead"
 * rule the DMR channel registry already follows (liveness from an
 * authenticated /health, never inferred from recent traffic) applies here.
 *
 *   - `pbx_trunks.last_heartbeat_at` — stamped by api/sip-ingest.php when the
 *     bridge POSTs {"event":"heartbeat"} (every ~30s while it is running).
 *   - `pbx_trunks.bridge_info`       — the bridge's own one-line description
 *     (version + mode), shown on the trunk admin page.
 *
 * Idempotent; verifies its own outcome and exits non-zero if either column is
 * missing afterwards (a migration that prints a failure and exits 0 is a
 * migration that never ran — see CLAUDE.md, Phase 128).
 *
 * File-name ordering: `run_phase155_*` sorts AFTER `run_phase149_*`
 * (sql/run_migrations.php ksort()s lexicographically), so `pbx_trunks`
 * exists by the time this runs on a fresh install.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';

echo "Phase 155 — pbx_trunks bridge heartbeat columns\n";
echo "================================================\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';
$table  = $prefix . 'pbx_trunks';

$colExists = function (string $col) use ($table): bool {
    return (bool) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
        [$table, $col]
    );
};

$tableExists = (bool) db_fetch_value(
    "SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
    [$table]
);
if (!$tableExists) {
    echo "[FAIL] {$table} does not exist — run_phase149_inbound_sip_calls.php must run first\n";
    exit(1);
}

$wanted = [
    'last_heartbeat_at' => "ALTER TABLE `{$table}` ADD COLUMN `last_heartbeat_at` DATETIME NULL DEFAULT NULL AFTER `enabled`",
    'bridge_info'       => "ALTER TABLE `{$table}` ADD COLUMN `bridge_info` VARCHAR(120) NULL DEFAULT NULL AFTER `last_heartbeat_at`",
];

foreach ($wanted as $col => $ddl) {
    if ($colExists($col)) {
        echo "[SKIP] {$table}.{$col} already exists\n";
        continue;
    }
    try {
        db_query($ddl);
        echo "[OK] {$table}.{$col} added\n";
    } catch (Throwable $e) {
        echo "[FAIL] {$table}.{$col}: " . $e->getMessage() . "\n";
    }
}

// Verify the outcome — ask the database, not the log we just printed.
$missing = [];
foreach (array_keys($wanted) as $col) {
    if (!$colExists($col)) { $missing[] = $col; }
}
if ($missing) {
    echo "\n[FAIL] still missing after migration: " . implode(', ', $missing) . "\n";
    exit(1);
}

echo "\nDone — both heartbeat columns verified present.\n";
exit(0);
