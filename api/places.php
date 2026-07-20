<?php
/**
 * Phase 41 — Places API (named locations for fast incident-form lookup).
 *
 *   GET  ?action=list      → all places
 *   GET  ?action=detail&id → single place
 *   POST ?action=create    → name, street, city, state, lat, lon, zoom,
 *                            apply_to (city|bldg), information
 *   POST ?action=update    → fields by id
 *   POST ?action=delete    → id
 *   GET  ?action=search&q  → typeahead — name LIKE %q% or street LIKE %q%
 *
 * RBAC: action.manage_config.
 */
ini_set('display_errors', '0');
header('Content-Type: application/json');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

$input = [];
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $input = $raw ? (json_decode($raw, true) ?: []) : $_POST;
    if (!$action && !empty($input['action'])) $action = $input['action'];
}

// Phase 41-fix (2026-06-27, a beta tester): the original RBAC gate at
// the top of this file required action.manage_config for EVERY action,
// including `search`. That broke the new-incident form's places
// autocomplete for any non-admin dispatcher — typing in the street
// address dropped a 403 instead of suggestions. Per-action RBAC: read
// actions (list, detail, search) only need a logged-in user (auth.php
// already enforced that above); write actions (create, update, delete)
// still require action.manage_config.
$readOnlyActions = ['list', 'detail', 'search'];
if (!in_array($action, $readOnlyActions, true)) {
    if (!rbac_can('action.manage_config')) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden — requires action.manage_config']);
        exit;
    }
}

if ($action === 'list' && $method === 'GET') {
    try {
        $rows = db_fetch_all(
            "SELECT id, name, apply_to, street, city, state, information, lat, lon, zoom
               FROM `{$prefix}places`
              ORDER BY name ASC"
        );
        json_response(['places' => $rows]);
    } catch (Exception $e) { json_error_safe('list failed. Check server logs.', $e, 'places.list'); }
}

if ($action === 'detail' && $method === 'GET') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) json_error('id required');
    try {
        $row = db_fetch_one("SELECT * FROM `{$prefix}places` WHERE id = ?", [$id]);
        if (!$row) json_error('not found', 404);
        json_response(['place' => $row]);
    } catch (Exception $e) { json_error_safe('detail failed. Check server logs.', $e, 'places.detail'); }
}

if ($action === 'create' && $method === 'POST') {
    if (empty($input['csrf_token']) || !csrf_verify($input['csrf_token'])) {
        json_error('Invalid CSRF token', 403);
    }
    $name = substr(trim((string) ($input['name'] ?? '')), 0, 64);
    if ($name === '') json_error('name required');
    $apply  = in_array((string) ($input['apply_to'] ?? 'bldg'), ['city','bldg'], true) ? $input['apply_to'] : 'bldg';
    $street = substr((string) ($input['street'] ?? ''), 0, 96);
    $city   = substr((string) ($input['city'] ?? ''), 0, 32);
    $state  = substr((string) ($input['state'] ?? ''), 0, 4);
    $info   = substr((string) ($input['information'] ?? ''), 0, 1024);
    $lat    = isset($input['lat']) ? (float) $input['lat'] : null;
    $lon    = isset($input['lon']) ? (float) $input['lon'] : null;
    $zoom   = isset($input['zoom']) ? max(1, min(20, (int) $input['zoom'])) : 16;
    try {
        db_query(
            "INSERT INTO `{$prefix}places` (name, apply_to, street, city, state, information, lat, lon, zoom)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [$name, $apply, $street, $city, $state, $info, $lat, $lon, $zoom]
        );
        json_response(['id' => (int) db_insert_id(), 'name' => $name]);
    } catch (Exception $e) { json_error_safe('create failed. Check server logs.', $e, 'places.create'); }
}

