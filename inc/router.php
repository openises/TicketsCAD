<?php
/**
 * NewUI v4.0 — Cross-Protocol Message Routing Engine
 *
 * Evaluates routing rules when messages flow through the broker and forwards
 * them to other channels based on configurable filters and transformations.
 *
 * Architecture:
 *   Message arrives on channel → broker logs it → router_evaluate() runs →
 *   matching routes forward the message to destination channels via broker_send()
 *
 * Design: "All matches fire" — every matching route executes, not just the first.
 * Loop prevention: _routed array of route IDs + _route_depth counter (max 5).
 *
 * USAGE:
 *   require_once __DIR__ . '/router.php';
 *
 * Phase 18e (2026-06-11): when an incident-bearing message routes,
 * the security-label gate is consulted. If the label blocks broadcast,
 * the route is logged as 'security_blocked' and no send happens.
 * If the label has a non-zero send delay, the message is queued in
 * pending_routed_messages and a cron sends it later. Killable via
 * api/pending-messages.php during the delay window.
 *
 *   // Evaluate routing rules after a message is sent/received
 *   router_evaluate('meshtastic', 'inbound', $message, $messageId);
 *
 *   // Dry-run test: see which routes would match without forwarding
 *   $matches = router_test('local_chat', 'outbound', $testMessage);
 */

// Maximum routing chain depth to prevent infinite loops
define('ROUTER_MAX_DEPTH', 5);

// Phase 18e — security label + send-delay queue. Optional; if they're
// not loaded the router skips the security gate gracefully (a fresh
// install before the Phase 18 migration is run).
if (file_exists(__DIR__ . '/security-labels.php')) {
    require_once __DIR__ . '/security-labels.php';
}
if (file_exists(__DIR__ . '/pending-messages.php')) {
    require_once __DIR__ . '/pending-messages.php';
}

// Phase 99v-1 — recipient-predicate resolver. Same optional-load
// pattern as the other Phase-18 helpers: if the file is missing
// (fresh install before the migration runs), the router_forward
// guard uses function_exists() to skip.
if (file_exists(__DIR__ . '/router_recipients.php')) {
    require_once __DIR__ . '/router_recipients.php';
    router_recipients_ensure_column();
}

// Phase 111 Slice A — active-event message auto-logging. The single
// entry point mi_attach_message_to_active_event() is called from
// router_evaluate() ONLY when a matched route carries
// attach_action='add_note' AND an active event is configured. Loaded
// with the same optional-load + function_exists() guard as the helpers
// above; a fresh install without the file simply skips the hook.
if (file_exists(__DIR__ . '/message-incident.php')) {
    require_once __DIR__ . '/message-incident.php';
}

/**
 * Ensure routing tables exist (idempotent).
 */
