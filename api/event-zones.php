<?php
/**
 * NewUI v4.0 — Event Zones API (Phase 109 Slice A)
 *
 * CRUD for per-event zones (the "posts" on the Net Control Board).
 *
 * GET  /api/event-zones.php?ticket_id=N
 *        → { zones: [...] } ordered by sort_order, id. Any authenticated
 *          user (read is not privileged — the board itself is gated by
 *          screen.net_control).
 *
 * POST /api/event-zones.php   (JSON body, requires CSRF + RBAC
 *      action.manage_event_zones)
 *   action=create   { ticket_id, name, code, color?, sort_order? }
 *   action=update   { id, ticket_id, name?, code?, color?, sort_order?, hide? }
 *   action=delete   { id, ticket_id }
 *
 * Every mutation is audit-logged (best-effort). Parameterized queries
 * only. Zone `code` is unique per ticket — enforced in code.
 */
ini_set('display_errors', '0');

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/audit.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

/**
 * Local defensive fetch — logs and returns [] on SQL error rather than
 * corrupting the JSON response (per CLAUDE.md schema-resilience rule).
 */
function _ez_fetch_all(string $sql, array $params = []): array {
    try {
        return db_fetch_all($sql, $params);
    } catch (Exception $e) {
        error_log('[event-zones] ' . $e->getMessage() . ' :: ' . substr($sql, 0, 200));
        return [];
    }
}

/**
 * Shape a raw event_zones row for the API.
 */
function _ez_shape(array $row): array {
    return [
        'id'         => (int) $row['id'],
        'ticket_id'  => (int) $row['ticket_id'],
        'name'       => (string) $row['name'],
        'code'       => (string) $row['code'],
        'color'      => $row['color'] !== null ? (string) $row['color'] : null,
        'geo_json'   => $row['geo_json'] !== null ? (string) $row['geo_json'] : null,
        'sort_order' => (int) $row['sort_order'],
        'hide'       => (int) $row['hide'],
    ];
}

// ══════════════════════════════════════════════════════════════
// GET — list zones for an event
// ══════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $ticketId = (int) ($_GET['ticket_id'] ?? 0);
    if ($ticketId <= 0) {
        json_error('ticket_id is required');
    }
    $rows = _ez_fetch_all(
        "SELECT `id`, `ticket_id`, `name`, `code`, `color`, `geo_json`, `sort_order`, `hide`
         FROM `{$prefix}event_zones`
         WHERE `ticket_id` = ?
         ORDER BY `sort_order` ASC, `id` ASC",
        [$ticketId]
    );
    $zones = array_map('_ez_shape', $rows);
    json_response(['zones' => $zones]);
}

// ══════════════════════════════════════════════════════════════
// POST — create / update / delete
// ══════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed', 405);
}

// RBAC: manage zones is dispatcher+admin only.
if (!rbac_can('action.manage_event_zones')) {
    json_error('Insufficient permissions: manage event zones', 403);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    json_error('Invalid JSON body');
}

// CSRF on every mutating call.
if (empty($input['csrf_token']) || !csrf_verify((string) $input['csrf_token'])) {
    json_error('Invalid CSRF token', 403);
}

$action    = trim((string) ($input['action'] ?? ''));
$ticketId  = (int) ($input['ticket_id'] ?? 0);

// list_templates is event-independent; every other action needs a ticket.
if ($action !== 'list_templates' && $ticketId <= 0) {
    json_error('ticket_id is required and must be > 0');
}

/**
 * Validate + normalise the mutable field set. Returns
 * [ok=>bool, error=>string, name, code, color, sort_order, hide].
 */
