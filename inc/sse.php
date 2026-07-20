<?php
/**
 * NewUI v4.0 — SSE Event Publisher
 *
 * Provides sse_publish() and scope-aware helpers (sse_publish_for_incident,
 * sse_publish_for_responder, sse_publish_for_user, sse_publish_for_admin).
 * Events are written to the `sse_events` table, which the stream.php endpoint
 * polls and filters per-user before pushing to connected clients.
 *
 * F-007 hardening (2026-05-04):
 *   - The `sse_events` table now carries `visibility_scope` and `visibility_ids`
 *     columns. stream.php enforces a WHERE clause that drops events the
 *     reader has no business seeing. Existing publishers that pass only the
 *     legacy 3-arg signature default to scope='public' for backward compat.
 *
 * Visibility scopes:
 *   public  — every authenticated client receives the event (default).
 *   admin   — only level <= 1 receives.
 *   group   — only clients whose user_groups intersect visibility_ids receive
 *             (admins always receive). visibility_ids is a comma-separated list.
 *   user    — only the user whose id matches visibility_ids receives.
 *
 * Usage:
 *   sse_publish('system:refresh', ['reason' => 'config_change']);
 *   sse_publish_for_incident('incident:new', ['ticket_id' => 42], 42);
 *   sse_publish_for_responder('responder:status', $payload, 7);
 *   sse_publish_for_user('message:new', $payload, $recipientUserId);
 *   sse_publish_for_admin('routing:created', $payload);
 */

