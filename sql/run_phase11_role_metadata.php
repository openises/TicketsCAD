<?php
/**
 * Run Phase 11 migration — role metadata for canonical RBAC identity.
 *
 * Adds:
 *   roles.legacy_level INT NULL
 *     Reverse map: which v3.x integer level corresponds to this role.
 *     Used by api/config-admin.php to keep user.level in sync when
 *     admin assigns a role via the User Accounts form.
 *
 *   roles.is_system TINYINT(1) NOT NULL DEFAULT 0
 *     Marks the 6 built-in system roles. Cannot be deleted via the UI.
 *     Admin can rename, re-permission, or re-prioritize them.
 *
 * Backfills the 6 default roles with their canonical mappings (see
 * specs/phase-11-rbac-canonical-2026-06/plan.md for the table).
 *
 * Idempotent. Guards via information_schema.COLUMNS lookups.
 *
 * Usage:  php sql/run_phase11_role_metadata.php
 */
require_once __DIR__ . '/../config.php';

echo "Phase 11 — role metadata migration\n";
echo "==================================\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

// ── 1. roles.legacy_level column ─────────────────────────────────────────
try {
    $col = db_fetch_one(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = ?
           AND COLUMN_NAME  = 'legacy_level'",
        [$prefix . 'roles']
    );
    if (!$col) {
        db_query(
            "ALTER TABLE `{$prefix}roles`
             ADD COLUMN `legacy_level` INT NULL
             COMMENT 'Reverse map: which v3.x level integer corresponds to this role'"
        );
        echo "[OK] Added roles.legacy_level column\n";
    } else {
        echo "[OK] roles.legacy_level already exists (skipped)\n";
    }
} catch (Exception $e) {
    echo "[WARN] legacy_level column: " . $e->getMessage() . "\n";
}

// ── 2. roles.is_system column ────────────────────────────────────────────
try {
    $col = db_fetch_one(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = ?
           AND COLUMN_NAME  = 'is_system'",
        [$prefix . 'roles']
    );
    if (!$col) {
        db_query(
            "ALTER TABLE `{$prefix}roles`
             ADD COLUMN `is_system` TINYINT(1) NOT NULL DEFAULT 0
             COMMENT 'System-built-in role — protected from deletion'"
        );
        echo "[OK] Added roles.is_system column\n";
    } else {
        echo "[OK] roles.is_system already exists (skipped)\n";
    }
} catch (Exception $e) {
    echo "[WARN] is_system column: " . $e->getMessage() . "\n";
}

// ── 3. Backfill the 6 default roles ──────────────────────────────────────
//
// The mapping mirrors the existing levelToRole table in
// api/config-admin.php and tools/migrate_rbac.php. Two roles share
// legacy_level=2 (Dispatcher + Operator); that's intentional — both are
// "non-admin staff" in legacy v3.x terms. Forward direction (role →
// level) is unambiguous; reverse direction is handled by the existing
// migration tool's existing mapping.
$backfill = [
    1 => ['name' => 'Super Admin', 'legacy_level' => 0],
    2 => ['name' => 'Org Admin',   'legacy_level' => 1],
    3 => ['name' => 'Dispatcher',  'legacy_level' => 2],
    4 => ['name' => 'Operator',    'legacy_level' => 2],
    5 => ['name' => 'Read-Only',   'legacy_level' => 3],
    6 => ['name' => 'Field Unit',  'legacy_level' => 4],
];

$applied = 0;
foreach ($backfill as $id => $info) {
    try {
        // Only backfill if the role actually exists with this name. We
        // don't want to misclassify a custom role whose admin happens
        // to have used id=3.
        $existing = db_fetch_one(
            "SELECT id, name, legacy_level, is_system FROM `{$prefix}roles`
             WHERE id = ? LIMIT 1",
            [$id]
        );
        if (!$existing) {
            echo "[--] role id={$id} ({$info['name']}) not present — skipped\n";
            continue;
        }
        // Only update if the legacy_level is currently NULL OR is_system=0.
        // If admin has already customized these, leave them alone.
        $needsUpdate = ($existing['legacy_level'] === null) || (int)$existing['is_system'] === 0;
        if ($needsUpdate) {
            db_query(
                "UPDATE `{$prefix}roles`
                 SET legacy_level = ?, is_system = 1
                 WHERE id = ? AND (legacy_level IS NULL OR is_system = 0)",
                [$info['legacy_level'], $id]
            );
            $applied++;
            echo "[OK] role id={$id} ({$existing['name']}) → legacy_level={$info['legacy_level']}, is_system=1\n";
        } else {
            echo "[OK] role id={$id} ({$existing['name']}) already stamped (legacy_level={$existing['legacy_level']}, is_system={$existing['is_system']})\n";
        }
    } catch (Exception $e) {
        echo "[WARN] role id={$id}: " . $e->getMessage() . "\n";
    }
}

echo "\nBackfilled {$applied} role(s)\n";

// ── 4. Report final state ────────────────────────────────────────────────
try {
    $rows = db_fetch_all(
        "SELECT id, name, legacy_level, is_system FROM `{$prefix}roles` ORDER BY sort_order, name"
    );
    echo "\nAll roles after migration:\n";
    foreach ($rows as $r) {
        printf("  id=%-3d  name=%-20s  legacy_level=%-4s  is_system=%d\n",
            $r['id'],
            $r['name'],
            $r['legacy_level'] === null ? 'NULL' : (string)$r['legacy_level'],
            (int)$r['is_system']
        );
    }
} catch (Exception $e) {
    echo "[WARN] report: " . $e->getMessage() . "\n";
}

echo "\nDone.\n";
