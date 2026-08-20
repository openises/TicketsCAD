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

if (!function_exists('facility_capacity_summary_rows')) {

    /**
     * Facility-capacity summary rows, scoped to what the CURRENT session
     * is actually allowed to see (2026-08-19 IDOR fix).
     *
     * api/facility-capacity.php's `?facility_id=X` path has always called
     * user_can_access_entity('facility', $facId) before returning a single
     * facility's bed/capacity counts. The `?summary=1` sibling path — an
     * unfiltered JOIN across facility_capacity/facilities/capacity_categories
     * — had NO such check at all: any authenticated user (the file only
     * requires basic session auth) could see bed/capacity data for EVERY
     * facility in the install via the summary, including facilities they
     * get a 404 for if they request individually. A filter-bypass of the
     * IDOR gate one path over.
     *
     * Extracted into its own function — the same technique
     * inc/facility-scope.php uses for facility_ticket_visibility_sql() —
     * so both the real endpoint and a regression test call the exact same
     * production code, with no HTTP harness and no hand-seeded mock of
     * "correct" behavior required.
     *
     * Admins (is_admin() — is_super OR action.manage_config) see every
     * facility, matching the single-facility path's own admin bypass
     * inside user_can_access_entity(). Everyone else is scoped down to
     * facilities user_can_access_entity('facility', $facilityId) allows —
     * the same RBAC-view-permission bypass and allocates-group check the
     * single-facility path already applies, so the two paths can never
     * disagree about which facilities a given session may see.
     */
    function facility_capacity_summary_rows(): array
    {
        $prefix = $GLOBALS['db_prefix'] ?? '';
        try {
            $rows = db_fetch_all(
                "SELECT f.id AS facility_id, f.name AS facility_name, f.`type` AS fac_type,
                        cc.name AS category_name, cc.icon, cc.unit_label,
                        fc.total, fc.available, fc.updated_at
                 FROM `{$prefix}facility_capacity` fc
                 JOIN `{$prefix}facilities` f ON fc.facility_id = f.id
                 JOIN `{$prefix}capacity_categories` cc ON fc.category_id = cc.id
                 WHERE fc.total > 0
                 ORDER BY f.name, cc.sort_order"
            );
        } catch (Exception $e) {
            return [];
        }

        require_once __DIR__ . '/rbac.php';
        if (is_admin()) {
            return $rows;
        }

        $accessCache = [];
        return array_values(array_filter($rows, function ($r) use (&$accessCache) {
            $fid = (int) $r['facility_id'];
            if (!array_key_exists($fid, $accessCache)) {
                $accessCache[$fid] = user_can_access_entity('facility', $fid);
            }
            return $accessCache[$fid];
        }));
    }
}
