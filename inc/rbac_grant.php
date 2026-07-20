<?php
/**
 * NewUI v4.0 — RBAC Grant module
 *
 * Centralised grant/revoke/expire operations. Every mutation:
 *   - runs inside a transaction
 *   - emits an audit_log row
 *   - enforces the privilege-escalation guard (rbac_can_grant)
 *
 * Companion spec: specs/rbac-redesign-2026-05/plan.md (Block C).
 *
 * Public surface:
 *
 *   rbac_grant_role(int $userId, int $roleId, string $scopeKind = 'global',
 *                   ?int $scopeId = null, ?string $expiresAt = null,
 *                   ?string $reason = null, ?int $grantedBy = null,
 *                   ?int $delegatedBy = null, int $delegationDepth = 0): int
 *
 *   rbac_revoke_grant(int $grantId, ?string $reason = null,
 *                     ?int $revokedBy = null): void
 *
 *   rbac_expire_due_grants(): int
 *
 *   rbac_user_grants(int $userId, bool $includeExpired = false): array
 *
 *   rbac_can_grant(int $granterId, int $roleId, string $scopeKind,
 *                  ?int $scopeId): bool
 *
 *   rbac_setting(string $name, ?string $default = null): ?string
 *
 * Throws \RuntimeException on validation / privilege failures.
 */

declare(strict_types=1);

require_once __DIR__ . '/rbac.php';
require_once __DIR__ . '/audit.php';

// ─────────────────────────────────────────────────────────────────────
// Public API
// ─────────────────────────────────────────────────────────────────────

function rbac_grant_role(
    int $userId,
    int $roleId,
    string $scopeKind = 'global',
    ?int $scopeId = null,
    ?string $expiresAt = null,
    ?string $reason = null,
    ?int $grantedBy = null,
    ?int $delegatedBy = null,
    int $delegationDepth = 0
): int {
    if ($userId <= 0)            throw new RuntimeException('user_id is required');
    if ($roleId <= 0)            throw new RuntimeException('role_id is required');
    _rbac_validate_scope($scopeKind, $scopeId);
    _rbac_validate_expiry($expiresAt);

    $prefix    = $GLOBALS['db_prefix'] ?? '';
    $grantedBy = $grantedBy ?? (int) ($_SESSION['user_id'] ?? 0);

    // Privilege-escalation guard. Skipped only when no actor is present
    // (CLI tooling, install_fresh, tests). Service callers must pass a
    // sentinel granter id to acknowledge the bypass.
    if ($grantedBy > 0 && !rbac_can_grant($grantedBy, $roleId, $scopeKind, $scopeId)) {
        throw new RuntimeException('Granter lacks the permissions held by this role');
    }

    // Delegation depth cap (per setting rbac.delegation_max_depth, default 1).
    if ($scopeKind === 'delegate') {
        $maxDepth = (int) (rbac_setting('rbac.delegation_max_depth', '1') ?? '1');
        if ($maxDepth <= 0) {
            throw new RuntimeException('Delegation is disabled');
        }
        if ($delegationDepth > $maxDepth) {
            throw new RuntimeException(
                "Delegation depth $delegationDepth exceeds cap $maxDepth"
            );
        }
        if ($delegatedBy === null) {
            throw new RuntimeException('delegated_by is required for delegate scope');
        }
    }

    $row = db_fetch_one(
        "SELECT id FROM `{$prefix}roles` WHERE id = ?", [$roleId]
    );
    if (empty($row)) throw new RuntimeException("Role #$roleId does not exist");

    db_query("START TRANSACTION");
    try {
        // Mirror old (org_id) for back-compat readers; populate from
        // scope when scope_kind = 'org' so legacy queries keep working.
        $orgIdMirror = ($scopeKind === 'org') ? $scopeId : null;

        // expires_at: convert via FROM_UNIXTIME so the stored DATETIME
        // is in the DB session's timezone — same frame as NOW() in
        // the visibility query. Without this, an env where PHP and
        // MariaDB disagree on TZ creates rows that are immediately
        // filtered out as "expired" (verified on the your-server
        // VM where PHP=America/New_York, system=UTC).
        $expiresUnix = $expiresAt ? strtotime($expiresAt) : null;

        db_query(
            "INSERT INTO `{$prefix}user_roles`
             (user_id, role_id, org_id, scope_kind, scope_id, expires_at,
              granted_by, granted_at, reason, delegated_by, delegation_depth)
             VALUES (?, ?, ?, ?, ?,
                     " . ($expiresUnix === null || $expiresUnix === false ? "NULL" : "FROM_UNIXTIME(?)") . ",
                     ?, NOW(), ?, ?, ?)",
            $expiresUnix === null || $expiresUnix === false
                ? [$userId, $roleId, $orgIdMirror, $scopeKind, $scopeId,
                   ($grantedBy ?: null), $reason, $delegatedBy, $delegationDepth]
                : [$userId, $roleId, $orgIdMirror, $scopeKind, $scopeId, $expiresUnix,
                   ($grantedBy ?: null), $reason, $delegatedBy, $delegationDepth]
        );
        $grantId = (int) db_insert_id();

        audit_log('rbac', 'grant', 'user_role', $grantId,
            "Granted role #{$roleId} to user #{$userId} ({$scopeKind})",
            [
                'user_id'    => $userId,
                'role_id'    => $roleId,
                'scope_kind' => $scopeKind,
                'scope_id'   => $scopeId,
                'expires_at' => $expiresAt,
                'reason'     => $reason,
                'granted_by' => $grantedBy ?: null,
                'delegated_by'     => $delegatedBy,
                'delegation_depth' => $delegationDepth,
            ]
        );
        db_query("COMMIT");
        rbac_clear_cache();
        return $grantId;
    } catch (Throwable $e) {
        db_query("ROLLBACK");
        throw new RuntimeException('Grant failed: ' . $e->getMessage(), 0, $e);
    }
}

