<?php
/**
 * NewUI v4.0 API — Facility Bed/Capacity Tracking
 *
 * GET  /api/facility-capacity.php?facility_id=X  — Get capacity for a facility
 * GET  /api/facility-capacity.php?summary=1       — Get summary across all facilities
 * POST action=update                              — Update capacity counts
 * POST action=save_category                       — Create/update capacity category
 * POST action=delete_category                     — Delete capacity category
 *
 * Tracks beds, shelter spots, equipment slots, etc. by category per facility.
 */

ini_set('display_errors', '0');
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/audit.php';
require_once __DIR__ . '/../inc/access.php';

$method = $_SERVER['REQUEST_METHOD'];
$prefix = $GLOBALS['db_prefix'] ?? '';

// ── Ensure tables exist ──
try {
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}capacity_categories` (
        `id`          INT AUTO_INCREMENT PRIMARY KEY,
        `name`        VARCHAR(64) NOT NULL,
        `icon`        VARCHAR(64) DEFAULT 'bi-hospital',
        `unit_label`  VARCHAR(32) DEFAULT 'beds',
        `sort_order`  INT NOT NULL DEFAULT 0,
        UNIQUE KEY `uk_cap_name` (`name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}facility_capacity` (
        `id`           INT AUTO_INCREMENT PRIMARY KEY,
        `facility_id`  INT NOT NULL,
        `category_id`  INT NOT NULL,
        `total`        INT NOT NULL DEFAULT 0,
        `available`    INT NOT NULL DEFAULT 0,
        `notes`        VARCHAR(255) DEFAULT '',
        `updated_by`   INT NOT NULL DEFAULT 0,
        `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY `uk_fac_cat` (`facility_id`, `category_id`),
        KEY `idx_facility` (`facility_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

// Seed default categories
try {
    $count = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}capacity_categories`");
    if ($count === 0) {
        $cats = [
            ['ICU Beds',       'bi-heart-pulse',  'beds',   1],
            ['General Beds',   'bi-hospital',     'beds',   2],
            ['Pediatric Beds', 'bi-emoji-smile',  'beds',   3],
            ['ER Beds',        'bi-lightning',     'beds',   4],
            ['Shelter Spots',  'bi-house',         'spots',  5],
            ['Cots',           'bi-moon',          'cots',   6],
            ['Ventilators',    'bi-wind',          'units',  7],
            ['Decon Stations', 'bi-droplet',       'stations', 8]
        ];
        foreach ($cats as $c) {
            db_query("INSERT IGNORE INTO `{$prefix}capacity_categories` (name, icon, unit_label, sort_order) VALUES (?, ?, ?, ?)", $c);
        }
    }
} catch (Exception $e) {}

// ── GET ──
if ($method === 'GET') {
    // Summary across all facilities
    if (!empty($_GET['summary'])) {
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
            $rows = [];
        }

        // Also return totals
        $totals = [];
        foreach ($rows as $r) {
            $cat = $r['category_name'];
            if (!isset($totals[$cat])) $totals[$cat] = ['total' => 0, 'available' => 0];
            $totals[$cat]['total'] += (int) $r['total'];
            $totals[$cat]['available'] += (int) $r['available'];
        }

        json_response(['capacity' => $rows, 'totals' => $totals]);
    }

    // Categories list
    if (!empty($_GET['categories'])) {
        try {
            $cats = db_fetch_all("SELECT * FROM `{$prefix}capacity_categories` ORDER BY sort_order, name");
        } catch (Exception $e) {
            $cats = [];
        }
        json_response(['categories' => $cats]);
    }

    // Single facility
    $facId = intval($_GET['facility_id'] ?? 0);
    if (!$facId) json_error('facility_id required');

    // IDOR — non-admins must be in a group allocated to this facility.
    if (!user_can_access_entity('facility', $facId)) {
        json_error('Facility not found', 404);
    }

    try {
        $rows = db_fetch_all(
            "SELECT fc.*, cc.name AS category_name, cc.icon, cc.unit_label
             FROM `{$prefix}facility_capacity` fc
             JOIN `{$prefix}capacity_categories` cc ON fc.category_id = cc.id
             WHERE fc.facility_id = ?
             ORDER BY cc.sort_order",
            [$facId]
        );
    } catch (Exception $e) {
        $rows = [];
    }

    // Get all categories so UI can show empty slots
    try {
        $allCats = db_fetch_all("SELECT * FROM `{$prefix}capacity_categories` ORDER BY sort_order");
    } catch (Exception $e) {
        $allCats = [];
    }

    json_response(['facility_id' => $facId, 'capacity' => $rows, 'categories' => $allCats]);
}

