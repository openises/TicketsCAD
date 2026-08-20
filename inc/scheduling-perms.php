<?php
/**
 * Scheduling Permissions — Resolution Engine
 *
 * Resolves the effective scheduling permission profile for a given member
 * in a given context (template, event, or role).
 *
 * Resolution order (most specific wins):
 *   1. Per-member assignment for the specific scope
 *   2. Per-team assignment for the specific scope (member's team_id)
 *   3. Per-member_type assignment for the specific scope
 *   4. All-targets assignment for the specific scope
 *   5. Global per-member assignment
 *   6. Global per-team assignment
 *   7. Global per-member_type assignment
 *   8. Global default (target_type='all', scope_type='global')
 *   9. Fallback: view_only profile
 *
 * Usage:
 *   require_once __DIR__ . '/scheduling-perms.php';
 *   $perms = scheduling_get_permissions($memberId, 'template', $templateId);
 *   if ($perms['can_self_assign']) { ... }
 */

/**
 * Get the effective scheduling permissions for a member in a context.
 *
 * @param  int         $memberId    The member ID
 * @param  string      $scopeType   'template', 'event', 'role', or 'global'
 * @param  int|null    $scopeId     The template_id, event_id, or role_id (null for global)
 * @return array       Permission flags (can_view_schedule, can_self_assign, etc.)
 */
function scheduling_get_permissions(int $memberId, string $scopeType = 'global', ?int $scopeId = null): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';

    // Get member info for member_type resolution
    $member = null;
    try {
        $member = db_fetch_one(
            "SELECT `id`, `member_type_id`
             FROM `{$prefix}member` WHERE `id` = ?",
            [$memberId]
        );
    } catch (Exception $e) {}

    $typeId = $member ? (int) ($member['member_type_id'] ?? 0) : 0;

    // GH#76 Phase 144 (2026-08-18): team scope now resolves from ALL of the
    // member's team_members rows, not the single legacy member.team_id
    // column — that column is retained only as an external-API compat
    // mirror as of this release and is no longer written by any internal
    // code path (see inc/member-write.php, CLAUDE.md's GH#76 pitfall
    // entry). SHIPPED DEFAULT (flagged for Eric's explicit confirmation
    // before release per the design spec): a member belonging to more than
    // one team combines by UNION / most-permissive-wins at each "per-team"
    // priority level below — if ANY of the member's teams has a matching
    // profile at a given scope, that profile's flags are OR'd together
    // (see _sched_perm_find_team_union()). A member with exactly one team
    // reproduces the prior single-team-column behavior byte-for-byte
    // (single-row union == the old direct lookup). A member with zero
    // team_members rows keeps the existing member_type/global fallback,
    // unchanged. Verify with tools/scheduling_perms_diagnose.php before
    // deploying this to an install with existing team-scoped profiles.
    $teamIds = [];
    try {
        $teamRows = db_fetch_all(
            "SELECT `team_id` FROM `{$prefix}team_members` WHERE `member_id` = ?",
            [$memberId]
        );
        foreach ($teamRows as $tr) {
            $tid = (int) ($tr['team_id'] ?? 0);
            if ($tid > 0) $teamIds[] = $tid;
        }
    } catch (Exception $e) {}
    $teamIds = array_values(array_unique($teamIds));

    // Build candidate queries in priority order. Each candidate carries a
    // 'kind' so the resolver loop below knows which finder to call —
    // 'member'/'member_type'/'all' behave exactly as before (single exact
    // match); 'team_union' evaluates every one of the member's teams at
    // that scope and combines matches (see above).
    $candidates = [];

    // Level 1: Scope-specific, per-member
    if ($scopeType !== 'global' && $scopeId) {
        $candidates[] = [
            'kind'        => 'exact',
            'scope_type'  => $scopeType,
            'scope_id'    => $scopeId,
            'target_type' => 'member',
            'target_id'   => $memberId,
        ];
    }

    // Level 2: Scope-specific, per-team (union across every team)
    if ($scopeType !== 'global' && $scopeId && !empty($teamIds)) {
        $candidates[] = [
            'kind'        => 'team_union',
            'scope_type'  => $scopeType,
            'scope_id'    => $scopeId,
            'target_type' => 'team',
            'target_ids'  => $teamIds,
        ];
    }

    // Level 3: Scope-specific, per-member_type
    if ($scopeType !== 'global' && $scopeId && $typeId) {
        $candidates[] = [
            'kind'        => 'exact',
            'scope_type'  => $scopeType,
            'scope_id'    => $scopeId,
            'target_type' => 'member_type',
            'target_id'   => $typeId,
        ];
    }

    // Level 4: Scope-specific, all targets
    if ($scopeType !== 'global' && $scopeId) {
        $candidates[] = [
            'kind'        => 'exact',
            'scope_type'  => $scopeType,
            'scope_id'    => $scopeId,
            'target_type' => 'all',
            'target_id'   => null,
        ];
    }

    // Level 5-7: Global, per-member/team/type
    $candidates[] = [
        'kind'        => 'exact',
        'scope_type'  => 'global',
        'scope_id'    => null,
        'target_type' => 'member',
        'target_id'   => $memberId,
    ];
    if (!empty($teamIds)) {
        $candidates[] = [
            'kind'        => 'team_union',
            'scope_type'  => 'global',
            'scope_id'    => null,
            'target_type' => 'team',
            'target_ids'  => $teamIds,
        ];
    }
    if ($typeId) {
        $candidates[] = [
            'kind'        => 'exact',
            'scope_type'  => 'global',
            'scope_id'    => null,
            'target_type' => 'member_type',
            'target_id'   => $typeId,
        ];
    }

    // Level 8: Global default (all)
    $candidates[] = [
        'kind'        => 'exact',
        'scope_type'  => 'global',
        'scope_id'    => null,
        'target_type' => 'all',
        'target_id'   => null,
    ];

    // Try each candidate in priority order
    foreach ($candidates as $c) {
        $profile = ($c['kind'] === 'team_union')
            ? _sched_perm_find_team_union($prefix, $c)
            : _sched_perm_find($prefix, $c);
        if ($profile) {
            return $profile;
        }
    }

    // Fallback: view_only if no assignment found
    return _sched_perm_default();
}

