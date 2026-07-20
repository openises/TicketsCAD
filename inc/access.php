<?php
/**
 * NewUI v4.0 — Per-resource access helpers (Constitution rule #6, IDOR prevention)
 *
 * `user_can_access_entity()` returns true if the current session user is
 * allowed to read/modify a specific resource by id. Mirrors the group-based
 * `allocates` filter that list endpoints (incidents.php, responders.php) apply
 * for non-admins, so that detail/save/upload sibling endpoints don't leak.
 *
 * Map of entity_type → allocates.type value:
 *   incident  → 1
 *   responder → 2
 *   facility  → 3
 *
 * Other entity types (member, equipment, vehicle, sop, general) currently have
 * no `allocates` rows and are treated as authenticated-org-wide resources.
 * Tighten this later when per-entity ACL is added.
 */

if (!function_exists('user_can_access_entity')) {

    function user_can_access_entity(string $entityType, int $entityId): bool
    {
        if ($entityId <= 0) {
            return false;
        }

        // Phase 12 (2026-06-11): admin bypass driven by RBAC instead of
        // legacy level integer. is_admin() returns true for users with a
        // super role grant or the action.manage_config permission.
        require_once __DIR__ . '/rbac.php';
        if (is_admin()) {
            return true;
        }

        $allocatesType = [
            'incident'  => 1,
            'responder' => 2,
            'facility'  => 3,
        ];

        if (!isset($allocatesType[$entityType])) {
            // Org-wide resources (member, equipment, vehicle, sop, general) —
            // any authenticated user. See file header for the deferred work.
            return !empty($_SESSION['user_id']);
        }

        // 2026-06-11 (Phase 10b bug-fix): honour RBAC bypass per entity type.
        //
        // The list endpoints (api/facilities.php, api/incidents.php,
        // api/responders.php) all do a `rbac_can('screen.X') ||
        // rbac_can('X.view')` short-circuit BEFORE the allocates-group
        // filter. Without the same bypass here, a user who can SEE a
        // facility on the board (via RBAC role) gets 404 the moment
        // they click into the detail — silent asymmetry.
        //
        // Caught by Eric 2026-06-11 testing the `demo` user (level 2,
        // Dispatcher role, granted `screen.facilities` +
        // `screen.facility_detail`): visible on facility board, 404 on
        // click-through.
        require_once __DIR__ . '/rbac.php';
        if (function_exists('rbac_can')) {
            $rbacPerEntity = [
                'incident' => [
                    'screen.incidents',
                    'screen.incident_detail',
                    'incident.view',
                    'widget.incidents',
                ],
                'responder' => [
                    'screen.units',
                    'screen.unit_detail',
                    'responder.view',
                    'unit.view',
                    'widget.units',
                ],
                'facility' => [
                    'screen.facilities',
                    'screen.facility_detail',
                    'facility.view',
                    'widget.facilities',
                ],
            ];
            foreach ($rbacPerEntity[$entityType] ?? [] as $perm) {
                if (rbac_can($perm)) {
                    return true;
                }
            }
        }

        $userGroups = $_SESSION['user_groups'] ?? [];
        if (empty($userGroups)) {
            return false;
        }

        $prefix = $GLOBALS['db_prefix'] ?? '';
        $placeholders = implode(',', array_fill(0, count($userGroups), '?'));
        $params = array_merge([$entityId, $allocatesType[$entityType]], $userGroups);

        try {
            $hit = db_fetch_value(
                "SELECT 1 FROM `{$prefix}allocates`
                 WHERE `resource_id` = ? AND `type` = ?
                   AND `group` IN ($placeholders)
                 LIMIT 1",
                $params
            );
            return !empty($hit);
        } catch (Exception $e) {
            return false;
        }
    }
}