if ($action === 'update' && $method === 'POST') {
    if (empty($input['csrf_token']) || !csrf_verify($input['csrf_token'])) {
        json_error('Invalid CSRF token', 403);
    }
    $id = (int) ($input['id'] ?? 0);
    if ($id <= 0) json_error('id required');
    $sets = [];
    $params = [];
    foreach (['name','street','city','state','information','apply_to'] as $f) {
        if (isset($input[$f])) {
            $sets[] = "`{$f}` = ?";
            if ($f === 'apply_to' && !in_array((string) $input[$f], ['city','bldg'], true)) $input[$f] = 'bldg';
            $params[] = substr((string) $input[$f], 0, 1024);
        }
    }
    foreach (['lat','lon'] as $f) {
        if (array_key_exists($f, $input)) { $sets[] = "`{$f}` = ?"; $params[] = $input[$f] === null ? null : (float) $input[$f]; }
    }
    if (isset($input['zoom'])) { $sets[] = "zoom = ?"; $params[] = max(1, min(20, (int) $input['zoom'])); }
    if (!$sets) json_error('nothing to update');
    $params[] = $id;
    try {
        db_query("UPDATE `{$prefix}places` SET " . implode(', ', $sets) . " WHERE id = ?", $params);
        json_response(['ok' => true]);
    } catch (Exception $e) { json_error_safe('update failed. Check server logs.', $e, 'places.update'); }
}

if ($action === 'delete' && $method === 'POST') {
    if (empty($input['csrf_token']) || !csrf_verify($input['csrf_token'])) {
        json_error('Invalid CSRF token', 403);
    }
    $id = (int) ($input['id'] ?? 0);
    if ($id <= 0) json_error('id required');
    try {
        db_query("DELETE FROM `{$prefix}places` WHERE id = ?", [$id]);
        json_response(['ok' => true]);
    } catch (Exception $e) { json_error_safe('delete failed. Check server logs.', $e, 'places.delete'); }
}

if ($action === 'search' && $method === 'GET') {
    $q = trim((string) ($_GET['q'] ?? ''));
    if ($q === '') json_response(['places' => []]);
    try {
        $like = '%' . $q . '%';
        $rows = db_fetch_all(
            "SELECT id, name, street, city, state, lat, lon
               FROM `{$prefix}places`
              WHERE name LIKE ? OR street LIKE ?
              ORDER BY name ASC
              LIMIT 20",
            [$like, $like]
        );
        json_response(['places' => $rows]);
    } catch (Exception $e) { json_error_safe('search failed. Check server logs.', $e, 'places.search'); }
}

// ── Phase 108 (issue #36) — bulk export ─────────────────────────────
// GET ?action=export&format=csv|json  — streams the file with a
// Content-Disposition attachment header. RBAC-gated the same way as
// create/update/delete (write-side admin permission).
if ($action === 'export' && $method === 'GET') {
    $format = strtolower((string) ($_GET['format'] ?? 'csv'));
    if (!in_array($format, ['csv', 'json'], true)) {
        json_error('format must be csv or json');
    }
    try {
        $rows = db_fetch_all(
            "SELECT name, apply_to, street, city, state, information, lat, lon, zoom
               FROM `{$prefix}places`
              ORDER BY name ASC"
        );
    } catch (Exception $e) { json_error_safe('export failed. Check server logs.', $e, 'places.export'); }

    $stamp = date('Ymd-His');
    if ($format === 'json') {
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="places-' . $stamp . '.json"');
        echo json_encode(['places' => $rows], JSON_PRETTY_PRINT);
        exit;
    }
    // CSV
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="places-' . $stamp . '.csv"');
    $out = fopen('php://output', 'w');
    // BOM so Excel opens the UTF-8 file correctly out of the box.
    fwrite($out, "\xEF\xBB\xBF");
    $cols = ['name','apply_to','street','city','state','information','lat','lon','zoom'];
    // PHP 8.4 deprecates the implicit escape param — pass '' explicitly
    // for a clean, standards-compliant CSV. Same for str_getcsv below.
    fputcsv($out, $cols, ',', '"', '');
    foreach ($rows as $r) {
        $line = [];
        foreach ($cols as $c) $line[] = $r[$c] ?? '';
        fputcsv($out, $line, ',', '"', '');
    }
    fclose($out);
    exit;
}

