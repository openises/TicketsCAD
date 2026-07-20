<?php
/**
 * Phase 99e (2026-06-28) — talkgroup registry API.
 *
 *   GET    /api/talkgroups.php             — list all (sorted)
 *   GET    /api/talkgroups.php?enabled=1   — only enabled rows
 *   POST   /api/talkgroups.php             — create or update
 *           {id?, dmr_id, name, description?, sort_order?, enabled?}
 *   DELETE /api/talkgroups.php?id=N        — delete a row
 *
 * Read: any logged-in user (Compose + radio widget need this).
 * Write: action.manage_talkgroups RBAC permission.
 */

declare(strict_types=1);

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

function _tg_write_gate() {
    if (!rbac_can('action.manage_talkgroups') && !is_admin()) {
        http_response_code(403);
        echo json_encode(['error' => 'action.manage_talkgroups required']);
        exit;
    }
}

function _tg_csrf_gate(array $body) {
    if (!function_exists('csrf_check')) return;
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($body['_csrf'] ?? ($body['csrf_token'] ?? ''));
    if (!csrf_check($token)) {
        http_response_code(403);
        echo json_encode(['error' => 'CSRF token mismatch']);
        exit;
    }
}

if ($method === 'GET') {
    // Export-as-CSV mode (Phase 99e v2 — 2026-06-28).
    if (isset($_GET['format']) && $_GET['format'] === 'csv') {
        try {
            $rows = db_fetch_all(
                "SELECT dmr_id, name, description, call_type, sort_order, enabled
                   FROM `{$prefix}talkgroups`
                  ORDER BY sort_order ASC, name ASC"
            );
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="talkgroups-' . date('Y-m-d') . '.csv"');
            // PHP 8.4 deprecated the implicit-default $escape arg on
            // fputcsv. Pass all defaults explicitly through $escape='\\'
            // to silence the warning AND keep the output stable across
            // PHP versions (PHP 9 will change the default to ''; pinning
            // here makes the file format independent of php.ini).
            $out = fopen('php://output', 'w');
            fputcsv($out, ['dmr_id', 'name', 'description', 'call_type', 'sort_order', 'enabled'], ',', '"', '\\');
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['dmr_id'], $r['name'], $r['description'] ?? '',
                    $r['call_type'] ?? 'group', $r['sort_order'] ?? 0, $r['enabled'] ? '1' : '0'
                ], ',', '"', '\\');
            }
            fclose($out);
            exit;
        } catch (Throwable $e) {
            http_response_code(500);
            echo "error: " . $e->getMessage();
            exit;
        }
    }

    $where = '1=1';
    if (isset($_GET['enabled']) && $_GET['enabled'] === '1') {
        $where = '`enabled` = 1';
    }
    try {
        $rows = db_fetch_all(
            "SELECT id, dmr_id, name, description, call_type, sort_order, enabled
               FROM `{$prefix}talkgroups`
              WHERE {$where}
              ORDER BY sort_order ASC, name ASC"
        );
        echo json_encode(['talkgroups' => $rows, 'count' => count($rows)]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'List failed: ' . $e->getMessage()]);
    }
    exit;
}

