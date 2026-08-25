<?php
/**
 * NewUI v4.0 — RBAC "admin_only" structural guard
 *
 * Root-cause fix for a bug class this project has hit FIVE separate times
 * (see CLAUDE.md's several "RBAC EXCLUSION-LIST MECHANISM LEAKS" entries,
 * most recently Phase 149's action.manage_calls leaking onto Dispatcher via
 * sql/run_rbac_v2.php's A8 canonical-alias mirror step — in the SAME COMMIT
 * that created the permission and its own exclusion-list entry): an
 * admin-only permission code silently ends up granted to a role that should
 * never hold it, because "admin-only" was never a real, checkable database
 * property — only a hand-maintained `WHERE code NOT IN (...)` string list in
 * sql/rbac.sql and sql/run_00_rbac.php that a new canonical alias, a new
 * migration, or a new broad grant statement can all bypass invisibly.
 *
 * This file makes "admin-only" a real, structural, schema-level property
 * (permissions.admin_only) and provides the ONE guard every grant-writing
 * code path is expected to consult before writing a role_permissions row:
 *
 *   rbac_grant_permission_allowed(int $roleId, int $permissionId): bool
 *   rbac_assert_grant_permission_allowed(int $roleId, int $permissionId): void
 *
 * ── The tier model ──────────────────────────────────────────────────────
 *
 * permissions.admin_only is NOT a plain boolean, deliberately. The five
 * documented incidents span TWO different tiers of "admin-only", and a
 * single boolean cannot represent both without either (a) missing the
 * Phase 149 incident specifically (manage_calls/manage_matrix are meant
 * for Org Admin AND Super Admin, only Dispatcher-and-below must never hold
 * them), or (b) wrongly blocking Org Admin's OWN legitimate holds if the
 * flag were defined too broadly. See sql/rbac.sql's exclusion lists for
 * the authoritative source this classification was cross-referenced
 * against — a code excluded from BOTH Org Admin's and Dispatcher's lists
 * is tier 2; a code excluded from ONLY Dispatcher's (Org Admin holds it by
 * design) is tier 1.
 *
 *   0 = unrestricted        — the default; ordinary permission
 *   1 = org_admin_or_above  — Super Admin AND Org Admin may hold it;
 *                             Dispatcher and every less-senior role may not
 *   2 = super_admin_only    — ONLY a role with roles.is_super = 1 may hold
 *                             it; a "full defeat of the boundary" if it
 *                             ever lands elsewhere (action.manage_config,
 *                             action.manage_roles are the textbook cases —
 *                             either one alone makes is_admin() true for
 *                             every holder of the role)
 *
 * A permission reserved for a specific NON-admin role (e.g. Facility's
 * screen.facility_portal / action.facility_self_report — excluded from Org
 * Admin/Dispatcher's broad grants for an entirely different reason, and
 * legitimately held by hundreds of non-super Facility-role rows on real
 * installs) is deliberately left at tier 0. This is not "admin-only" in
 * the sense this file exists to protect — it is "reserved for a specific
 * bespoke role", a different concern entirely, still handled by category +
 * the existing exclusion lists.
 *
 * ── Role tier resolution ────────────────────────────────────────────────
 *
 * Role tier 2 = roles.is_super = 1 (structural — the same flag is_admin()
 * and rbac_can()'s super-admin short-circuit already trust).
 * Role tier 1 = role id 2 OR name 'Org Admin' — Org Admin is one of the
 * six original roles seeded with an EXPLICIT id at install time (unlike
 * Facility, which resolves by name because it can collide with a
 * pre-existing custom role's id — see sql/rbac.sql's own comment on that).
 * Hardcoding role id 2 for Org Admin is consistent with dozens of existing
 * call sites throughout sql/rbac.sql, sql/run_00_rbac.php and api/rbac.php
 * that already do exactly this for the same six roles.
 * Role tier 0 = everything else (Dispatcher, Operator, Read-Only, Field
 * Unit, Facility, and any custom role).
 *
 * ── What this does NOT do ───────────────────────────────────────────────
 *
 * It does not stop a genuine Super Admin from customising a role's grants
 * via the Roles & Permissions UI for ordinary, non-admin-only permissions
 * (that is the entire point of RBAC). It DOES refuse — unconditionally,
 * with no override, from any code path — an attempt to grant a tier>0
 * permission to a role whose tier is lower than required, whether that
 * attempt comes from a human via the UI or from an automated migration
 * step. Five real incidents were ALL of the second kind; none were a
 * deliberate admin decision to loosen these codes below their reserved
 * tier, so hardening this to a hard, structural refusal changes no
 * currently-correct role's grants.
 */

declare(strict_types=1);

const RBAC_ADMIN_ONLY_NONE           = 0;
const RBAC_ADMIN_ONLY_ORG_ADMIN_UP   = 1;
const RBAC_ADMIN_ONLY_SUPER_ADMIN    = 2;

/**
 * Idempotent existence check for the admin_only column. Callers use this
 * to degrade gracefully (never fatal) on an install mid-upgrade where the
 * column hasn't landed yet — the guard simply can't restrict anything it
 * has no schema to ask about, which is exactly today's (leaky) behaviour,
 * never worse.
 */
function rbac_admin_only_column_exists(): bool {
    static $exists = null;
    if ($exists !== null) return $exists;
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $row = db_fetch_one(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'admin_only'",
            [$prefix . 'permissions']
        );
        return $exists = !empty($row);
    } catch (Throwable $e) {
        return $exists = false;
    }
}

/**
 * Resolve a role's admin tier (0/1/2). See the file docblock for the
 * exact semantics. Cached per-request per role id — this is called on
 * every grant attempt and the underlying data never changes mid-request.
 */