// ── POST ──
if ($method === 'POST') {
    if (!rbac_can('action.update_capacity')) {
        json_error('Insufficient permissions: update capacity', 403);
    }

    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $input['action'] ?? '';

    // Update capacity
    if ($action === 'update') {
        $facId = intval($input['facility_id'] ?? 0);
        $catId = intval($input['category_id'] ?? 0);
        if (!$facId || !$catId) json_error('facility_id and category_id required');

        // IDOR — admins-by-RBAC could still be in another org; require
        // group access to the specific facility before mutating capacity.
        if (!user_can_access_entity('facility', $facId)) {
            json_error('Facility not found', 404);
        }

        $total = max(0, intval($input['total'] ?? 0));
        $available = min($total, max(0, intval($input['available'] ?? 0)));
        $notes = trim($input['notes'] ?? '');
        $userId = (int) ($_SESSION['user_id'] ?? 0);

        try {
            db_query(
                "INSERT INTO `{$prefix}facility_capacity` (facility_id, category_id, total, available, notes, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE total = VALUES(total), available = VALUES(available), notes = VALUES(notes), updated_by = VALUES(updated_by)",
                [$facId, $catId, $total, $available, $notes, $userId]
            );
            audit_log('facility', 'update', 'capacity', $facId,
                "Updated capacity: cat=$catId total=$total available=$available");

            // Publish SSE event — F-007 long-tail fix: scope by the
            // facility's allocates groups so users in other orgs don't
            // see capacity changes for facilities they don't operate.
            $sseFn = function_exists('_facility_sse_publish_scoped')
                ? '_facility_sse_publish_scoped'
                : 'sse_publish_for_admin';
            // Use the facility-scoped helper if we have it; otherwise fall
            // back to the generic `_sse_groups_for_resource` lookup.
            if (function_exists('sse_publish')) {
                $groups = function_exists('_sse_groups_for_resource')
                    ? _sse_groups_for_resource($facId, 3) // type=3 facility
                    : [];
                $payload = [
                    'facility_id' => $facId,
                    'category_id' => $catId,
                    'total'       => $total,
                    'available'   => $available
                ];
                if (!empty($groups)) {
                    sse_publish('facility:capacity', $payload, null, 'group', $groups);
                } elseif (function_exists('sse_publish_for_admin')) {
                    // No allocates rows — fail-closed to admin only.
                    sse_publish_for_admin('facility:capacity', $payload);
                } else {
                    sse_publish('facility:capacity', $payload);
                }
            }
        } catch (Exception $e) {
            json_error('Update failed: ' . $e->getMessage());
        }
        json_response(['success' => true]);
    }

    // Save category
    if ($action === 'save_category') {
        $id = intval($input['id'] ?? 0);
        $name = trim($input['name'] ?? '');
        if (!$name) json_error('Category name required');

        $icon = $input['icon'] ?? 'bi-hospital';
        $unitLabel = $input['unit_label'] ?? 'beds';
        $order = intval($input['sort_order'] ?? 0);

        try {
            if ($id > 0) {
                db_query("UPDATE `{$prefix}capacity_categories` SET name=?, icon=?, unit_label=?, sort_order=? WHERE id=?",
                    [$name, $icon, $unitLabel, $order, $id]);
            } else {
                db_query("INSERT INTO `{$prefix}capacity_categories` (name, icon, unit_label, sort_order) VALUES (?, ?, ?, ?)",
                    [$name, $icon, $unitLabel, $order]);
                $id = db_insert_id();
            }
        } catch (Exception $e) {
            json_error('Save failed: ' . $e->getMessage());
        }
        json_response(['success' => true, 'id' => $id]);
    }

    // Delete category
    if ($action === 'delete_category') {
        $id = intval($input['id'] ?? 0);
        if (!$id) json_error('id required');

        try {
            $used = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}facility_capacity` WHERE category_id = ?", [$id]);
            if ($used > 0) json_error("Cannot delete: $used facility(s) use this category");
            db_query("DELETE FROM `{$prefix}capacity_categories` WHERE id = ?", [$id]);
        } catch (Exception $e) {
            json_error('Delete failed: ' . $e->getMessage());
        }
        json_response(['success' => true]);
    }

    json_error('Unknown action');
}

json_error('Method not allowed', 405);
