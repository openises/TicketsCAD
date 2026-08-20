<?php
/**
 * Run Phase 145 migration — facility-account schema cleanup (GH#90 / GH#91).
 *
 * GH#90 asked whether a facility login (v3's LEVEL_FACILITY) was ever
 * meant to grow in v4, and — noting `user.level`, `user.responder_id`,
 * `user.facility_id` are level-era columns nothing currently reads — asked
 * whether they should be dropped. This migration IS the answer, split
 * three ways (documented in full in the GH#90 close-out comment and
 * docs/FACILITY-ACCOUNTS.md):
 *
 *   - `user.facility_id`  → REPURPOSED. It is now the real link for a
 *     facility account (see inc/facility-scope.php). NOT dropped — its
 *     column comment is updated to say so, and its existing NOT NULL
 *     DEFAULT 0 shape (int(7)) is left exactly as-is, since 0 already
 *     means "no facility" and every existing row is already 0.
 *   - `user.responder_id` → DROPPED here. Confirmed via full-tree grep
 *     (2026-08-19) that nothing reads `user.responder_id` anywhere —
 *     the Field Unit role links a login to a unit from the OTHER
 *     direction (`responder.user_id` / `responder.personal_for_member_id`,
 *     resolved by mobile_resolve_responder_id() in api/mobile-data.php),
 *     so this column was never the mechanism for that either. Safe to
 *     drop independently of the facility_id decision.
 *   - `user.level`        → LEFT ALONE. Already fully dead as an
 *     authorization signal since Phase 128 (inc/rbac.php's deleted
 *     _rbac_legacy_check()); this migration takes no position on
 *     dropping it — that's GH#91's broader ~15-column `user` table
 *     cleanup to decide, unblocked by this decision either way.
 *
 * The role/permission seeding (the Facility role (resolved by name, never a fixed id),
 * screen.facility_portal, action.facility_self_report) lives directly in
 * sql/rbac.sql and sql/run_00_rbac.php (both idempotent, both already
 * reached by every install path) — NOT duplicated here. This migration
 * only verifies that seeding landed and reports a WARN if it didn't,
 * rather than re-implementing it as a second source of truth.
 *
 * Idempotent — every step guarded by an information_schema existence
 * check. Safe to re-run.
 *
 * Usage:  php sql/run_phase145_facility_accounts.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';

echo "Phase 145 — facility-account schema cleanup (GH#90 / GH#91)\n";
echo "=============================================================\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

// ── 1. Drop user.responder_id (confirmed dead — see docblock above) ────
try {
    $col = db_fetch_one(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'responder_id'",
        [$prefix . 'user']
    );
    if ($col) {
        db_query("ALTER TABLE `{$prefix}user` DROP COLUMN `responder_id`");
        echo "[OK] Dropped user.responder_id (dead v3 column — Field Unit links the\n";
        echo "     other way, via responder.user_id)\n";
    } else {
        echo "[OK] user.responder_id already absent (skipped)\n";
    }
} catch (Exception $e) {
    echo "[WARN] drop user.responder_id: " . $e->getMessage() . "\n";
}

// ── 2. Re-purpose user.facility_id — update its comment only ───────────
// The column itself (int(7) NOT NULL DEFAULT 0) is left untouched: 0
// already means "not a facility account" for every existing row on every
// install, which is exactly the invariant the new meaning needs.
try {
    $col = db_fetch_one(
        "SELECT COLUMN_COMMENT FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'facility_id'",
        [$prefix . 'user']
    );
    if ($col === null) {
        echo "[WARN] user.facility_id column not found — cannot repurpose\n";
    } elseif (strpos((string) ($col['COLUMN_COMMENT'] ?? ''), 'Phase 145') !== false) {
        echo "[OK] user.facility_id comment already updated (skipped)\n";
    } else {
        db_query(
            "ALTER TABLE `{$prefix}user`
             MODIFY COLUMN `facility_id` int(7) NOT NULL DEFAULT 0
             COMMENT 'Phase 145 (GH#90): facility-account link. >0 = this login is confined to that facility (see inc/facility-scope.php). 0 = not a facility account.'"
        );
        echo "[OK] Re-purposed user.facility_id (comment updated; column shape unchanged)\n";
    }
} catch (Exception $e) {
    echo "[WARN] repurpose user.facility_id: " . $e->getMessage() . "\n";
}

// ── 3. Verify the Facility role + permissions landed (seeded by ────────
//      sql/rbac.sql / sql/run_00_rbac.php — not duplicated here) ───────
try {
    // Resolved by NAME, never a hardcoded id — see sql/rbac.sql's comment
    // on the Facility role INSERT for why (a pre-existing custom role can
    // already occupy any given id on a real install).
    $role = db_fetch_one("SELECT id, name FROM `{$prefix}roles` WHERE name = 'Facility' LIMIT 1");
    if ($role) {
        echo "[OK] Facility role present (id={$role['id']}, name='{$role['name']}')\n";
    } else {
        echo "[WARN] Facility role not found — check sql/run_00_rbac.php ran\n";
    }
    $permCount = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}permissions`
          WHERE `code` IN ('screen.facility_portal', 'action.facility_self_report')"
    );
    if ($permCount === 2) {
        echo "[OK] Both facility-portal permissions present\n";
    } else {
        echo "[WARN] Expected 2 facility-portal permissions, found {$permCount} — check sql/run_00_rbac.php ran\n";
    }
    $grantCount = $role ? (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}role_permissions` rp
         JOIN `{$prefix}permissions` p ON p.id = rp.permission_id
         WHERE rp.role_id = ? AND p.code IN ('screen.facility_portal', 'action.facility_self_report')",
        [(int) $role['id']]
    ) : 0;
    if ($grantCount === 2) {
        echo "[OK] Facility role holds both permissions\n";
    } else {
        echo "[WARN] Facility role holds {$grantCount}/2 expected permission grants\n";
    }
} catch (Exception $e) {
    echo "[WARN] verify Facility role/permissions: " . $e->getMessage() . "\n";
}

echo "\nDone.\n";
