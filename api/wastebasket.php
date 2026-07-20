<?php
/**
 * NewUI v4.0 API - Wastebasket (Recoverable Deletes)
 *
 * GET  /api/wastebasket.php              — List soft-deleted records
 * GET  /api/wastebasket.php?type=X       — Filter by type (member, responder, ticket, facility)
 * GET  /api/wastebasket.php?count=1      — Just return total count of deleted items
 * POST /api/wastebasket.php action=restore  — Restore a deleted record
 * POST /api/wastebasket.php action=purge    — Permanently delete (admin only)
 * POST /api/wastebasket.php action=empty    — Purge all deleted records older than N days (admin only)
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/audit.php';

ini_set('display_errors', '0');

$prefix = $GLOBALS['db_prefix'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// Only admins can access wastebasket
if (!is_admin()) {
    json_error('Insufficient permissions. Only administrators can manage the wastebasket.', 403);
}

// Safe query helper
function safe_wb_fetch($sql, $params = []) {
    try {
        return db_fetch_all($sql, $params);
    } catch (Exception $e) {
        // Phase 73f — silent SQL failures used to leave zero trace.
            // Log the SQL excerpt + driver message so future column-name drift
            // shows up in /var/log/apache2/*-error.log instead of via Eric.
            error_log("[safe_wb_fetch] silent SQL failure: " . $e->getMessage()
                . " - SQL: " . preg_replace('/\s+/', ' ', substr($sql, 0, 240)));
            return [];
    }
}

/**
 * Check if a table has a deleted_at column.
 */
