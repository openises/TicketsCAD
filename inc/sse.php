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
 *   org     — Phase 142 (2026-08-17) — only clients whose org_visible_ids()
 *             intersect visibility_ids receive. Used for cross-org-share
 *             recipients; visibility_ids carries organization ids, resolved
 *             fresh at PUBLISH time by _sse_share_orgs_for_ticket() /
 *             _org_sharing_notify_share_change() (inc/org-sharing.php) so a
 *             revoked share stops matching starting with the next publish —
 *             no reader-side re-check needed. Phase 143 (2026-08-17) reuses
 *             this SAME scope, unchanged, for standing-relationship
 *             recipients — see _org_relationship_orgs_for_ticket_owner()
 *             and the two coarse 'org_relationship:activated'/
 *             'org_relationship:deactivated' lifecycle events
 *             (inc/org-relationships.php) — zero reader-side change needed
 *             for either.
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
        // 'org' (Phase 142, 2026-08-17) — cross-org-share recipients, see
        // _sse_share_orgs_for_ticket() below. visibility_ids carries org ids
        // (never user or allocates-group ids) for this scope specifically.
        $allowedScopes = ['public', 'admin', 'group', 'user', 'entitled', 'org'];
        if (!in_array($scope, $allowedScopes, true)) {
            $scope = 'public';
        }

        // Normalize scopeIds to comma-separated string of positive ints.
        $idsStr = null;
        if ($scope === 'group' || $scope === 'user' || $scope === 'org') {
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
        } elseif ($scope === 'entitled' && $scopeIds !== null) {
            // Phase 149 (2026-08-22) — OPTIONAL org-scoping layered on top of
            // 'entitled' (plan.md §6): a multi-org install's inbound-call
            // trunk carries an org_id, and a ringing call must not reach
            // every other agency's screens on a shared install. Unlike the
            // group/user/org branch above, an entitled event with NO
            // scopeIds (every existing 'entitled' caller — incident:new,
            // etc.) must stay a pure broadcast-to-every-entitled-user, so
            // this branch is OPT-IN only when the caller actually passes an
            // org id, never a silent no-op like the group/user/org branch's
            // "empty is a no-op" rule would produce if reused here.
            $list = is_array($scopeIds) ? $scopeIds : [$scopeIds];
            $clean = [];
            foreach ($list as $v) {
                $v = (int) $v;
                if ($v > 0) $clean[] = $v;
            }
            if (!empty($clean)) {
                $idsStr = implode(',', $clean);
            }
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

            // 2026-07-31 — this was a SECOND synchronous outbound fan-out on
            // the dispatch hot path, and it was not in the offline audit's
            // finding: the audit named inc/audit.php, and fixing only that
            // still left a unit status change costing 3.07s against a
            // black-holed webhook subscriber, because responder-write also
            // publishes an SSE event and this fired webhooks from inside it.
            //
            // Same treatment: queue it, let the scheduled sweep deliver it.
            // WEBHOOKS ONLY — an SSE event has never fanned out to Web Push
            // and must not start now, or every chat line would buzz a phone.
            // That restriction is also what makes the recursion terminate:
            // a webhook-only row never re-enters the routing engine, so it
            // cannot publish another SSE event.
            if (function_exists('notify_fanout_dispatch')) {
                notify_fanout_dispatch($eventType, $payload, ['webhook']);
            } elseif (is_file(__DIR__ . '/notify-fanout.php')) {
                require_once __DIR__ . '/notify-fanout.php';
                notify_fanout_dispatch($eventType, $payload, ['webhook']);
            } elseif (function_exists('webhook_fire')) {
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
        // No allocates rows — 'entitled': admins + RBAC view-permission
        // holders receive (GH #13). The old 'admin' fallback was STRICTER
        // than the read path, so on installs that don't use group
        // allocation (allocates empty — the common case), field users who
        // could see the incident on every page received NO events for it:
        // exactly the CAD→mobile real-time gap a beta tester reported.
        $delivered = empty($groups)
            ? sse_publish($eventType, $payload, $userId, 'entitled')
            : sse_publish($eventType, $payload, $userId, 'group', $groups);

        // Phase 142 (2026-08-17) — also reach every org with an ACTIVE
        // cross-org share on this ticket. This is what closes Phase 141's
        // named SSE limitation for ORDINARY ticket events (incident:update,
        // incident:note, responder:status, etc.) at every one of the
        // existing call sites, with ZERO code change at any of them.
        // "Authorize at publish time, not read time" — the check re-queries
        // incident_shares fresh on every publish, so a revoked share simply
        // stops being included starting with the very next published event
        // for this ticket. No propagation-delay window on the write side to
        // go stale, unlike stream.php's per-connection $userOrgIds snapshot
        // (plan.md's SSE section — see also api/stream.php's own comment).
        //
        // Deliberately a SECOND, independent sse_publish() call rather than
        // folding both audiences into one row: the two id-namespaces (legacy
        // allocates group ids and org ids) are not comparable without a
        // prefix-disambiguation scheme this problem doesn't need. Accepted,
        // named risk: a session that is BOTH an allocates-group member for
        // this ticket AND the shared-with org's member receives the same
        // logical event twice (two sse_events rows) — requires an allocates
        // row naming a group belonging to an org OTHER than the ticket's
        // owning org, which nothing in this codebase's org model or
        // assignment UI ever creates today.
        //
        // Zero-share case (every ticket that has never used sharing) is a
        // pure no-op: _sse_share_orgs_for_ticket() returns [] and this
        // branch never runs — proven directly by
        // tests/test_org_sharing_sse_noop.php.
        $shareOrgIds = _sse_share_orgs_for_ticket($ticketId);
        if (!empty($shareOrgIds)) {
            sse_publish($eventType, $payload, $userId, 'org', $shareOrgIds);
        }

        // Phase 143 (2026-08-17) — THIRD, independent sse_publish() call:
        // every org with an ACTIVE standing-relationship grant into this
        // ticket's owning org (relationships are a property of the owning
        // org, not the ticket — see inc/org-relationships.php's
        // _org_relationship_orgs_for_ticket_owner()). Same reasoning Phase
        // 142 already gave for its own second call above: the id-namespaces
        // don't need disambiguating for this problem, and the zero-
        // relationship case is a pure no-op — proven by
        // tests/test_org_relationships_sse_noop.php. Lazy-loaded so an
        // install without the Phase 143 tables/file is unaffected.
        if (!function_exists('_org_relationship_orgs_for_ticket_owner')) {
            if (is_file(__DIR__ . '/org-relationships.php')) require_once __DIR__ . '/org-relationships.php';
        }
        if (function_exists('_org_relationship_orgs_for_ticket_owner')) {
            $relOrgIds = _org_relationship_orgs_for_ticket_owner($ticketId);
            if (!empty($relOrgIds)) {
                sse_publish($eventType, $payload, $userId, 'org', $relOrgIds);
            }
        }

        return $delivered;
    }

    /**
     * Phase 142 (2026-08-17) — active incident_shares recipients for a
     * ticket (both Phase 141 auto-routed AND Phase 142 manual shares — the
     * table doesn't distinguish origin for this purpose). Wrapped in
     * try/catch: [] if incident_shares doesn't exist (pre-Phase-141
     * install) or the ticket has none — the exact condition that keeps
     * sse_publish_for_incident() byte-identical to its pre-Phase-142
     * behavior for every install/ticket that has never used sharing.
     */
    function _sse_share_orgs_for_ticket(int $ticketId): array
    {
        if ($ticketId <= 0) return [];
        $prefix = $GLOBALS['db_prefix'] ?? '';
        try {
            $rows = db_fetch_all(
                "SELECT DISTINCT `shared_with_org_id` FROM `{$prefix}incident_shares`
                  WHERE `ticket_id` = ? AND `revoked_at` IS NULL",
                [$ticketId]
            );
            $out = [];
            foreach ($rows as $r) {
                $oid = (int) ($r['shared_with_org_id'] ?? 0);
                if ($oid > 0) $out[] = $oid;
            }
            return $out;
        } catch (Throwable $e) {
            return [];
        }
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
     * Phase 149 (2026-08-22) — inbound SIP/PBX calls (plan.md §6).
     * Structurally identical to sse_publish_for_incident(): no allocates
     * concept exists for a phone call, so this always uses the 'entitled'
     * scope (screen.call_queue holders — see api/stream.php's
     * entPermMap). $orgId is OPTIONAL org-scoping layered on top: a
     * NULL-org (install-wide) trunk is a pure broadcast to every
     * entitled user — the common single-agency case pays no extra cost;
     * a non-NULL org id additionally requires the connecting user's
     * org_visible_ids() to include it (api/stream.php enforces the read
     * side; this is the write side).
     */
    function sse_publish_for_call(int $callId, string $eventType, array $payload, ?int $orgId = null): bool
    {
        return sse_publish($eventType, $payload, null, 'entitled', $orgId);
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