// ── Phase 108 (issue #36) — bulk import ─────────────────────────────
// POST action=import  (multipart form)
//   file       — uploaded file (CSV or JSON)
//   format     — 'csv' | 'json'  (defaults to CSV)
//   dry_run    — '1' | '0'       (default '1'; when set, no writes;
//                                 the response still reports what
//                                 WOULD have happened per row)
//   csrf_token — required
//
// Match key: `name` (case-insensitive). If the incoming row's name
// matches an existing row, we UPDATE; otherwise INSERT. Malformed
// rows are collected in `errors[]` with row-number + message; the
// rest of the file still processes. Return payload:
//   { inserted, updated, skipped, errors: [{row, message}] }
if ($action === 'import' && $method === 'POST') {
    // For multipart form uploads $_POST is populated (json_decode
    // won't have been). Rehydrate expectations.
    $csrf = $_POST['csrf_token'] ?? ($input['csrf_token'] ?? '');
    if (empty($csrf) || !csrf_verify($csrf)) json_error('Invalid CSRF token', 403);

    $format  = strtolower((string) ($_POST['format']  ?? 'csv'));
    $dryRun  = (string) ($_POST['dry_run'] ?? '1') === '1';
    if (!in_array($format, ['csv', 'json'], true)) json_error('format must be csv or json');

    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        json_error('file upload missing or failed');
    }
    // Size guard (H2 in code review 2026-07-03): reject anything over
    // 5 MB — a places CSV should be a handful of KB even for large
    // agencies; anything bigger is either a wrong file or an insider
    // DoS attempt.
    $MAX_UPLOAD = 5 * 1024 * 1024;
    if ($_FILES['file']['size'] > $MAX_UPLOAD) {
        json_error('file too large (max ' . intdiv($MAX_UPLOAD, 1024) . ' KB)');
    }
    // Row cap so a pathological file can't hold PHP for too long.
    $MAX_ROWS = 10000;

    $tmpPath = $_FILES['file']['tmp_name'];

    // Parse to a uniform list of row arrays.
    $items = [];
    $errors = [];
    if ($format === 'json') {
        $raw = file_get_contents($tmpPath);
        if ($raw === false) json_error('could not read uploaded file');
        $parsed = json_decode($raw, true);
        if (!is_array($parsed)) { json_error('JSON must decode to an object or array'); }
        $items = isset($parsed['places']) && is_array($parsed['places']) ? $parsed['places'] : $parsed;
        if (!is_array($items) || (count($items) && !is_array(reset($items)))) {
            json_error('JSON shape must be {"places": [...]} or an array of row objects');
        }
        if (count($items) > $MAX_ROWS) {
            json_error('too many rows (max ' . $MAX_ROWS . ')');
        }
    } else {
        // L2 in code review: use fgetcsv on the file handle so quoted
        // fields containing embedded newlines don't shift columns on
        // subsequent rows. The previous regex-split approach broke on
        // any description field with a newline in it.
        $fh = fopen($tmpPath, 'rb');
        if ($fh === false) json_error('could not open uploaded file');
        // Strip UTF-8 BOM if present so the first header lines up.
        $bom = fread($fh, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            // Not a BOM — rewind so we read the header line correctly.
            rewind($fh);
        }
        $headers = fgetcsv($fh, 0, ',', '"', '');
        if (!$headers) {
            fclose($fh);
            json_error('empty CSV');
        }
        if (!in_array('name', $headers, true)) {
            fclose($fh);
            json_error('CSV missing required column: name');
        }
        while (($vals = fgetcsv($fh, 0, ',', '"', '')) !== false) {
            if (count($items) >= $MAX_ROWS) {
                fclose($fh);
                json_error('too many rows (max ' . $MAX_ROWS . ')');
            }
            if ($vals === [null]) continue;   // blank line
            $row = [];
            for ($c = 0; $c < count($headers); $c++) {
                $row[$headers[$c]] = $vals[$c] ?? '';
            }
            $items[] = $row;
        }
        fclose($fh);
    }

    $inserted = 0;
    $updated  = 0;
    $skipped  = 0;

    foreach ($items as $i => $row) {
        $rowNum = $i + ($format === 'csv' ? 2 : 1);  // +2 for header line
        $name = isset($row['name']) ? trim((string) $row['name']) : '';
        if ($name === '') {
            $errors[] = ['row' => $rowNum, 'message' => 'name is required'];
            $skipped++;
            continue;
        }
        $apply = isset($row['apply_to']) && in_array((string) $row['apply_to'], ['city','bldg'], true)
                    ? (string) $row['apply_to'] : 'bldg';
        $street = isset($row['street']) ? substr((string) $row['street'], 0, 96) : '';
        $city   = isset($row['city'])   ? substr((string) $row['city'], 0, 32) : '';
        $state  = isset($row['state'])  ? substr((string) $row['state'], 0, 4)  : '';
        $info   = isset($row['information']) ? substr((string) $row['information'], 0, 1024) : '';
        $lat    = null;
        $lon    = null;
        if (isset($row['lat']) && $row['lat'] !== '') {
            if (!is_numeric($row['lat'])) {
                $errors[] = ['row' => $rowNum, 'message' => 'lat must be numeric'];
                $skipped++; continue;
            }
            $lat = (float) $row['lat'];
        }
        if (isset($row['lon']) && $row['lon'] !== '') {
            if (!is_numeric($row['lon'])) {
                $errors[] = ['row' => $rowNum, 'message' => 'lon must be numeric'];
                $skipped++; continue;
            }
            $lon = (float) $row['lon'];
        }
        $zoom = isset($row['zoom']) && $row['zoom'] !== '' ? max(1, min(20, (int) $row['zoom'])) : 16;

        // Match on name case-insensitively.
        try {
            $existingId = db_fetch_value(
                "SELECT id FROM `{$prefix}places` WHERE LOWER(name) = LOWER(?) LIMIT 1",
                [$name]
            );
        } catch (Exception $e) {
            error_log('[places.import.lookup] row ' . $rowNum . ': ' . $e->getMessage()
                . ' at ' . $e->getFile() . ':' . $e->getLine());
            $errors[] = ['row' => $rowNum, 'message' => 'lookup failed (check server logs)'];
            $skipped++;
            continue;
        }
        if ($existingId) {
            if (!$dryRun) {
                try {
                    db_query(
                        "UPDATE `{$prefix}places`
                            SET apply_to = ?, street = ?, city = ?, state = ?, information = ?,
                                lat = ?, lon = ?, zoom = ?
                          WHERE id = ?",
                        [$apply, $street, $city, $state, $info, $lat, $lon, $zoom, $existingId]
                    );
                } catch (Exception $e) {
                    error_log('[places.import.update] row ' . $rowNum . ': ' . $e->getMessage()
                        . ' at ' . $e->getFile() . ':' . $e->getLine());
                    $errors[] = ['row' => $rowNum, 'message' => 'update failed (check server logs)'];
                    $skipped++;
                    continue;
                }
            }
            $updated++;
        } else {
            if (!$dryRun) {
                try {
                    db_query(
                        "INSERT INTO `{$prefix}places`
                           (name, apply_to, street, city, state, information, lat, lon, zoom)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                        [$name, $apply, $street, $city, $state, $info, $lat, $lon, $zoom]
                    );
                } catch (Exception $e) {
                    error_log('[places.import.insert] row ' . $rowNum . ': ' . $e->getMessage()
                        . ' at ' . $e->getFile() . ':' . $e->getLine());
                    $errors[] = ['row' => $rowNum, 'message' => 'insert failed (check server logs)'];
                    $skipped++;
                    continue;
                }
            }
            $inserted++;
        }
    }

    json_response([
        'dry_run'  => $dryRun,
        'inserted' => $inserted,
        'updated'  => $updated,
        'skipped'  => $skipped,
        'errors'   => $errors,
        'total_processed' => count($items),
    ]);
}

json_error('Unknown action: ' . $action);
