<?php
/**
 * Phase 31 (2026-06-12) — un_status.resets_par
 *
 * Eric on 2026-06-12: "The timer starts when a unit is dispatched,
 * then resets when they indicate responding, and again when they
 * indicate arrived. Generally I'd say PAR resets on status changes,
 * or we should configure which statuses reset PAR for that unit.
 * Generally, we'll call for a PAR check of all assigned units when
 * the first unit reaches the time limit."
 *
 * Implements the "configure which statuses reset PAR" piece. Each
 * un_status row gets a boolean `resets_par`. When a unit's recorded
 * status (or transition to dispatched / responding / on_scene)
 * happens via an un_status with resets_par=1, that timestamp counts
 * as the unit's last reset event for cadence computation.
 *
 * Seed defaults align with Phase 25 — any un_status whose
 * incident_action is 'dispatched', 'responding', or 'on_scene'
 * is set to resets_par=1. Everything else (Available, Standby,
 * Out of Service, At Facility, etc.) defaults to 0; admins can
 * tick the box for any other status that should reset.
 *
 * Idempotent. Safe to re-run.
 */
require_once __DIR__ . '/../config.php';

echo "Phase 31 — un_status.resets_par\n";
echo "===============================\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

try {
    $col = db_fetch_one(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME   = ?
            AND COLUMN_NAME  = 'resets_par'",
        [$prefix . 'un_status']
    );
    if (!$col) {
        db_query(
            "ALTER TABLE `{$prefix}un_status`
             ADD COLUMN `resets_par` TINYINT(1) NOT NULL DEFAULT 0
             COMMENT 'Phase 31: 1=when a unit enters this status, reset its PAR cadence timer. Default seed: 1 for incident_action in (dispatched/responding/on_scene).'"
        );
        echo "[OK] Added un_status.resets_par\n";

        // Seed defaults from Phase 25's incident_action mapping.
        $stmt = db_query(
            "UPDATE `{$prefix}un_status`
                SET resets_par = 1
              WHERE incident_action IN ('dispatched','responding','on_scene')"
        );
        $n = $stmt ? $stmt->rowCount() : 0;
        echo "[OK] Seeded resets_par=1 on {$n} row(s) (dispatched/responding/on_scene)\n";
    } else {
        echo "[OK] un_status.resets_par already exists\n";
    }
} catch (Exception $e) {
    echo "[FAIL] " . $e->getMessage() . "\n";
    exit(1);
}

// Report mapping.
echo "\nCurrent reset mapping:\n";
try {
    foreach (db_fetch_all(
        "SELECT id, status_val, incident_action, resets_par
           FROM `{$prefix}un_status`
          ORDER BY sort, id") as $r) {
        printf("  id=%-3d %-18s action=%-12s resets_par=%d\n",
            $r['id'],
            $r['status_val'],
            $r['incident_action'] === '' ? '(none)' : $r['incident_action'],
            (int) $r['resets_par']);
    }
} catch (Exception $e) {}

echo "\nDone.\n";