/**
 * Get all permission profiles (for settings UI).
 *
 * @return array  List of profiles with all flags
 */
function scheduling_get_all_profiles(): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        return db_fetch_all(
            "SELECT * FROM `{$prefix}scheduling_permission_profiles`
             WHERE `active` = 1
             ORDER BY `sort_order` ASC"
        );
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Get all permission assignments (for settings UI).
 *
 * @param  string|null  $scopeType  Filter by scope type
 * @param  int|null     $scopeId    Filter by scope ID
 * @return array        List of assignments with profile info
 */
function scheduling_get_assignments(?string $scopeType = null, ?int $scopeId = null): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';

    $where = '1=1';
    $params = [];

    if ($scopeType !== null) {
        $where .= ' AND spa.`scope_type` = ?';
        $params[] = $scopeType;
        if ($scopeId !== null) {
            $where .= ' AND spa.`scope_id` = ?';
            $params[] = $scopeId;
        }
    }

    try {
        return db_fetch_all(
            "SELECT spa.*, spp.`code` AS `profile_code`, spp.`name` AS `profile_name`,
                    spp.`description` AS `profile_description`
             FROM `{$prefix}scheduling_permission_assignments` spa
             JOIN `{$prefix}scheduling_permission_profiles` spp ON spa.`profile_id` = spp.`id`
             WHERE $where
             ORDER BY spa.`scope_type` ASC, spa.`target_type` ASC",
            $params
        );
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Check if the current user is a scheduling admin. Phase 12 (2026-06-11):
 * driven by RBAC. Admins always get full_control regardless of any specific
 * scheduling permission assignments.
 *
 * @return bool
 */
function scheduling_is_admin(): bool
{
    require_once __DIR__ . '/rbac.php';
    return is_admin();
}

/**
 * Get effective permissions, with admin override.
 * Admins always get full_control.
 *
 * @param  int         $memberId
 * @param  string      $scopeType
 * @param  int|null    $scopeId
 * @return array
 */
function scheduling_get_effective_permissions(int $memberId, string $scopeType = 'global', ?int $scopeId = null): array
{
    if (scheduling_is_admin()) {
        return _sched_perm_full_control();
    }
    return scheduling_get_permissions($memberId, $scopeType, $scopeId);
}

// ── Internal helpers ────────────────────────────────────────

function _sched_perm_find(string $prefix, array $criteria): ?array
{
    $where = "`scope_type` = ?";
    $params = [$criteria['scope_type']];

    if ($criteria['scope_id'] === null) {
        $where .= " AND `scope_id` IS NULL";
    } else {
        $where .= " AND `scope_id` = ?";
        $params[] = $criteria['scope_id'];
    }

    $where .= " AND `target_type` = ?";
    $params[] = $criteria['target_type'];

    if ($criteria['target_id'] === null) {
        $where .= " AND `target_id` IS NULL";
    } else {
        $where .= " AND `target_id` = ?";
        $params[] = $criteria['target_id'];
    }

    try {
        $row = db_fetch_one(
            "SELECT spp.*
             FROM `{$prefix}scheduling_permission_assignments` spa
             JOIN `{$prefix}scheduling_permission_profiles` spp ON spa.`profile_id` = spp.`id`
             WHERE $where
             LIMIT 1",
            $params
        );
    } catch (Exception $e) {
        return null;
    }

    if (!$row) return null;

    return _sched_perm_to_array($row);
}

/**
 * GH#76 Phase 144 (2026-08-18) — team-scope resolver over MULTIPLE teams
 * (a member's full team_members set), combined by UNION / most-permissive-
 * wins. Mirrors _sched_perm_find()'s WHERE shape exactly except
 * `target_id = ?` becomes `target_id IN (...)` and every matching row is
 * combined rather than just the first.
 *
 * A member with exactly one team_members row degrades to the identical
 * single-row result _sched_perm_find() would have returned for the old
 * member.team_id lookup — see tests/test_scheduling_perms_multiteam_union.php.
 */
function _sched_perm_find_team_union(string $prefix, array $criteria): ?array
{
    $where = "`scope_type` = ?";
    $params = [$criteria['scope_type']];

    if ($criteria['scope_id'] === null) {
        $where .= " AND `scope_id` IS NULL";
    } else {
        $where .= " AND `scope_id` = ?";
        $params[] = $criteria['scope_id'];
    }

    $where .= " AND `target_type` = 'team'";
    $ids = $criteria['target_ids'] ?? [];
    if (empty($ids)) return null;
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $where .= " AND `target_id` IN ({$placeholders})";
    $params = array_merge($params, $ids);

    try {
        $rows = db_fetch_all(
            "SELECT spp.*
             FROM `{$prefix}scheduling_permission_assignments` spa
             JOIN `{$prefix}scheduling_permission_profiles` spp ON spa.`profile_id` = spp.`id`
             WHERE $where",
            $params
        );
    } catch (Exception $e) {
        return null;
    }

    if (empty($rows)) return null;

    $combined = _sched_perm_to_array($rows[0]);
    if (count($rows) === 1) return $combined;

    // Union / most-permissive-wins: OR every boolean capability flag
    // across every matching team profile at this scope. A member gets a
    // capability if ANY of their teams' profiles here grant it.
    $combined['profile_code'] = 'combined_team_union';
    $combined['profile_name'] = 'Combined (multiple teams)';
    for ($i = 1; $i < count($rows); $i++) {
        $next = _sched_perm_to_array($rows[$i]);
        foreach ($next as $k => $v) {
            if ($k === 'profile_code' || $k === 'profile_name') continue;
            $combined[$k] = ((int) $combined[$k] || (int) $v) ? 1 : 0;
        }
    }
    return $combined;
}

function _sched_perm_to_array(array $row): array
{
    return [
        'profile_code'        => $row['code'] ?? '',
        'profile_name'        => $row['name'] ?? '',
        'can_view_schedule'   => (int) ($row['can_view_schedule'] ?? 0),
        'can_view_own'        => (int) ($row['can_view_own'] ?? 0),
        'can_view_others'     => (int) ($row['can_view_others'] ?? 0),
        'can_view_available'  => (int) ($row['can_view_available'] ?? 0),
        'can_self_assign'     => (int) ($row['can_self_assign'] ?? 0),
        'can_self_remove'     => (int) ($row['can_self_remove'] ?? 0),
        'can_mark_unavailable' => (int) ($row['can_mark_unavailable'] ?? 0),
        'can_swap'            => (int) ($row['can_swap'] ?? 0),
        'can_request_cover'   => (int) ($row['can_request_cover'] ?? 0),
        'can_assign_others'   => (int) ($row['can_assign_others'] ?? 0),
        'can_remove_others'   => (int) ($row['can_remove_others'] ?? 0),
        'can_change_status'   => (int) ($row['can_change_status'] ?? 0),
        'can_manage_slots'    => (int) ($row['can_manage_slots'] ?? 0),
    ];
}

function _sched_perm_default(): array
{
    return [
        'profile_code'        => 'view_only',
        'profile_name'        => 'View Only (Fallback)',
        'can_view_schedule'   => 1,
        'can_view_own'        => 1,
        'can_view_others'     => 1,
        'can_view_available'  => 1,
        'can_self_assign'     => 0,
        'can_self_remove'     => 0,
        'can_mark_unavailable' => 0,
        'can_swap'            => 0,
        'can_request_cover'   => 0,
        'can_assign_others'   => 0,
        'can_remove_others'   => 0,
        'can_change_status'   => 0,
        'can_manage_slots'    => 0,
    ];
}

function _sched_perm_full_control(): array
{
    return [
        'profile_code'        => 'full_control',
        'profile_name'        => 'Full Control (Admin)',
        'can_view_schedule'   => 1,
        'can_view_own'        => 1,
        'can_view_others'     => 1,
        'can_view_available'  => 1,
        'can_self_assign'     => 1,
        'can_self_remove'     => 1,
        'can_mark_unavailable' => 1,
        'can_swap'            => 1,
        'can_request_cover'   => 1,
        'can_assign_others'   => 1,
        'can_remove_others'   => 1,
        'can_change_status'   => 1,
        'can_manage_slots'    => 1,
    ];
}
