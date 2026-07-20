<?php
/**
 * GH #20 round 2 (a beta tester 2026-07-07) — per-status bed-delivery flag
 *
 * The Phase 103 bed automation matched delivery events by ENGLISH
 * status-name substrings ('at facility', 'arrived', ...). Real agency
 * deployments use their own status names — a beta tester's at-facility status
 * is "At Destination" (never matched), while his "Arrived" means
 * arrived ON SCENE (would have matched and fired a bed count at the
 * wrong moment).
 *
 * Adds `un_status.bed_delivery` TINYINT(1): when ANY status on the
 * install has the flag set, the automation uses the flags EXCLUSIVELY;
 * with no flags set anywhere it falls back to the legacy name-pattern
 * list so existing auto-mode installs keep working unchanged.
 *
 * Also seeds the flag on statuses whose names match the legacy pattern
 * list minus the ambiguous 'arrived' (on-scene vs at-facility is agency
 * jargon; admins opt in explicitly via Settings > Unit Statuses).
 *
 * Idempotent — picked up automatically by run_migrations.php.
 */

chdir(__DIR__ . '/..');
require_once 'config.php';
$dbInc = file_exists('inc/db.inc.php') ? 'inc/db.inc.php' : 'inc/db.php';
require_once $dbInc;
$prefix = $GLOBALS['db_prefix'] ?? '';

echo "GH #20 — per-status bed-delivery flag\n";
echo "=====================================\n\n";

try {
    $exists = (int) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ? AND COLUMN_NAME = 'bed_delivery'",
        [$prefix . 'un_status']
    );
    if ($exists) {
        echo "skip: bed_delivery already present\n";
    } else {
        db_query("ALTER TABLE `{$prefix}un_status`
                  ADD COLUMN `bed_delivery` TINYINT(1) NOT NULL DEFAULT 0");
        echo "added: un_status.bed_delivery\n";

        // Seed unambiguous delivery-flavored names so installs that were
        // relying on the pattern matcher get equivalent flags. 'arrived'
        // is deliberately NOT seeded (ambiguous: on-scene vs at-facility).
        $seeded = 0;
        foreach (db_fetch_all("SELECT id, status_val FROM `{$prefix}un_status`") as $r) {
            $n = strtolower(trim((string) $r['status_val']));
            foreach (['at facility', 'at hospital', 'delivered', 'transfer of care'] as $pat) {
                if ($n !== '' && strpos($n, $pat) !== false) {
                    db_query("UPDATE `{$prefix}un_status` SET bed_delivery = 1 WHERE id = ?", [$r['id']]);
                    $seeded++;
                    break;
                }
            }
        }
        echo "seeded flag on $seeded delivery-named status(es)\n";
    }
} catch (Exception $e) {
    echo "ERR: " . $e->getMessage() . "\n";
}

echo "\nDone.\n";