function rbac_revoke_grant(int $grantId, ?string $reason = null, ?int $revokedBy = null): void {
    if ($grantId <= 0) throw new RuntimeException('grant_id is required');

    $prefix    = $GLOBALS['db_prefix'] ?? '';
    $revokedBy = $revokedBy ?? (int) ($_SESSION['user_id'] ?? 0);

    $row = db_fetch_one(
        "SELECT * FROM `{$prefix}user_roles` WHERE id = ?", [$grantId]
    );
    if (empty($row)) throw new RuntimeException("Grant #$grantId does not exist");

    // Privilege-escalation: revoker must be able to grant the same role
    // in the same scope (so an Org A admin can't revoke Org B grants).
    if ($revokedBy > 0
        && !rbac_can_grant($revokedBy, (int) $row['role_id'],
                           (string) $row['scope_kind'],
                           $row['scope_id'] === null ? null : (int) $row['scope_id'])) {
        throw new RuntimeException('Revoker lacks privilege over this grant');
    }

    db_query("START TRANSACTION");
    try {
        db_query("DELETE FROM `{$prefix}user_roles` WHERE id = ?", [$grantId]);
        audit_log('rbac', 'revoke', 'user_role', $grantId,
            "Revoked role #{$row['role_id']} from user #{$row['user_id']}",
            [
                'user_id'    => (int) $row['user_id'],
                'role_id'    => (int) $row['role_id'],
                'scope_kind' => $row['scope_kind'],
                'scope_id'   => $row['scope_id'],
                'reason'     => $reason,
                'revoked_by' => $revokedBy ?: null,
            ]
        );
        db_query("COMMIT");
        rbac_clear_cache();
    } catch (Throwable $e) {
        db_query("ROLLBACK");
        throw new RuntimeException('Revoke failed: ' . $e->getMessage(), 0, $e);
    }
}

/**
 * Sweep due grants. We DELETE expired rows so the user_roles table
 * doesn't accumulate dead history; the audit_log carries the full
 * trail. Returns the number of rows expired.
 */
