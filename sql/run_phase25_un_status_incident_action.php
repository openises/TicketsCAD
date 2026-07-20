<?php
/**
 * Phase 25 (2026-06-11) — un_status.incident_action
 *
 * Eric on 2026-06-11: the incident-detail assignment row was
 * hardcoded to Responding/On Scene/Clear buttons. Admin can define
 * 10+ unit statuses but most of them were unreachable from the
 * incident screen. The old code auto-set responder.un_status_id by
 * matching status names via LIKE — brittle if admin renames a
 * status.
 *
 * This migration:
 *
 *   1. Adds un_status.incident_action ENUM(''|'dispatched'|'responding'
 *      |'on_scene'|'clear'). NULL/empty = "just a flag, no
 *      assigns timestamp."
 *
 *   2. Seeds sensible defaults by name on installs that haven't
 *      been customized — admin can change via Settings → Unit Statuses.
 *
 * Idempotent. Required for the redesigned incident-detail status
 * dropdown.
 */
require_once __DIR__ . '/../config.php';

echo "Phase 25 — un_status.incident_action\n";
echo "====================================\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

// ── Schema ──────────────────────────────────────────────────────────
try {
    $col = db_fetch_one(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME   = ?
            AND COLUMN_NAME  = 'incident_action'",
        [$prefix . 'un_status']
    );
    if (!$col) {
        db_query(
            "ALTER TABLE `{$prefix}un_status`
             ADD COLUMN `incident_action`
                 ENUM('','dispatched','responding','on_scene','clear')
                 NOT NULL DEFAULT ''
             COMMENT 'Phase 25: assigns timestamp this status maps to'"
        );
        echo "[OK] Added un_status.incident_action\n";
    } else {
        echo "[OK] un_status.incident_action already exists\n";
    }
} catch (Exception $e) {
    echo "[FAIL] " . $e->getMessage() . "\n";
    exit(1);
}

// ── Seed defaults ──────────────────────────────────────────────────
// Only write where the column is still the default (''). Don't
// clobber admin customizations from a re-run.
$seeds = [
    'dispatched'  => "LOWER(status_val) LIKE '%dispatch%'",
    'responding'  => "LOWER(status_val) LIKE '%respond%' OR LOWER(status_val) LIKE '%en route%' OR LOWER(status_val) LIKE '%enroute%'",
    'on_scene'    => "LOWER(status_val) LIKE '%on scene%' OR LOWER(status_val) LIKE '%on-scene%' OR LOWER(status_val) LIKE '%arrived%'",
    // Phase 103d (a beta tester GH #19) — the original Phase 25 seed left
    // 'clear' unmapped, so a unit going Available on a real install
    // never stamped assigns.clear and the unit stayed on the
    // incident's active-responder list. The June 28 commit 9dbc802
    // fixed the incident-detail query to hide already-cleared
    // assigns, but the *transition* still needed a status whose
    // incident_action='clear' — which nothing seeded.
    'clear'       => "LOWER(status_val) LIKE '%available%' OR LOWER(status_val) LIKE '%clear%' OR LOWER(status_val) IN ('av','ir','in service','in-service','in_service')",
];
$total = 0;
foreach ($seeds as $action => $where) {
    try {
        $stmt = db_query("
            UPDATE `{$prefix}un_status`
               SET incident_action = ?
             WHERE incident_action = ''
               AND ({$where})",
            [$action]);
        $n = $stmt ? $stmt->rowCount() : 0;
        if ($n > 0) echo "  - {$action}: stamped {$n} row(s)\n";
        $total += $n;
    } catch (Exception $e) {
        echo "[WARN] seed {$action}: " . $e->getMessage() . "\n";
    }
}
echo "[OK] Seeded incident_action on {$total} row(s) total.\n";
echo "     Verify mapping at Settings → Unit Statuses.\n";

// Report current state.
echo "\nCurrent mapping:\n";
try {
    foreach (db_fetch_all(
        "SELECT id, status_val, incident_action
           FROM `{$prefix}un_status`
          ORDER BY sort, id") as $r) {
        printf("  id=%-3d %-18s → %s\n",
            $r['id'],
            $r['status_val'],
            $r['incident_action'] === '' ? '(none)' : $r['incident_action']);
    }
} catch (Exception $e) {}

echo "\nDone.\n";
