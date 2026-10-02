<?php
/**
 * Phase 152 (Console rebuild) — workstation identity + adjacent-transmit
 * mute (spec.md user story #12, plan.md section 3.5).
 *
 * Two tables:
 *
 * - console_workstations: a persistent identity for a PHYSICAL desk,
 *   independent of who is logged in or which login session is active
 *   (plan.md's own hard requirement — the acoustic-echo problem this
 *   feature exists to solve doesn't change when a different person sits
 *   down at the same desk tomorrow). `workstation_token` is a UUID
 *   generated CLIENT-SIDE (assets/js/console-workstation.js) and
 *   persisted in that browser's localStorage — this table's row is
 *   created the first time that token is ever seen by the server
 *   (console_workstation_resolve(), inc/console-workstations.php),
 *   either via api/console-session.php's own mint call (Prerequisite 3)
 *   or api/console-workstation-mutes.php itself, whichever the browser
 *   reaches first. `beacon_code` is reserved for the acoustic-discovery
 *   sub-feature (a later, separate task) — nullable and unused until then.
 *
 * - console_workstation_mutes: "workstation A does not want to hear
 *   workstation B's own outgoing transmissions." Genuinely directional at
 *   the schema/mixing level (plan.md: "Directional... they usually will
 *   want it mutual") — every UI path that CREATES a pairing (the manual
 *   toggle, and later the acoustic-discovery match) sets up BOTH
 *   directions in one action (inc/console-workstations.php::
 *   console_workstation_set_mute_pair()), but the table itself never
 *   assumes symmetry, so a future admin tool could break one direction
 *   apart from the other if that's ever genuinely needed.
 *
 * No foreign-key constraints, matching this project's schema-resilience
 * convention elsewhere (comm_channels, console_positions) —
 * inc/console-workstations.php cleans up dependent rows itself if a
 * workstation row is ever removed (not built in THIS migration — there is
 * no admin "delete a workstation" action yet, since a stale/abandoned
 * desk identity is harmless: it just stops appearing in the "recently
 * active" nearby list once its last_seen_at ages out).
 *
 * Idempotent — safe to run repeatedly; picked up by run_migrations.php.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

chdir(__DIR__ . '/..');
require_once 'config.php';
$dbInc = file_exists('inc/db.inc.php') ? 'inc/db.inc.php' : 'inc/db.php';
require_once $dbInc;
$prefix = $GLOBALS['db_prefix'] ?? '';

echo "Phase 152 -- workstation identity + adjacent-transmit mute\n";
echo "============================================================\n\n";

$ok = true;
$tables = [];

$tables['console_workstations'] = "CREATE TABLE IF NOT EXISTS `{$prefix}console_workstations` (
    `id`                INT AUTO_INCREMENT PRIMARY KEY,
    `workstation_token` CHAR(36) NOT NULL COMMENT 'a UUID generated client-
                         side (assets/js/console-workstation.js) on first
                         console load and persisted in that browser''s
                         localStorage -- survives logout and a different
                         user logging in on the same machine; does NOT
                         survive a browser data wipe or a different
                         browser/device (the honest, documented limit of
                         this approach)',
    `label`             VARCHAR(64) DEFAULT NULL COMMENT 'operator/admin-
                         assigned, e.g. \"Desk 1\" -- NULL until someone
                         names it',
    `beacon_code`       INT DEFAULT NULL COMMENT 'reserved for the acoustic
                         proximity auto-discovery sub-feature (a later,
                         separate task) -- unused and always NULL until
                         then',
    `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_seen_at`      DATETIME DEFAULT NULL COMMENT 'touched every time
                         this token is seen by the server -- the \"nearby
                         workstations\" list is simply every OTHER
                         workstation with a recent last_seen_at, not a
                         precise real-time channel-overlap computation
                         (see api/console-workstation-mutes.php''s own
                         docblock for why that simplification is honest,
                         not a silent shortfall)',
    UNIQUE KEY `uk_workstation_token` (`workstation_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Phase 152 -- a persistent
    physical-desk identity, independent of login/session.'";

$tables['console_workstation_mutes'] = "CREATE TABLE IF NOT EXISTS `{$prefix}console_workstation_mutes` (
    `id`                    INT AUTO_INCREMENT PRIMARY KEY,
    `workstation_id`        INT NOT NULL COMMENT 'the workstation whose
                             SPEAKERS suppress the other one -- \"I do not
                             want to hear workstation muted_workstation_id''s
                             own outgoing transmissions\"',
    `muted_workstation_id`  INT NOT NULL,
    `created_by`            INT DEFAULT NULL COMMENT 'the user_id who set
                             this pairing up, for audit purposes',
    `created_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_workstation_pair` (`workstation_id`, `muted_workstation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Phase 152 -- directional
    per-destination mute pairings for the adjacent-transmit-mute feature.
    Every UI path that creates a pairing sets up both directions in one
    action; the table itself stays genuinely directional.'";

foreach ($tables as $name => $sql) {
    try {
        db_query($sql);
        echo "[OK] {$name} table ready\n";
    } catch (Exception $e) {
        fwrite(STDERR, "[FAIL] {$name}: " . $e->getMessage() . "\n");
        $ok = false;
    }
}

// Verify (Phase 128 A9b lesson: a migration that swallows its own failure
// and exits 0 is a migration that never ran).
foreach (array_keys($tables) as $name) {
    $exists = db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        [$prefix . $name]
    );
    if ((int) $exists !== 1) {
        fwrite(STDERR, "[FAIL] verification: {$name} not found after migration\n");
        $ok = false;
    }
}

if (!$ok) { exit(1); }
echo "\n[OK] verified.\n";
exit(0);
