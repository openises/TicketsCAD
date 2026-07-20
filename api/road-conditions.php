<?php
/**
 * NewUI v4.0 API - Road Conditions
 *
 * Manages road condition reports and condition types.
 *
 * GET  /api/road-conditions.php              — list all road conditions with joined condition type
 * GET  /api/road-conditions.php?id=X         — single road condition detail
 * GET  /api/road-conditions.php?types=1      — list condition types
 * POST /api/road-conditions.php action=save       — create/update road condition
 * POST /api/road-conditions.php action=delete     — delete road condition
 * POST /api/road-conditions.php action=save_type  — create/update condition type
 * POST /api/road-conditions.php action=delete_type — delete condition type
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/audit.php';

// Suppress display_errors to keep JSON clean
ini_set('display_errors', '0');

$prefix = $GLOBALS['db_prefix'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// ── Auth: require admin for writes ──────────────────────────────
if ($method === 'POST' && !is_admin()) {
    json_error('Admin access required', 403);
}

// ── CSRF on writes ──────────────────────────────────────────────
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $token = $input['csrf_token'] ?? '';
    if (!csrf_verify($token)) {
        json_error('Invalid CSRF token', 403);
    }
}

// ═══════════════════════════════════════════════════════════════
//  GET — Read operations
// ═══════════════════════════════════════════════════════════════
if ($method === 'GET') {

    // GET ?types=1 — list condition types
    if (isset($_GET['types'])) {
        try {
            $rows = db_fetch_all(
                "SELECT `id`, `title`, `description`, `icon`, `_by`, `_on`
                 FROM `{$prefix}conditions`
                 ORDER BY `title` ASC"
            );
        } catch (Exception $e) {
            $rows = [];
        }
        json_response(['rows' => $rows]);
    }

    // GET ?map=1 — reports for the map overlay (maps-comprehensive-2026-06).
    // Returns only reports that have plottable coordinates, joined with the
    // condition's icon/title/description so the Leaflet layer can render a
    // marker + popup for each. Reports with no lat/lng are skipped here.
    if (isset($_GET['map'])) {
        try {
            $rows = db_fetch_all(
                "SELECT r.`id`, r.`title`, r.`description`, r.`address`,
                        r.`conditions` AS `condition_id`, r.`lat`, r.`lng`,
                        r.`_on`,
                        c.`title` AS `condition_title`,
                        c.`description` AS `condition_description`,
                        c.`icon` AS `condition_icon`
                 FROM `{$prefix}roadinfo` r
                 LEFT JOIN `{$prefix}conditions` c ON r.`conditions` = c.`id`
                 WHERE r.`lat` IS NOT NULL AND r.`lng` IS NOT NULL
                   AND r.`lat` <> 0 AND r.`lng` <> 0
                 ORDER BY r.`_on` DESC"
            );
        } catch (Exception $e) {
            $rows = [];
        }
        json_response([
            'reports' => $rows,
            'count'   => count($rows),
        ]);
    }

    // GET ?id=X — single road condition
    if (isset($_GET['id'])) {
        $id = (int) $_GET['id'];
        try {
            $row = db_fetch_one(
                "SELECT r.`id`, r.`title`, r.`description`, r.`address`,
                        r.`conditions` AS `condition_id`, r.`lat`, r.`lng`,
                        r.`username`, r.`_by`, r.`_on`, r.`_from`,
                        c.`title` AS `condition_title`, c.`icon` AS `condition_icon`
                 FROM `{$prefix}roadinfo` r
                 LEFT JOIN `{$prefix}conditions` c ON r.`conditions` = c.`id`
                 WHERE r.`id` = ?",
                [$id]
            );
        } catch (Exception $e) {
            $row = null;
        }
        if (!$row) {
            json_error('Road condition not found', 404);
        }
        json_response(['road_condition' => $row]);
    }

    // GET (no params) — list all road conditions
    try {
        $rows = db_fetch_all(
            "SELECT r.`id`, r.`title`, r.`description`, r.`address`,
                    r.`conditions` AS `condition_id`, r.`lat`, r.`lng`,
                    r.`username`, r.`_by`, r.`_on`, r.`_from`,
                    c.`title` AS `condition_title`, c.`icon` AS `condition_icon`
             FROM `{$prefix}roadinfo` r
             LEFT JOIN `{$prefix}conditions` c ON r.`conditions` = c.`id`
             ORDER BY r.`_on` DESC"
        );
    } catch (Exception $e) {
        $rows = [];
    }
    json_response([
        'road_conditions' => $rows,
        'count'           => count($rows),
    ]);
}

// ═══════════════════════════════════════════════════════════════
//  POST — Write operations
// ═══════════════════════════════════════════════════════════════
if ($method === 'POST') {
    $action = $input['action'] ?? '';

    // ── save — create/update road condition ─────────────────────
    if ($action === 'save') {
        $id          = isset($input['id']) && $input['id'] !== '' ? (int) $input['id'] : null;
        $title       = trim($input['title'] ?? '');
        $description = trim($input['description'] ?? '');
        $address     = trim($input['address'] ?? '');
        $conditionId = isset($input['condition_id']) ? (int) $input['condition_id'] : 0;
        $lat         = isset($input['lat']) ? (float) $input['lat'] : 0;
        $lng         = isset($input['lng']) ? (float) $input['lng'] : 0;

        if ($title === '') {
            json_error('Title is required');
        }

        try {
            if ($id) {
                db_query(
                    "UPDATE `{$prefix}roadinfo`
                     SET `title` = ?, `description` = ?, `address` = ?,
                         `conditions` = ?, `lat` = ?, `lng` = ?,
                         `username` = ?, `_by` = ?, `_on` = NOW()
                     WHERE `id` = ?",
                    [$title, $description, $address, $conditionId, $lat, $lng,
                     $current_user, $current_user_id, $id]
                );
                audit_log('config', 'update', 'road_condition', $id,
                    "Updated road condition '{$title}'",
                    ['address' => $address, 'condition_id' => $conditionId]);
            } else {
                db_query(
                    "INSERT INTO `{$prefix}roadinfo`
                     (`title`, `description`, `address`, `conditions`, `lat`, `lng`,
                      `username`, `_by`, `_on`, `_from`)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)",
                    [$title, $description, $address, $conditionId, $lat, $lng,
                     $current_user, $current_user_id, $_SERVER['REMOTE_ADDR'] ?? '']
                );
                $id = (int) db_insert_id();
                audit_log('config', 'create', 'road_condition', $id,
                    "Created road condition '{$title}'",
                    ['address' => $address, 'condition_id' => $conditionId]);
            }
            json_response(['saved' => true, 'id' => $id]);
        } catch (Exception $e) {
            json_error('Save failed: ' . $e->getMessage(), 500);
        }
    }

    // ── delete — delete road condition ──────────────────────────
    if ($action === 'delete') {
        $id = isset($input['id']) ? (int) $input['id'] : 0;
        if (!$id) {
            json_error('Missing id');
        }
        try {
            // Fetch title for audit log before deleting
            $existing = db_fetch_one(
                "SELECT `title` FROM `{$prefix}roadinfo` WHERE `id` = ?",
                [$id]
            );
            db_query("DELETE FROM `{$prefix}roadinfo` WHERE `id` = ?", [$id]);
            audit_log('config', 'delete', 'road_condition', $id,
                "Deleted road condition '" . ($existing['title'] ?? 'unknown') . "'");
            json_response(['deleted' => true]);
        } catch (Exception $e) {
            json_error('Delete failed: ' . $e->getMessage(), 500);
        }
    }

    // ── save_type — create/update condition type ────────────────
    if ($action === 'save_type') {
        $id          = isset($input['id']) && $input['id'] !== '' ? (int) $input['id'] : null;
        $title       = trim($input['title'] ?? '');
        $description = trim($input['description'] ?? '');
        $icon        = trim($input['icon'] ?? '');

        if ($title === '') {
            json_error('Condition type title is required');
        }

        try {
            if ($id) {
                db_query(
                    "UPDATE `{$prefix}conditions`
                     SET `title` = ?, `description` = ?, `icon` = ?,
                         `_by` = ?, `_on` = NOW()
                     WHERE `id` = ?",
                    [$title, $description, $icon, $current_user_id, $id]
                );
                audit_log('config', 'update', 'condition_type', $id,
                    "Updated condition type '{$title}'",
                    ['icon' => $icon]);
            } else {
                db_query(
                    "INSERT INTO `{$prefix}conditions`
                     (`title`, `description`, `icon`, `_by`, `_on`, `_from`)
                     VALUES (?, ?, ?, ?, NOW(), ?)",
                    [$title, $description, $icon, $current_user_id, $_SERVER['REMOTE_ADDR'] ?? '']
                );
                $id = (int) db_insert_id();
                audit_log('config', 'create', 'condition_type', $id,
                    "Created condition type '{$title}'",
                    ['icon' => $icon]);
            }
            json_response(['saved' => true, 'id' => $id]);
        } catch (Exception $e) {
            json_error('Save failed: ' . $e->getMessage(), 500);
        }
    }

    // ── delete_type — delete condition type ─────────────────────
    if ($action === 'delete_type') {
        $id = isset($input['id']) ? (int) $input['id'] : 0;
        if (!$id) {
            json_error('Missing id');
        }
        try {
            // Check if any road conditions use this type
            $count = db_fetch_one(
                "SELECT COUNT(*) AS cnt FROM `{$prefix}roadinfo` WHERE `conditions` = ?",
                [$id]
            );
            if ($count && (int) $count['cnt'] > 0) {
                json_error('Cannot delete: ' . $count['cnt'] . ' road condition(s) use this type. Reassign them first.');
            }

            $existing = db_fetch_one(
                "SELECT `title` FROM `{$prefix}conditions` WHERE `id` = ?",
                [$id]
            );
            db_query("DELETE FROM `{$prefix}conditions` WHERE `id` = ?", [$id]);
            audit_log('config', 'delete', 'condition_type', $id,
                "Deleted condition type '" . ($existing['title'] ?? 'unknown') . "'");
            json_response(['deleted' => true]);
        } catch (Exception $e) {
            json_error('Delete failed: ' . $e->getMessage(), 500);
        }
    }

    json_error('Unknown action: ' . $action, 400);
}

json_error('Method not allowed', 405);
