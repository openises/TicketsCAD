<?php
/**
 * Backfill user_roles.scope_id for org-scoped grants  (GH #56)
 *
 * Root cause (Billy Irwin / K9OH, 2026-07-04): the User Accounts form writer in
 * api/config-admin.php inserted org-scoped role grants with the org in the
 * `org_id` column but `scope_id = NULL`. The RBAC engine matches on `scope_id`
 * (_rbac_scope_satisfied compares active_org_id === grant.scope_id), so every
 * such grant was dead on arrival — the user got "access denied" on every screen
 * despite all permission boxes being checked. The writer is fixed going forward;
 * this repairs rows already written the broken way.
 *
 * Idempotent: only touches org-scoped rows whose scope_id is NULL/0 but whose
 * org_id mirror is a real org. Safe to run repeatedly. Auto-discovered by
 * sql/run_migrations.php (and therefore tools/deploy.sh).
 *
 * Usage: php sql/run_backfill_org_scope_id.php
 */
require_once __DIR__ . '/../config.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

echo "Backfill user_roles.scope_id for org-scoped grants (GH #56)\n";
echo "===========================================================\n\n";

try {
    // Pre-check: RBAC v2 columns present?
    $hasCols = db_fetch_one(
        "SELECT COUNT(*) AS c FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME IN ('scope_kind', 'scope_id', 'org_id')",
        [$prefix . 'user_roles']
    );
    if (!$hasCols || (int) $hasCols['c'] < 3) {
        echo "[SKIP] user_roles is not on the RBAC-v2 schema (scope_kind/scope_id/org_id) — nothing to do.\n";
        exit(0);
    }

    // How many rows are broken (org-scoped, scope_id empty, org_id real)?
    $broken = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}user_roles`
          WHERE scope_kind = 'org'
            AND (scope_id IS NULL OR scope_id = 0)
            AND org_id IS NOT NULL AND org_id > 0"
    );

    if ($broken === 0) {
        echo "[OK] No broken org-scoped grants found — nothing to repair.\n";
        exit(0);
    }

    echo "[..] Found {$broken} org-scoped grant(s) with a NULL/0 scope_id; repairing from org_id...\n";
    db_query(
        "UPDATE `{$prefix}user_roles`
            SET scope_id = org_id
          WHERE scope_kind = 'org'
            AND (scope_id IS NULL OR scope_id = 0)
            AND org_id IS NOT NULL AND org_id > 0"
    );

    // Confirm.
    $remaining = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}user_roles`
          WHERE scope_kind = 'org'
            AND (scope_id IS NULL OR scope_id = 0)
            AND org_id IS NOT NULL AND org_id > 0"
    );
    echo "[OK] Repaired {$broken} grant(s); {$remaining} still broken (should be 0).\n";
    echo "\nNote: affected users must LOG OUT and back IN — active_org_id is set at login.\n";
} catch (Exception $e) {
    echo "[WARN] Backfill failed: " . $e->getMessage() . "\n";
    // Non-fatal: never block the migration runner on a repair.
}

echo "\nDone.\n";
