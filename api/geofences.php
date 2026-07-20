<?php
/**
 * NewUI v4.0 API — Geofences
 *
 * GET  /api/geofences.php              — List all geofences with active unit counts
 * GET  /api/geofences.php?id=X         — Single geofence detail with units inside
 * POST action=create                   — Create geofence from existing markup
 * POST action=update                   — Update geofence settings
 * POST action=delete                   — Delete geofence (and its state records)
 * POST action=check                    — Check if lat/lng is inside any active geofence
 */

ini_set('display_errors', '0');
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/audit.php';
require_once __DIR__ . '/../inc/geofence.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// ═══════════════════════════════════════════════════════════════
//  GET — Read operations
// ═══════════════════════════════════════════════════════════════
if ($method === 'GET') {

    // GET ?id=X — single geofence detail with units inside
    if (isset($_GET['id'])) {
        $id = (int) $_GET['id'];
        try {
            $row = db_fetch_one(
                "SELECT g.*, m.`line_name` AS `markup_name`, m.`line_type` AS `markup_type`, m.`line_data` AS `geojson`
                 FROM `{$prefix}geofences` g
                 JOIN `{$prefix}mmarkup` m ON g.`markup_id` = m.`id`
                 WHERE g.`id` = ?",
                [$id]
            );
        } catch (Exception $e) {
            $row = null;
        }
        if (!$row) {
            json_error('Geofence not found', 404);
        }

        // Decode JSON fields
        $row['alert_channels'] = json_decode($row['alert_channels_json'], true) ?: [];
        $row['notify_users']   = json_decode($row['notify_users_json'], true) ?: [];

        // Units currently inside
        $row['units_inside'] = geofence_units_inside($id);
        $row['units_inside_count'] = count($row['units_inside']);

        json_response(['geofence' => $row]);
    }

    // GET (no params) — list all geofences with active unit counts
    try {
        $rows = db_fetch_all(
            "SELECT g.`id`, g.`markup_id`, g.`name`, g.`active`,
                    g.`alert_on_enter`, g.`alert_on_exit`,
                    g.`alert_channels_json`, g.`notify_users_json`,
                    g.`created_at`, g.`updated_at`,
                    m.`line_name` AS `markup_name`, m.`line_type` AS `markup_type`
             FROM `{$prefix}geofences` g
             JOIN `{$prefix}mmarkup` m ON g.`markup_id` = m.`id`
             ORDER BY g.`name`"
        );
    } catch (Exception $e) {
        $rows = [];
    }

    // Add unit counts
    foreach ($rows as &$row) {
        $row['units_inside_count'] = geofence_count_inside((int) $row['id']);
        $row['alert_channels'] = json_decode($row['alert_channels_json'], true) ?: [];
        $row['notify_users']   = json_decode($row['notify_users_json'], true) ?: [];
    }
    unset($row);

    json_response([
        'geofences' => $rows,
        'count'     => count($rows)
    ]);
}