function _rbac_role_admin_tier(int $roleId): int {
    static $cache = [];
    if (array_key_exists($roleId, $cache)) return $cache[$roleId];

    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $row = db_fetch_one(
            "SELECT id, name, is_super FROM `{$prefix}roles` WHERE id = ?",
            [$roleId]
        );
    } catch (Throwable $e) {
        return $cache[$roleId] = 0;
    }
    if (!$row) return $cache[$roleId] = 0;

    if ((int) ($row['is_super'] ?? 0) === 1) return $cache[$roleId] = 2;
    if ((int) $row['id'] === 2 || ($row['name'] ?? '') === 'Org Admin') return $cache[$roleId] = 1;
    return $cache[$roleId] = 0;
}

/**
 * Resolve a permission's admin_only tier, checked SYMMETRICALLY across a
 * canonical-alias relationship in EITHER direction. This is the specific
 * fix for the Phase 149 failure mode: run_rbac_v2.php's A8 step creates a
 * brand-new canonical permission row and mirrors role_permissions onto it
 * from whichever roles hold the OLD code at that moment — if the two rows
 * ever briefly disagree on admin_only (e.g. the new row was just created
 * this instant and its own admin_only column is still at the schema
 * default), resolving through BOTH directions means the stricter of the
 * two always wins, so a lag in propagating the classification can never
 * itself become a bypass.
 */
function _rbac_permission_admin_only_tier(int $permissionId): int {
    if (!rbac_admin_only_column_exists()) return 0;
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $rows = db_fetch_all(
            "SELECT p.admin_only AS own_tier,
                    alias_target.admin_only AS canonical_tier,
                    alias_source.admin_only AS old_code_tier
               FROM `{$prefix}permissions` p
               LEFT JOIN `{$prefix}permissions` alias_target
                      ON alias_target.code = p.deprecated_alias_of
               LEFT JOIN `{$prefix}permissions` alias_source
                      ON alias_source.deprecated_alias_of = p.code
              WHERE p.id = ?",
            [$permissionId]
        );
    } catch (Throwable $e) {
        return 0;
    }
    $tier = 0;
    foreach ($rows as $row) {
        $tier = max(
            $tier,
            (int) ($row['own_tier'] ?? 0),
            (int) ($row['canonical_tier'] ?? 0),
            (int) ($row['old_code_tier'] ?? 0)
        );
    }
    return $tier;
}

/**
 * The public guard. Returns true iff $roleId may hold $permissionId per
 * the admin_only tier model. Unrestricted permissions (tier 0, the vast
 * majority) always return true immediately.
 */
function rbac_grant_permission_allowed(int $roleId, int $permissionId): bool {
    $required = _rbac_permission_admin_only_tier($permissionId);
    if ($required <= RBAC_ADMIN_ONLY_NONE) return true;
    return _rbac_role_admin_tier($roleId) >= $required;
}

/**
 * Same check, but throws with a message naming the code and role so a
 * caller (API endpoint, migration script) can surface something useful
 * rather than a bare boolean. Never silently drops the offending grant —
 * every call site is expected to refuse the WHOLE operation, not filter
 * one bad row out of a batch and proceed as if nothing happened.
 */
function rbac_assert_grant_permission_allowed(int $roleId, int $permissionId): void {
    if (rbac_grant_permission_allowed($roleId, $permissionId)) return;

    $prefix = $GLOBALS['db_prefix'] ?? '';
    $code = null;
    $roleName = null;
    try {
        $code = db_fetch_value("SELECT code FROM `{$prefix}permissions` WHERE id = ?", [$permissionId]);
    } catch (Throwable $e) {}
    try {
        $roleName = db_fetch_value("SELECT name FROM `{$prefix}roles` WHERE id = ?", [$roleId]);
    } catch (Throwable $e) {}

    $required = _rbac_permission_admin_only_tier($permissionId);
    $tierName = $required >= RBAC_ADMIN_ONLY_SUPER_ADMIN ? 'Super Admin only' : 'Org Admin or above';

    throw new RuntimeException(sprintf(
        "Refusing to grant admin-only permission%s to role #%d%s: this permission is reserved for %s.",
        $code ? " '{$code}'" : " #{$permissionId}",
        $roleId,
        $roleName ? " ({$roleName})" : '',
        $tierName
    ));
}

/**
 * SQL fragment helper for migration scripts that grant permissions in
 * bulk via INSERT ... SELECT rather than one row at a time. Returns a
 * boolean SQL expression (referencing an aliased `permissions` row `p`
 * and an aliased `roles` row `r`) that is TRUE iff the role's tier is
 * sufficient for the permission's admin_only value — i.e. the structural
 * equivalent of rbac_grant_permission_allowed(), expressed as SQL so it
 * can be embedded directly in a broad "everything except ..." grant
 * statement. Degrades to the literal 'TRUE' expression when the column
 * doesn't exist yet (never blocks a legitimate grant on a not-yet-migrated
 * install — the exclusion-list text remains the only guard until the
 * column lands, exactly today's behaviour).
 *
 * $permAlias / $roleAlias let callers match whatever aliases their query
 * already uses.
 */
function rbac_admin_only_sql_predicate(string $permAlias = 'p', string $roleAlias = 'r'): string {
    if (!rbac_admin_only_column_exists()) return '1=1';
    return "(CASE WHEN {$roleAlias}.is_super = 1 THEN 2 "
         . "WHEN {$roleAlias}.id = 2 OR {$roleAlias}.name = 'Org Admin' THEN 1 "
         . "ELSE 0 END) >= {$permAlias}.admin_only";
}
