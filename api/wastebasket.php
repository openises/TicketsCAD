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
// ics_form_label() — one definition of how an ICS form is named, shared with
// the delete path so the wastebasket row and the audit entry read alike.
require_once __DIR__ . '/../inc/ics-forms-write.php';
// wb_purge_ticket_children() — GH#124's ticket-purge cascade (assigns/
// action/patient/files). Extracted to inc/ so it's directly testable
// without booting this file's own HTTP request dispatch below.
require_once __DIR__ . '/../inc/wastebasket-write.php';

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
            // Issue #25 — read the columns `ticket` actually has. These
            // were `nature`/`address`, which do not exist, so this arm
            // could only ever reach its fallback even if the SELECT had
            // succeeded.
            $nature = isset($row['scope']) ? $row['scope'] : '';
            $addr = isset($row['street']) ? $row['street'] : '';
            // Phase 99p — fallback uses the case number when available.
            $caseNum = !empty($row['incident_number']) ? $row['incident_number'] : ('#' . $row['id']);
            return $nature ? $nature . ($addr ? ' @ ' . $addr : '') : ('Incident ' . $caseNum);

        case 'facilities':
            return isset($row['name']) ? $row['name'] : ('Facility #' . $row['id']);

        case 'ics_forms':
            return ics_form_label($row);

        case 'equipment_log':
            // GH#38 -- the select list is deliberately columns-only (no join,
            // matching every other arm here), so resolve the equipment name
            // with one small lookup rather than widening the shared SELECT.
            $actionLabel = ucfirst((string) ($row['action'] ?? 'activity'));
            $eqName = null;
            try {
                $eq = db_fetch_one(
                    "SELECT name FROM `" . ($GLOBALS['db_prefix'] ?? '') . "newui_equipment` WHERE id = ?",
                    [(int) ($row['equipment_id'] ?? 0)]
                );
                $eqName = $eq['name'] ?? null;
            } catch (Exception $e) {}
            return $actionLabel . ' — ' . ($eqName ?: ('Equipment #' . ($row['equipment_id'] ?? '?')));

        default:
            return '#' . ($row['id'] ?? '?');
    }
}

// ═══════════════════════════════════════════════════════════════
//  Table configuration — types we support soft-delete for
//
//  `purgeable` (default true) says whether this type may be PERMANENTLY
//  destroyed — by the per-row purge button or by "Empty wastebasket". It is
//  false for ICS forms: a finalized ICS-214 is the operational record of a
//  real incident, so the approved policy (Eric, 2026-08-02) is that they are
//  recoverable forever and no path hard-deletes one. Both write paths below
//  honour it, and the GET output carries `can_purge` so the UI does not offer
//  a button the server will refuse.
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
        // Public issue #25 — `nature` and `address` are not columns of
        // `ticket`; the real ones are `scope` and `street`. The SELECT
        // therefore threw 1054, safe_wb_fetch() swallowed it and
        // returned [], and soft-deleted incidents were invisible in the
        // wastebasket — while still being served everywhere else.
        // Found while fixing the read paths: stopping the leak makes
        // this the ONLY route back to a mistakenly deleted incident, so
        // it has to actually work. `incident_number` is selected because
        // get_record_label() reads it for the fallback label.
        'select'  => 'id, incident_number, scope, street, city, description, deleted_at, deleted_by',
    ],
    'facilities' => [
        'table'   => $prefix . 'facilities',
        'label'   => 'Facility',
        'icon'    => 'bi-hospital',
        'select'  => 'id, name, description, deleted_at, deleted_by',
    ],
    'ics_forms' => [
        'table'     => $prefix . 'ics_forms',
        'label'     => 'ICS Form',
        'icon'      => 'bi-file-earmark-text',
        // Phase 140: form_data_json is needed so ics_form_label() can read
        // a custom-type form's frozen _meta.form_number/form_title -- the
        // nine built-in types ignore this column entirely (their label
        // comes from form_type alone), so this is a no-op for them.
        'select'    => 'id, form_type, title, status, incident_id, form_data_json, deleted_at, deleted_by',
        'purgeable' => false,
    ],
    // GH#38 (Chris Byrd, 2026-08-07) — equipment checkout/checkin activity
    // log entries. Unlike ICS forms this type IS purgeable (default, so no
    // 'purgeable' key needed): a mis-logged checkout/checkin line is a much
    // lower-stakes record than a finalized incident form.
    'equipment_log' => [
        'table'   => $prefix . 'newui_equipment_log',
        'label'   => 'Equipment Log Entry',
        'icon'    => 'bi-clock-history',
        'select'  => 'id, equipment_id, `action`, member_id, notes, created_at, deleted_at, deleted_by',
    ],
];