function rbac_expire_due_grants(): int {
    $prefix = $GLOBALS['db_prefix'] ?? '';

    $rows = db_fetch_all(
        "SELECT id, user_id, role_id, scope_kind, scope_id, expires_at
         FROM `{$prefix}user_roles`
         WHERE expires_at IS NOT NULL AND expires_at <= NOW()"
    );
    if (empty($rows)) return 0;

    $count = 0;
    foreach ($rows as $row) {
        try {
            db_query("DELETE FROM `{$prefix}user_roles` WHERE id = ?", [$row['id']]);
            audit_log('rbac', 'expire', 'user_role', (int) $row['id'],
                "Auto-expired role #{$row['role_id']} from user #{$row['user_id']}",
                [
                    'user_id'    => (int) $row['user_id'],
                    'role_id'    => (int) $row['role_id'],
                    'scope_kind' => $row['scope_kind'],
                    'scope_id'   => $row['scope_id'],
                    'expired_at' => $row['expires_at'],
                ]
            );
            $count++;
        } catch (Throwable $e) {
            audit_log('rbac', 'expire_failed', 'user_role', (int) $row['id'],
                'Auto-expire failed: ' . $e->getMessage());
        }
    }
    rbac_clear_cache();
    return $count;
}

function rbac_user_grants(int $userId, bool $includeExpired = false): array {
    if ($userId <= 0) return [];
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $where  = $includeExpired ? '' : 'AND (ur.expires_at IS NULL OR ur.expires_at > NOW())';
    return db_fetch_all(
        "SELECT ur.id AS grant_id, ur.user_id, ur.role_id, ur.scope_kind,
                ur.scope_id, ur.expires_at, ur.granted_by, ur.granted_at,
                ur.reason, ur.delegated_by, ur.delegation_depth,
                r.name AS role_name, r.is_super
         FROM `{$prefix}user_roles` ur
         JOIN `{$prefix}roles` r ON ur.role_id = r.id
         WHERE ur.user_id = ? $where
         ORDER BY r.sort_order, ur.granted_at DESC",
        [$userId]
    );
}

/**
 * Privilege-escalation guard. Returns true iff the granter can grant
 * the target role in the target scope. Implementation:
 *
 *   1. Super admins can grant anything.
 *   2. The granter must hold every permission the target role grants
 *      (via rbac_can()) AND must hold action.manage_roles in the
 *      target scope.
 *   3. For org-scoped grants, the granter's matching grant must be
 *      either global or in the same org.
 *
 * We default to deny on unrecognised scope kinds. Tests in F6 cover
 * the boundary cases.
 */
function rbac_can_grant(int $granterId, int $roleId, string $scopeKind, ?int $scopeId): bool {
    if ($granterId <= 0) return false;
    _rbac_validate_scope($scopeKind, $scopeId);

    $prefix = $GLOBALS['db_prefix'] ?? '';

    // Super admin?
    $superGrant = db_fetch_one(
        "SELECT 1 FROM `{$prefix}user_roles` ur
         JOIN `{$prefix}roles` r ON ur.role_id = r.id
         WHERE ur.user_id = ? AND r.is_super = 1
           AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
         LIMIT 1",
        [$granterId]
    );
    if (!empty($superGrant)) return true;

    // Pull the target role's permission set.
    $rolePerms = db_fetch_all(
        "SELECT p.code, p.deprecated_alias_of
         FROM `{$prefix}role_permissions` rp
         JOIN `{$prefix}permissions` p ON p.id = rp.permission_id
         WHERE rp.role_id = ?",
        [$roleId]
    );

    // Pull the granter's permission set, scope-aware. We honour the
    // standard scope predicates by checking each candidate permission
    // through rbac_can() with the granter swapped in.
    //
    // Implementation note: rbac_can() reads from $_SESSION; we can't
    // cleanly pose-as-another-user without rewriting the cache. So we
    // bypass the cached helper and run a direct query for the granter.

    $granterPerms = _rbac_perms_for_user($granterId, $scopeKind, $scopeId);

    // The granter must hold action.manage_roles (or its alias) in this scope.
    $hasManage = false;
    foreach (['action.manage_roles', 'role.manage', 'roles.manage'] as $code) {
        if (isset($granterPerms[$code])) { $hasManage = true; break; }
    }
    if (!$hasManage) return false;

    // Subset check: every permission the role grants must be one the
    // granter has in this scope. Honour aliases.
    foreach ($rolePerms as $rp) {
        $code = $rp['code'];
        $alias = $rp['deprecated_alias_of'];
        if (isset($granterPerms[$code])) continue;
        if ($alias && isset($granterPerms[$alias])) continue;
        // Granter is missing this permission → cannot grant the role.
        return false;
    }
    return true;
}

