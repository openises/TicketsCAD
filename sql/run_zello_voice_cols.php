<?php
/**
 * Zello voice-message columns migration.
 *
 * The proxy's logMessage() (proxy/ZelloProxyApp.php) records a completed voice
 * transmission by INSERTing `duration_ms` (recording length in ms) and
 * `media_url` (relative path of the saved .ogg under cache/zello-audio/) into
 * `zello_messages`. On installs created before these columns were added to the
 * canonical schema (sql/zello_tables.sql), that INSERT failed with:
 *
 *   "Unknown column 'duration_ms' in 'INSERT INTO'"
 *
 * so voice messages were never persisted. This runner adds the two columns
 * idempotently so existing installs catch up. Fresh installs already get them
 * from zello_tables.sql.
 *
 * Safe to re-run. No data migration; both columns default NULL, which is what
 * a text/location row should read as anyway.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

echo "Zello voice-message columns (zello_messages.duration_ms, media_url)\n";
echo "==================================================================\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

// Guard the helper so the migration is safe to require twice in one PHP
// process (e.g. the idempotency check in tests/test_zello_infra_fixes.php).
if (!function_exists('_zvc_has_col')) {
    function _zvc_has_col(string $table, string $col): bool {
        global $prefix;
        return (bool) db_fetch_value(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
            [$prefix . $table, $col]
        );
    }
}

// zello_messages must exist first (created by sql/zello_tables.sql). If the
// install has never set up Zello, there's nothing to alter — exit cleanly.
$tableExists = (bool) db_fetch_value(
    "SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
    [$prefix . 'zello_messages']
);
if (!$tableExists) {
    echo "[SKIP] {$prefix}zello_messages does not exist yet — run sql/zello_tables.sql first.\n";
    echo "       (Nothing to migrate; these columns are added when the table is created.)\n";
    echo "\nDone.\n";
    exit(0);
}

/**
 * Add one nullable column idempotently. Returns true on success/already-present,
 * false (and prints [FAIL]) only on an unexpected error.
 *
 * Guarded so the runner is safe to require twice in one PHP process (the
 * idempotency check in tests/test_zello_infra_fixes.php requires it again).
 */
if (!function_exists('_zvc_add_col')) {
    function _zvc_add_col(string $prefix, string $col, string $definition): bool {
        if (_zvc_has_col('zello_messages', $col)) {
            echo "[OK] zello_messages.{$col} already present\n";
            return true;
        }
        try {
            db_query(
                "ALTER TABLE `{$prefix}zello_messages`
                    ADD COLUMN `{$col}` {$definition}"
            );
            echo "[OK] added zello_messages.{$col}\n";
            return true;
        } catch (Exception $e) {
            if (stripos($e->getMessage(), 'duplicate column') !== false) {
                echo "[OK] zello_messages.{$col} already present (race)\n";
                return true;
            }
            echo "[FAIL] zello_messages.{$col}: " . $e->getMessage() . "\n";
            return false;
        }
    }
}

$ok = true;
$ok = _zvc_add_col($prefix, 'duration_ms', "INT DEFAULT NULL "
        . "COMMENT 'Voice-message recording length in ms; NULL for text/location' "
        . "AFTER `incident_id`") && $ok;
$ok = _zvc_add_col($prefix, 'media_url', "VARCHAR(255) DEFAULT NULL "
        . "COMMENT 'Relative URL of saved .ogg under cache/zello-audio/; NULL for text/location' "
        . "AFTER `duration_ms`") && $ok;

// NOTE: no exit() on success — this runner is `require`d inline by
// tests/test_zello_infra_fixes.php (and may be by other callers), so a bare
// exit() would terminate the host script. Only a hard ALTER failure exits
// non-zero, mirroring sql/run_zello_dm.php. The master runner
// (sql/run_migrations.php) executes each runner in an isolated subprocess and
// keys off a /\bFAILED\b/i match in the output, which _zvc_add_col emits on a
// real error.
echo "\nDone.\n";
if (!$ok) {
    exit(1);
}
