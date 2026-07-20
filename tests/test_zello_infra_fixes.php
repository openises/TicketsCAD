<?php
/**
 * Zello proxy infrastructure regression tests (2026-06-26).
 *
 * Locks in the fixes for two infra bugs found while testing voice on training:
 *
 *   BUG 1 — voice recordings failed to write because the systemd unit
 *           (newui-zello-proxy.service) ran ProtectSystem=strict without the
 *           cache dir in ReadWritePaths → file_put_contents() "Read-only file
 *           system" → "playback error". Fix: cache path added to the shipped
 *           unit template's ReadWritePaths.
 *
 *   BUG 2 — voice messages failed to log because logMessage() INSERTs
 *           duration_ms + media_url into zello_messages, but those columns
 *           didn't exist on older installs → "Unknown column 'duration_ms'".
 *           Fix: columns added to the canonical schema + an idempotent
 *           migration runner (sql/run_zello_voice_cols.php).
 *
 * Run: /c/xampp/8.2.4/php/php.exe tools/test_all.php   (or this file directly)
 */

require __DIR__ . '/../config.php';

echo "=== Zello Proxy Infra Fixes (2026-06-26) ===\n\n";
$pass = 0;
$fail = 0;
$prefix = $GLOBALS['db_prefix'] ?? '';

function ti_ok(string $label, bool $cond, string $detail = '') {
    global $pass, $fail;
    if ($cond) { echo "[PASS] {$label}\n"; $pass++; }
    else       { echo "[FAIL] {$label}" . ($detail ? " — {$detail}" : '') . "\n"; $fail++; }
}

// ──────────────────────────────────────────────────────────────────────
// BUG 1 — shipped systemd unit grants the cache path
// ──────────────────────────────────────────────────────────────────────
echo "-- BUG 1: systemd unit ReadWritePaths --\n";
$unitPath = __DIR__ . '/../proxy/newui-zello-proxy.service.example';
$unit = file_get_contents($unitPath);
ti_ok("shipped unit template exists", $unit !== false);

// Find the ReadWritePaths line and assert the cache path is in it.
$rwpLine = '';
foreach (explode("\n", (string) $unit) as $ln) {
    if (stripos(trim($ln), 'ReadWritePaths=') === 0) { $rwpLine = trim($ln); break; }
}
ti_ok("unit has a ReadWritePaths= line", $rwpLine !== '');
ti_ok("ReadWritePaths includes the cache dir (voice recordings writable)",
    strpos($rwpLine, '/var/www/newui/cache') !== false,
    "got: {$rwpLine}");
ti_ok("ReadWritePaths still includes proxy dir (PID file)",
    strpos($rwpLine, '/var/www/newui/proxy') !== false);
ti_ok("ReadWritePaths still includes the log dir",
    strpos($rwpLine, '/var/log/newui') !== false);

// The proxy writes recordings under cache/zello-audio — make sure the path the
// code uses sits under the granted cache dir (guards against the dir moving
// out from under ReadWritePaths in a future refactor).
$appSrc = file_get_contents(__DIR__ . '/../proxy/ZelloProxyApp.php');
ti_ok("proxy writes recordings under cache/zello-audio",
    strpos($appSrc, "/cache/zello-audio") !== false);

// ──────────────────────────────────────────────────────────────────────
// BUG 2 — zello_messages voice columns + idempotent migration
// ──────────────────────────────────────────────────────────────────────
echo "\n-- BUG 2: zello_messages voice columns --\n";

// Ensure the base table exists (mirrors sql/zello_tables.sql) so the test is
// self-contained on a DB that never set up Zello.
try {
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}zello_messages` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `channel` VARCHAR(100) NOT NULL DEFAULT '',
        `recipient` VARCHAR(100) NOT NULL DEFAULT '',
        `direction` ENUM('incoming','outgoing') NOT NULL DEFAULT 'incoming',
        `message_type` VARCHAR(20) NOT NULL DEFAULT 'text',
        `sender_username` VARCHAR(100) NOT NULL DEFAULT '',
        `sender_display` VARCHAR(100) NOT NULL DEFAULT '',
        `content` TEXT, `incident_id` INT UNSIGNED DEFAULT NULL,
        `created` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_channel (`channel`), INDEX idx_created (`created`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) { /* may exist */ }

function ti_has_col(string $table, string $col): bool {
    global $prefix;
    return (bool) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
        [$prefix . $table, $col]
    );
}

// Run the migration the way an install would.
$ran = true;
try {
    ob_start();
    require __DIR__ . '/../sql/run_zello_voice_cols.php';
    ob_end_clean();
} catch (Throwable $e) { $ran = false; @ob_end_clean(); }
ti_ok("run_zello_voice_cols.php runs without throwing", $ran);

ti_ok("zello_messages.duration_ms present after migration",
    ti_has_col('zello_messages', 'duration_ms'));
ti_ok("zello_messages.media_url present after migration",
    ti_has_col('zello_messages', 'media_url'));

// Idempotent — a second run must not error.
$idemOk = true;
try {
    ob_start();
    require __DIR__ . '/../sql/run_zello_voice_cols.php';
    ob_end_clean();
} catch (Throwable $e) { $idemOk = false; @ob_end_clean(); }
ti_ok("run_zello_voice_cols.php is idempotent (re-run clean)", $idemOk);

// The canonical schema file must also declare both columns (fresh installs).
$schema = file_get_contents(__DIR__ . '/../sql/zello_tables.sql');
ti_ok("zello_tables.sql declares duration_ms",
    (bool) preg_match('/`duration_ms`\s+INT/i', $schema));
ti_ok("zello_tables.sql declares media_url",
    (bool) preg_match('/`media_url`\s+VARCHAR/i', $schema));

// ──────────────────────────────────────────────────────────────────────
// BUG 2 — simulate logMessage()'s INSERT end-to-end (the failing path)
// ──────────────────────────────────────────────────────────────────────
echo "\n-- BUG 2: voice INSERT round-trip --\n";

$insertOk = false;
$readBack  = null;
try {
    // This mirrors exactly what proxy logMessage() does for a voice message:
    // duration_ms + media_url populated, content NULL.
    db_query(
        "INSERT INTO `{$prefix}zello_messages`
            (`channel`, `recipient`, `direction`, `message_type`,
             `sender_username`, `sender_display`, `content`, `incident_id`,
             `duration_ms`, `media_url`, `created`)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
        ['ZTEST_CH', '', 'outgoing', 'voice', 'ztest_user', 'ZTest User',
         null, null, 4200, 'cache/zello-audio/ztest_999.ogg']
    );
    $id = (int) db_insert_id();
    $insertOk = $id > 0;
    $readBack = db_fetch_one(
        "SELECT duration_ms, media_url FROM `{$prefix}zello_messages` WHERE id = ?",
        [$id]
    );
    // Clean up the test row.
    db_query("DELETE FROM `{$prefix}zello_messages` WHERE id = ?", [$id]);
} catch (Exception $e) {
    echo "  INSERT error: " . $e->getMessage() . "\n";
}
ti_ok("voice-message INSERT (duration_ms + media_url) succeeds", $insertOk);
ti_ok("duration_ms round-trips", $readBack && (int) $readBack['duration_ms'] === 4200);
ti_ok("media_url round-trips",
    $readBack && $readBack['media_url'] === 'cache/zello-audio/ztest_999.ogg');

// Make sure no stray test rows survive.
db_query("DELETE FROM `{$prefix}zello_messages` WHERE sender_username = 'ztest_user'");

echo "\n=== Results: {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
