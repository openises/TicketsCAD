<?php
/**
 * NewUI v4.0 — Message Routing API
 *
 * CRUD for cross-protocol message routing rules, dry-run testing,
 * and routing log viewer.
 *
 * GET endpoints:
 *   GET /api/routing.php                     — List all routes
 *   GET /api/routing.php?id=X               — Get single route
 *   GET /api/routing.php?log=1&limit=50     — View routing log
 *   GET /api/routing.php?channels=1         — List available channels
 *   GET /api/routing.php?stats=1            — Routing statistics
 *
 * POST endpoints (require CSRF):
 *   POST action=create   — Create new route
 *   POST action=update   — Update existing route
 *   POST action=delete   — Delete a route
 *   POST action=toggle   — Enable/disable a route
 *   POST action=test     — Dry-run: test message against routes
 *   POST action=reorder  — Bulk update priorities
 */
ini_set('display_errors', '0');

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/sse.php';
require_once __DIR__ . '/../inc/broker.php';

// Permission check: admin or manage_routing permission
if (function_exists('rbac_can') && !rbac_can('action.manage_routing')) {
    // Fallback: legacy level check (level 0 = Super, 1 = Admin)
    if (!is_admin()) {
        json_error('Insufficient permissions', 403);
    }
}

$prefix = $GLOBALS['db_prefix'] ?? '';

// Ensure tables exist
_router_ensure_tables();

/**
 * Phase D — the unified mesh + Zello transport destinations. These are
 * selectable as a route's destination (they queue into mesh_outbox /
 * zello_outbox) and carry a sub-address. `subaddress` describes the field
 * shape the UI should collect for each.
 */
function _routing_transport_destinations(): array {
    return [
        [
            'code'        => 'mesh:meshtastic',
            'name'        => 'Mesh — Meshtastic',
            'enabled'     => true,
            'status'      => 'transport',
            'transport'   => 'mesh',
            'subaddress'  => 'mesh',
            'implemented' => true,
        ],
        [
            'code'        => 'mesh:meshcore',
            'name'        => 'Mesh — MeshCore',
            'enabled'     => true,
            'status'      => 'transport',
            'transport'   => 'mesh',
            'subaddress'  => 'mesh',
            'implemented' => true,
        ],
        [
            'code'        => 'zello',
            'name'        => 'Zello',
            'enabled'     => true,
            'status'      => 'transport',
            'transport'   => 'zello',
            'subaddress'  => 'zello',
            'implemented' => true,
        ],
    ];
}

/**
 * Phase D — validate + normalize a route's dest sub-address for a given
 * destination. Returns a clean array (or null to clear). Rejects unknown
 * keys so a malformed sub-address can't smuggle arbitrary payload fields.
 */
function _routing_clean_subaddress($destChannel, $raw): ?array {
    if (!is_array($raw) || empty($raw)) return null;
    if ($destChannel === 'mesh:meshtastic' || $destChannel === 'mesh:meshcore') {
        $out = [];
        if (isset($raw['to_node']) && trim((string) $raw['to_node']) !== '') {
            $out['to_node'] = substr(trim((string) $raw['to_node']), 0, 32);
        } elseif (isset($raw['unit_id']) && (int) $raw['unit_id'] > 0) {
            $out['unit_id'] = (int) $raw['unit_id'];
        } elseif (isset($raw['member_id']) && (int) $raw['member_id'] > 0) {
            $out['member_id'] = (int) $raw['member_id'];
        }
        // channel_slot always meaningful (0 = primary). Only stored when a
        // channel broadcast (no direct address) OR explicitly provided.
        if (isset($raw['channel_slot'])) {
            $out['channel_slot'] = max(0, min(7, (int) $raw['channel_slot']));
        }
        return !empty($out) ? $out : null;
    }
    if ($destChannel === 'zello') {
        $out = [];
        if (isset($raw['user']) && trim((string) $raw['user']) !== '') {
            $out['user'] = substr(trim((string) $raw['user']), 0, 100);
        } elseif (isset($raw['channel']) && trim((string) $raw['channel']) !== '') {
            $out['channel'] = substr(trim((string) $raw['channel']), 0, 100);
        }
        return !empty($out) ? $out : null;
    }
    // Flat channels carry no sub-address.
    return null;
}