function _router_ensure_tables() {
    $prefix = $GLOBALS['db_prefix'] ?? '';

    try {
        db_query("CREATE TABLE IF NOT EXISTS `{$prefix}message_routes` (
            `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name`             VARCHAR(100) NOT NULL,
            `description`      VARCHAR(255) NOT NULL DEFAULT '',
            `enabled`          TINYINT NOT NULL DEFAULT 1,
            `priority`         INT NOT NULL DEFAULT 100,
            `source_channel`   VARCHAR(64) NOT NULL,
            `dest_channel`     VARCHAR(64) NOT NULL,
            `direction`        ENUM('inbound','outbound','both') NOT NULL DEFAULT 'both',
            `filters_json`     TEXT DEFAULT NULL,
            `transform_json`   TEXT DEFAULT NULL,
            `created_by`       INT UNSIGNED DEFAULT NULL,
            `created_at`       DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at`       DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_enabled`  (`enabled`),
            KEY `idx_source`   (`source_channel`),
            KEY `idx_priority` (`priority`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        db_query("CREATE TABLE IF NOT EXISTS `{$prefix}routing_log` (
            `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `route_id`          INT UNSIGNED NOT NULL,
            `source_channel`    VARCHAR(64) NOT NULL,
            `dest_channel`      VARCHAR(64) NOT NULL,
            `source_message_id` INT UNSIGNED DEFAULT NULL,
            `dest_message_id`   INT UNSIGNED DEFAULT NULL,
            `status`            ENUM('forwarded','failed','skipped','loop_blocked') NOT NULL DEFAULT 'forwarded',
            `error`             TEXT DEFAULT NULL,
            `payload_summary`   VARCHAR(500) DEFAULT '',
            `routed_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_route`     (`route_id`),
            KEY `idx_routed`    (`routed_at`),
            KEY `idx_source_msg` (`source_message_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {
        // Fail silently — routing is an enhancement, not a requirement
    }
}

/**
 * Phase D — does message_routes have the dest_subaddress_json column?
 * (Added by sql/run_route_subaddress.php.) Cached per-process; a failed
 * probe returns false so route CRUD degrades to the pre-Phase-D shape.
 */
function _router_has_subaddress_col(): bool {
    static $has = null;
    if ($has !== null) return $has;
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $has = (bool) db_fetch_value(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ? AND COLUMN_NAME = 'dest_subaddress_json'",
            [$prefix . 'message_routes']
        );
    } catch (Exception $e) {
        $has = false;
    }
    return $has;
}

// ── Route Evaluation ─────────────────────────────────────────

/**
 * Evaluate routing rules for a message on a channel.
 *
 * Called by broker_send() (outbound) and broker_receive() (inbound) after
 * successful delivery/logging. Loads all active routes matching the source
 * channel and direction, checks filters, and forwards to destinations.
 *
 * @param string   $channel          Source channel code
 * @param string   $direction        'inbound' or 'outbound'
 * @param array    $message          Message data (body, type, priority, from, to, etc.)
 * @param int|null $sourceMessageId  The messages.id from the broker log
 * @return array   Summary of routing actions: [{route_id, dest, status, error}, ...]
 */
function router_evaluate($channel, $direction, array $message, $sourceMessageId = null) {
    // Phase 73u — caller-controlled loop-prevention metadata is a
    // forgery surface. Without this guard, any caller of broker_send
    // (a future webhook adapter, a malicious payload that survived a
    // channel handler's sanitisation) could preset _routed = [all
    // route IDs] or _route_depth = 99 to silently bypass all routing
    // rules.
    //
    // Discipline: only the router itself, via router_forward(), is
    // allowed to set _routed and _route_depth. router_forward marks
    // its forwarded copy with _is_routed_forward = true. We honour
    // those fields ONLY when that flag is present and truthy;
    // otherwise we discard the caller's input and start fresh.
    $trusted = !empty($message['_is_routed_forward']);
    $depth   = $trusted ? (int) ($message['_route_depth'] ?? 0) : 0;
    $routed  = $trusted ? ($message['_routed'] ?? []) : [];
    if (!is_array($routed)) $routed = [];

    if ($depth >= ROUTER_MAX_DEPTH) {
        return [];
    }

    $routes = _router_get_routes($channel);
    if (empty($routes)) {
        return [];
    }

    $results = [];

    foreach ($routes as $route) {
        // Skip if this route was already applied to this message
        if (in_array((int) $route['id'], $routed, true)) {
            continue;
        }

        // Check direction filter
        if ($route['direction'] !== 'both' && $route['direction'] !== $direction) {
            continue;
        }

        // Check filters
        $filters = $route['filters_json'] ? json_decode($route['filters_json'], true) : [];
        if (!empty($filters) && !_router_match_filters($filters, $message)) {
            continue;
        }

        // Forward the message
        $result = router_forward($route, $message, $sourceMessageId);
        $results[] = [
            'route_id'   => (int) $route['id'],
            'route_name' => $route['name'],
            'dest'       => $route['dest_channel'],
            'status'     => $result['status'],
            'error'      => $result['error'] ?? null
        ];

        // Phase 111 Slice A — active-event auto-logging hook. PURELY
        // ADDITIVE: this fires ONLY when the matched route explicitly
        // carries attach_action='add_note'. On an install where the
        // message_routes.attach_action column was never added (routing
        // not migrated to Phase 111), $route['attach_action'] is simply
        // unset → the guard is false → no-op. And even when a route IS
        // marked, mi_attach_message_to_active_event() itself returns
        // immediately unless an active event is configured. So with the
        // feature dormant (the default), this changes NOTHING about the
        // router's forwarding decisions or side effects. The helper never
        // throws, so it cannot break the forward loop either.
        if (!empty($route['attach_action'])
            && $route['attach_action'] === 'add_note'
            && function_exists('mi_attach_message_to_active_event')) {
            mi_attach_message_to_active_event($message, $channel);
        }
    }

    return $results;
}

/**
 * Dry-run test: evaluate which routes would match without actually forwarding.
 *
 * @param string $channel    Source channel code
 * @param string $direction  'inbound' or 'outbound'
 * @param array  $message    Test message data
 * @return array  List of matching routes with transformed message preview
 */
function router_test($channel, $direction, array $message) {
    $routes = _router_get_routes($channel);
    $matches = [];

    foreach ($routes as $route) {
        if ($route['direction'] !== 'both' && $route['direction'] !== $direction) {
            continue;
        }

        $filters = $route['filters_json'] ? json_decode($route['filters_json'], true) : [];
        if (!empty($filters) && !_router_match_filters($filters, $message)) {
            continue;
        }

        // Build transformed preview
        $transform = $route['transform_json'] ? json_decode($route['transform_json'], true) : [];
        $transformed = !empty($transform)
            ? _router_transform($transform, $message, $channel)
            : $message;

        $matches[] = [
            'route_id'    => (int) $route['id'],
            'route_name'  => $route['name'],
            'dest'        => $route['dest_channel'],
            'priority'    => (int) $route['priority'],
            'transformed' => [
                'body'     => $transformed['body'] ?? '',
                'priority' => $transformed['priority'] ?? 'normal',
                'type'     => $transformed['type'] ?? 'text'
            ]
        ];
    }

    return $matches;
}

// ── Forwarding ───────────────────────────────────────────────

/**
 * Phase D — is this dest_channel one of the unified mesh/Zello transports
 * reached through their real stacks (not a broker-registered channel)?
 *
 *   mesh:meshtastic / mesh:meshcore → queue a mesh_outbox row
 *   zello                           → queue a zello_outbox row
 *
 * Returns the canonical transport string, or '' for a normal broker channel.
 */
function _router_transport_for_dest(string $destChannel): string {
    if ($destChannel === 'mesh:meshtastic' || $destChannel === 'mesh:meshcore' || $destChannel === 'zello') {
        return $destChannel;
    }
    return '';
}

/**
 * Forward a message to a destination channel.
 *
 * Applies transformations, sets loop-prevention metadata, checks channel
 * availability, and logs the result. A normal destination is delivered via
 * broker_send(); a unified-transport destination (mesh:meshtastic,
 * mesh:meshcore, zello) is delivered through its real stack
 * (_router_forward_mesh / _router_forward_zello), which QUEUES the send for
 * the bridge/proxy to drain — no faked success.
 *
 * @param array    $route            Route row from message_routes
 * @param array    $message          Original message array
 * @param int|null $sourceMessageId  Original message ID from broker log
 * @return array   ['status' => string, 'dest_message_id' => int|null, 'error' => string|null]
 */
function router_forward(array $route, array $message, $sourceMessageId = null) {
    global $_broker_channels;

    $destChannel = $route['dest_channel'];
    $routeId = (int) $route['id'];
    $sourceChannel = $route['source_channel'];
    $summary = substr($message['body'] ?? '', 0, 200);

    $transport = _router_transport_for_dest($destChannel);

    // For a NORMAL broker channel, verify it is registered + enabled.
    // Unified-transport destinations skip these checks — they are not broker
    // channels (their dead stubs were deleted in Phase D); they are reached
    // through the mesh_outbox / zello_outbox queues instead.
    if ($transport === '') {
        // Check destination channel exists
        if (!isset($_broker_channels[$destChannel])) {
            $error = "Destination channel '$destChannel' not registered";
            _router_log($routeId, $sourceChannel, $destChannel, $sourceMessageId, null, 'skipped', $error, $summary);
            return ['status' => 'skipped', 'dest_message_id' => null, 'error' => $error];
        }

        // Check destination channel is enabled
        $enabled = _broker_get_enabled_channels();
        if (!in_array($destChannel, $enabled, true)) {
            $error = "Destination channel '$destChannel' is not enabled";
            _router_log($routeId, $sourceChannel, $destChannel, $sourceMessageId, null, 'skipped', $error, $summary);
            return ['status' => 'skipped', 'dest_message_id' => null, 'error' => $error];
        }
    }

    // Check depth limit
    // Phase 73u — same trust rule as router_evaluate: only honour
    // depth/routed metadata when this message was marked as a
    // router-internal forward.
    $trusted = !empty($message['_is_routed_forward']);
    $depth   = $trusted ? (int) ($message['_route_depth'] ?? 0) : 0;
    if ($depth >= ROUTER_MAX_DEPTH) {
        _router_log($routeId, $sourceChannel, $destChannel, $sourceMessageId, null, 'loop_blocked', 'Max routing depth exceeded', $summary);
        return ['status' => 'loop_blocked', 'dest_message_id' => null, 'error' => 'Max routing depth exceeded'];
    }

    // Apply transformations
    $transform = $route['transform_json'] ? json_decode($route['transform_json'], true) : [];
    $forwarded = !empty($transform)
        ? _router_transform($transform, $message, $sourceChannel)
        : $message;

    // Set loop-prevention metadata. We re-read the trusted source
    // here (the `_routed` array on the inbound message) — same trust
    // rule as router_evaluate: only carry forward if the inbound was
    // itself a trusted router forward. Untrusted inputs start fresh.
    $forwarded['_is_routed_forward'] = true;
    $forwarded['_route_depth'] = $depth + 1;
    $forwarded['_routed'] = $trusted ? ($message['_routed'] ?? []) : [];
    if (!is_array($forwarded['_routed'])) $forwarded['_routed'] = [];
    $forwarded['_routed'][] = $routeId;
    $forwarded['_source_channel'] = $sourceChannel;
    $forwarded['_source_message_id'] = $sourceMessageId;

    // Phase 99v-2 (a beta tester/Eric beta 2026-06-29) — recipient predicate
    // resolution. If the route carries a recipient_predicate_json,
    // resolve it to a list of user IDs and attach them so the channel
    // handler (currently only inc/channels/push.php, but other
    // user-axis channels in v2 will use the same convention) can
    // deliver to a per-user audience instead of channel-broadcast.
    //
    // A route can have:
    //   - dest_channel + NULL predicate   → channel broadcast (today's behaviour)
    //   - dest_channel + non-NULL predicate → per-user delivery via that channel
    //
    // The empty-recipient case ("predicate matched zero users") is
    // logged as a 'skipped' result rather than calling the channel
    // with an empty audience.
    $predicateRaw = $route['recipient_predicate_json'] ?? null;
    if ($predicateRaw && function_exists('router_recipients_resolve')) {
        $predicate = json_decode((string) $predicateRaw, true);
        if (is_array($predicate) && !empty($predicate)) {
            $recipientIds = router_recipients_resolve($predicate, $forwarded);
            if (empty($recipientIds)) {
                _router_log($routeId, $sourceChannel, $destChannel, $sourceMessageId, null,
                    'skipped', 'recipient predicate matched zero users', $summary);
                return ['status' => 'skipped', 'dest_message_id' => null,
                        'error' => 'recipient predicate matched zero users'];
            }
            $forwarded['_recipient_user_ids'] = $recipientIds;
        }
    }

    // Phase 18e — security label gate. If the message carries a
    // ticket_id, resolve the incident's security label and check
    // whether the route is permitted, plus whether a send delay
    // applies. Routes are 'broadcast' by default; the routing rule
    // can mark routing_kind='direct' to bypass broadcast gating.
    $routeKind = $route['routing_kind'] ?? 'broadcast';
    $tid = isset($forwarded['ticket_id']) ? (int) $forwarded['ticket_id'] : 0;
    if ($tid > 0 && function_exists('seclabel_resolve')) {
        $sec = seclabel_resolve($tid);
        $broadcastBlocked = ($routeKind === 'broadcast' && (int) $sec['routing_allow_broadcast'] === 0);
        $directBlocked    = ($routeKind === 'direct'    && (int) $sec['routing_allow_direct']    === 0);
        if ($broadcastBlocked || $directBlocked) {
            _router_log($routeId, $sourceChannel, $destChannel, $sourceMessageId, null, 'security_blocked',
                "Blocked by security label '" . $sec['name'] . "'", $summary);
            return ['status' => 'security_blocked', 'dest_message_id' => null,
                    'error' => 'Blocked by security label ' . $sec['name']];
        }
        // Queue with a send delay if configured.
        $delay = (int) $sec['routing_send_delay_secs'];
        if ($delay > 0 && function_exists('pending_enqueue')) {
            $qid = pending_enqueue([
                'ticket_id' => $tid,
                'route_id'  => $routeId,
                'channel'   => $destChannel,
                'target'    => $forwarded['target'] ?? $destChannel,
                'subject'   => $forwarded['subject'] ?? null,
                'body'      => $forwarded['body'] ?? '',
                'priority'  => $forwarded['priority'] ?? null,
                'scheduled_send_at' => date('Y-m-d H:i:s', time() + $delay),
                'created_by' => $_SESSION['user_id'] ?? null,
            ]);
            _router_log($routeId, $sourceChannel, $destChannel, $sourceMessageId, $qid, 'queued',
                "Queued for {$delay}s send-delay (label '" . $sec['name'] . "')", $summary);
            return ['status' => 'queued', 'pending_id' => $qid];
        }
    }

    // Forward. Unified-transport destinations queue into the mesh/zello
    // outbox the bridge/proxy drains; everything else goes via broker_send.
    if ($transport === 'mesh:meshtastic' || $transport === 'mesh:meshcore') {
        $result = _router_forward_mesh($transport, $route, $forwarded);
    } elseif ($transport === 'zello') {
        $result = _router_forward_zello($route, $forwarded);
    } else {
        $result = broker_send($destChannel, $forwarded);
    }

    $status = ($result['success'] ?? false) ? 'forwarded' : ($result['status'] ?? 'failed');
    $destMsgId = $result['message_id'] ?? null;
    $error = $result['error'] ?? null;

    _router_log($routeId, $sourceChannel, $destChannel, $sourceMessageId, $destMsgId, $status, $error, $summary);

    // Publish SSE event for routing activity (admin-only per F-007)
    if ($status === 'forwarded' && function_exists('sse_publish_for_admin')) {
        sse_publish_for_admin('routing:forwarded', [
            'route_id'       => $routeId,
            'route_name'     => $route['name'],
            'source_channel' => $sourceChannel,
            'dest_channel'   => $destChannel,
            'summary'        => $summary
        ]);
    }

    // Phase 99v-4 follow-on (2026-06-30) — surface channel-adapter
    // metrics so the test-send API can show "N pushes delivered" /
    // "M recipients resolved" without inventing them at the call site.
    // Optional keys, only set when the channel adapter populated them
    // (push does; broadcast channels typically don't).
    $out = ['status' => $status, 'dest_message_id' => $destMsgId, 'error' => $error];
    foreach (['delivered', 'failed', 'gone', 'queued', 'recipients_resolved', 'subscriptions_matched'] as $k) {
        if (isset($result[$k])) $out[$k] = $result[$k];
    }
    return $out;
}

// ── Phase D — unified transport forwarding (mesh + Zello) ─────

/**
 * Read + decode a route's dest sub-address JSON. Returns [] when absent
 * (a flat-channel route, or a pre-migration install without the column).
 */
function _router_route_subaddress(array $route): array {
    if (!array_key_exists('dest_subaddress_json', $route)) return [];
    $raw = $route['dest_subaddress_json'];
    if ($raw === null || $raw === '') return [];
    $decoded = json_decode((string) $raw, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Forward a routed message to a mesh transport by QUEUEING a mesh_outbox
 * send_text row (the bridge drains it via api/mesh.php?action=poll_outbox).
 *
 * The route's sub-address decides channel vs. DM:
 *   {channel_slot:N}          → channel broadcast on slot N (default 0)
 *   {to_node:"!hex"}          → direct to a raw transport address
 *   {unit_id:N} / {member_id} → resolved to that unit/person's address on
 *                               this transport via inc/comm_resolve.php
 *
 * Returns a broker-shaped result: success=true on enqueue, or
 * status='failed' with an error if the resolve/queue fails (a real failure,
 * not a fake success).
 *
 * @param string $transport  'mesh:meshtastic' | 'mesh:meshcore'
 */
function _router_forward_mesh(string $transport, array $route, array $message): array {
    require_once __DIR__ . '/mesh_outbox.php';

    $proto = ($transport === 'mesh:meshcore') ? 'meshcore' : 'meshtastic';
    $sub   = _router_route_subaddress($route);
    $text  = (string) ($message['body'] ?? '');
    if (trim($text) === '') {
        return ['success' => false, 'status' => 'failed', 'error' => 'empty message body'];
    }

    $slot   = isset($sub['channel_slot']) ? max(0, min(7, (int) $sub['channel_slot'])) : 0;
    $toNode = isset($sub['to_node']) ? substr(trim((string) $sub['to_node']), 0, 32) : '';

    // Resolve unit_id / member_id → transport address when no raw to_node.
    if ($toNode === '') {
        $unitId   = isset($sub['unit_id'])   ? (int) $sub['unit_id']   : 0;
        $memberId = isset($sub['member_id']) ? (int) $sub['member_id'] : 0;
        if ($unitId > 0 || $memberId > 0) {
            require_once __DIR__ . '/comm_resolve.php';
            $addr = ($unitId > 0)
                ? resolve_unit_address($unitId, $proto, 'unit')
                : resolve_unit_address($memberId, $proto, 'member');
            if ($addr === null) {
                $who = $unitId > 0 ? "unit #$unitId" : "member #$memberId";
                return ['success' => false, 'status' => 'failed',
                        'error' => "no $proto address on file for $who"];
            }
            $toNode = substr(trim((string) $addr), 0, 32);
        }
    }

    $isDirect  = ($toNode !== '');
    $threadKey = mesh_build_thread_key($proto, $isDirect ? $toNode : null, $slot, $isDirect);

    try {
        $oid = mesh_enqueue_send([
            'text'         => $text,
            'protocol'     => $proto,
            'channel_slot' => $slot,
            'to_node'      => $toNode,
            'queued_by'    => (int) ($_SESSION['user_id'] ?? 0),
            'thread_key'   => $threadKey,
        ]);
        return ['success' => true, 'status' => 'forwarded', 'message_id' => $oid, 'error' => null];
    } catch (Exception $e) {
        return ['success' => false, 'status' => 'failed', 'error' => 'mesh queue failed: ' . $e->getMessage()];
    }
}

/**
 * Forward a routed message to Zello by QUEUEING a zello_outbox row that the
 * Zello proxy drains on its loop timer (ZelloProxyApp::pollOutbox). The web
 * process cannot reach the proxy's WebSocket event loop directly, so this is
 * the correct hand-off: the proxy relays it and marks the row sent/failed.
 *
 * Sub-address:
 *   {channel:"Name"} → channel text (blank = proxy's default dispatch channel)
 *   {user:"handle"}  → user DM (the proxy wires the recipient in Phase E)
 *
 * If Zello has never been configured (no zello_* settings at all), the route
 * is reported 'skipped' rather than 'failed' — there is simply no Zello to
 * deliver to yet, which is a configuration gap, not a delivery error.
 */
function _router_forward_zello(array $route, array $message): array {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $text   = (string) ($message['body'] ?? '');
    if (trim($text) === '') {
        return ['success' => false, 'status' => 'failed', 'error' => 'empty message body'];
    }

    $sub     = _router_route_subaddress($route);
    $channel = isset($sub['channel']) ? substr(trim((string) $sub['channel']), 0, 100) : '';
    $user    = isset($sub['user'])    ? substr(trim((string) $sub['user']),    0, 100) : '';

    // Is Zello configured at all? (any zello_* setting present.)
    try {
        $hasZello = (bool) db_fetch_value(
            "SELECT COUNT(*) FROM `{$prefix}settings` WHERE `name` LIKE 'zello\\_%'"
        );
    } catch (Exception $e) {
        $hasZello = false;
    }
    if (!$hasZello) {
        return ['success' => false, 'status' => 'skipped',
                'error' => 'Zello is not configured (no zello_* settings)'];
    }

    try {
        db_query(
            "INSERT INTO `{$prefix}zello_outbox`
                (kind, channel, recipient, body, status, queued_by, source)
             VALUES ('text', ?, ?, ?, 'queued', ?, 'router')",
            [$channel, $user, substr($text, 0, 1000),
             (int) ($_SESSION['user_id'] ?? 0) ?: null]
        );
        return ['success' => true, 'status' => 'forwarded', 'message_id' => (int) db_insert_id(), 'error' => null];
    } catch (Exception $e) {
        return ['success' => false, 'status' => 'failed', 'error' => 'zello queue failed: ' . $e->getMessage()];
    }
}

// ── Filter Matching ──────────────────────────────────────────

/**
 * Check if a message matches a route's filter conditions.
 * All non-null filter fields are ANDed together.
 *
 * @param array $filters  Decoded filters_json
 * @param array $message  Message data
 * @return bool  True if all filters pass
 */
function _router_match_filters(array $filters, array $message) {
    // Incident type filter
    if (!empty($filters['incident_type_ids'])) {
        $typeId = $message['in_types_id'] ?? ($message['incident_type_id'] ?? null);
        if ($typeId === null || !in_array((int) $typeId, array_map('intval', $filters['incident_type_ids']), true)) {
            return false;
        }
    }

    // Minimum severity filter
    if (isset($filters['severity_min']) && $filters['severity_min'] !== null) {
        $severity = $message['severity'] ?? 0;
        if ((int) $severity < (int) $filters['severity_min']) {
            return false;
        }
    }

    // Priority filter (exact match list)
    if (!empty($filters['priority_in'])) {
        $priority = $message['priority'] ?? 'normal';
        if (!in_array($priority, $filters['priority_in'], true)) {
            return false;
        }
    }

    // Sender role filter
    if (!empty($filters['sender_roles'])) {
        $senderRole = $message['sender_role'] ?? ($message['level'] ?? null);
        if ($senderRole === null || !in_array((int) $senderRole, array_map('intval', $filters['sender_roles']), true)) {
            return false;
        }
    }

    // Keyword filter (any keyword must appear in body)
    if (!empty($filters['keywords'])) {
        $body = strtolower($message['body'] ?? '');
        $found = false;
        foreach ($filters['keywords'] as $kw) {
            if (strpos($body, strtolower(trim($kw))) !== false) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            return false;
        }
    }

    // Exclude keyword filter (none of these should appear)
    if (!empty($filters['exclude_keywords'])) {
        $body = strtolower($message['body'] ?? '');
        foreach ($filters['exclude_keywords'] as $kw) {
            if (strpos($body, strtolower(trim($kw))) !== false) {
                return false;
            }
        }
    }

    // Specific incident ID filter
    if (!empty($filters['incident_id'])) {
        $ticketId = $message['ticket_id'] ?? ($message['incident_id'] ?? null);
        if ($ticketId === null || (int) $ticketId !== (int) $filters['incident_id']) {
            return false;
        }
    }

    return true;
}

// ── Message Transformation ───────────────────────────────────

/**
 * Apply transformations to a message before forwarding.
 *
 * @param array  $transform     Decoded transform_json
 * @param array  $message       Original message
 * @param string $sourceChannel Source channel code (for prefix formatting)
 * @return array Transformed message (copy, original not modified)
 */
function _router_transform(array $transform, array $message, $sourceChannel) {
    $result = $message;

    // Prepend prefix to body
    if (!empty($transform['prefix'])) {
        $prefix = str_replace('{source}', $sourceChannel, $transform['prefix']);
        $result['body'] = $prefix . ($result['body'] ?? '');
    }

    // Override priority
    if (!empty($transform['override_priority'])) {
        $result['priority'] = $transform['override_priority'];
    }

    // Override message type
    if (!empty($transform['override_type'])) {
        $result['type'] = $transform['override_type'];
    }

    return $result;
}

// ── Route Loading ────────────────────────────────────────────

/**
 * Get all active routes matching a source channel, ordered by priority.
 * Includes routes with source_channel = '*' (wildcard).
 *
 * @param string $channel  Source channel code
 * @return array  Route rows from message_routes
 */
function _router_get_routes($channel) {
    _router_ensure_tables();

    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        return db_fetch_all(
            "SELECT * FROM `{$prefix}message_routes`
             WHERE `enabled` = 1
               AND (`source_channel` = ? OR `source_channel` = '*')
             ORDER BY `priority` ASC, `id` ASC",
            [$channel]
        );
    } catch (Exception $e) {
        return [];
    }
}

// ── Logging ──────────────────────────────────────────────────

/**
 * Log a routing action to the routing_log table.
 *
 * @param int         $routeId
 * @param string      $sourceChannel
 * @param string      $destChannel
 * @param int|null    $sourceMessageId
 * @param int|null    $destMessageId
 * @param string      $status
 * @param string|null $error
 * @param string      $summary
 */
function _router_log($routeId, $sourceChannel, $destChannel, $sourceMessageId, $destMessageId, $status, $error = null, $summary = '') {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        db_query(
            "INSERT INTO `{$prefix}routing_log`
                (`route_id`, `source_channel`, `dest_channel`, `source_message_id`, `dest_message_id`, `status`, `error`, `payload_summary`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            [$routeId, $sourceChannel, $destChannel, $sourceMessageId, $destMessageId, $status, $error, substr($summary, 0, 500)]
        );
    } catch (Exception $e) {
        // Logging failure is not critical
    }
}

// ── CRUD Helpers (used by api/routing.php) ───────────────────

/**
 * Get all routes (for admin listing).
 *
 * @return array  All route rows ordered by priority
 */
function router_get_all() {
    _router_ensure_tables();

    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        return db_fetch_all(
            "SELECT * FROM `{$prefix}message_routes` ORDER BY `priority` ASC, `id` ASC"
        );
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Get a single route by ID.
 *
 * @param int $id
 * @return array|null
 */
function router_get($id) {
    _router_ensure_tables();

    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        return db_fetch_one(
            "SELECT * FROM `{$prefix}message_routes` WHERE `id` = ?",
            [(int) $id]
        );
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Create a new route.
 *
 * @param array $data  Route fields
 * @return int  New route ID
 */
function router_create(array $data) {
    _router_ensure_tables();

    $prefix = $GLOBALS['db_prefix'] ?? '';
    $userId = $_SESSION['user_id'] ?? null;

    // Phase D — dest_subaddress_json may not exist on a pre-migration install.
    // Probe once; only write the column when present (graceful degradation).
    $hasSub = _router_has_subaddress_col();

    // Phase 99v-4 (2026-06-30) — recipient predicate column. Bootstrapped
    // by router_recipients_ensure_column() at router.php load time, but
    // some routes will be created before that runs (rare race; just guard).
    $predicateJson = !empty($data['recipient_predicate'])
        ? json_encode($data['recipient_predicate'])
        : null;

    if ($hasSub) {
        db_query(
            "INSERT INTO `{$prefix}message_routes`
                (`name`, `description`, `enabled`, `priority`, `source_channel`, `dest_channel`, `direction`, `filters_json`, `transform_json`, `dest_subaddress_json`, `recipient_predicate_json`, `created_by`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $data['name'] ?? 'Untitled Route',
                $data['description'] ?? '',
                (int) ($data['enabled'] ?? 1),
                (int) ($data['priority'] ?? 100),
                $data['source_channel'] ?? '*',
                $data['dest_channel'] ?? 'local_chat',
                $data['direction'] ?? 'both',
                isset($data['filters']) ? json_encode($data['filters']) : null,
                isset($data['transform']) ? json_encode($data['transform']) : null,
                !empty($data['dest_subaddress']) ? json_encode($data['dest_subaddress']) : null,
                $predicateJson,
                $userId
            ]
        );
    } else {
        db_query(
            "INSERT INTO `{$prefix}message_routes`
                (`name`, `description`, `enabled`, `priority`, `source_channel`, `dest_channel`, `direction`, `filters_json`, `transform_json`, `created_by`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $data['name'] ?? 'Untitled Route',
                $data['description'] ?? '',
                (int) ($data['enabled'] ?? 1),
                (int) ($data['priority'] ?? 100),
                $data['source_channel'] ?? '*',
                $data['dest_channel'] ?? 'local_chat',
                $data['direction'] ?? 'both',
                isset($data['filters']) ? json_encode($data['filters']) : null,
                isset($data['transform']) ? json_encode($data['transform']) : null,
                $userId
            ]
        );
    }

    return (int) db_insert_id();
}

/**
 * Update an existing route.
 *
 * @param int   $id
 * @param array $data  Fields to update
 * @return bool
 */
function router_update($id, array $data) {
    $prefix = $GLOBALS['db_prefix'] ?? '';

    $sets = [];
    $params = [];

    $allowedFields = ['name', 'description', 'enabled', 'priority', 'source_channel', 'dest_channel', 'direction'];
    foreach ($allowedFields as $field) {
        if (array_key_exists($field, $data)) {
            $sets[] = "`$field` = ?";
            $params[] = $data[$field];
        }
    }

    if (array_key_exists('filters', $data)) {
        $sets[] = '`filters_json` = ?';
        $params[] = $data['filters'] !== null ? json_encode($data['filters']) : null;
    }

    if (array_key_exists('transform', $data)) {
        $sets[] = '`transform_json` = ?';
        $params[] = $data['transform'] !== null ? json_encode($data['transform']) : null;
    }

    // Phase 99v-4 (2026-06-30) — recipient predicate. Setting to null
    // clears the column (route reverts to channel-broadcast).
    if (array_key_exists('recipient_predicate', $data)) {
        $sets[] = '`recipient_predicate_json` = ?';
        $params[] = $data['recipient_predicate'] !== null ? json_encode($data['recipient_predicate']) : null;
    }

    // Phase D — dest sub-address (mesh/zello target). Only touch the column
    // when it exists; null clears it (route reverts to a plain channel send).
    if (array_key_exists('dest_subaddress', $data) && _router_has_subaddress_col()) {
        $sets[] = '`dest_subaddress_json` = ?';
        $params[] = !empty($data['dest_subaddress']) ? json_encode($data['dest_subaddress']) : null;
    }

    if (empty($sets)) {
        return false;
    }

    $params[] = (int) $id;
    db_query(
        "UPDATE `{$prefix}message_routes` SET " . implode(', ', $sets) . " WHERE `id` = ?",
        $params
    );

    return true;
}

/**
 * Delete a route.
 *
 * @param int $id
 * @return bool
 */
function router_delete($id) {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    db_query("DELETE FROM `{$prefix}message_routes` WHERE `id` = ?", [(int) $id]);
    return true;
}

/**
 * Toggle route enabled/disabled.
 *
 * @param int  $id
 * @param bool $enabled
 * @return bool
 */
function router_toggle($id, $enabled) {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    db_query(
        "UPDATE `{$prefix}message_routes` SET `enabled` = ? WHERE `id` = ?",
        [(int) $enabled, (int) $id]
    );
    return true;
}

/**
 * Bulk update route priorities (reorder).
 *
 * @param array $order  [{id: int, priority: int}, ...]
 * @return int  Number of routes updated
 */
function router_reorder(array $order) {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $count = 0;
    foreach ($order as $item) {
        if (isset($item['id']) && isset($item['priority'])) {
            db_query(
                "UPDATE `{$prefix}message_routes` SET `priority` = ? WHERE `id` = ?",
                [(int) $item['priority'], (int) $item['id']]
            );
            $count++;
        }
    }
    return $count;
}

/**
 * Get routing log entries.
 *
 * @param int      $limit
 * @param int      $offset
 * @param int|null $routeId  Filter by route ID
 * @return array
 */
function router_get_log($limit = 50, $offset = 0, $routeId = null) {
    _router_ensure_tables();

    $prefix = $GLOBALS['db_prefix'] ?? '';
    $params = [];
    $where = '';

    if ($routeId !== null) {
        $where = 'WHERE `route_id` = ?';
        $params[] = (int) $routeId;
    }

    $params[] = (int) $limit;
    $params[] = (int) $offset;

    try {
        return db_fetch_all(
            "SELECT rl.*, mr.name AS route_name
             FROM `{$prefix}routing_log` rl
             LEFT JOIN `{$prefix}message_routes` mr ON rl.route_id = mr.id
             $where
             ORDER BY rl.id DESC
             LIMIT ? OFFSET ?",
            $params
        );
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Get total count of routing log entries.
 *
 * @param int|null $routeId  Filter by route ID
 * @return int
 */
function router_get_log_count($routeId = null) {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        if ($routeId !== null) {
            return (int) db_fetch_value(
                "SELECT COUNT(*) FROM `{$prefix}routing_log` WHERE `route_id` = ?",
                [(int) $routeId]
            );
        }
        return (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}routing_log`");
    } catch (Exception $e) {
        return 0;
    }
}
