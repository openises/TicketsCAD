<?php
/**
 * GH #68 round 2 (Eric decision 2026-07-08) — explicit per-status filter
 * classification.
 *
 * Eric: rather than maintaining regex/name-pattern lists to guess which
 * bucket a status belongs to, every unit status carries an explicit
 * classification — Available / In Service / Unavailable — and the
 * units.php filter buttons match on THAT. Custom status names never
 * need to be pattern-guessable again.
 *
 * NULL means "not classified yet": the JS falls back to the legacy
 * heuristic for NULL rows, so pre-migration installs and unclassified
 * rows behave exactly as before. This migration seeds a best-effort
 * value from the same semantics the heuristic used (group prefixes,
 * incident_action) so admins start from a correct baseline and only
 * touch the odd ones out.
 *
 * Idempotent — guarded ALTER; seed only touches NULL rows.
 */

chdir(__DIR__ . '/..');
require_once 'config.php';
$prefix = $GLOBALS['db_prefix'] ?? '';

echo "GH #68 — un_status.units_filter classification\n";
echo "==============================================\n\n";

try {
    $has = (int) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ? AND COLUMN_NAME = 'units_filter'",
        [$prefix . 'un_status']
    );
    if ($has) {
        echo "skip: units_filter present\n";
    } else {
        db_query("ALTER TABLE `{$prefix}un_status`
                  ADD COLUMN `units_filter` ENUM('available','in_service','unavailable') NULL DEFAULT NULL
                  COMMENT 'Explicit units-page filter bucket; NULL = legacy name-pattern fallback'");
        echo "added: un_status.units_filter\n";
    }

    // Best-effort seed for rows still NULL, mirroring the JS heuristic:
    // group prefix first, then incident_action, then status name.
    // incident_action is itself migration-added and sorts AFTER this
    // script on a fresh install — select it only when it exists (CI's
    // fresh-install gate caught the unguarded version on first push).
    $hasIa = (int) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ? AND COLUMN_NAME = 'incident_action'",
        [$prefix . 'un_status']
    );
    $iaSelect = $hasIa ? "`incident_action`" : "'' AS `incident_action`";
    $seeded = 0;
    foreach (db_fetch_all(
        "SELECT `id`, `status_val`, `group`, {$iaSelect}
         FROM `{$prefix}un_status` WHERE `units_filter` IS NULL") as $row) {
        $g  = strtolower(preg_replace('/[^a-z]/', '', (string) $row['group']));
        $n  = strtolower(preg_replace('/[^a-z]/', '', (string) $row['status_val']));
        $ia = strtolower((string) $row['incident_action']);
        $probe = $g !== '' ? $g : $n;
        $class = null;
        if (in_array($ia, ['dispatched', 'responding', 'on_scene'], true)) {
            $class = 'in_service';
        } elseif (strpos($probe, 'un') === 0 || strpos($probe, 'off') === 0
               || strpos($probe, 'out') === 0 || $probe === 'na' || strpos($probe, 'oos') === 0) {
            $class = 'unavailable';
        } elseif (strpos($probe, 'inserv') === 0 || strpos($probe, 'service') === 0
               || $probe === 'is' || $probe === 'en' || $probe === 'call'
               || strpos($probe, 'busy') === 0 || strpos($probe, 'disp') === 0) {
            $class = 'in_service';
        } elseif (strpos($probe, 'av') === 0 || $probe === 'a'
               || $probe === 'rdy' || strpos($probe, 'ready') === 0) {
            $class = 'available';
        }
        if ($class !== null) {
            db_query("UPDATE `{$prefix}un_status` SET `units_filter` = ? WHERE `id` = ?",
                [$class, (int) $row['id']]);
            $seeded++;
        }
    }
    echo "seeded: {$seeded} status(es) classified (unmatched rows stay NULL = legacy fallback)\n";
} catch (Exception $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}

echo "\nDone.\n";
