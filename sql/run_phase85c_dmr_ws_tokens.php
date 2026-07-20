<?php
/**
 * Phase 85c — short-lived auth tokens for the DMR WebSocket proxy.
 *
 * Mirror of zello_ws_tokens: the browser, while authenticated to PHP
 * via its session cookie, calls /api/dmr-token.php to mint a one-shot
 * token. It then opens the proxy WebSocket and sends that token as the
 * first message; the proxy verifies it against this table and consumes
 * it on first use.
 *
 * Lets the proxy daemon stay session-file-free: it never has to read
 * /var/lib/php/sessions, never has to run as www-data for that reason,
 * and doesn't care about how PHP serialises sessions. Same security
 * trade-off as Zello: tokens expire in 2 minutes, are single-use, and
 * carry the authenticated user_id from the session that minted them.
 *
 * Usage:  php sql/run_phase85c_dmr_ws_tokens.php
 * Safe to re-run (CREATE TABLE IF NOT EXISTS).
 */

require_once __DIR__ . '/../config.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

echo "Phase 85c — DMR WebSocket proxy auth tokens\n";
echo "===========================================\n\n";

try {
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}dmr_ws_tokens` (
        `token`      VARCHAR(64) NOT NULL PRIMARY KEY,
        `user_id`    INT          NOT NULL,
        `user`       VARCHAR(64)  NOT NULL,
        `user_level` INT          NOT NULL DEFAULT 99,
        `channel_id` INT          NULL COMMENT 'optional dmr_channels.id pre-selected at issue time',
        `created`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_created (`created`),
        INDEX idx_user_id (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Single-use auth tokens for the DMR proxy (Phase 85c)'");
    echo "[OK] dmr_ws_tokens table ready\n";
} catch (Exception $e) {
    echo "[ERR] " . $e->getMessage() . "\n"; exit(1);
}

// Seed the per-install setting for the proxy port (default 8092 —
// 8090 is Zello's).
try {
    $existing = db_fetch_value(
        "SELECT `value` FROM `{$prefix}settings` WHERE `name` = 'dmr_proxy_port'"
    );
    if ($existing === null || $existing === false) {
        db_query(
            "INSERT IGNORE INTO `{$prefix}settings` (`name`, `value`) VALUES ('dmr_proxy_port', '8092')"
        );
        echo "[OK] dmr_proxy_port setting seeded as 8092\n";
    } else {
        echo "[skip] dmr_proxy_port already set to {$existing}\n";
    }
} catch (Exception $e) {
    echo "[WARN] could not seed dmr_proxy_port: " . $e->getMessage() . "\n";
}

echo "\nDone.\n";