// ── GET Requests ─────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    // List available channels with status. Phase D appends the unified
    // transport destinations (mesh + Zello) — these are NOT broker-registered
    // channels (their dead stubs were deleted), they are reached through the
    // real mesh_outbox / zello_outbox stacks. They appear as selectable
    // ROUTE DESTINATIONS only (they carry a sub-address: channel/unit/user).
    if (isset($_GET['channels'])) {
        $statuses = broker_channel_statuses();
        foreach (_routing_transport_destinations() as $td) {
            $statuses[] = $td;
        }
        json_response(['channels' => $statuses]);
    }

    // Enabled delivery channels (for the broker_enabled_channels manager).
    // Returns every registered channel with its human name, current
    // enabled state, and whether sending is implemented (stubs return
    // the 'not_implemented' error from their send handler).
    if (isset($_GET['enabled_channels'])) {
        // Phase D: the meshtastic + zello broker stubs were deleted; mesh +
        // Zello are now real routing destinations reached through their own
        // stacks (not the broker's enabled-channels list). 'dmr' is the only
        // remaining not_implemented broker send stub.
        $stubs = ['dmr'];
        $enabled = _broker_get_enabled_channels();
        $statuses = broker_channel_statuses();
        $channels = [];
        foreach ($statuses as $ch) {
            $channels[] = [
                'code'        => $ch['code'],
                'name'        => $ch['name'],
                'enabled'     => in_array($ch['code'], $enabled, true),
                'always_on'   => ($ch['code'] === 'local_chat'),
                'implemented' => !in_array($ch['code'], $stubs, true)
            ];
        }
        json_response(['channels' => $channels]);
    }

    // Routing log
    if (isset($_GET['log'])) {
        $limit = min(200, max(1, (int) ($_GET['limit'] ?? 50)));
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $offset = ($page - 1) * $limit;
        $routeId = isset($_GET['route_id']) ? (int) $_GET['route_id'] : null;

        $log = router_get_log($limit, $offset, $routeId);
        $total = router_get_log_count($routeId);

        json_response([
            'log'   => $log,
            'page'  => $page,
            'limit' => $limit,
            'total' => $total,
            'pages' => ceil($total / $limit)
        ]);
    }

    // Routing statistics
    if (isset($_GET['stats'])) {
        try {
            $activeRoutes = (int) db_fetch_value(
                "SELECT COUNT(*) FROM `{$prefix}message_routes` WHERE `enabled` = 1"
            );
            $totalRoutes = (int) db_fetch_value(
                "SELECT COUNT(*) FROM `{$prefix}message_routes`"
            );
            $recentForwarded = (int) db_fetch_value(
                "SELECT COUNT(*) FROM `{$prefix}routing_log` WHERE `status` = 'forwarded' AND `routed_at` >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
            );
            $recentFailed = (int) db_fetch_value(
                "SELECT COUNT(*) FROM `{$prefix}routing_log` WHERE `status` = 'failed' AND `routed_at` >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
            );
            $recentBlocked = (int) db_fetch_value(
                "SELECT COUNT(*) FROM `{$prefix}routing_log` WHERE `status` = 'loop_blocked' AND `routed_at` >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
            );
        } catch (Exception $e) {
            $activeRoutes = $totalRoutes = $recentForwarded = $recentFailed = $recentBlocked = 0;
        }

        json_response([
            'active_routes'     => $activeRoutes,
            'total_routes'      => $totalRoutes,
            'forwarded_24h'     => $recentForwarded,
            'failed_24h'        => $recentFailed,
            'loop_blocked_24h'  => $recentBlocked
        ]);
    }

    // Single route
    if (isset($_GET['id'])) {
        $route = router_get((int) $_GET['id']);
        if (!$route) {
            json_error('Route not found', 404);
        }
        // Decode JSON fields for the client
        $route['filters'] = $route['filters_json'] ? json_decode($route['filters_json'], true) : null;
        $route['transform'] = $route['transform_json'] ? json_decode($route['transform_json'], true) : null;
        $route['dest_subaddress'] = !empty($route['dest_subaddress_json']) ? json_decode($route['dest_subaddress_json'], true) : null;
        $route['recipient_predicate'] = !empty($route['recipient_predicate_json']) ? json_decode($route['recipient_predicate_json'], true) : null;
        json_response(['route' => $route]);
    }

    // List all routes
    $routes = router_get_all();
    foreach ($routes as &$r) {
        $r['filters'] = $r['filters_json'] ? json_decode($r['filters_json'], true) : null;
        $r['transform'] = $r['transform_json'] ? json_decode($r['transform_json'], true) : null;
        $r['dest_subaddress'] = !empty($r['dest_subaddress_json']) ? json_decode($r['dest_subaddress_json'], true) : null;
        $r['recipient_predicate'] = !empty($r['recipient_predicate_json']) ? json_decode($r['recipient_predicate_json'], true) : null;
    }
    unset($r);

    json_response(['routes' => $routes]);
}

