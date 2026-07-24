<?php
/**
 * NewUI v4.0 API - Map Markups
 *
 * GET  /api/map-markups.php              — List all markups with categories
 * GET  /api/map-markups.php?id=X         — Single markup detail
 * GET  /api/map-markups.php?categories=1 — List markup categories
 * POST action=save                       — Create/update markup
 * POST action=delete                     — Delete markup
 * POST action=save_category              — Create/update category
 * POST action=toggle_visibility          — Toggle markup visibility
 *
 * Legacy tables: mmarkup + mmarkup_cats
 */

ini_set('display_errors', '0');
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/audit.php';
require_once __DIR__ . '/../inc/map-format-converters.php';

$method = $_SERVER['REQUEST_METHOD'];

// ── Phase 43c: export ?action=export&format=geojson|kml|gpx[&category_id=N] ──
// Returns the export inline as the right content-type with a download filename.
if ($method === 'GET' && ($_GET['action'] ?? '') === 'export') {
    $format = strtolower($_GET['format'] ?? 'geojson');
    $catId  = isset($_GET['category_id']) ? (int) $_GET['category_id'] : 0;
    $sql = "SELECT m.*, mc.category AS category_name
              FROM " . db_table('mmarkup') . " m
              LEFT JOIN " . db_table('mmarkup_cats') . " mc ON mc.id = COALESCE(m.category_id, m.line_cat_id)";
    $params = [];
    if ($catId > 0) {
        $sql .= " WHERE COALESCE(m.category_id, m.line_cat_id) = ?";
        $params[] = $catId;
    }
    $sql .= " ORDER BY mc.category, m.line_name";
    try { $rows = db_fetch_all($sql, $params); } catch (Exception $e) { $rows = []; }

    $catLookup = [];
    try {
        foreach (db_fetch_all("SELECT id, category FROM " . db_table('mmarkup_cats')) as $c) {
            $catLookup[(int) $c['id']] = $c['category'];
        }
    } catch (Exception $e) {}

    $stamp = date('Ymd-His');
    $catSlug = $catId && isset($catLookup[$catId])
        ? '-' . preg_replace('/[^a-z0-9-]+/i', '_', strtolower($catLookup[$catId]))
        : '';
    $base = "map-overlays{$catSlug}-{$stamp}";

    if ($format === 'kml') {
        $docName = ($catId && isset($catLookup[$catId]) ? $catLookup[$catId] : 'Map Overlays') . ' — TicketsCAD';
        $body = mmarkup_rows_to_kml($rows, $docName, $catLookup);
        header('Content-Type: application/vnd.google-earth.kml+xml; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $base . '.kml"');
        echo $body; exit;
    }
    if ($format === 'gpx') {
        $body = mmarkup_rows_to_gpx($rows);
        header('Content-Type: application/gpx+xml; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $base . '.gpx"');
        echo $body; exit;
    }
    // default geojson
    $body = mmarkup_rows_to_geojson($rows, $catLookup);
    header('Content-Type: application/geo+json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $base . '.geojson"');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

if ($method === 'GET') {
    // Categories
    if (!empty($_GET['categories'])) {
        try {
            $rows = db_fetch_all("SELECT * FROM " . db_table('mmarkup_cats') . " ORDER BY category");
        } catch (Exception $e) {
            $rows = [];
        }
        json_response(['categories' => $rows]);
    }

    // Single markup
    if (!empty($_GET['id'])) {
        $id = intval($_GET['id']);
        try {
            $row = db_fetch_one(
                "SELECT m.*, mc.category AS category_name
                 FROM " . db_table('mmarkup') . " m
                 LEFT JOIN " . db_table('mmarkup_cats') . " mc ON m.line_cat_id = mc.id
                 WHERE m.id = ?",
                [$id]
            );
        } catch (Exception $e) {
            $row = null;
        }
        if (!$row) json_error('Markup not found', 404);
        json_response(['markup' => $row]);
    }

    // List all (optionally filter by category)
    $catId = intval($_GET['category_id'] ?? 0);
    $where = '';
    $params = [];
    if ($catId) {
        $where = ' WHERE m.line_cat_id = ?';
        $params[] = $catId;
    }
    try {
        $rows = db_fetch_all(
            "SELECT m.*, mc.category AS category_name
             FROM " . db_table('mmarkup') . " m
             LEFT JOIN " . db_table('mmarkup_cats') . " mc ON m.line_cat_id = mc.id" .
            $where . " ORDER BY mc.category, m.line_name",
            $params
        );
    } catch (Exception $e) {
        $rows = [];
    }
    json_response(['markups' => $rows]);
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];

    // RBAC + CSRF enforcement (specs/rbac-enforcement-2026-06).
    // Writes require action.manage_map; reads (GET) stay open to viewers.
    if (!rbac_can('action.manage_map')) {
        json_error('Insufficient permissions: manage map', 403);
    }
    if (empty($input['csrf_token']) || !csrf_verify($input['csrf_token'])) {
        json_error('Invalid CSRF token', 403);
    }

    $action = $input['action'] ?? 'save';

    // ── Save category ──
    if ($action === 'save_category') {
        $category = trim($input['category'] ?? '');
        if (!$category) json_error('Category name required');
        $id = intval($input['id'] ?? 0);
        try {
            if ($id > 0) {
                db_query("UPDATE " . db_table('mmarkup_cats') . " SET category = ? WHERE id = ?", [$category, $id]);
            } else {
                db_query(
                    "INSERT INTO " . db_table('mmarkup_cats') . " (category, _by, _from, _on) VALUES (?, ?, 'newui', NOW())",
                    [$category, $current_user_id]
                );
                $id = db_insert_id();
            }
            audit_log('config', $input['id'] ? 'update' : 'create', 'markup_category', $id, "Saved markup category '{$category}'");
        } catch (Exception $e) {
            json_error('Failed: ' . $e->getMessage());
        }
        json_response(['success' => true, 'id' => $id]);
    }

    // ── Save markup ──
    if ($action === 'save') {
        $name = trim($input['name'] ?? '');
        if (!$name) json_error('Name is required');
        $id = intval($input['id'] ?? 0);

        $catId = intval($input['category_id'] ?? 0);
        // GH TicketsCAD#3 — column => the request key that supplies it.
        // $fields below is the FULL set, with defaults, and is what an INSERT
        // writes. An UPDATE must write only the columns the caller actually
        // sent: a partial save (Rename posts id + name only) previously wrote
        // the whole defaulted set, blanking line_data — the geometry — which
        // left a row no renderer can draw, so the shape silently vanished from
        // every map and its coordinates were unrecoverable.
        $srcKey = [
            'line_name'     => 'name',
            'line_status'   => 'visible',
            'line_type'     => 'type',
            'line_ident'    => 'ident',
            'line_cat_id'   => 'category_id',
            'line_data'     => 'coordinates',
            'line_color'    => 'color',
            'line_opacity'  => 'opacity',
            'line_width'    => 'width',
            'fill_color'    => 'fill_color',
            'fill_opacity'  => 'fill_opacity',
            'filled'        => 'filled',
            'use_with_bm'   => 'use_with_bm',
            'use_with_r'    => 'use_with_r',
            'use_with_f'    => 'use_with_f',
            'use_with_u_ex' => 'use_with_u_ex',
            'use_with_u_rf' => 'use_with_u_rf',
            'category_id'   => 'category_id',
        ];
        $fields = [
            'line_name'   => $name,
            'line_status' => $input['visible'] ?? 1,
            'line_type'   => substr(trim($input['type'] ?? 'P'), 0, 1), // P=polygon, L=line, C=circle, M=marker
            'line_ident'  => trim($input['ident'] ?? ''),
            'line_cat_id' => $catId,
            'line_data'   => $input['coordinates'] ?? '', // JSON string of coordinates
            'line_color'  => trim($input['color'] ?? '#FF0000'),
            'line_opacity' => floatval($input['opacity'] ?? 0.5),
            'line_width'  => intval($input['width'] ?? 2),
            'fill_color'  => trim($input['fill_color'] ?? ''),
            'fill_opacity' => floatval($input['fill_opacity'] ?? 0.2),
            'filled'      => intval($input['filled'] ?? 0),
            'use_with_bm' => intval($input['use_with_bm'] ?? 1),
            'use_with_r'  => intval($input['use_with_r'] ?? 0),
            'use_with_f'  => intval($input['use_with_f'] ?? 0),
            'use_with_u_ex' => intval($input['use_with_u_ex'] ?? 0),
            'use_with_u_rf' => intval($input['use_with_u_rf'] ?? 0),
        ];
        // Phase 43: write the new mmarkup.category_id column too, when it
        // exists (Phase 41 migration adds it). Lets the dispatcher overlay
        // toggle group by either id without touching legacy data.
        static $_hasNewCatCol = null;
        if ($_hasNewCatCol === null) {
            try {
                $_hasNewCatCol = (bool) db_fetch_value(
                    "SELECT COUNT(*) FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE()
                        AND TABLE_NAME = ?
                        AND COLUMN_NAME = 'category_id'",
                    [db_table('mmarkup')]
                );
            } catch (Exception $e) { $_hasNewCatCol = false; }
        }
        if ($_hasNewCatCol) {
            $fields['category_id'] = $catId ?: null;
        }

        try {
            if ($id > 0) {
                // Only touch columns the caller actually supplied (GH #3).
                // line_name is always written — it is required and validated above.
                $sets = [];
                $vals = [];
                foreach ($fields as $col => $val) {
                    $key = $srcKey[$col] ?? $col;
                    if ($col !== 'line_name' && !array_key_exists($key, $input)) {
                        continue;
                    }
                    $sets[] = "`{$col}` = ?";
                    $vals[] = $val;
                }
                $vals[] = $id;
                db_query("UPDATE " . db_table('mmarkup') . " SET " . implode(', ', $sets) . " WHERE id = ?", $vals);
                audit_log('map', 'update', 'markup', $id, "Updated map markup '{$name}'");
            } else {
                $fields['_by'] = $current_user_id;
                $fields['_from'] = 'newui';
                $fields['_on'] = date('Y-m-d H:i:s');
                $cols = array_keys($fields);
                $placeholders = array_fill(0, count($cols), '?');
                db_query(
                    "INSERT INTO " . db_table('mmarkup') . " (`" . implode('`, `', $cols) . "`) VALUES (" . implode(', ', $placeholders) . ")",
                    array_values($fields)
                );
                $id = db_insert_id();
                audit_log('map', 'create', 'markup', $id, "Created map markup '{$name}'");
            }
        } catch (Exception $e) {
            json_error('Failed to save: ' . $e->getMessage());
        }
        json_response(['success' => true, 'id' => $id]);
    }

    // ── Toggle visibility ──
    if ($action === 'toggle_visibility') {
        $id = intval($input['id'] ?? 0);
        if (!$id) json_error('Missing id');
        try {
            db_query(
                "UPDATE " . db_table('mmarkup') . " SET line_status = IF(line_status = 1, 0, 1) WHERE id = ?",
                [$id]
            );
        } catch (Exception $e) {
            json_error('Failed: ' . $e->getMessage());
        }
        json_response(['success' => true]);
    }

    // ── Phase 43c: import shapes from GeoJSON / KML ──
    // Body: { action: 'import', format: 'geojson'|'kml', content: '<raw text>',
    //         category_id: N (optional, used as default for features without one) }
    if ($action === 'import') {
        $fmt = strtolower(trim((string) ($input['format'] ?? '')));
        $content = (string) ($input['content'] ?? '');
        $defaultCat = isset($input['category_id']) ? (int) $input['category_id'] : null;
        if ($content === '') json_error('content is required');

        $shapes = [];
        try {
            if ($fmt === 'geojson' || $fmt === 'json') {
                $fc = json_decode($content, true);
                if (!is_array($fc)) json_error('Invalid GeoJSON');
                $shapes = geojson_to_mmarkup_rows($fc, $defaultCat);
            } elseif ($fmt === 'kml') {
                $shapes = kml_to_mmarkup_rows($content, $defaultCat);
            } elseif ($fmt === 'kmz') {
                // KMZ is a binary zip, so the client sends it base64-encoded
                // (a flag `content_encoding: 'base64'` is set). Decode, then
                // unzip + reuse the KML converter.
                $binary = $content;
                if (($input['content_encoding'] ?? '') === 'base64') {
                    $binary = base64_decode($content, true);
                    if ($binary === false) json_error('Invalid base64 KMZ payload.');
                }
                $shapes = kmz_to_mmarkup_rows($binary, $defaultCat); // throws on bad zip
            } else {
                json_error('Unsupported format. Use geojson, kml, or kmz.', 415);
            }
        } catch (RuntimeException $e) {
            // Corrupt/empty KMZ, no KML inside, missing ZipArchive, etc.
            json_error('KMZ import failed: ' . $e->getMessage());
        } catch (Exception $e) {
            json_error('Parse failed: ' . $e->getMessage());
        }
        if (!$shapes) json_error('No importable shapes found in payload.');

        // Insert each shape using the same fields the save action uses, so
        // the new mmarkup.category_id column (when present) gets written too.
        $hasNewCatCol = false;
        try {
            $hasNewCatCol = (bool) db_fetch_value(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = ?
                    AND COLUMN_NAME = 'category_id'",
                [db_table('mmarkup')]
            );
        } catch (Exception $e) {}

        $imported = 0; $errors = [];
        foreach ($shapes as $s) {
            $catId = isset($s['category_id']) ? (int) $s['category_id'] : 0;
            $fields = [
                'line_name'   => substr((string) $s['name'], 0, 128),
                'line_status' => 1,
                'line_type'   => substr((string) ($s['type'] ?? 'P'), 0, 1),
                'line_ident'  => trim((string) ($s['ident'] ?? '')),
                'line_cat_id' => $catId,
                'line_data'   => (string) ($s['coordinates'] ?? '[]'),
                'line_color'  => trim((string) ($s['color'] ?? '#1976d2')),
                'line_opacity' => (float) ($s['opacity'] ?? 0.8),
                'line_width'  => (int) ($s['width'] ?? 2),
                'fill_color'  => trim((string) ($s['fill_color'] ?? ($s['color'] ?? '#1976d2'))),
                'fill_opacity' => (float) ($s['fill_opacity'] ?? 0.2),
                'filled'      => (int) ($s['filled'] ?? 0),
                'use_with_bm' => 1,
                'use_with_r'  => 0,
                'use_with_f'  => 0,
                'use_with_u_ex' => 0,
                'use_with_u_rf' => 0,
                '_by'         => $current_user_id,
                '_from'       => 'newui-import-' . $fmt,
                '_on'         => date('Y-m-d H:i:s'),
            ];
            if ($hasNewCatCol) $fields['category_id'] = $catId ?: null;
            try {
                $cols = array_keys($fields);
                $ph   = array_fill(0, count($cols), '?');
                db_query(
                    "INSERT INTO " . db_table('mmarkup') . " (`" . implode('`, `', $cols) . "`) VALUES (" . implode(', ', $ph) . ")",
                    array_values($fields)
                );
                $imported++;
            } catch (Exception $e) {
                $errors[] = $s['name'] . ': ' . $e->getMessage();
            }
        }
        audit_log('map', 'import', 'markup', 0,
            "Imported {$imported} {$fmt} shape(s)" . ($defaultCat ? " into category #{$defaultCat}" : ''));
        json_response(['success' => true, 'imported' => $imported, 'errors' => $errors]);
    }

    // ── Delete markup ──
    if ($action === 'delete') {
        $id = intval($input['id'] ?? 0);
        if (!$id) json_error('Missing id');
        try {
            db_query("DELETE FROM " . db_table('mmarkup') . " WHERE id = ?", [$id]);
            audit_log('map', 'delete', 'markup', $id, "Deleted map markup #{$id}");
        } catch (Exception $e) {
            json_error('Delete failed: ' . $e->getMessage());
        }
        json_response(['success' => true]);
    }

    json_error('Unknown action');
}

json_error('Method not allowed', 405);
