<?php
/**
 * Phase 12 — Org Admin / action.manage_config. REVOKES, does not grant.
 *
 * REVERSED 2026-08-20 (found while building GH#96's Mileage Log report --
 * a live test scoping a plain Org Admin account against api/reports.php's
 * org_id-authorization check unexpectedly resolved is_admin()=true for
 * that account on a genuinely fresh install, exposing this).
 *
 * ORIGINAL 2026-06-11 intent (kept for history, no longer correct): "Phase
 * 12's is_admin() helper recognizes a user as admin iff any active role
 * grant is on a role with is_super=1, OR the user holds
 * action.manage_config. The 6 system roles backfilled in Phase 11 left
 * Org Admin (role 2) without action.manage_config, even though under the
 * legacy model Administrator-tier users (level 1) had full admin access.
 * Phase 12 preserves that by adding action.manage_config to Org Admin."
 * That rationale is OBSOLETE: Phase 128 (2026-07-29) deleted the legacy
 * user.level fallback entirely, and multiple LATER, explicit decisions
 * (2026-07-04 bulk-member-deletion; 2026-07-07's action.manage_config/
 * action.manage_roles exclusion; 2026-08-16's RBAC canonical-alias-leak
 * fix) all establish that action.manage_config is Super-Admin-ONLY and
 * Org Admin must NOT hold it -- is_admin()'s own contract (inc/rbac.php)
 * is "is_super=1, OR holds action.manage_config", so ANY install where
 * this script had ever run left every Org Admin account with full,
 * unrestricted Super-Admin-equivalent access via that fallback, a
 * complete defeat of the Org-Admin/Super-Admin boundary this codebase
 * has since gone to considerable lengths to build and repeatedly repair
 * (see sql/rbac.sql's own "RBAC canonical-alias privilege-leak fix"
 * repair-DELETEs, which target exactly this permission for exactly this
 * role -- but never had a chance to catch THIS script's grant, because
 * this file is a `run_*.php` migration sql/run_migrations.php discovers
 * and executes in LEXICOGRAPHIC filename order (ksort()), and
 * "run_phase12_..." sorts BEFORE sql/rbac.sql's repair even runs during
 * a fresh install's foundational-SQL-import phase -- rbac.sql's repair
 * literally cannot see a grant this script has not made YET at that
 * point in the sequence, and nothing re-runs rbac.sql's repair
 * afterward on a straight fresh install).
 *
 * This script's OWN content-hash is tracked in `_migrations`
 * (script_name, script_hash) -- changing its behavior here is safe and
 * self-propagating by design: on a fresh install it now revokes nothing
 * (there is nothing to revoke yet) and grants nothing; on any EXISTING
 * install that already ran the old grant, the hash change makes
 * sql/run_migrations.php treat this as a new pending migration and
 * re-run it, this time revoking the leaked grant. Idempotent either way.
 *
 * Revokes BOTH mechanisms that could carry the grant, matching
 * sql/rbac.sql's own two-part repair exactly:
 *   (1) DIRECT — a role_permissions row for (role 2, action.manage_config).
 *   (2) ALIAS — a role_permissions row for (role 2, <the canonical
 *       resource.verb code action.manage_config was migrated to by
 *       sql/run_rbac_v2.php's A8 step>), reached via permissions.
 *       deprecated_alias_of. A8's own role_permissions mirroring
 *       (grant the canonical code to every role that already held the
 *       old code) is exactly how a bad direct grant from THIS script
 *       propagates forward into the canonical code too, once A8 runs
 *       later in the same fresh-install sequence.
 *
 * Usage:  php sql/run_phase12_org_admin_manage_config.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';

echo "Phase 12 (reversed 2026-08-20) — revoke action.manage_config from Org Admin\n";
echo "=============================================================================\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

try {
    $orgAdmin = db_fetch_one(
        "SELECT id FROM `{$prefix}roles` WHERE id = 2 LIMIT 1"
    );
    if (!$orgAdmin) {
        echo "[--] Role id=2 not present; nothing to do.\n";
        exit(0);
    }
    $orgAdminId = (int) $orgAdmin['id'];

    // (1) DIRECT revoke.
    $directRemoved = 0;
    try {
        $stmt = db_query(
            "DELETE `{$prefix}role_permissions` FROM `{$prefix}role_permissions`
             JOIN `{$prefix}permissions` p ON p.id = `{$prefix}role_permissions`.permission_id
             WHERE `{$prefix}role_permissions`.role_id = ? AND p.code = 'action.manage_config'",
            [$orgAdminId]
        );
        $directRemoved = $stmt ? $stmt->rowCount() : 0;
    } catch (Exception $e) {
        echo "[WARN] direct-revoke query failed: " . $e->getMessage() . "\n";
    }

    // (2) ALIAS revoke — only meaningful once sql/run_rbac_v2.php's A8 step
    // has created a canonical code for action.manage_config; harmless
    // no-op (0 rows) before that.
    $aliasRemoved = 0;
    try {
        $stmt = db_query(
            "DELETE rp FROM `{$prefix}role_permissions` rp
             JOIN `{$prefix}permissions` canon ON canon.id = rp.permission_id
             JOIN `{$prefix}permissions` old_p ON old_p.deprecated_alias_of = canon.code
             WHERE rp.role_id = ? AND old_p.code = 'action.manage_config'",
            [$orgAdminId]
        );
        $aliasRemoved = $stmt ? $stmt->rowCount() : 0;
    } catch (Exception $e) {
        echo "[WARN] alias-revoke query failed: " . $e->getMessage() . "\n";
    }

    if ($directRemoved > 0 || $aliasRemoved > 0) {
        echo "[OK] Revoked action.manage_config from role id={$orgAdminId} (Org Admin) "
            . "— direct: {$directRemoved}, canonical-alias: {$aliasRemoved}\n";
    } else {
        echo "[OK] role id={$orgAdminId} (Org Admin) does not hold action.manage_config "
            . "(directly or via alias) — nothing to revoke\n";
    }
} catch (Exception $e) {
    echo "[WARN] " . $e->getMessage() . "\n";
}

echo "\nDone.\n";