if ($method === 'POST') {
    $raw  = file_get_contents('php://input');
    $body = json_decode($raw, true) ?: [];
    _tg_csrf_gate($body);
    _tg_write_gate();

    // CSV-import action (Phase 99e v2 — 2026-06-28).
    //   {action: 'import_csv', csv: '<text>', replace_existing: bool}
    // replace_existing=true → row matched by dmr_id is updated
    // replace_existing=false → row matched by dmr_id is skipped (INSERT IGNORE)
    // Header row required: dmr_id, name, [description], [call_type], [sort_order], [enabled]
    // Phase 99e v4 (2026-06-28) — reorder. Takes an ordered array of
    // talkgroup ids and renumbers sort_order sequentially 1, 2, 3, ...
    // (was step=3 — dropped per Eric: 'We don't need the renumber by
    // 3 any longer if I can simply drag to reorder.')
    //
    // Backs both the drag-and-drop tbody reorder AND the "type a
    // number in the sort cell" semantic (target row lands AT that
    // number; old row at that number becomes N+1, old N+1 becomes
    // N+2, etc).
    if (($body['action'] ?? '') === 'reorder') {
        $order = $body['order'] ?? null;
        if (!is_array($order) || empty($order)) {
            http_response_code(400);
            echo json_encode(['error' => 'order must be a non-empty array of ids']);
            exit;
        }
        try {
            $sort = 1;
            $applied = 0;
            foreach ($order as $rowId) {
                $rid = (int) $rowId;
                if ($rid <= 0) continue;
                db_query(
                    "UPDATE `{$prefix}talkgroups` SET sort_order = ? WHERE id = ?",
                    [$sort, $rid]
                );
                $sort++;
                $applied++;
            }
            echo json_encode(['ok' => true, 'applied' => $applied, 'last_sort' => $sort - 1]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Reorder failed: ' . $e->getMessage()]);
        }
        exit;
    }

    // Phase 99e v3 followup (2026-06-28) — partial UPDATE for the
    // inline-toggle enabled checkbox. Previously the inline toggle
    // posted the whole row including a possibly-stale sort_order
    // from cache, which clobbered freshly-saved sort changes if the
    // GET reload hadn't completed yet. This action touches ONLY the
    // enabled column so the inline UI can't overwrite anything else.
    if (($body['action'] ?? '') === 'set_enabled') {
        $id = (int) ($body['id'] ?? 0);
        $en = !empty($body['enabled']) ? 1 : 0;
        if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'id required']); exit; }
        try {
            db_query(
                "UPDATE `{$prefix}talkgroups` SET enabled = ? WHERE id = ?",
                [$en, $id]
            );
            echo json_encode(['ok' => true, 'id' => $id, 'enabled' => $en]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Toggle failed: ' . $e->getMessage()]);
        }
        exit;
    }

    if (($body['action'] ?? '') === 'import_csv') {
        $csv     = (string) ($body['csv'] ?? '');
        $replace = !empty($body['replace_existing']);
        $stats = ['rows_seen' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];
        if ($csv === '') { http_response_code(400); echo json_encode(['error' => 'csv body is empty']); exit; }
        try {
            $fh = fopen('php://memory', 'r+');
            fwrite($fh, $csv);
            rewind($fh);
            // Explicit $escape for PHP 8.4+ (deprecation 2026-06)
            $header = fgetcsv($fh, 0, ',', '"', '\\');
            if (!$header) { http_response_code(400); echo json_encode(['error' => 'CSV has no header row']); exit; }
            $cols = array_map(function ($h) { return strtolower(trim((string) $h)); }, $header);
            $idx = array_flip($cols);
            if (!isset($idx['dmr_id']) || !isset($idx['name'])) {
                http_response_code(400);
                echo json_encode(['error' => 'CSV must have dmr_id and name columns at minimum']);
                exit;
            }
            while (($row = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
                $stats['rows_seen']++;
                $dmrId = (int) ($row[$idx['dmr_id']] ?? 0);
                $name  = trim((string) ($row[$idx['name']] ?? ''));
                if ($dmrId <= 0 || $dmrId > 16777215 || $name === '') {
                    $stats['errors'][] = "Row {$stats['rows_seen']}: invalid dmr_id ({$dmrId}) or empty name";
                    continue;
                }
                $desc  = isset($idx['description']) ? trim((string) ($row[$idx['description']] ?? '')) : '';
                $ct    = isset($idx['call_type']) ? strtolower(trim((string) ($row[$idx['call_type']] ?? 'group'))) : 'group';
                if (!in_array($ct, ['group', 'private'], true)) $ct = 'group';
                $sort  = isset($idx['sort_order']) ? (int) ($row[$idx['sort_order']] ?? 0) : 0;
                $en    = isset($idx['enabled']) ? (((int) $row[$idx['enabled']]) ? 1 : 0) : 1;

                try {
                    if ($replace) {
                        db_query(
                            "INSERT INTO `{$prefix}talkgroups`
                                (dmr_id, name, description, call_type, sort_order, enabled)
                             VALUES (?, ?, ?, ?, ?, ?)
                             ON DUPLICATE KEY UPDATE
                                 name = VALUES(name),
                                 description = VALUES(description),
                                 call_type = VALUES(call_type),
                                 sort_order = VALUES(sort_order),
                                 enabled = VALUES(enabled)",
                            [$dmrId, $name, $desc !== '' ? $desc : null, $ct, $sort, $en]
                        );
                        // Can't distinguish inserted vs updated reliably without
                        // a pre-query — count as 'inserted_or_updated'
                        $stats['inserted']++;
                    } else {
                        // Skip-on-conflict mode
                        $existing = db_fetch_value(
                            "SELECT id FROM `{$prefix}talkgroups` WHERE dmr_id = ? LIMIT 1",
                            [$dmrId]
                        );
                        if ($existing) {
                            $stats['skipped']++;
                            continue;
                        }
                        db_query(
                            "INSERT INTO `{$prefix}talkgroups`
                                (dmr_id, name, description, call_type, sort_order, enabled)
                             VALUES (?, ?, ?, ?, ?, ?)",
                            [$dmrId, $name, $desc !== '' ? $desc : null, $ct, $sort, $en]
                        );
                        $stats['inserted']++;
                    }
                } catch (Throwable $e) {
                    $stats['errors'][] = "Row {$stats['rows_seen']} (dmr_id={$dmrId}): " . $e->getMessage();
                }
            }
            fclose($fh);
            echo json_encode(['ok' => true, 'stats' => $stats]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Import failed: ' . $e->getMessage()]);
        }
        exit;
    }

    $id     = isset($body['id']) && $body['id'] !== '' ? (int) $body['id'] : 0;
    $dmrId  = (int) ($body['dmr_id'] ?? 0);
    $name   = trim((string) ($body['name'] ?? ''));
    $desc   = trim((string) ($body['description'] ?? ''));
    $sort   = (int) ($body['sort_order'] ?? 0);
    $enabled = !empty($body['enabled']) ? 1 : 0;
    // Phase 99e v2 — call_type: 'group' (default, talkgroup broadcast)
    // or 'private' (point-to-point to a specific DMR radio ID).
    $callType = strtolower(trim((string) ($body['call_type'] ?? 'group')));
    if (!in_array($callType, ['group', 'private'], true)) $callType = 'group';

    if ($dmrId <= 0)   { http_response_code(400); echo json_encode(['error' => 'dmr_id is required and must be > 0']); exit; }
    if ($name === '')  { http_response_code(400); echo json_encode(['error' => 'name is required']); exit; }
    if ($dmrId > 16777215) { http_response_code(400); echo json_encode(['error' => 'dmr_id exceeds DMR 24-bit range']); exit; }

    try {
        if ($id > 0) {
            db_query(
                "UPDATE `{$prefix}talkgroups`
                    SET dmr_id = ?, name = ?, description = ?, call_type = ?, sort_order = ?, enabled = ?
                  WHERE id = ?",
                [$dmrId, $name, $desc !== '' ? $desc : null, $callType, $sort, $enabled, $id]
            );
            echo json_encode(['ok' => true, 'id' => $id, 'mode' => 'update']);
        } else {
            db_query(
                "INSERT INTO `{$prefix}talkgroups` (dmr_id, name, description, call_type, sort_order, enabled)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                     name = VALUES(name),
                     description = VALUES(description),
                     call_type = VALUES(call_type),
                     sort_order = VALUES(sort_order),
                     enabled = VALUES(enabled)",
                [$dmrId, $name, $desc !== '' ? $desc : null, $callType, $sort, $enabled]
            );
            echo json_encode(['ok' => true, 'id' => (int) db_insert_id(), 'mode' => 'upsert']);
        }
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Save failed: ' . $e->getMessage()]);
    }
    exit;
}

if ($method === 'DELETE') {
    _tg_write_gate();
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'id required']); exit; }
    try {
        db_query("DELETE FROM `{$prefix}talkgroups` WHERE id = ?", [$id]);
        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Delete failed: ' . $e->getMessage()]);
    }
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