function _ez_validate(array $input): array {
    $out = ['ok' => true, 'error' => ''];

    $name = trim((string) ($input['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 64) {
        return ['ok' => false, 'error' => 'name is required and must be 1–64 characters'];
    }

    $code = trim((string) ($input['code'] ?? ''));
    if ($code === '' || mb_strlen($code) > 16) {
        return ['ok' => false, 'error' => 'code is required and must be 1–16 characters'];
    }

    $color = trim((string) ($input['color'] ?? ''));
    if ($color !== '') {
        if (mb_strlen($color) > 16 || !preg_match('/^#?[0-9A-Za-z]{1,15}$/', $color)) {
            return ['ok' => false, 'error' => 'color must be a short hex-ish value (≤16 chars)'];
        }
    } else {
        $color = null;
    }

    // Slice D — optional zone geometry (GeoJSON point/polygon). Stored as-is;
    // rendered on the situation map. Validate it parses + isn't absurdly large.
    $geo = $input['geo_json'] ?? null;
    if ($geo !== null && $geo !== '') {
        $geoStr = is_string($geo) ? $geo : json_encode($geo);
        if (strlen($geoStr) > 200000) {
            return ['ok' => false, 'error' => 'geo_json is too large (max 200 KB)'];
        }
        if (json_decode($geoStr) === null && strtolower(trim($geoStr)) !== 'null') {
            return ['ok' => false, 'error' => 'geo_json must be valid JSON'];
        }
        $out['geo_json'] = $geoStr;
    } else {
        $out['geo_json'] = null;
    }

    $out['name']       = $name;
    $out['code']       = $code;
    $out['color']      = $color;
    $out['sort_order'] = (int) ($input['sort_order'] ?? 0);
    $out['hide']       = !empty($input['hide']) ? 1 : 0;
    return $out;
}

$prefixTbl = $prefix . 'event_zones';

try {
    if ($action === 'create') {
        $v = _ez_validate($input);
        if (!$v['ok']) json_error($v['error']);

        // Enforce unique code per ticket (case-insensitive).
        $dupe = db_fetch_one(
            "SELECT `id` FROM `{$prefixTbl}` WHERE `ticket_id` = ? AND LOWER(`code`) = LOWER(?)",
            [$ticketId, $v['code']]
        );
        if ($dupe) {
            json_error('A zone with that code already exists for this event');
        }

        db_query(
            "INSERT INTO `{$prefixTbl}` (`ticket_id`, `name`, `code`, `color`, `geo_json`, `sort_order`, `hide`)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [$ticketId, $v['name'], $v['code'], $v['color'], $v['geo_json'], $v['sort_order'], $v['hide']]
        );
        $id = (int) db_insert_id();

        try {
            audit_log('config', 'event_zone_create', 'event_zones', $id,
                "Created zone '{$v['name']}' ({$v['code']}) on event #{$ticketId}",
                ['ticket_id' => $ticketId, 'code' => $v['code']]);
        } catch (Throwable $e) { /* audit must never break the action */ }

        $row = db_fetch_one("SELECT * FROM `{$prefixTbl}` WHERE `id` = ?", [$id]);
        json_response(['ok' => true, 'zone' => $row ? _ez_shape($row) : null]);
    }

    if ($action === 'update') {
        $id = (int) ($input['id'] ?? 0);
        if ($id <= 0) json_error('id is required for update');

        $existing = db_fetch_one(
            "SELECT * FROM `{$prefixTbl}` WHERE `id` = ? AND `ticket_id` = ?",
            [$id, $ticketId]
        );
        if (!$existing) {
            json_error('Zone not found for this event', 404);
        }

        $v = _ez_validate($input);
        if (!$v['ok']) json_error($v['error']);

        // Unique code per ticket, excluding self.
        $dupe = db_fetch_one(
            "SELECT `id` FROM `{$prefixTbl}`
             WHERE `ticket_id` = ? AND LOWER(`code`) = LOWER(?) AND `id` != ?",
            [$ticketId, $v['code'], $id]
        );
        if ($dupe) {
            json_error('A zone with that code already exists for this event');
        }

        // Preserve existing geometry unless the caller explicitly sent geo_json
        // (so a name-only edit never wipes a drawn zone).
        $geoForUpdate = array_key_exists('geo_json', $input) ? $v['geo_json'] : ($existing['geo_json'] ?? null);
        db_query(
            "UPDATE `{$prefixTbl}`
             SET `name` = ?, `code` = ?, `color` = ?, `geo_json` = ?, `sort_order` = ?, `hide` = ?
             WHERE `id` = ? AND `ticket_id` = ?",
            [$v['name'], $v['code'], $v['color'], $geoForUpdate, $v['sort_order'], $v['hide'], $id, $ticketId]
        );

        try {
            audit_log('config', 'event_zone_update', 'event_zones', $id,
                "Updated zone '{$v['name']}' ({$v['code']}) on event #{$ticketId}",
                ['ticket_id' => $ticketId, 'code' => $v['code']]);
        } catch (Throwable $e) { /* non-fatal */ }

        $row = db_fetch_one("SELECT * FROM `{$prefixTbl}` WHERE `id` = ?", [$id]);
        json_response(['ok' => true, 'zone' => $row ? _ez_shape($row) : null]);
    }

    if ($action === 'delete') {
        $id = (int) ($input['id'] ?? 0);
        if ($id <= 0) json_error('id is required for delete');

        $existing = db_fetch_one(
            "SELECT * FROM `{$prefixTbl}` WHERE `id` = ? AND `ticket_id` = ?",
            [$id, $ticketId]
        );
        if (!$existing) {
            json_error('Zone not found for this event', 404);
        }

        // Clear the zone off any units currently parked in it so the
        // board never renders a dangling zone id. Best-effort.
        try {
            db_query(
                "UPDATE `{$prefix}assigns` SET `current_zone_id` = NULL
                 WHERE `current_zone_id` = ? AND `ticket_id` = ?",
                [$id, $ticketId]
            );
        } catch (Exception $e) { /* pre-migration assigns — non-fatal */ }

        db_query("DELETE FROM `{$prefixTbl}` WHERE `id` = ? AND `ticket_id` = ?", [$id, $ticketId]);

        try {
            audit_log('config', 'event_zone_delete', 'event_zones', $id,
                "Deleted zone '{$existing['name']}' ({$existing['code']}) from event #{$ticketId}",
                ['ticket_id' => $ticketId, 'code' => $existing['code']]);
        } catch (Throwable $e) { /* non-fatal */ }

        json_response(['ok' => true, 'deleted' => $id]);
    }

    // ── Slice D: zone templates (save an event's zone set, apply it later) ──
    $tplTbl = $prefix . 'event_zone_templates';

    if ($action === 'save_template') {
        $tname = trim((string) ($input['name'] ?? ''));
        if ($tname === '' || mb_strlen($tname) > 64) json_error('Template name is required (1–64 chars)');
        $typeId = (int) ($input['incident_type_id'] ?? 0);
        $zoneRows = db_fetch_all(
            "SELECT `name`, `code`, `color`, `geo_json`, `sort_order`, `hide`
             FROM `{$prefixTbl}` WHERE `ticket_id` = ? ORDER BY `sort_order`, `id`", [$ticketId]);
        if (empty($zoneRows)) json_error('This event has no zones to save as a template');
        db_query(
            "INSERT INTO `{$tplTbl}` (`name`, `incident_type_id`, `zones_json`, `created_by`, `created_at`)
             VALUES (?, ?, ?, ?, NOW())",
            [$tname, $typeId > 0 ? $typeId : null, json_encode(array_values($zoneRows)),
             (int) ($_SESSION['user_id'] ?? 0)]
        );
        $tid = (int) db_insert_id();
        try {
            audit_log('config', 'event_zone_template_save', 'event_zone_templates', $tid,
                "Saved zone template '{$tname}' (" . count($zoneRows) . " zones)");
        } catch (Throwable $e) { /* non-fatal */ }
        json_response(['ok' => true, 'template_id' => $tid, 'zone_count' => count($zoneRows)]);
    }

    if ($action === 'list_templates') {
        $rows = db_fetch_all("SELECT `id`, `name`, `incident_type_id`, `zones_json` FROM `{$tplTbl}` ORDER BY `name`");
        $out = [];
        foreach ($rows as $r) {
            $z = json_decode((string) $r['zones_json'], true);
            $out[] = [
                'id'               => (int) $r['id'],
                'name'             => (string) $r['name'],
                'incident_type_id' => $r['incident_type_id'] !== null ? (int) $r['incident_type_id'] : null,
                'zone_count'       => is_array($z) ? count($z) : 0,
            ];
        }
        json_response(['templates' => $out]);
    }

    if ($action === 'apply_template') {
        $tplId = (int) ($input['template_id'] ?? 0);
        if ($tplId <= 0) json_error('template_id is required');
        $tpl = db_fetch_one("SELECT * FROM `{$tplTbl}` WHERE `id` = ?", [$tplId]);
        if (!$tpl) json_error('Template not found', 404);
        $zones = json_decode((string) $tpl['zones_json'], true);
        if (!is_array($zones)) json_error('Template has no zones');

        // Skip codes already on this event so re-applying is idempotent-ish.
        $existingCodes = [];
        foreach (db_fetch_all("SELECT `code` FROM `{$prefixTbl}` WHERE `ticket_id` = ?", [$ticketId]) as $er) {
            $existingCodes[strtolower((string) $er['code'])] = true;
        }
        $added = 0;
        foreach ($zones as $z) {
            $code = strtolower(trim((string) ($z['code'] ?? '')));
            if ($code === '' || isset($existingCodes[$code])) continue;
            try {
                db_query(
                    "INSERT INTO `{$prefixTbl}` (`ticket_id`, `name`, `code`, `color`, `geo_json`, `sort_order`, `hide`)
                     VALUES (?, ?, ?, ?, ?, ?, ?)",
                    [$ticketId, (string) ($z['name'] ?? $z['code']), (string) ($z['code'] ?? ''),
                     $z['color'] ?? null, $z['geo_json'] ?? null,
                     (int) ($z['sort_order'] ?? 0), !empty($z['hide']) ? 1 : 0]
                );
                $existingCodes[$code] = true;
                $added++;
            } catch (Throwable $e) { /* skip a bad row, keep going */ }
        }
        try {
            audit_log('config', 'event_zone_template_apply', 'event_zones', $ticketId,
                "Applied zone template '{$tpl['name']}' to event #{$ticketId} ({$added} zones added)");
        } catch (Throwable $e) { /* non-fatal */ }
        json_response(['ok' => true, 'added' => $added]);
    }

    json_error('Unknown action: ' . $action .
        '. Valid actions: create, update, delete, save_template, list_templates, apply_template');
} catch (Throwable $e) {
    json_error_safe('Zone operation failed', $e, 'event-zones');
}
