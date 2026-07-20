<?php
/**
 * NewUI v4.0 API - Communication / Tracking Identifiers
 *
 * Manages comm modes (reference table) and per-member identifiers.
 *
 * GET  ?action=modes                         — All comm modes
 * GET  ?action=member_ids&member_id=X        — Identifiers for a member
 * GET  ?action=radioid_lookup&callsign=X     — Proxy to RadioID.net API
 *
 * POST action=save_mode                      — Create/update comm mode (admin)
 * POST action=delete_mode                    — Delete comm mode (admin)
 * POST action=save_identifier                — Add/update member identifier (level <= 2)
 * POST action=delete_identifier              — Remove member identifier
 * POST action=set_primary                    — Toggle primary flag
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/audit.php';

$prevDisplay = ini_get('display_errors');
ini_set('display_errors', '0');

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    _ensure_sort_order_column();
    handleGet();
} elseif ($method === 'POST') {
    _ensure_sort_order_column();
    handlePost();
} else {
    json_error('Method not allowed', 405);
}

ini_set('display_errors', $prevDisplay);

/**
 * Phase 48 (2026-06-14): self-healing schema. The original
 * member_comm_identifiers table (sql/comm_identifiers.sql) doesn't have a
 * sort_order column — sorting was by `mode_sort_order, label` which gives
 * no per-member control. Eric needs to prioritise multiple Radio IDs on
 * one member ("Mobile HT first, Portable second, Base last"). Add the
 * column on first hit so existing installs upgrade transparently.
 */
function _ensure_sort_order_column(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        // Fix (a beta tester GH issue #2, 2026-07-01) — db_table() returns
        // the table name ALREADY wrapped in backticks (see inc/db.php),
        // so wrapping it AGAIN in "`{$tbl}`" produced double-backticks
        // which MySQL treats as invalid identifier syntax. The catch
        // below then swallowed the resulting SQL error, the column
        // never got added, and every subsequent save_identifier
        // request blew up with "Unknown column 'sort_order' in SELECT".
        // db_table() returns backticked identifier — use as-is.
        $tbl = db_table('member_comm_identifiers');
        $cols = db_fetch_all("SHOW COLUMNS FROM {$tbl} LIKE 'sort_order'");
        if (!$cols) {
            db_query("ALTER TABLE {$tbl} ADD COLUMN `sort_order` INT NOT NULL DEFAULT 0");
            // Seed sort_order from the existing row order (id ascending)
            // so the first list render preserves what admins were looking
            // at before. New rows pick up the next slot on insert.
            db_query("UPDATE {$tbl} SET sort_order = id WHERE sort_order = 0");
        }
    } catch (Exception $e) {
        // Don't break the request if the schema check fails — older
        // MySQL grants may forbid SHOW COLUMNS. The reorder action will
        // surface a clean error if the column is genuinely missing.
        error_log('comm-identifiers _ensure_sort_order_column: ' . $e->getMessage());
    }
}

// ─── GET handlers ──────────────────────────────────────────────────────

