<?php
/**
 * Phase 152 (Communications Console v2) — prerequisite #3, the console
 * session table backing the browser audio leg.
 *
 * Every static leg (DMR, Zello) is wired once at service boot from
 * comm_channels. A dispatcher's own browser tab is not static -- it opens
 * on login, closes on logout/tab-close, and there can be any number of
 * them. So the matrix service's browser leg (services/audio-matrix/legs/
 * browser.py) creates and destroys a matrix channel PER WEBSOCKET
 * CONNECTION, keyed `browser:<console_sessions.id>` -- and it authenticates
 * that connection by looking up a session_token here, not by trusting
 * anything the browser claims about its own identity.
 *
 * api/console-session.php mints a row when a logged-in dispatcher opens
 * the console (already holds screen.console via the normal session/RBAC
 * check); the Python leg validates the token directly against this table
 * on each new WS connection (inc/comm_fcc_gate.php's DB-wrapper pattern,
 * mirrored on the Python side rather than round-tripping through PHP for
 * every handshake -- the matrix service already holds its own DB
 * connection for comm_channels/comm_routes).
 *
 * session_token is stored in plaintext, matching this codebase's existing
 * convention for short-lived, mint-once, holder-controlled secrets
 * (pbx_trunks.bearer_token, sql/run_phase149_inbound_calls.php) rather
 * than the long-lived admin-secret pattern that gets hashed --
 * expires_at is short (the console UI mints a fresh one per page load,
 * see api/console-session.php) so a leaked row has a narrow blast radius
 * even in plaintext.
 *
 * Idempotent -- safe to run repeatedly; picked up by run_migrations.php.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

chdir(__DIR__ . '/..');
require_once 'config.php';
$dbInc = file_exists('inc/db.inc.php') ? 'inc/db.inc.php' : 'inc/db.php';
require_once $dbInc;
$prefix = $GLOBALS['db_prefix'] ?? '';

echo "Phase 152 -- console_sessions\n";
echo "==============================\n\n";

$sql = "CREATE TABLE IF NOT EXISTS `{$prefix}console_sessions` (
    `id`                  INT AUTO_INCREMENT PRIMARY KEY,
    `session_token`       CHAR(64) NOT NULL COMMENT 'bin2hex(random_bytes(32))',
    `user_id`             INT NOT NULL,
    `username`            VARCHAR(64) NOT NULL COMMENT 'denormalized -- survives
                           a later user deletion, matches this project''s
                           audit-log convention',
    `workstation_token`   VARCHAR(64) DEFAULT NULL COMMENT 'the browser-local
                           persistent workstation id (see the workstation-mute
                           feature, console_workstations) -- nullable because
                           minting a session must never fail just because the
                           workstation identity has not been established yet',
    `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_seen_at`        DATETIME DEFAULT NULL,
    `expires_at`          DATETIME NOT NULL COMMENT 'short-lived by design --
                           the console UI mints a fresh session on each page
                           load rather than reusing one across days',
    `revoked_at`          DATETIME DEFAULT NULL COMMENT 'set on explicit
                           logout/tab-close notification; a lapsed expires_at
                           alone (no explicit revoke) is the common case and
                           is NOT an error',
    UNIQUE KEY `uk_session_token` (`session_token`),
    KEY `idx_user_expires` (`user_id`, `expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Phase 152 -- authenticates
    a browser tab''s WebSocket connection to the audio-matrix service''s
    dynamic browser leg. One row per open console session, not per user.'";

$ok = true;
try {
    db_query($sql);
    echo "[OK] console_sessions table ready\n";
} catch (Exception $e) {
    fwrite(STDERR, "[FAIL] console_sessions: " . $e->getMessage() . "\n");
    $ok = false;
}

// Verify (Phase 128 A9b lesson: a migration that swallows its own failure
// and exits 0 is a migration that never ran).
$exists = db_fetch_value(
    "SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
    [$prefix . 'console_sessions']
);
if ((int) $exists !== 1) {
    fwrite(STDERR, "[FAIL] verification: console_sessions not found after migration\n");
    $ok = false;
}

if (!$ok) { exit(1); }
echo "\n[OK] verified.\n";
exit(0);