function table_has_soft_delete($tableName) {
    try {
        $col = db_fetch_one(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'deleted_at'",
            [$tableName]
        );
        return $col !== null;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Get a display name for a deleted record.
 */
function get_record_label($type, $row) {
    switch ($type) {
        case 'member':
            $fn = isset($row['first_name']) ? $row['first_name'] : '';
            $ln = isset($row['last_name']) ? $row['last_name'] : '';
            $name = trim($fn . ' ' . $ln);
            return $name ?: ('Member #' . $row['id']);

        case 'responder':
            return isset($row['name']) ? $row['name'] : ('Unit #' . $row['id']);

        case 'ticket':
            $nature = isset($row['nature']) ? $row['nature'] : '';
            $addr = isset($row['address']) ? $row['address'] : '';
            // Phase 99p — fallback uses the case number when available.
            $caseNum = !empty($row['incident_number']) ? $row['incident_number'] : ('#' . $row['id']);
            return $nature ? $nature . ($addr ? ' @ ' . $addr : '') : ('Incident ' . $caseNum);

        case 'facilities':
            return isset($row['name']) ? $row['name'] : ('Facility #' . $row['id']);

        default:
            return '#' . ($row['id'] ?? '?');
    }
}

// ═══════════════════════════════════════════════════════════════
//  Table configuration — types we support soft-delete for
// ═══════════════════════════════════════════════════════════════
$tableConfig = [
    'member' => [
        'table'   => $prefix . 'member',
        'label'   => 'Member',
        'icon'    => 'bi-person',
        'select'  => 'id, first_name, last_name, callsign, email, deleted_at, deleted_by',
    ],
    'responder' => [
        'table'   => $prefix . 'responder',
        'label'   => 'Unit',
        'icon'    => 'bi-people',
        'select'  => 'id, name, handle, description, deleted_at, deleted_by',
    ],
    'ticket' => [
        'table'   => $prefix . 'ticket',
        'label'   => 'Incident',
        'icon'    => 'bi-exclamation-triangle',
        'select'  => 'id, nature, address, city, description, deleted_at, deleted_by',
    ],
    'facilities' => [
        'table'   => $prefix . 'facilities',
        'label'   => 'Facility',
        'icon'    => 'bi-hospital',
        'select'  => 'id, name, description, deleted_at, deleted_by',
    ],
];

// ═══════════════════════════════════════════════════════════════
//  GET — List deleted records
// ═══════════════════════════════════════════════════════════════
if ($method === 'GET') {

    // Count-only mode (for badge)
    if (isset($_GET['count'])) {
        $total = 0;
        foreach ($tableConfig as $type => $cfg) {
            if (!table_has_soft_delete($cfg['table'])) continue;
            $rows = safe_wb_fetch(
                "SELECT COUNT(*) AS cnt FROM `{$cfg['table']}` WHERE `deleted_at` IS NOT NULL"
            );
            $total += (!empty($rows) ? (int) $rows[0]['cnt'] : 0);
        }
        json_response(['count' => $total]);
    }

    $filterType = isset($_GET['type']) ? trim($_GET['type']) : '';
    $items = [];

    foreach ($tableConfig as $type => $cfg) {
        if ($filterType && $filterType !== $type) continue;
        if (!table_has_soft_delete($cfg['table'])) continue;

        $rows = safe_wb_fetch(
            "SELECT {$cfg['select']} FROM `{$cfg['table']}`
             WHERE `deleted_at` IS NOT NULL
             ORDER BY `deleted_at` DESC
             LIMIT 500"
        );

        foreach ($rows as $row) {
            // Resolve deleted_by user name
            $deletedByName = '';
            if (!empty($row['deleted_by'])) {
                $uRow = safe_wb_fetch(
                    "SELECT `user` FROM `{$prefix}user` WHERE `id` = ?",
                    [(int) $row['deleted_by']]
                );
                $deletedByName = !empty($uRow) ? $uRow[0]['user'] : ('User #' . $row['deleted_by']);
            }

            $items[] = [
                'type'        => $type,
                'type_label'  => $cfg['label'],
                'type_icon'   => $cfg['icon'],
                'id'          => (int) $row['id'],
                'label'       => get_record_label($type, $row),
                'deleted_at'  => $row['deleted_at'],
                'deleted_by'  => $deletedByName,
            ];
        }
    }

    // Sort all by deleted_at descending
    usort($items, function ($a, $b) {
        return strcmp($b['deleted_at'], $a['deleted_at']);
    });

    json_response([
        'items' => $items,
        'count' => count($items),
    ]);
}

// ═══════════════════════════════════════════════════════════════
//  POST — Write operations
// ═══════════════════════════════════════════════════════════════
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        json_error('Invalid JSON body');
    }

    // CSRF check
    if (empty($input['csrf_token']) || !csrf_verify($input['csrf_token'])) {
        json_error('Invalid CSRF token', 403);
    }

    $action = $input['action'] ?? '';

    // ── Restore a deleted record ───────────────────────────────
    if ($action === 'restore') {
        $type = trim($input['type'] ?? '');
        $id = (int) ($input['id'] ?? 0);

        if (!$type || !$id || !isset($tableConfig[$type])) {
            json_error('Invalid type or id');
        }

        $cfg = $tableConfig[$type];
        if (!table_has_soft_delete($cfg['table'])) {
            json_error('Soft delete not supported for this type');
        }

        try {
            // Verify it exists and is deleted
            $row = db_fetch_one(
                "SELECT `id` FROM `{$cfg['table']}` WHERE `id` = ? AND `deleted_at` IS NOT NULL",
                [$id]
            );
            if (!$row) {
                json_error('Record not found or not deleted', 404);
            }

            db_query(
                "UPDATE `{$cfg['table']}` SET `deleted_at` = NULL, `deleted_by` = NULL WHERE `id` = ?",
                [$id]
            );

            audit_log('system', 'restore', $type, $id,
                "Restored {$cfg['label']} #{$id} from wastebasket", null, 3);

        } catch (Exception $e) {
            json_error('Failed to restore: ' . $e->getMessage());
        }

        json_response(['success' => true, 'message' => $cfg['label'] . ' restored']);
    }

    // ── Purge (permanently delete) a single record ─────────────
    if ($action === 'purge') {
        // Super admin only (level 0)
        if (!is_admin()) {
            json_error('Only super administrators can permanently delete records', 403);
        }

        $type = trim($input['type'] ?? '');
        $id = (int) ($input['id'] ?? 0);

        if (!$type || !$id || !isset($tableConfig[$type])) {
            json_error('Invalid type or id');
        }

        $cfg = $tableConfig[$type];

        try {
            // Only purge if already soft-deleted
            $row = db_fetch_one(
                "SELECT `id` FROM `{$cfg['table']}` WHERE `id` = ? AND `deleted_at` IS NOT NULL",
                [$id]
            );
            if (!$row) {
                json_error('Record not found or not in wastebasket', 404);
            }

            // Clean up related records
            if ($type === 'member') {
                try { db_query("DELETE FROM `{$prefix}member_certifications` WHERE `member_id` = ?", [$id]); } catch (Exception $e) {}
                try { db_query("DELETE FROM `{$prefix}member_callsigns` WHERE `member_id` = ?", [$id]); } catch (Exception $e) {}
                try { db_query("DELETE FROM `{$prefix}member_organizations` WHERE `member_id` = ?", [$id]); } catch (Exception $e) {}
                try { db_query("DELETE FROM `{$prefix}member_comm_identifiers` WHERE `member_id` = ?", [$id]); } catch (Exception $e) {}
            } elseif ($type === 'responder') {
                try { db_query("DELETE FROM `{$prefix}allocates` WHERE `resource_id` = ? AND `type` = 2", [$id]); } catch (Exception $e) {}
            }

            db_query("DELETE FROM `{$cfg['table']}` WHERE `id` = ?", [$id]);

            audit_log('system', 'delete', $type, $id,
                "Permanently deleted {$cfg['label']} #{$id} from wastebasket", null, 4);

        } catch (Exception $e) {
            json_error('Failed to purge: ' . $e->getMessage());
        }

        json_response(['success' => true, 'message' => $cfg['label'] . ' permanently deleted']);
    }

    // ── Empty wastebasket (purge items older than N days) ──────
    if ($action === 'empty') {
        // Super admin only
        if (!is_admin()) {
            json_error('Only super administrators can empty the wastebasket', 403);
        }

        $days = (int) ($input['days'] ?? 30);
        if ($days < 1) $days = 30;
        $purged = 0;

        foreach ($tableConfig as $type => $cfg) {
            if (!table_has_soft_delete($cfg['table'])) continue;

            try {
                // Count what we are about to purge
                $countRow = db_fetch_one(
                    "SELECT COUNT(*) AS cnt FROM `{$cfg['table']}`
                     WHERE `deleted_at` IS NOT NULL
                       AND `deleted_at` < DATE_SUB(NOW(), INTERVAL ? DAY)",
                    [$days]
                );
                $cnt = $countRow ? (int) $countRow['cnt'] : 0;

                if ($cnt > 0) {
                    // Clean up related records for members
                    if ($type === 'member') {
                        try {
                            db_query(
                                "DELETE mc FROM `{$prefix}member_certifications` mc
                                 JOIN `{$prefix}member` m ON mc.member_id = m.id
                                 WHERE m.`deleted_at` IS NOT NULL
                                   AND m.`deleted_at` < DATE_SUB(NOW(), INTERVAL ? DAY)",
                                [$days]
                            );
                        } catch (Exception $e) {}
                    }

                    db_query(
                        "DELETE FROM `{$cfg['table']}`
                         WHERE `deleted_at` IS NOT NULL
                           AND `deleted_at` < DATE_SUB(NOW(), INTERVAL ? DAY)",
                        [$days]
                    );
                    $purged += $cnt;
                }
            } catch (Exception $e) {
                // Continue with other types
            }
        }

        audit_log('system', 'delete', 'wastebasket', null,
            "Emptied wastebasket: purged {$purged} records older than {$days} days", null, 4);

        json_response([
            'success' => true,
            'purged'  => $purged,
            'message' => "Purged {$purged} records older than {$days} days"
        ]);
    }

    json_error('Unknown action');
}

json_error('Method not allowed', 405);