// ═══════════════════════════════════════════════════════════════
//  POST — Write operations
// ═══════════════════════════════════════════════════════════════
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $input['action'] ?? '';

    // CSRF check
    $token = $input['csrf_token'] ?? '';
    if (!csrf_verify($token)) {
        json_error('Invalid CSRF token', 403);
    }

    // Phase 73w — RBAC gate. Geofences hook into broker channels and
    // map overlays — wide blast radius. Restrict create/update/delete
    // to admin or explicit map-management roles. Read endpoints
    // (handled in the GET branch above) remain open to any
    // authenticated user.
    require_once __DIR__ . '/../inc/rbac.php';
    if (!is_admin()
        && !rbac_can('action.manage_map')
        && !rbac_can('action.manage_geofences')
        && !rbac_can('action.manage_config')) {
        json_error('Forbidden — managing geofences requires admin or map-management role', 403);
    }

    // Phase 73w — $current_user_id was previously undefined in this
    // file's scope (auth.php sets it at file-top global scope, not in
    // function scope, but this branch runs in the file's top-level
    // global scope so it should be visible). However a lint pass
    // caught it as ambiguous — make the read explicit and fall back
    // to the session value so the audit-log trail can never be NULL.
    $createdBy = (int) ($GLOBALS['current_user_id'] ?? $_SESSION['user_id'] ?? 0);

    // ── create — Create geofence from existing markup ────────
    if ($action === 'create') {
        $markupId     = isset($input['markup_id']) ? (int) $input['markup_id'] : 0;
        $name         = trim($input['name'] ?? '');
        $alertEnter   = isset($input['alert_on_enter']) ? (int) $input['alert_on_enter'] : 1;
        $alertExit    = isset($input['alert_on_exit'])  ? (int) $input['alert_on_exit']  : 1;
        $channels     = $input['alert_channels'] ?? [];
        $notifyUsers  = $input['notify_users'] ?? [];

        if (!$markupId) {
            json_error('markup_id is required');
        }

        // Phase 73w — validate notify_users against actual user IDs
        // so a malicious caller can't drop arbitrary ints into
        // notify_users_json (which the broker later iterates).
        if (is_array($notifyUsers) && !empty($notifyUsers)) {
            $cleaned = array_map('intval', $notifyUsers);
            $cleaned = array_values(array_filter($cleaned, fn($v) => $v > 0));
            if (!empty($cleaned)) {
                $place = implode(',', array_fill(0, count($cleaned), '?'));
                try {
                    $valid = db_fetch_all(
                        "SELECT id FROM `{$prefix}user` WHERE id IN ($place)",
                        $cleaned
                    );
                    $notifyUsers = array_map(fn($r) => (int) $r['id'], $valid ?: []);
                } catch (Exception $e) {
                    $notifyUsers = [];
                }
            } else {
                $notifyUsers = [];
            }
        } else {
            $notifyUsers = [];
        }

        // Verify the markup exists
        // Check legacy mmarkup table first (where map-markups API saves),
        // then fall back to new map_markups table
        $markup = null;
        try {
            $markup = db_fetch_one(
                "SELECT `id`, `line_name` AS `name` FROM `{$prefix}mmarkup` WHERE `id` = ?",
                [$markupId]
            );
        } catch (Exception $e) {}

        if (!$markup) {
            try {
                $markup = db_fetch_one(
                    "SELECT `id`, `name` FROM `{$prefix}map_markups` WHERE `id` = ?",
                    [$markupId]
                );
            } catch (Exception $e) {
                json_error('Failed to look up markup: ' . $e->getMessage(), 500);
            }
        }

        if (!$markup) {
            json_error('Markup not found', 404);
        }

        // Default name to the markup name
        if ($name === '') {
            $name = $markup['name'] ?? '';
        }

        try {
            db_query(
                "INSERT INTO `{$prefix}geofences`
                 (`markup_id`, `name`, `active`, `alert_on_enter`, `alert_on_exit`,
                  `alert_channels_json`, `notify_users_json`, `created_by`)
                 VALUES (?, ?, 1, ?, ?, ?, ?, ?)",
                [
                    $markupId,
                    $name,
                    $alertEnter,
                    $alertExit,
                    json_encode(is_array($channels) ? $channels : []),
                    json_encode(is_array($notifyUsers) ? $notifyUsers : []),
                    $createdBy
                ]
            );
            $id = (int) db_insert_id();
            audit_log('config', 'create', 'geofence', $id, "Created geofence '{$name}' from markup #{$markupId}");
            json_response(['success' => true, 'id' => $id]);
        } catch (Exception $e) {
            json_error('Failed to create geofence: ' . $e->getMessage(), 500);
        }
    }

    // ── update — Update geofence settings ────────────────────
    if ($action === 'update') {
        $id = isset($input['id']) ? (int) $input['id'] : 0;
        if (!$id) {
            json_error('Geofence id is required');
        }

        $sets = [];
        $params = [];

        if (isset($input['name'])) {
            $sets[] = '`name` = ?';
            $params[] = trim($input['name']);
        }
        if (isset($input['active'])) {
            $sets[] = '`active` = ?';
            $params[] = (int) $input['active'];
        }
        if (isset($input['alert_on_enter'])) {
            $sets[] = '`alert_on_enter` = ?';
            $params[] = (int) $input['alert_on_enter'];
        }
        if (isset($input['alert_on_exit'])) {
            $sets[] = '`alert_on_exit` = ?';
            $params[] = (int) $input['alert_on_exit'];
        }
        if (isset($input['alert_channels'])) {
            $sets[] = '`alert_channels_json` = ?';
            $params[] = json_encode(is_array($input['alert_channels']) ? $input['alert_channels'] : []);
        }
        if (isset($input['notify_users'])) {
            $sets[] = '`notify_users_json` = ?';
            $params[] = json_encode(is_array($input['notify_users']) ? $input['notify_users'] : []);
        }

        if (empty($sets)) {
            json_error('Nothing to update');
        }

        $params[] = $id;
        try {
            db_query(
                "UPDATE `{$prefix}geofences` SET " . implode(', ', $sets) . " WHERE `id` = ?",
                $params
            );
            audit_log('config', 'update', 'geofence', $id, "Updated geofence #{$id}");
            json_response(['success' => true, 'id' => $id]);
        } catch (Exception $e) {
            json_error('Update failed: ' . $e->getMessage(), 500);
        }
    }

    // ── delete — Delete geofence and state records ───────────
    if ($action === 'delete') {
        $id = isset($input['id']) ? (int) $input['id'] : 0;
        if (!$id) {
            json_error('Geofence id is required');
        }

        try {
            // Delete state records first
            db_query("DELETE FROM `{$prefix}geofence_unit_state` WHERE `geofence_id` = ?", [$id]);
            // Delete the geofence
            db_query("DELETE FROM `{$prefix}geofences` WHERE `id` = ?", [$id]);
            audit_log('config', 'delete', 'geofence', $id, "Deleted geofence #{$id}");
            json_response(['success' => true]);
        } catch (Exception $e) {
            json_error('Delete failed: ' . $e->getMessage(), 500);
        }
    }

    // ── check — Check if a point is inside any active geofence
    if ($action === 'check') {
        $lat    = isset($input['lat']) ? (float) $input['lat'] : null;
        $lng    = isset($input['lng']) ? (float) $input['lng'] : null;
        $unitId = trim($input['unit_identifier'] ?? '');

        if ($lat === null || $lng === null) {
            json_error('lat and lng are required');
        }
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            json_error('lat must be -90..90, lng must be -180..180');
        }
        if ($unitId === '') {
            json_error('unit_identifier is required');
        }

        $events = geofence_check($lat, $lng, $unitId);
        json_response([
            'events' => $events,
            'count'  => count($events)
        ]);
    }

    json_error('Unknown action: ' . $action, 400);
}

json_error('Method not allowed', 405);