function handleGet() {
    $action = $_GET['action'] ?? 'modes';

    if ($action === 'modes') {
        $rows = db_fetch_all(
            "SELECT cm.*,
                    (SELECT COUNT(*) FROM " . db_table('member_comm_identifiers') . " mci
                     WHERE mci.comm_mode_id = cm.id) AS identifier_count
             FROM " . db_table('comm_modes') . " cm
             ORDER BY cm.sort_order, cm.name"
        );
        json_response(['modes' => $rows]);
    }

    if ($action === 'member_ids') {
        $memberId = intval($_GET['member_id'] ?? 0);
        if (!$memberId) json_error('member_id required');

        // Phase 48 — per-identifier sort_order takes precedence over the
        // per-mode sort_order. Phase 48b — COALESCE on NULLIF(sort_order, 0)
        // so legacy rows that came in before the column existed (or were
        // INSERTed without a sort_order) still land in id order rather
        // than all clustering at sort_order=0.
        $rows = db_fetch_all(
            "SELECT mci.*, cm.code AS mode_code, cm.name AS mode_name,
                    cm.icon AS mode_icon, cm.color AS mode_color, cm.fields_json
             FROM " . db_table('member_comm_identifiers') . " mci
             JOIN " . db_table('comm_modes') . " cm ON mci.comm_mode_id = cm.id
             WHERE mci.member_id = ? AND cm.enabled = 1
             ORDER BY COALESCE(NULLIF(mci.sort_order, 0), mci.id),
                      cm.sort_order, mci.id",
            [$memberId]
        );
        json_response(['identifiers' => $rows]);
    }

    if ($action === 'radioid_lookup') {
        $callsign = trim($_GET['callsign'] ?? '');
        if ($callsign === '') json_error('callsign required');

        // Sanitize: only allow alphanumeric and common callsign chars
        $callsign = preg_replace('/[^A-Za-z0-9\/\-]/', '', $callsign);

        $url = 'https://database.radioid.net/api/dmr/user/?callsign=' . urlencode($callsign);

        $context = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'timeout' => 5,
                'header'  => "User-Agent: NewUI-CAD/4.0\r\n"
            ]
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            json_error('RadioID.net lookup failed — service may be unavailable. You can enter the ID manually.');
        }

        $data = json_decode($response, true);
        if (!$data) {
            json_error('Invalid response from RadioID.net');
        }

        // Return the results array
        json_response([
            'results' => $data['results'] ?? [],
            'count'   => $data['count'] ?? 0
        ]);
    }

    json_error('Unknown action: ' . $action);
}

// ─── POST handlers ─────────────────────────────────────────────────────

