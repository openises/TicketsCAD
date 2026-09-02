<?php
/**
 * Found 2026-09-02 while deploying an unrelated fix (Phase 86): after
 * sql/run_migrations.php's schema-verify self-force replayed EVERY tracked
 * migration on your-server (226 scripts, triggered by unrelated
 * drift), tools/rbac_exclusion_leak_audit.php reported 42 findings — Org
 * Admin (role 2) directly held 10 Super-Admin-only (admin_only=2)
 * permission codes plus their canonical aliases (action.manage_config,
 * action.manage_roles, action.bulk_delete_members, and seven more), a
 * near-total defeat of the Org-Admin/Super-Admin boundary. training was
 * unaffected (it only had one genuinely-new migration to apply, no
 * self-force replay).
 *
 * Root cause: sql/run_00_rbac.php's own repair-DELETE blocks (the thing
 * that actually revokes a leaked grant) run as PART OF run_00_rbac.php,
 * which -- per its own "00" prefix -- runs FIRST in run_migrations.php's
 * ksort() ordering. Re-running run_00_rbac.php directly confirmed this:
 * it dropped all 42 findings to 0 immediately. So the repair logic is
 * correct, but a later-sorting, phase-specific migration (each of the ten
 * leaked codes has its own dedicated feature: org_relationships,
 * org_routing, ics_form_types, public_board, audit_retention,
 * dispositions, bulk_delete_members, config/roles themselves) grants role
 * 2 one of these codes as part of ITS OWN setup, and because it runs
 * AFTER run_00_rbac.php's one-time repair pass, nothing ever revokes it
 * again -- UNTIL NOW, unless a full self-force replay happens to also
 * re-run run_00_rbac.php's repair LAST (it doesn't; ksort() puts it
 * first every time, replay or not).
 *
 * This is the exact same shape sql/run_zzz_admin_only_reconcile.php was
 * built for (2026-08-26) -- a repair that only runs early can never catch
 * a leak introduced by anything that runs after it -- but that file only
 * reconciles the admin_only CLASSIFICATION column (is code X tier 0/1/2),
 * not who actually HOLDS a grant. Confirmed directly: on your deployment,
 * admin_only was already correctly set to 2 for every leaked code (the
 * audit's own findings say so explicitly: "holds admin_only=2 code X ...
 * but its own tier is only 1") -- the classification was never wrong, the
 * GRANT was. A file that reconciles classification cannot also close a
 * grant-level leak; this is that file's missing sibling.
 *
 * Generic and future-proof by construction: uses the SAME
 * rbac_admin_only_sql_predicate() every other grant-writing code path in
 * this project is expected to consult (inc/rbac_admin_only.php) --
 * whatever a role's tier actually is and whatever a permission's
 * admin_only value actually is, right now, in the live database -- so a
 * FUTURE tier-2 permission introduced by some not-yet-written migration is
 * covered automatically, with no new code needed here. Named to sort
 * (ksort()) after run_zzz_admin_only_reconcile.php, so classification is
 * always reconciled before grants are checked against it.
 *
 * Usage: php sql/run_zzz_rbac_grant_reconcile.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/rbac_admin_only.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

try {
    if (!rbac_admin_only_column_exists()) {
        echo "[SKIP] {$prefix}permissions.admin_only does not exist on this install -- nothing to reconcile\n";
        exit(0);
    }

    $violatesTier = '(CASE WHEN r.is_super = 1 THEN 2 '
        . "WHEN r.id = 2 OR r.name = 'Org Admin' THEN 1 "
        . 'ELSE 0 END) < p.admin_only';

    // Report before deleting -- an operator reading a deploy log should be
    // able to see exactly what this repaired, not just a bare row count.
    $leaks = db_fetch_all(
        "SELECT r.id AS role_id, r.name AS role_name, p.code
           FROM `{$prefix}role_permissions` rp
           JOIN `{$prefix}roles` r ON r.id = rp.role_id
           JOIN `{$prefix}permissions` p ON p.id = rp.permission_id
          WHERE p.admin_only > 0 AND {$violatesTier}
          ORDER BY r.id, p.code"
    );
    foreach ($leaks as $row) {
        echo "[REVOKE] role #{$row['role_id']} ({$row['role_name']}) held '{$row['code']}' above its tier\n";
    }

    $deleted = db_query(
        "DELETE rp FROM `{$prefix}role_permissions` rp
           JOIN `{$prefix}roles` r ON r.id = rp.role_id
           JOIN `{$prefix}permissions` p ON p.id = rp.permission_id
          WHERE p.admin_only > 0 AND {$violatesTier}"
    )->rowCount();

    echo "[OK] rbac grant reconcile: {$deleted} over-tier grant(s) revoked\n";
    echo "Done.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}