/** Whether a wastebasket type may ever be permanently destroyed. */
function wb_is_purgeable(array $cfg): bool {
    return !array_key_exists('purgeable', $cfg) || $cfg['purgeable'] === true;
}

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
                // settings.php reads exactly this key to decide whether to
                // render the permanent-delete button.
                'can_purge'   => wb_is_purgeable($cfg),
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

        // Records-retention types are never destroyed, by anyone. This is the
        // server-side half of the rule — the button is also hidden, but the
        // button is not the gate.
        if (!wb_is_purgeable($cfg)) {
            json_error($cfg['label'] . 's are operational records and cannot be permanently '
                . 'deleted. They stay here and can be restored at any time.', 403);
        }

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
                // Chris Byrd, Google Group 2026-08-06: "Vehicle Owner ...
                // appears i have some null records." newui_vehicles.member_id
                // has no foreign key and this list never included it, so
                // purging a member left any vehicle they owned pointing at a
                // row that no longer existed anywhere — not soft-deleted,
                // gone — and the owner column silently rendered blank with
                // nothing to explain why. The vehicle is a real asset and
                // outlives its owner; null out the reference rather than
                // touching the vehicle itself.
                try { db_query("UPDATE `{$prefix}newui_vehicles` SET `member_id` = NULL WHERE `member_id` = ?", [$id]); } catch (Exception $e) {}
            } elseif ($type === 'responder') {
                try { db_query("DELETE FROM `{$prefix}allocates` WHERE `resource_id` = ? AND `type` = 2", [$id]); } catch (Exception $e) {}
            } elseif ($type === 'ticket') {
                wb_purge_ticket_children([$id]);
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
        // GH#43 (Chris Byrd, 2026-08-08): "Says 1 deleted item. ICS Form does
        // not delete." Not a bug -- ICS Forms are deliberately excluded from
        // Empty (see wb_is_purgeable()), and the "1 deleted item" was some
        // OTHER eligible record entirely. The defect was that the response
        // never said so, leaving an admin who was watching one specific
        // record with no way to tell "skipped on purpose" from "silently
        // failed". $skippedLabels counts and names what Empty is about to
        // leave behind, of exactly the age that would otherwise qualify.
        $skippedLabels = [];

        foreach ($tableConfig as $type => $cfg) {
            if (!table_has_soft_delete($cfg['table'])) continue;
            // Never swept up by a bulk empty — see wb_is_purgeable().
            if (!wb_is_purgeable($cfg)) {
                try {
                    $skipCnt = (int) db_fetch_value(
                        "SELECT COUNT(*) FROM `{$cfg['table']}`
                          WHERE `deleted_at` IS NOT NULL
                            AND `deleted_at` < DATE_SUB(NOW(), INTERVAL ? DAY)",
                        [$days]
                    );
                    if ($skipCnt > 0) {
                        $skippedLabels[] = $skipCnt . ' ' . $cfg['label'] . ($skipCnt === 1 ? '' : 's');
                    }
                } catch (Exception $e) { /* non-fatal — reporting only */ }
                continue;
            }

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
                    } elseif ($type === 'ticket') {
                        // GH#124 — same cascade the single-record purge below
                        // gets, applied to every ticket this bulk sweep is
                        // about to permanently remove. Resolve the eligible
                        // ids first since wb_purge_ticket_children() needs
                        // them individually (it also unlinks each ticket's
                        // attached files on disk).
                        try {
                            $eligibleTicketIds = array_column(db_fetch_all(
                                "SELECT id FROM `{$cfg['table']}`
                                  WHERE `deleted_at` IS NOT NULL
                                    AND `deleted_at` < DATE_SUB(NOW(), INTERVAL ? DAY)",
                                [$days]
                            ), 'id');
                            wb_purge_ticket_children($eligibleTicketIds);
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
            "Emptied wastebasket: purged {$purged} records older than {$days} days"
                . (!empty($skippedLabels) ? '; left in place (not purgeable): ' . implode(', ', $skippedLabels) : ''),
            null, 4);

        $message = "Purged {$purged} record" . ($purged === 1 ? '' : 's') . " older than {$days} day" . ($days === 1 ? '' : 's') . ".";
        if (!empty($skippedLabels)) {
            $message .= ' Left in place: ' . implode(', ', $skippedLabels)
                . ' — these are operational records that are never permanently deleted, only restored.';
        }

        json_response([
            'success' => true,
            'purged'  => $purged,
            'skipped' => $skippedLabels,
            'message' => $message
        ]);
    }

    json_error('Unknown action');
}

json_error('Method not allowed', 405);
