<?php
/**
 * Run Phase 11d migration — mobile-first role flag + self-heal.
 *
 * The "go to mobile.php on login" routing was hardcoded to:
 *   - user.level === 4         (legacy Field Unit equivalent)
 *   - OR user_roles.role_id == 6 (hardcoded Field Unit row)
 *
 * Two problems:
 *   1. Magic id=6 doesn't survive Phase 11c rename of the built-in.
 *   2. Phase 11 made user.level=4 the fallback for custom roles with
 *      no legacy_level mapping. So any new admin-created role (e.g.,
 *      "Internal Auditor") sent its users to the mobile interface.
 *      Caught by Eric on 2026-06-11.
 *
 * This migration:
 *   - Adds roles.mobile_first TINYINT(1) NOT NULL DEFAULT 0
 *   - Backfills mobile_first=1 on whichever role has legacy_level=4
 *     (the Field Unit built-in, even if it's been renamed)
 *   - Self-heals: re-derives user.level for users whose primary
 *     active role has NULL legacy_level (i.e., on a custom role) to
 *     3 (Read-Only) instead of the buggy 4 (Field Unit).
 *
 * Idempotent.
 *
 * Usage:  php sql/run_phase11d_mobile_first.php
 */
require_once __DIR__ . '/../config.php';

echo "Phase 11d — mobile-first role flag + self-heal\n";
echo "==============================================\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

// ── 1. roles.mobile_first column ────────────────────────────────────────
try {
    $col = db_fetch_one(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = ?
           AND COLUMN_NAME  = 'mobile_first'",
        [$prefix . 'roles']
    );
    if (!$col) {
        db_query(
            "ALTER TABLE `{$prefix}roles`
             ADD COLUMN `mobile_first` TINYINT(1) NOT NULL DEFAULT 0
             COMMENT 'Send users with this role to the mobile interface on login'"
        );
        echo "[OK] Added roles.mobile_first column\n";
    } else {
        echo "[OK] roles.mobile_first already exists (skipped)\n";
    }
} catch (Exception $e) {
    echo "[WARN] mobile_first column: " . $e->getMessage() . "\n";
}

// ── 2. Backfill mobile_first=1 on the Field Unit role ──────────────────
// Identify by legacy_level=4 rather than magic id=6 — survives renames.
try {
    $stmt = db_query(
        "UPDATE `{$prefix}roles`
         SET mobile_first = 1
         WHERE legacy_level = 4 AND mobile_first = 0"
    );
    $updated = $stmt ? $stmt->rowCount() : 0;
    if ($updated > 0) {
        $rows = db_fetch_all(
            "SELECT id, name FROM `{$prefix}roles` WHERE legacy_level = 4"
        );
        foreach ($rows as $r) {
            echo "[OK] role id={$r['id']} ({$r['name']}) → mobile_first=1\n";
        }
    } else {
        echo "[OK] mobile_first already set on the Field Unit role (skipped)\n";
    }
} catch (Exception $e) {
    echo "[WARN] backfill mobile_first: " . $e->getMessage() . "\n";
}

// ── 3. Self-heal user.level for users on custom roles ──────────────────
// Phase 11 set user.level=4 as the fallback for users whose role had
// no legacy_level mapping (custom admin-created roles). That fallback
// was wrong — level 4 triggers the mobile redirect. Re-derive to 3.
try {
    $rows = db_fetch_all(
        "SELECT u.id AS uid, u.user, u.level AS old_level, r.id AS rid, r.name AS rname
         FROM `{$prefix}user` u
         JOIN `{$prefix}user_roles` ur ON ur.user_id = u.id
            AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
         JOIN `{$prefix}roles` r ON r.id = ur.role_id
         WHERE r.legacy_level IS NULL
           AND r.mobile_first = 0
           AND u.level = 4
         GROUP BY u.id"
    );
    if (empty($rows)) {
        echo "[OK] no users need self-heal (no level=4 from custom-role fallback)\n";
    } else {
        foreach ($rows as $r) {
            db_query(
                "UPDATE `{$prefix}user` SET level = 3 WHERE id = ?",
                [$r['uid']]
            );
            echo "[OK] healed {$r['user']}: was level=4 (from custom role '{$r['rname']}'), now level=3\n";
        }
    }
} catch (Exception $e) {
    echo "[WARN] self-heal: " . $e->getMessage() . "\n";
}

// ── 4. Report final state ──────────────────────────────────────────────
try {
    $rows = db_fetch_all(
        "SELECT id, name, legacy_level, mobile_first, is_system
         FROM `{$prefix}roles`
         ORDER BY sort_order, name"
    );
    echo "\nAll roles after migration:\n";
    foreach ($rows as $r) {
        printf("  id=%-3d  name=%-22s  legacy_level=%-4s  mobile_first=%d  is_system=%d\n",
            $r['id'],
            $r['name'],
            $r['legacy_level'] === null ? 'NULL' : (string)$r['legacy_level'],
            (int)$r['mobile_first'],
            (int)$r['is_system']
        );
    }
} catch (Exception $e) {
    echo "[WARN] report: " . $e->getMessage() . "\n";
}

echo "\nDone.\n";