// ── POST Requests ────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }

    $action = $input['action'] ?? '';

    // CSRF verification
    $token = $input['csrf_token'] ?? '';
    if (!csrf_verify($token)) {
        json_error('Invalid CSRF token', 403);
    }

    switch ($action) {

        case 'create':
            $name = trim($input['name'] ?? '');
            if (!$name) {
                json_error('Route name is required');
            }
            $sourceChannel = trim($input['source_channel'] ?? '*');
            $destChannel = trim($input['dest_channel'] ?? '');
            if (!$destChannel) {
                json_error('Destination channel is required');
            }

            try {
                $id = router_create([
                    'name'           => $name,
                    'description'    => trim($input['description'] ?? ''),
                    'enabled'        => (int) ($input['enabled'] ?? 1),
                    'priority'       => (int) ($input['priority'] ?? 100),
                    'source_channel' => $sourceChannel,
                    'dest_channel'   => $destChannel,
                    'direction'      => $input['direction'] ?? 'both',
                    'filters'        => $input['filters'] ?? null,
                    'transform'      => $input['transform'] ?? null,
                    'dest_subaddress'=> _routing_clean_subaddress($destChannel, $input['dest_subaddress'] ?? null),
                    'recipient_predicate' => $input['recipient_predicate'] ?? null,
                ]);

                sse_publish_for_admin('routing:created', ['route_id' => $id, 'name' => $name]);
                json_response(['ok' => true, 'id' => $id]);
            } catch (Exception $e) {
                json_error('Failed to create route: ' . $e->getMessage(), 500);
            }
            break;

        case 'update':
            $id = (int) ($input['id'] ?? 0);
            if (!$id) {
                json_error('Route ID is required');
            }
            $existing = router_get($id);
            if (!$existing) {
                json_error('Route not found', 404);
            }

            $data = [];
            $fields = ['name', 'description', 'enabled', 'priority', 'source_channel', 'dest_channel', 'direction'];
            foreach ($fields as $f) {
                if (array_key_exists($f, $input)) {
                    $data[$f] = $input[$f];
                }
            }
            if (array_key_exists('filters', $input)) {
                $data['filters'] = $input['filters'];
            }
            if (array_key_exists('transform', $input)) {
                $data['transform'] = $input['transform'];
            }
            if (array_key_exists('recipient_predicate', $input)) {
                $data['recipient_predicate'] = $input['recipient_predicate'];
            }
            // Phase D — clean the sub-address against the effective dest channel
            // (the one being set, or the existing one if unchanged).
            if (array_key_exists('dest_subaddress', $input)) {
                $effectiveDest = $data['dest_channel'] ?? $existing['dest_channel'];
                $data['dest_subaddress'] = _routing_clean_subaddress($effectiveDest, $input['dest_subaddress']);
            }

            try {
                router_update($id, $data);
                sse_publish_for_admin('routing:updated', ['route_id' => $id]);
                json_response(['ok' => true]);
            } catch (Exception $e) {
                json_error('Failed to update route: ' . $e->getMessage(), 500);
            }
            break;

        case 'delete':
            $id = (int) ($input['id'] ?? 0);
            if (!$id) {
                json_error('Route ID is required');
            }
            try {
                router_delete($id);
                sse_publish_for_admin('routing:deleted', ['route_id' => $id]);
                json_response(['ok' => true]);
            } catch (Exception $e) {
                json_error('Failed to delete route: ' . $e->getMessage(), 500);
            }
            break;

        case 'toggle':
            $id = (int) ($input['id'] ?? 0);
            $enabled = (int) ($input['enabled'] ?? 0);
            if (!$id) {
                json_error('Route ID is required');
            }
            try {
                router_toggle($id, $enabled);
                sse_publish_for_admin('routing:toggled', ['route_id' => $id, 'enabled' => $enabled]);
                json_response(['ok' => true]);
            } catch (Exception $e) {
                json_error('Failed to toggle route: ' . $e->getMessage(), 500);
            }
            break;

        case 'test':
            // Dry-run: test a message against all routes
            $channel = trim($input['channel'] ?? 'local_chat');
            $direction = $input['direction'] ?? 'outbound';
            $testMessage = [
                'body'             => $input['body'] ?? 'Test message',
                'type'             => $input['type'] ?? 'text',
                'priority'         => $input['priority'] ?? 'normal',
                'severity'         => $input['severity'] ?? 0,
                'in_types_id'      => $input['in_types_id'] ?? null,
                'incident_type_id' => $input['incident_type_id'] ?? null,
                'ticket_id'        => $input['ticket_id'] ?? null,
                'sender_role'      => $input['sender_role'] ?? ($current_level ?? 0)
            ];

            $matches = router_test($channel, $direction, $testMessage);
            json_response([
                'matches'      => $matches,
                'match_count'  => count($matches),
                'test_message' => $testMessage,
                'channel'      => $channel,
                'direction'    => $direction
            ]);
            break;

        case 'reorder':
            $order = $input['order'] ?? [];
            if (!is_array($order) || empty($order)) {
                json_error('Order array is required');
            }
            try {
                $count = router_reorder($order);
                json_response(['ok' => true, 'updated' => $count]);
            } catch (Exception $e) {
                json_error('Failed to reorder routes: ' . $e->getMessage(), 500);
            }
            break;

        case 'save_enabled_channels':
            // Persist the set of channels routing is allowed to deliver to.
            // Stored as a JSON array in the settings row 'broker_enabled_channels',
            // read back by _broker_get_enabled_channels() in inc/broker.php.
            $requested = $input['channels'] ?? [];
            if (!is_array($requested)) {
                json_error('channels must be an array');
            }

            // Only accept codes that are actually registered channels.
            global $_broker_channels;
            $valid = array_keys($_broker_channels);
            $selected = [];
            foreach ($requested as $code) {
                $code = (string) $code;
                if (in_array($code, $valid, true) && !in_array($code, $selected, true)) {
                    $selected[] = $code;
                }
            }
            // local_chat is always on — never let it be removed.
            if (!in_array('local_chat', $selected, true)) {
                array_unshift($selected, 'local_chat');
            }

            try {
                db_query(
                    "INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES ('broker_enabled_channels', ?)
                     ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
                    [json_encode(array_values($selected))]
                );
                if (function_exists('audit_log')) {
                    audit_log('config', 'update', 'settings', null,
                        'Updated broker enabled delivery channels',
                        ['channels' => $selected]);
                }
                json_response(['ok' => true, 'channels' => array_values($selected)]);
            } catch (Exception $e) {
                json_error('Failed to save enabled channels: ' . $e->getMessage(), 500);
            }
            break;

        default:
            json_error('Unknown action: ' . $action);
    }
}

json_error('Method not allowed', 405);