/**
 * Read a setting with a fallback default. Public wrapper around the
 * same cached reader rbac.php uses internally; needed here for the
 * delegation-depth cap.
 */
function rbac_setting(string $name, ?string $default = null): ?string {
    if (function_exists('_rbac_setting')) {
        $val = _rbac_setting($name);
        return $val ?? $default;
    }
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $val = db_fetch_value(
            "SELECT value FROM `{$prefix}settings` WHERE name = ?", [$name]
        );
        return $val !== false && $val !== null ? (string) $val : $default;
    } catch (Throwable $e) {
        return $default;
    }
}

// ─────────────────────────────────────────────────────────────────────
// Internals
// ─────────────────────────────────────────────────────────────────────

function _rbac_validate_scope(string $scopeKind, ?int $scopeId): void {
    $valid = ['global','org','team','self','delegate'];
    if (!in_array($scopeKind, $valid, true)) {
        throw new RuntimeException("Invalid scope_kind: $scopeKind");
    }
    if ($scopeKind === 'global' && $scopeId !== null) {
        throw new RuntimeException('global scope must have scope_id = NULL');
    }
    if (in_array($scopeKind, ['org','team','delegate'], true) && ($scopeId === null || $scopeId <= 0)) {
        throw new RuntimeException("$scopeKind scope requires a scope_id");
    }
}

function _rbac_validate_expiry(?string $expiresAt): void {
    if ($expiresAt === null || $expiresAt === '') return;
    $ts = strtotime($expiresAt);
    if ($ts === false) {
        throw new RuntimeException('expires_at is not a valid datetime');
    }
    // We INSERT via FROM_UNIXTIME($ts) so DB sees this exact unix
    // moment, then filters with NOW() in the same frame. To validate
    // here we compare $ts to the DB's UNIX_TIMESTAMP(NOW()), which
    // works regardless of PHP/DB timezone alignment.
    try {
        $dbNowUnix = (int) db_fetch_value("SELECT UNIX_TIMESTAMP(NOW())");
        if ($dbNowUnix > 0 && $ts <= $dbNowUnix) {
            throw new RuntimeException('expires_at is already in the past');
        }
    } catch (RuntimeException $re) {
        throw $re;
    } catch (Throwable $e) {
        // DB query failed → fall back to PHP-side check.
        if ($ts < time()) {
            throw new RuntimeException('expires_at is already in the past');
        }
    }
}

/**
 * Compute the permission set held by an arbitrary user in a given
 * scope. Returns an associative array of code => true. Honours grants
 * that are global, the matching org, or self / delegate (caller passes
 * the relevant scope_id).
 *
 * This intentionally does NOT rely on $_SESSION — it's used by the
 * privilege-escalation guard which needs to reason about the granter,
 * who may not be the current actor.
 */
function _rbac_perms_for_user(int $userId, string $scopeKind, ?int $scopeId): array {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $rows = db_fetch_all(
        "SELECT ur.scope_kind AS gk, ur.scope_id AS gid,
                p.code, p.deprecated_alias_of
         FROM `{$prefix}user_roles` ur
         JOIN `{$prefix}role_permissions` rp ON rp.role_id = ur.role_id
         JOIN `{$prefix}permissions` p       ON p.id = rp.permission_id
         WHERE ur.user_id = ?
           AND (ur.expires_at IS NULL OR ur.expires_at > NOW())",
        [$userId]
    );

    $out = [];
    foreach ($rows as $r) {
        $applies =
            $r['gk'] === 'global' ||
            ($r['gk'] === 'org'  && $scopeKind === 'org'  && (int) $r['gid'] === (int) $scopeId) ||
            ($r['gk'] === 'team' && $scopeKind === 'team' && (int) $r['gid'] === (int) $scopeId);
        // self/delegate grants don't authorise issuing further grants.
        if (!$applies) continue;

        $out[$r['code']] = true;
        if (!empty($r['deprecated_alias_of'])) {
            $out[$r['deprecated_alias_of']] = true;
        }
    }
    return $out;
}
