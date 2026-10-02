<?php
/**
 * Phase 152 (Communications Console v2) — prerequisite #6, mandatory,
 * renewable route expiry.
 *
 * `comm_routes` gains five columns, all NULL/0 by default so this is a
 * pure additive schema change with zero effect on any existing row until
 * something writes to them:
 *
 *   expires_at         DATETIME NULL — when the patch stops mixing.
 *                       REQUIRED (enforced in inc/matrix-routes.php, not
 *                       here) whenever allow_cross_class = 1; optional
 *                       for a routine same-class patch a dispatcher may
 *                       legitimately need to leave up for a whole event.
 *   group_id           INT NULL — for a future named coupling group
 *                       (3+ channels as one object with one shared
 *                       timer/ack) to renew/expire together; unused by
 *                       this prerequisite's own single-route flow, but
 *                       the column belongs on the row it will eventually
 *                       group, not bolted on later as a migration that
 *                       has to backfill.
 *   operator_ack_by    INT NULL — the user who created or most recently
 *                       renewed a cross-class route (the human who
 *                       acknowledged the FCC Part 97.113 override).
 *   operator_callsign  VARCHAR(16) NULL — that user's `user.callsign`
 *                       SNAPSHOTTED at ack/renewal time, not looked up
 *                       live at read time. A callsign on file can change
 *                       (or be cleared) later; the audit record must
 *                       reflect what was actually true at the moment of
 *                       acknowledgment, not whatever the user table says
 *                       today.
 *   warned_at          DATETIME NULL — set by
 *                       tools/matrix_expiry_warning_tick.php the first
 *                       time it fires the comm:route_expiring SSE warning
 *                       for this route, so the warning fires exactly
 *                       once per approaching expiry rather than every
 *                       tick of that job.
 *
 * matrix_core.py enforces expires_at READ-TIME on every mix tick — the
 * matrix service itself refuses to relay an expired route's audio,
 * independent of whether any PHP-side sweep has ever run (Phase 143's
 * "the core enforces itself; a sweep only closes the audit record"
 * convention, applied here for the third time this project — see
 * inc/matrix-routes.php's own docblock for the full reasoning).
 *
 * Idempotent — safe to run repeatedly; picked up by run_migrations.php.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

chdir(__DIR__ . '/..');
require_once 'config.php';
$dbInc = file_exists('inc/db.inc.php') ? 'inc/db.inc.php' : 'inc/db.php';
require_once $dbInc;
$prefix = $GLOBALS['db_prefix'] ?? '';

echo "Phase 152 -- comm_routes expiry columns\n";
echo "=========================================\n\n";

$columns = [
    'expires_at'        => "DATETIME NULL",
    'group_id'          => "INT NULL",
    'operator_ack_by'   => "INT NULL",
    'operator_callsign' => "VARCHAR(16) NULL",
    'warned_at'         => "DATETIME NULL",
];

$ok = true;
foreach ($columns as $name => $def) {
    try {
        $exists = db_fetch_value(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
            [$prefix . 'comm_routes', $name]
        );
        if ((int) $exists === 0) {
            db_query("ALTER TABLE `{$prefix}comm_routes` ADD COLUMN `{$name}` {$def}");
            echo "[OK] added comm_routes.{$name}\n";
        } else {
            echo "[OK] comm_routes.{$name} already exists\n";
        }
    } catch (Exception $e) {
        fwrite(STDERR, "[FAIL] {$name}: " . $e->getMessage() . "\n");
        $ok = false;
    }
}

// Verify (Phase 128 A9b lesson).
foreach (array_keys($columns) as $name) {
    $verify = db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
        [$prefix . 'comm_routes', $name]
    );
    if ((int) $verify !== 1) {
        fwrite(STDERR, "[FAIL] verification: comm_routes.{$name} not found after migration\n");
        $ok = false;
    }
}

if (!$ok) { exit(1); }
echo "\n[OK] verified.\n";
exit(0);
