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

    // Get member info for team/type resolution
    $member = null;
    try {
        $member = db_fetch_one(
            "SELECT `id`, `team_id`, `member_type_id`
             FROM `{$prefix}member` WHERE `id` = ?",
            [$memberId]
        );
    } catch (Exception $e) {}

    $teamId = $member ? (int) ($member['team_id'] ?? 0) : 0;
    $typeId = $member ? (int) ($member['member_type_id'] ?? 0) : 0;

    // Build candidate queries in priority order
    $candidates = [];

    // Level 1: Scope-specific, per-member
    if ($scopeType !== 'global' && $scopeId) {
        $candidates[] = [
            'scope_type' => $scopeType,
            'scope_id'   => $scopeId,
            'target_type' => 'member',
            'target_id'  => $memberId,
        ];
    }

    // Level 2: Scope-specific, per-team
    if ($scopeType !== 'global' && $scopeId && $teamId) {
        $candidates[] = [
            'scope_type' => $scopeType,
            'scope_id'   => $scopeId,
            'target_type' => 'team',
            'target_id'  => $teamId,
        ];
    }

    // Level 3: Scope-specific, per-member_type
    if ($scopeType !== 'global' && $scopeId && $typeId) {
        $candidates[] = [
            'scope_type' => $scopeType,
            'scope_id'   => $scopeId,
            'target_type' => 'member_type',
            'target_id'  => $typeId,
        ];
    }

    // Level 4: Scope-specific, all targets
    if ($scopeType !== 'global' && $scopeId) {
        $candidates[] = [
            'scope_type' => $scopeType,
            'scope_id'   => $scopeId,
            'target_type' => 'all',
            'target_id'  => null,
        ];
    }

    // Level 5-7: Global, per-member/team/type
    $candidates[] = [
        'scope_type' => 'global',
        'scope_id'   => null,
        'target_type' => 'member',
        'target_id'  => $memberId,
    ];
    if ($teamId) {
        $candidates[] = [
            'scope_type' => 'global',
            'scope_id'   => null,
            'target_type' => 'team',
            'target_id'  => $teamId,
        ];
    }
    if ($typeId) {
        $candidates[] = [
            'scope_type' => 'global',
            'scope_id'   => null,
            'target_type' => 'member_type',
            'target_id'  => $typeId,
        ];
    }

    // Level 8: Global default (all)
    $candidates[] = [
        'scope_type' => 'global',
        'scope_id'   => null,
        'target_type' => 'all',
        'target_id'  => null,
    ];

    // Try each candidate in priority order
    foreach ($candidates as $c) {
        $profile = _sched_perm_find($prefix, $c);
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