function handlePost() {
    global $current_level, $current_user_id;

    $raw   = file_get_contents('php://input');
    $input = json_decode($raw, true) ?: [];
    $action = $input['action'] ?? '';

    // ── Comm Mode CRUD (admin only) ─────────────────────────────

    if ($action === 'save_mode') {
        if (!is_admin()) json_error('Admin access required', 403);

        $id   = intval($input['id'] ?? 0);
        $code = trim($input['code'] ?? '');
        $name = trim($input['name'] ?? '');
        if ($code === '' || $name === '') json_error('Code and name are required');

        // Validate fields_json
        $fieldsJson = $input['fields_json'] ?? '[]';
        if (is_array($fieldsJson)) {
            $fieldsJson = json_encode($fieldsJson);
        }
        $fields = json_decode($fieldsJson, true);
        if (!is_array($fields)) json_error('fields_json must be a valid JSON array');

        $data = [
            'code'       => strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', $code)),
            'name'       => $name,
            'icon'       => trim($input['icon'] ?? '') ?: null,
            'color'      => trim($input['color'] ?? '#6c757d'),
            'fields_json'=> $fieldsJson,
            'lookup_url' => trim($input['lookup_url'] ?? '') ?: null,
            'enabled'    => isset($input['enabled']) ? (int)(bool)$input['enabled'] : 1,
            'sort_order' => intval($input['sort_order'] ?? 0),
            'notes'      => trim($input['notes'] ?? '') ?: null,
        ];

        try {
            if ($id > 0) {
                $sets = [];
                $vals = [];
                foreach ($data as $col => $val) {
                    $sets[] = "`$col` = ?";
                    $vals[] = $val;
                }
                $vals[] = $id;
                db_query(
                    "UPDATE " . db_table('comm_modes') . " SET " . implode(', ', $sets) . " WHERE id = ?",
                    $vals
                );
                audit_log('config', 'update', 'comm_mode', $id, "Updated comm mode '{$name}'");
            } else {
                $cols = array_keys($data);
                $placeholders = array_fill(0, count($cols), '?');
                db_query(
                    "INSERT INTO " . db_table('comm_modes') . " (`" . implode('`, `', $cols) . "`) VALUES (" . implode(', ', $placeholders) . ")",
                    array_values($data)
                );
                $id = db_insert_id();
                audit_log('config', 'create', 'comm_mode', $id, "Created comm mode '{$name}'");
            }
            json_response(['success' => true, 'id' => $id]);
        } catch (Exception $e) {
            json_error('Failed to save comm mode: ' . $e->getMessage());
        }
    }

    if ($action === 'delete_mode') {
        if (!is_admin()) json_error('Admin access required', 403);

        $id = intval($input['id'] ?? 0);
        if (!$id) json_error('id required');

        $cnt = db_fetch_one(
            "SELECT COUNT(*) AS cnt FROM " . db_table('member_comm_identifiers') . " WHERE comm_mode_id = ?",
            [$id]
        );
        if ($cnt && (int)$cnt['cnt'] > 0) {
            json_error("Cannot delete: {$cnt['cnt']} identifier(s) use this mode. Remove them first.");
        }

        try {
            $mode = db_fetch_one("SELECT name FROM " . db_table('comm_modes') . " WHERE id = ?", [$id]);
            $modeName = $mode ? $mode['name'] : "#{$id}";

            db_query("DELETE FROM " . db_table('comm_modes') . " WHERE id = ?", [$id]);
            audit_log('config', 'delete', 'comm_mode', $id, "Deleted comm mode '{$modeName}'");
            json_response(['success' => true]);
        } catch (Exception $e) {
            json_error('Failed to delete comm mode: ' . $e->getMessage());
        }
    }

    // ── Member Identifier CRUD (level <= 2) ─────────────────────

    if ($action === 'save_identifier') {
        // Phase 12 (2026-06-11): operator+ semantic = admin OR roles
        // that can manage members (Dispatcher / Operator). Read-Only /
        // Field Unit are denied.
        if (!is_admin() && !rbac_can('action.manage_members')) json_error('Operator access required', 403);

        $id       = intval($input['id'] ?? 0);
        $memberId = intval($input['member_id'] ?? 0);
        $modeId   = intval($input['comm_mode_id'] ?? 0);

        if (!$memberId && $id === 0) json_error('member_id required');
        if (!$modeId && $id === 0) json_error('comm_mode_id required');

        // Validate values_json against the mode's field definitions
        $valuesJson = $input['values_json'] ?? '{}';
        if (is_array($valuesJson)) {
            $valuesJson = json_encode($valuesJson);
        }
        $values = json_decode($valuesJson, true);
        if (!is_array($values)) json_error('values_json must be a valid JSON object');

        // If creating, validate required fields
        if ($id === 0 && $modeId) {
            $mode = db_fetch_one(
                "SELECT fields_json FROM " . db_table('comm_modes') . " WHERE id = ?",
                [$modeId]
            );
            if ($mode) {
                $fieldDefs = json_decode($mode['fields_json'], true) ?: [];
                foreach ($fieldDefs as $def) {
                    if (!empty($def['required']) && empty($values[$def['key']])) {
                        json_error("Required field '{$def['label']}' is missing");
                    }
                }
            }
        }

        $data = [
            'label'       => trim($input['label'] ?? '') ?: null,
            'values_json' => $valuesJson,
            'is_primary'  => isset($input['is_primary']) ? (int)(bool)$input['is_primary'] : 0,
            'notes'       => trim($input['notes'] ?? '') ?: null,
            'updated_at'  => date('Y-m-d H:i:s'),
        ];

        try {
            if ($id > 0) {
                $sets = [];
                $vals = [];
                foreach ($data as $col => $val) {
                    $sets[] = "`$col` = ?";
                    $vals[] = $val;
                }
                $vals[] = $id;
                db_query(
                    "UPDATE " . db_table('member_comm_identifiers') . " SET " . implode(', ', $sets) . " WHERE id = ?",
                    $vals
                );
                audit_log('comms', 'update', 'comm_identifier', $id, "Updated comm identifier #{$id}");
            } else {
                $data['member_id']    = $memberId;
                $data['comm_mode_id'] = $modeId;
                $data['created_at']   = date('Y-m-d H:i:s');
                // Phase 48 — append at the end of this member's list.
                // MAX+1 + 1 means a freshly-added row sits one slot below
                // everything that's already there.
                $maxSort = db_fetch_value(
                    "SELECT COALESCE(MAX(sort_order), 0) FROM " . db_table('member_comm_identifiers') . " WHERE member_id = ?",
                    [$memberId]
                );
                $data['sort_order'] = ((int) $maxSort) + 1;
                $cols = array_keys($data);
                $placeholders = array_fill(0, count($cols), '?');
                db_query(
                    "INSERT INTO " . db_table('member_comm_identifiers') .
                    " (`" . implode('`, `', $cols) . "`) VALUES (" . implode(', ', $placeholders) . ")",
                    array_values($data)
                );
                $id = db_insert_id();

                $mode = db_fetch_one("SELECT name FROM " . db_table('comm_modes') . " WHERE id = ?", [$modeId]);
                $modeName = $mode ? $mode['name'] : "mode #{$modeId}";
                audit_log('comms', 'create', 'comm_identifier', $id, "Added {$modeName} identifier for member #{$memberId}", [
                    'member_id'    => $memberId,
                    'comm_mode_id' => $modeId,
                    'label'        => $data['label']
                ]);
            }

            // 2026-06-11 polish: tell the JS whether this identifier can
            // drive live location tracking, and if so, which units the
            // member is currently assigned to. The roster comm-identifier
            // modal uses this to surface a "Don't forget to bind this on
            // the Unit Detail page" hint with one-click links.
            //
            // Location-capable modes are the ones whose `code` matches a
            // location_providers.code: aprs, dmr, meshtastic, owntracks.
            // Zello and Generic Radio are voice-only — no location hint.
            $locationCapable = false;
            $providerCode = null;
            try {
                $modeFull = db_fetch_one(
                    "SELECT cm.code FROM " . db_table('comm_modes') . " cm
                     WHERE cm.id = ?
                       AND cm.code IN ('aprs','dmr','meshtastic','owntracks')",
                    [$modeId]
                );
                if ($modeFull) {
                    $locationCapable = true;
                    $providerCode = $modeFull['code'];
                }
            } catch (Exception $e) {}

            $assignedUnits = [];
            if ($locationCapable) {
                try {
                    $assignedUnits = db_fetch_all(
                        "SELECT r.id, r.name
                         FROM " . db_table('unit_personnel_assignments') . " upa
                         JOIN " . db_table('responder') . " r ON r.id = upa.responder_id
                         WHERE upa.member_id = ?
                           AND upa.status = 'active'
                           AND upa.released_at IS NULL
                         ORDER BY r.name",
                        [$memberId]
                    );
                } catch (Exception $e) {
                    // unit_personnel_assignments table may not exist on
                    // pre-Phase-N installs — skip the hint quietly.
                }
            }

            json_response([
                'success'          => true,
                'id'               => $id,
                'location_capable' => $locationCapable,
                'provider_code'    => $providerCode,
                'assigned_units'   => $assignedUnits,
            ]);
        } catch (Exception $e) {
            json_error('Failed to save identifier: ' . $e->getMessage());
        }
    }

    if ($action === 'delete_identifier') {
        // Phase 12 (2026-06-11): operator+ semantic = admin OR roles
        // that can manage members (Dispatcher / Operator). Read-Only /
        // Field Unit are denied.
        if (!is_admin() && !rbac_can('action.manage_members')) json_error('Operator access required', 403);

        $id = intval($input['id'] ?? 0);
        if (!$id) json_error('id required');

        try {
            $row = db_fetch_one(
                "SELECT mci.member_id, cm.name AS mode_name
                 FROM " . db_table('member_comm_identifiers') . " mci
                 JOIN " . db_table('comm_modes') . " cm ON mci.comm_mode_id = cm.id
                 WHERE mci.id = ?",
                [$id]
            );
            db_query("DELETE FROM " . db_table('member_comm_identifiers') . " WHERE id = ?", [$id]);
            if ($row) {
                audit_log('comms', 'delete', 'comm_identifier', $id, "Removed {$row['mode_name']} identifier from member #{$row['member_id']}");
            }
            json_response(['success' => true]);
        } catch (Exception $e) {
            json_error('Failed to delete identifier: ' . $e->getMessage());
        }
    }

    if ($action === 'set_primary') {
        // Phase 12 (2026-06-11): operator+ semantic = admin OR roles
        // that can manage members (Dispatcher / Operator). Read-Only /
        // Field Unit are denied.
        if (!is_admin() && !rbac_can('action.manage_members')) json_error('Operator access required', 403);

        $id = intval($input['id'] ?? 0);
        if (!$id) json_error('id required');

        try {
            // Get the identifier to find its member_id and mode_id
            $row = db_fetch_one(
                "SELECT member_id, comm_mode_id FROM " . db_table('member_comm_identifiers') . " WHERE id = ?",
                [$id]
            );
            if (!$row) json_error('Identifier not found');

            // Clear primary on all identifiers of same mode/member
            db_query(
                "UPDATE " . db_table('member_comm_identifiers') . " SET is_primary = 0 WHERE member_id = ? AND comm_mode_id = ?",
                [$row['member_id'], $row['comm_mode_id']]
            );
            // Set this one as primary
            db_query(
                "UPDATE " . db_table('member_comm_identifiers') . " SET is_primary = 1 WHERE id = ?",
                [$id]
            );
            audit_log('comms', 'update', 'comm_identifier', $id, "Set comm identifier #{$id} as primary");
            json_response(['success' => true]);
        } catch (Exception $e) {
            json_error('Failed to set primary: ' . $e->getMessage());
        }
    }

    if ($action === 'reorder_identifier') {
        // Phase 48 — swap this row's sort_order with the row immediately
        // above ('up') or below ('down') in the same member's list. The
        // list query orders by sort_order ASC so 'up' means picking the
        // row with the next-lower sort_order and trading places.
        if (!is_admin() && !rbac_can('action.manage_members')) {
            json_error('Operator access required', 403);
        }
        $id = intval($input['id'] ?? 0);
        $direction = $input['direction'] ?? '';
        if (!$id) json_error('id required');
        if ($direction !== 'up' && $direction !== 'down') json_error('direction must be up or down');

        try {
            $tbl = db_table('member_comm_identifiers');
            $row = db_fetch_one(
                "SELECT id, member_id, sort_order FROM `{$tbl}` WHERE id = ?",
                [$id]
            );
            if (!$row) json_error('Identifier not found');

            if ($direction === 'up') {
                $other = db_fetch_one(
                    "SELECT id, sort_order FROM `{$tbl}`
                       WHERE member_id = ?
                         AND (sort_order < ? OR (sort_order = ? AND id < ?))
                       ORDER BY sort_order DESC, id DESC LIMIT 1",
                    [$row['member_id'], $row['sort_order'], $row['sort_order'], $row['id']]
                );
            } else {
                $other = db_fetch_one(
                    "SELECT id, sort_order FROM `{$tbl}`
                       WHERE member_id = ?
                         AND (sort_order > ? OR (sort_order = ? AND id > ?))
                       ORDER BY sort_order ASC, id ASC LIMIT 1",
                    [$row['member_id'], $row['sort_order'], $row['sort_order'], $row['id']]
                );
            }
            if (!$other) json_response(['success' => true, 'moved' => false]);

            // Two-step swap with a temporary value avoids the (rare) case
            // where both rows share the same sort_order — assigning the
            // same number twice doesn't actually swap anything.
            db_query("UPDATE `{$tbl}` SET sort_order = ? WHERE id = ?",
                [-1 * (int) $row['id'], $row['id']]);
            db_query("UPDATE `{$tbl}` SET sort_order = ? WHERE id = ?",
                [(int) $row['sort_order'], $other['id']]);
            db_query("UPDATE `{$tbl}` SET sort_order = ? WHERE id = ?",
                [(int) $other['sort_order'], $row['id']]);

            audit_log('comms', 'update', 'comm_identifier', $id,
                "Reordered comm identifier #{$id} {$direction} (swapped with #{$other['id']})");
            json_response(['success' => true, 'moved' => true]);
        } catch (Exception $e) {
            json_error('Failed to reorder: ' . $e->getMessage());
        }
    }

    json_error('Unknown action: ' . $action);
}