if (!function_exists('sse_publish')) {

    /**
     * Internal: ensure the sse_events table has visibility columns.
     * Idempotent. Run once per request — gated by a static flag.
     */
    function _sse_ensure_schema(): void
    {
        static $checked = false;
        if ($checked) return;
        $checked = true;

        $prefix = $GLOBALS['db_prefix'] ?? '';

        try {
            // Create-from-scratch path matches the migrated schema.
            db_query("CREATE TABLE IF NOT EXISTS `{$prefix}sse_events` (
                `id`               BIGINT AUTO_INCREMENT PRIMARY KEY,
                `event_type`       VARCHAR(64)  NOT NULL,
                `payload`          TEXT         NOT NULL,
                `user_id`          INT          DEFAULT NULL COMMENT 'Originating user (null = system)',
                `visibility_scope` VARCHAR(16)  NOT NULL DEFAULT 'public',
                `visibility_ids`   VARCHAR(255) DEFAULT NULL,
                `created_at`       DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                KEY `idx_created`    (`created_at`),
                KEY `idx_type`       (`event_type`),
                KEY `idx_visibility` (`visibility_scope`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // Idempotent column-add for installs that pre-date F-007.
            $cols = db_fetch_all("SHOW COLUMNS FROM `{$prefix}sse_events`");
            $names = array_column($cols, 'Field');
            if (!in_array('visibility_scope', $names, true)) {
                db_query("ALTER TABLE `{$prefix}sse_events`
                    ADD COLUMN `visibility_scope` VARCHAR(16) NOT NULL DEFAULT 'public' AFTER `user_id`,
                    ADD COLUMN `visibility_ids`   VARCHAR(255) DEFAULT NULL AFTER `visibility_scope`,
                    ADD INDEX `idx_visibility` (`visibility_scope`)");
            }
        } catch (Exception $e) {
            // Schema upkeep is best-effort. The fallback insert path tolerates
            // missing columns.
        }
    }

    /**
     * Publish an event to SSE clients.
     *
     * @param string       $eventType  e.g. 'incident:new', 'chat:message'
     * @param array        $payload    arbitrary data (JSON-encoded for storage)
     * @param int|null     $userId     originating user (null = system / current session)
     * @param string       $scope      'public' | 'admin' | 'group' | 'user'
     * @param int|int[]|null $scopeIds For scope='group' or 'user', the allowed
     *                                 group_ids or target user_id. Ignored for
     *                                 'public' and 'admin'.
     * @return bool
     */
    function sse_publish($eventType, array $payload = [], $userId = null, $scope = 'public', $scopeIds = null): bool
    {
        if ($userId === null && isset($_SESSION['user_id'])) {
            $userId = (int) $_SESSION['user_id'];
        }

        // 'entitled' (GH #13, 2026-07-07): entity events for a resource with
        // NO allocates rows. Delivered to admins and to subscribers holding
        // the entity's RBAC view permission (stream.php matches by event_type
        // prefix) — mirroring the READ path (inc/access.php,
        // api/incidents.php), where an RBAC view permission alone grants
        // visibility and allocates only gates users without one. Still NOT
        // public: users with no view permission receive nothing (F-007).
        $allowedScopes = ['public', 'admin', 'group', 'user', 'entitled'];
        if (!in_array($scope, $allowedScopes, true)) {
            $scope = 'public';
        }

        // Normalize scopeIds to comma-separated string of positive ints.
        $idsStr = null;
        if ($scope === 'group' || $scope === 'user') {
            $list = is_array($scopeIds) ? $scopeIds : ($scopeIds === null ? [] : [$scopeIds]);
            $clean = [];
            foreach ($list as $v) {
                $v = (int) $v;
                if ($v > 0) $clean[] = $v;
            }
            if (empty($clean)) {
                // Group-/user-scoped event with no recipients is a no-op.
                return false;
            }
            $idsStr = implode(',', $clean);
        }

        _sse_ensure_schema();

        $prefix = $GLOBALS['db_prefix'] ?? '';

        try {
            db_query(
                "INSERT INTO `{$prefix}sse_events`
                    (`event_type`, `payload`, `user_id`, `visibility_scope`, `visibility_ids`)
                 VALUES (?, ?, ?, ?, ?)",
                [$eventType, json_encode($payload), $userId, $scope, $idsStr]
            );

            if (function_exists('webhook_fire')) {
                webhook_fire($eventType, $payload);
            }

            return true;
        } catch (Exception $e) {
            // Fallback for very old schema (pre-F-007 columns absent and ALTER
            // failed). Try the legacy 3-column INSERT so events at least flow.
            try {
                db_query(
                    "INSERT INTO `{$prefix}sse_events` (`event_type`, `payload`, `user_id`) VALUES (?, ?, ?)",
                    [$eventType, json_encode($payload), $userId]
                );
                return true;
            } catch (Exception $e2) {
                return false;
            }
        }
    }

    /**
     * Look up the group ids allocated to a ticket and publish the event scoped
     * to those groups. Falls back to scope='public' if the ticket has no
     * allocates rows (older data) — admins always see everything.
     */
    function sse_publish_for_incident(string $eventType, array $payload, int $ticketId, $userId = null): bool
    {
        $groups = _sse_groups_for_resource($ticketId, 1);
        if (empty($groups)) {
            // No allocates rows — 'entitled': admins + RBAC view-permission
            // holders receive (GH #13). The old 'admin' fallback was STRICTER
            // than the read path, so on installs that don't use group
            // allocation (allocates empty — the common case), field users who
            // could see the incident on every page received NO events for it:
            // exactly the CAD→mobile real-time gap a beta tester reported.
            return sse_publish($eventType, $payload, $userId, 'entitled');
        }
        return sse_publish($eventType, $payload, $userId, 'group', $groups);
    }

    function sse_publish_for_responder(string $eventType, array $payload, int $responderId, $userId = null): bool
    {
        $groups = _sse_groups_for_resource($responderId, 2);
        if (empty($groups)) {
            return sse_publish($eventType, $payload, $userId, 'entitled');
        }
        return sse_publish($eventType, $payload, $userId, 'group', $groups);
    }

    function sse_publish_for_user(string $eventType, array $payload, int $targetUserId, $userId = null): bool
    {
        return sse_publish($eventType, $payload, $userId, 'user', $targetUserId);
    }

    function sse_publish_for_admin(string $eventType, array $payload, $userId = null): bool
    {
        return sse_publish($eventType, $payload, $userId, 'admin');
    }

    /**
     * Internal: return the groups that have an `allocates` row for the given
     * resource. $type matches `allocates.type` (1=ticket, 2=responder, 3=facility).
     */
    function _sse_groups_for_resource(int $resourceId, int $type): array
    {
        if ($resourceId <= 0) return [];
        $prefix = $GLOBALS['db_prefix'] ?? '';
        try {
            $rows = db_fetch_all(
                "SELECT DISTINCT `group` FROM `{$prefix}allocates`
                 WHERE `resource_id` = ? AND `type` = ?",
                [$resourceId, $type]
            );
            $out = [];
            foreach ($rows as $r) {
                $g = (int) ($r['group'] ?? 0);
                if ($g > 0) $out[] = $g;
            }
            return $out;
        } catch (Exception $e) {
            // Phase 73z — was: silently return []. Downstream SSE scope
            // filter then treated [] as "no group restriction" and
            // delivered the event to everyone. Fail closed instead:
            // a missing allocates table or transient DB error must NOT
            // become an over-broadcast. Log the failure so the silent-
            // catch hardening (Phase 73f) catches it for triage.
            error_log('[sse._sse_groups_for_resource] '
                . 'resource=' . $resourceId . ' type=' . $type
                . ' allocates lookup failed: ' . $e->getMessage());
            // Return a sentinel that the publisher recognises as "skip
            // this event entirely". Using [0] (an impossible group id)
            // means the visibility WHERE will match nothing.
            return [0];
        }
    }

    /**
     * Publish multiple events (single best-effort transaction).
     * Each event entry: ['type' => string, 'payload' => array, 'scope' => string?, 'scope_ids' => int[]?]
     */
    function sse_publish_batch(array $events, $userId = null): int
    {
        if (empty($events)) return 0;
        $count = 0;
        foreach ($events as $evt) {
            $type    = $evt['type']      ?? 'system:unknown';
            $payload = $evt['payload']   ?? [];
            $scope   = $evt['scope']     ?? 'public';
            $ids     = $evt['scope_ids'] ?? null;
            if (sse_publish($type, $payload, $userId, $scope, $ids)) {
                $count++;
            }
        }
        return $count;
    }
}
